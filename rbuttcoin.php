<?php
require_once('rbuttcoin.inc.php');
require_once('rssbot.php');

$oTwitterBot = new RssBot(array(
    'sUsername'     => 'r_Buttcoin',
    'sUrl'          => 'http://www.reddit.com/r/Buttcoin/.rss',
    'sLastRunFile'  => 'rbuttcoin-last.json',
    'sTweetFormat'  => ':title (:commentcount) :link',
    'aTweetVars'    => array(
        array('sVar' => ':title', 'sValue' => 'title', 'bTruncate' => TRUE),
        array('sVar' => ':commentcount', 'sValue' => 'description', 'sRegex' => '/<a href="[^"]+">\[(\d+ comments)\]<\/a>/', 'sDefault' => '0 comments'),
        array('sVar' => ':link', 'sValue' => 'link'),
    ),
    'sTimestampXml' => 'pubDate',
));
$oTwitterBot->run();
?>
