<?php
require_once('twitteroauth.php');
require_once('config.inc.php');

$twitter = new TwitterOAuth(CONSUMER_KEY, CONSUMER_SECRET, ACCESS_TOKEN, ACCESS_TOKEN_SECRET);
$twitter->host = "https://api.twitter.com/1.1/";
$search = $twitter->get('search/tweets', array(
	'q' => 'found dildo -RT -retweet -retweeted -tumblr -tmblr',
	'count' => 15,
));

foreach($search->statuses as $tweet) {
	die(var_dumP('<pre>',$tweet));
	echo $tweet->text . "<br>\n";
	$twitter->get('statuses/retweet/' . $tweet->id_str);
}

echo "<br>Done!";
