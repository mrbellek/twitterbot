<?php
require_once('twitteroauth.php');
require_once('config.inc.php');

$twitter = new TwitterOAuth(CONSUMER_KEY, CONSUMER_SECRET, ACCESS_TOKEN, ACCESS_TOKEN_SECRET);
$twitter->host = "https://api.twitter.com/1.1/";
$search = $twitter->get('search/tweets', array(
	'q' => 'found dildo -RT -retweet -retweeted -tumblr -tmblr',
	'count' => 15,
));

/*
 * TODO:
 * - extra zoektermen die bij RoundTeam in de config zaten ("@, [office quote]@, @ebay, ask.fm, people demand rubber dicks)
 * - links expanden en filteren op zoektermen
 * - username/description/bio filteren op zoektermen
 * - in config.json opslaan vanaf welke id laatst gezocht is, zodat niet dingen 2x geretweet worden
 * - cronjob mss aanpassen zodat deze 1x of 2x per uur draait
 * - ratelimit ophalen? GET application/rate_limit_status
 */

foreach($search->statuses as $tweet) {
	die(var_dumP('<pre>',$tweet));
	echo $tweet->text . "<br>\n";
	$twitter->get('statuses/retweet/' . $tweet->id_str);
}

echo "<br>Done!";
