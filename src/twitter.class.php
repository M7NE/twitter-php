<?php

require_once dirname(__FILE__) . '/OAuth.php';
require_once dirname(__FILE__) . '/teltel.php';
require_once dirname(__FILE__) . '/mine.php';


/**
 * Twitter for PHP - library for sending messages to Twitter and receiving status updates.
 *
 * @author     David Grudl
 * @copyright  Copyright (c) 2008 David Grudl
 * @license    New BSD License
 * @link       http://phpfashion.com/
 * @see        http://dev.twitter.com/doc
 * @version    3.0
 */
class Twitter
{
	const API_URL = 'http://api.twitter.com/1.1/';

	/**#@+ Timeline {@link Twitter::load()} */
	const ME = 1;
	const ME_AND_FRIENDS = 2;
	const REPLIES = 3;
	const RETWEETS = 128; // include retweets?
	/**#@-*/

	/** @var int */
	public static $cacheExpire = 1800; // 30 min

	/** @var string */
	public static $cacheDir;

	/** @var Twitter_OAuthSignatureMethod */
	private $signatureMethod;

	/** @var Twitter_OAuthConsumer */
	private $consumer;

	/** @var Twitter_OAuthConsumer */
	private $token;



	/**
	 * Creates object using consumer and access keys.
	 * @param  string  consumer key
	 * @param  string  app secret
	 * @param  string  optional access token
	 * @param  string  optinal access token secret
	 * @throws TwitterException when allow_url_fopen is not enabled
	 */
	public function __construct($consumerKey, $consumerSecret, $accessToken = NULL, $accessTokenSecret = NULL)
	{
		if (!ini_get('allow_url_fopen')) {
			throw new TwitterException('PHP directive allow_url_fopen is not enabled.');
		}
		$this->signatureMethod = new Twitter_OAuthSignatureMethod_HMAC_SHA1();
		$this->consumer = new Twitter_OAuthConsumer($consumerKey, $consumerSecret);
		$this->token = new Twitter_OAuthConsumer($accessToken, $accessTokenSecret);
	}



	/**
	 * Tests if user credentials are valid.
	 * @return boolean
	 * @throws TwitterException
	 */
	public function authenticate()
	{
		try {
			$res = $this->request('account/verify_credentials', 'GET');
			return !empty($res->id);

		} catch (TwitterException $e) {
			if ($e->getCode() === 401) {
				return FALSE;
			}
			throw $e;
		}
	}



	/**
	 * Sends message to the Twitter.
	 * @param string   message encoded in UTF-8
	 * @return object
	 * @throws TwitterException
	 */
	public function send($message)
	{
		return $this->request('statuses/update', 'POST', array('status' => $message));
	}



	/**
	 * Returns the most recent statuses.
	 * @param  int    timeline (ME | ME_AND_FRIENDS | REPLIES) and optional (RETWEETS)
	 * @param  int    number of statuses to retrieve
	 * @param  int    page of results to retrieve
	 * @return mixed
	 * @throws TwitterException
	 */
	public function load($flags = self::ME, $count = 20, $page = 1)
	{
		static $timelines = array(self::ME => 'user_timeline', self::ME_AND_FRIENDS => 'home_timeline', self::REPLIES => 'mentions_timeline');
		if (!isset($timelines[$flags & 3])) {
			throw new InvalidArgumentException;
		}

		return $this->cachedRequest('statuses/' . $timelines[$flags & 3], array(
			'count' => $count,
			'page' => $page,
			'include_rts' => $flags & self::RETWEETS ? 1 : 0,
		));
	}



	/**
	 * Returns information of a given user.
	 * @param  string name
	 * @return mixed
	 * @throws TwitterException
	 */
	public function loadUserInfo($user)
	{
		return $this->cachedRequest('users/show', array('screen_name' => $user));
	}



	/**
	 * Destroys status.
	 * @param  int    id of status to be destroyed
	 * @return mixed
	 * @throws TwitterException
	 */
	public function destroy($id)
	{
		$res = $this->request("statuses/destroy/$id", 'GET');
		return $res->id ? $res->id : FALSE;
	}



