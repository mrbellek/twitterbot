<?php
/*
 * TODO:
 * - BUG: tweets are sometimes too long (check logfile and last_posted timestamp)
 */
require_once('notesfrombtc.inc.php');
require_once('tweetbot.php');

$oTwitterBot = new TweetBot(array(
	'sUsername'		=> 'NotesFromBTC',
	'aDbVars'		=> array(
		'sTable'		=> 'notes',
		'sIdCol'		=> 'id',
		'sCounterCol'	=> 'postcount',
		'sTimestampCol'	=> 'lasttimestamp',
	),
	'sTweetFormat'	=> ':note http://blockchain.info:tx #bitcoin',
	'aTweetVars'	=> array(
		array('sVar' => ':note', 'sRecordField' => 'note', 'bTruncate' => TRUE),
		array('sVar' => ':tx', 'sRecordField' => 'tx'),
	),
));
$oTwitterBot->run();
?>
