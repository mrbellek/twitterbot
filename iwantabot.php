<?php
require_once('iwantabot.inc.php');
require_once('retweetbot.php');

$oTwitterBot = new RetweetBot(array(
   'sUsername'         => 'IWantABot',
   'sSettingsFile'      => 'iwantabot.json',
   'sLastSearchFile'   => 'iwantabot-last%d.json',
   'aSearchStrings'   => array(
      1 => '"i want a bot"',
      2 => '"i want a twitter bot"',
      3 => '"i need a * bot"',
      4 => '"i need a twitter bot"',
      5 => '"make a * bot"',
   ),
));
$oTwitterBot->run();