	/**
	 * Returns tweets that match a specified query.
	 * @param  string|array   query
	 * @return mixed
	 * @throws TwitterException
	 */
	public function search($query)
	{
		return $this->request('search/tweets', 'GET', is_array($query) ? $query : array('q' => $query))->statuses;
	}



	/**
	 * Process HTTP request.
	 * @param  string  URL or twitter command
	 * @param  string  HTTP method GET or POST
	 * @param  array   data
	 * @return mixed
	 * @throws TwitterException
	 */
	public function request($resource, $method, array $data = NULL)
	{
		if (!strpos($resource, '://')) {
			if (!strpos($resource, '.')) {
				$resource .= '.json';
			}
			$resource = self::API_URL . $resource;
		}

		$request = Twitter_OAuthRequest::from_consumer_and_token($this->consumer, $this->token, $method, $resource, $data);
		$request->sign_request($this->signatureMethod, $this->consumer, $this->token);

		$options = array(
			'method' => $method,
			'timeout' => 20,
			'content' => $method === 'POST' ? $request->to_postdata() : NULL,
			'user_agent' => 'Twitter for PHP',
		);

		$f = @fopen($method === 'POST' ? $request->get_normalized_http_url() : $request->to_url(),
			'r', FALSE, stream_context_create(array('http' => $options)));
		if (!$f) {
			throw new TwitterException('Server error');
		}

		$result = stream_get_contents($f);
		$payload = version_compare(PHP_VERSION, '5.4.0') >= 0 ?
			@json_decode($result, FALSE, 128, JSON_BIGINT_AS_STRING) : @json_decode($result); // intentionally @

		if ($payload === FALSE) {
			throw new TwitterException('Invalid server response');
		}

		return $payload;
	}



	/**
	 * Cached HTTP request.
	 * @param  string  URL or twitter command
	 * @param  array
	 * @param  int
	 * @return mixed
	 */
	public function cachedRequest($resource, array $data = NULL, $cacheExpire = NULL)
	{
		if (!self::$cacheDir) {
			return $this->request($resource, 'GET', $data);
		}
		if ($cacheExpire === NULL) {
			$cacheExpire = self::$cacheExpire;
		}

		$cacheFile = self::$cacheDir . '/twitter.' . md5($resource . json_encode($data) . serialize(array($this->consumer, $this->token)));
		$cache = @json_decode(@file_get_contents($cacheFile)); // intentionally @
		if ($cache && @filemtime($cacheFile) + $cacheExpire > time()) { // intentionally @
			return $cache;
		}

		try {
			$payload = $this->request($resource, 'GET', $data);
			file_put_contents($cacheFile, json_encode($payload));
			return $payload;

		} catch (TwitterException $e) {
			if ($cache) {
				return $cache;
			}
			throw $e;
		}
	}



	/**
	 * Makes twitter links, @usernames and #hashtags clickable.
	 * @param  string
	 * @return string
	 */
	public static function clickable($s)
	{
		return preg_replace_callback(
			'~(?<!\w)(https?://\S+\w|www\.\S+\w|@\w+|#\w+)|[<>&]~u',
			array(__CLASS__, 'clickableCallback'),
			html_entity_decode($s, ENT_QUOTES, 'UTF-8')
		);
	}



	private static function clickableCallback($m)
	{
		$m = htmlspecialchars($m[0]);
		if ($m[0] === '#') {
			$m = substr($m, 1);
			return "<a href='http://twitter.com/search?q=%23$m'>#$m</a>";
		} elseif ($m[0] === '@') {
			$m = substr($m, 1);
			return "@<a href='http://twitter.com/$m'>$m</a>";
		} elseif ($m[0] === 'w') {
			return "<a href='http://$m'>$m</a>";
		} elseif ($m[0] === 'h') {
			return "<a href='$m'>$m</a>";
		} else {
			return $m;
		}
	}

}



/**
 * An exception generated by Twitter.
 */
class TwitterException extends Exception
{
}
