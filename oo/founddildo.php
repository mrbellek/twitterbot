<?php
require_once('autoload.php');
require_once('founddildo.inc.php');

use Twitterbot\Core\RetweetBot;

class FoundDildo extends RetweetBot
{
    public function __construct()
    {
        $this->sUsername = 'FoundDildo';
        parent::__construct();
    }
}

(new FoundDildo)->run();
