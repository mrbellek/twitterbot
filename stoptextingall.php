<?php
require_once('stoptextingall.inc.php');
require_once('tweetbot.php');

$oTwitterBot = new TweetBot(array(
    'sUsername'     => 'StopTextingAll',
    'aDbVars'       => array(
        'sTable'        => 'stoptexting',
        'sIdCol'        => 'id',
        'sCounterCol'   => 'postcount',
        'sTimestampCol' => 'lasttimestamp',
    ),
    'sTweetFormat'  => ':tweet',
    'bPostOnlyOnce' => TRUE,
    'aTweetVars'    => array(
        array('sVar' => ':tweet', 'sRecordField' => 'tweet'),
    ),
));
$oTwitterBot->run();
?>
