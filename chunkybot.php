<?php
require_once('chunkybot.inc.php');
require_once('bot.php');

$oTwitterBot = new TwitterBot(array(
	'sUsername'			=> 'ChunkyBot',
	'sSettingsFile'		=> 'chunkybot.json',
	'sLastSearchFile'	=> 'chunkybot-last%d.json',
	'aSearchStrings'	=> '"chunky monkey" ice -RT -retweet -retweeted -vegan',
));
$oTwitterBot->run();
