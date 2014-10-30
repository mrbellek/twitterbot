<?php
require_once('../markovbot.php');
require_once('./stallmantxt.inc.php');

$o = new MarkovBot(array(
    'sUsername' => 'stallman_txt',
    'sInputFile' => 'stallman.txt',
));
$o->run();
