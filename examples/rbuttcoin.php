<?php
require_once('rbuttcoin.inc.php');
require_once('rssbot.php');

$oTwitterBot = new RssBot(array(
    'sUsername'     => 'r_Buttcoin',
    'sUrl'          => 'http://www.reddit.com/r/Buttcoin/.json?sort=new',
	'sFeedFormat'	=> 'json',
    'sLastRunFile'  => 'rbuttcoin-last.json',
    'sTweetFormat'  => ':title (by :author) [:type] :link',
    'aTweetVars'    => array(
        array('sVar' => ':title', 'sValue' => 'title', 'bTruncate' => TRUE),
        //array('sVar' => ':author', 'sValue' => 'description', 'sRegex' => '/submitted by <a href="\S+?"> ?(\S+) ?<\/a>/i', 'sDefault' => '[deleted]'),
        array('sVar' => ':author', 'sValue' => 'author', 'sDefault' => '[deleted]'),
        array('sVar' => ':link', 'sValue' => 'permalink', 'sPrefix' => 'http://www.reddit.com'),
        array('sVar' => ':type', 'sValue' => 'special:redditmediatype', 'sSubject' => ':source'),
        //array('sVar' => ':source', 'sValue' => 'description', 'sRegex' => '/<a href="([^"]+)">\[link\]<\/a>/', 'bAttachImage' => TRUE),
        array('sVar' => ':source', 'sValue' => 'url', 'bAttachImage' => TRUE),
        //array('sVar' => ':commentcount', 'sValue' => 'description', 'sRegex' => '/<a href="[^"]+">\[(\d+ comments)\]<\/a>/', 'sDefault' => '0 comments'),
    ),
    'sTimestampField' => 'created',
));
$oTwitterBot->run();
?>
