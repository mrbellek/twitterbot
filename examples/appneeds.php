<?php
require_once('appneeds.inc.php');
require_once('retweetbot.php');

$oTwitterBot = new RetweetBot(array(
   'sUsername'         => 'AppNeeds',
   'sSettingsFile'      => 'appneeds.json',
   'sLastSearchFile'   => 'appneeds-last%d.json',
   'aSearchStrings'   => array(
      1 => '"i want an app" -filter:links -RT',
      2 => '"i need an app" -filter:links -RT',
   ),
));
$oTwitterBot->run();
