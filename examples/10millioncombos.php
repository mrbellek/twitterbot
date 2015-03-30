<?php
require_once('10millioncombos.inc.php');
require_once('tweetbot.php');

$oTwitterBot = new TweetBot(array(
    'sUsername'     => '10millioncombos',
    'aDbVars'       => array(
        'sTable'        => '10millionpasswords',
        'sIdCol'        => 'id',
        'sCounterCol'   => 'postcount',
        'sTimestampCol' => 'lasttimestamp',
    ),
    'sTweetFormat'  => ':username : :password',
    'aTweetVars'    => array(
        array('sVar' => ':username', 'sRecordField' => 'username'),
        array('sVar' => ':password', 'sRecordField' => 'password'),
    ),
    'bPostOnlyOnce' => TRUE,
	'bReplyToCmds'	=> FALSE,
));
$oTwitterBot->run();
?>
