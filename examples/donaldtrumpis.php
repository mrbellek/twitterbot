<?php
require_once('donaldtrumpis.inc.php');
require_once('retweetbot.php');

$oTwitterBot = new RetweetBot(array(
   'sUsername'         => 'DonaldTrump_Is',
   'sSettingsFile'      => 'donaldtrumpis.json',
   'sLastSearchFile'   => 'donaldtrumpis-last%d.json',
   'aSearchStrings'   => array(
      1 => '"donald trump is" -RT -retweet -retweeted -filter:links',
   ),
));
$oTwitterBot->run();

