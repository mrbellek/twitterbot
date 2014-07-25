<?php
require_once('config2.inc.php');
require_once('bot.php');

$oTwitterBot = new TwitterBot;
$oTwitterBot->init(array(
	'sUsername'			=> 'ChunkyBot',
	'sSettingsFile'		=> 'settings3.json',
	'sLastSearchFile'	=> 'lastsearch3.json',
	'sSearchString'		=> '"chunky monkey" ice -RT -retweet -retweeted -vegan',
));
$oTwitterBot->run();
