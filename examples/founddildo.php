<?php
require_once('founddildo.inc.php');
require_once('retweetbot.php');

$oTwitterBot = new RetweetBot(array(
	'sUsername'			=> 'FoundDildo',
	'sSettingsFile'		=> 'founddildo.json',
	'sLastSearchFile'	=> 'founddildo-last%d.json',
	'aSearchStrings'	=> array(
		1 => 'found dildo -RT -retweet -retweeted -"people demand rubber dicks" -"ask.fm" -tumblr -tmblr',
		2 => 'found vibrator -RT -retweet -retweeted -"ask.fm" -tumblr -tmblr -"cat hissing"',
        3 => 'found buttplug -"found out" -"@buttplug" -"ask.fm"',
        4 => 'found fleshlight -flashlight -cheese -"found out" -factory',
	),
));
$oTwitterBot->run();
