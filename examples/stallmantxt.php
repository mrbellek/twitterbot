<?php
require_once('markovbot.php');
require_once('stallmantxt.inc.php');

$o = new MarkovBot(array(
    'sUsername'     => 'stallman_txt',
    'sInputType'    => 'database',
    'aDbSettings'   => array(
        'sTable'        => 'stallman_txt',
        'sIdCol'        => 'id',
        'sCounterCol'   => 'postcount',
        'sTimestampCol' => 'lasttimestamp',
    ),
    'sTweetFormat'  => ':tweet',
    'aTweetVars'    => array(
        array('sVar' => ':tweet', 'sRecordField' => 'tweet', 'bTruncate' => TRUE)
    ),
));
$o->run();
