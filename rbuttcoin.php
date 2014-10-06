<?php
require_once('rbuttcoin.inc.php');
require_once('rssbot.php');

$oTwitterBot = new RssBot(array(
    'sUsername'     => 'r_Buttcoin',
    'sUrl'          => 'http://www.reddit.com/r/Buttcoin/.rss',
    'sLastRunFile'  => 'rbuttcoin-last.json',
    'sTweetFormat'  => ':title :link',
    'aTweetVars'    => array(
        array('sVar' => ':title', 'sValue' => 'title', 'bTruncate' => TRUE),
        array('sVar' => ':link', 'sValue' => 'link'),
        array('sVar' => ':source', 'sValue' => 'description', 'sRegex' => '/<a href="([^"]+)">\[link\]<\/a>/'),
        array('sVar' => ':type', 'sValue' => 'special:redditmediatype', 'sSubject' => ':source'),
        //array('sVar' => ':commentcount', 'sValue' => 'description', 'sRegex' => '/<a href="[^"]+">\[(\d+ comments)\]<\/a>/', 'sDefault' => '0 comments'),
    ),
    'sTimestampXml' => 'pubDate',
));
$oTwitterBot->run();
?>
