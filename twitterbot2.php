<?php
require_once('config.inc.php');
require_once('bot.php');

$oTwitterBot = new TwitterBot;
$oTwitterBot->init(array(
	'sUsername'			=> 'FoundDildo',
	'sSettingsFile'		=> 'settings2.json',
	'sLastSearchFile'	=> 'lastsearch2.json',
	'sSearchString'		=> 'found vibrator -RT -retweet -retweeted -"ask.fm" -tumblr -tmblr -"cat hissing"',
));
$oTwitterBot->run();
