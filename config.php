<?php
require_once('twitteroauth.php');

define('CONSUMER_KEY', 'insert_your_consumer_key_here');
define('CONSUMER_SECRET', 'insert_your_consumer_secret_here');
define('ACCESS_TOKEN', 'insert_your_access_token_here');
define('ACCESS_TOKEN_SECRET', 'insert_your_access_token_secret_here');

$twitter = new TwitterOAuth(CONSUMER_KEY, CONSUMER_SECRET, ACCESS_TOKEN, ACCESS_TOKEN_SECRET);
$twitter->host = "https://api.twitter.com/1.1/";
$search = $twitter->get('search', array('q' => 'search key word', 'rpp' => 15));

$twitter->host = "https://api.twitter.com/1.1/";
foreach($search->results as $tweet) {
	$status = 'RT @'.$tweet->from_user.' '.$tweet->text;
	if(strlen($status) > 140) $status = substr($status, 0, 139);
	$twitter->post('statuses/update', array('status' => $status));
}

echo "Success! Check your twitter bot for retweets!";
