<?php
require_once('config.inc.php');
require_once('bot.php');

$oTwitterBot = new TwitterBot;
$oTwitterBot->init(array(
	'sUsername'			=> 'FoundDildo',
	'sSettingsFile'		=> 'settings.json',
	'sLastSearchFile'	=> 'lastsearch.json',
	'sSearchString'		=> 'found dildo -RT -retweet -retweeted -"people demand rubber dicks" -"ask.fm" -tumblr -tmblr',
));
$oTwitterBot->run();
