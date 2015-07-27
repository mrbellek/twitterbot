<?php
require_once('iwantabot.inc.php');
require_once('retweetbot.php');

$oTwitterBot = new RetweetBot(array(
   'sUsername'         => 'IWantABot',
   'sSettingsFile'      => 'iwantabot.json',
   'sLastSearchFile'   => 'iwantabot-last%d.json',
   'aSearchStrings'   => array(
      1 => '"want a twitter bot"',
      2 => '"need a twitter bot"',
      3 => '"want a * bot"',
      4 => '"need a * bot"',
      5 => '"make a * bot"',
	  6 => '"made a * bot"',
   ),
));
$oTwitterBot->run();
