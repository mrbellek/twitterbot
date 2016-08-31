<?php
require_once('autoload.php');
require_once('chunkybot.inc.php');

use Twitterbot\Core\RetweetBot;

class ChunkyBot extends RetweetBot
{
    public function __construct()
    {
        $this->sUsername = 'ChunkyBot';
        parent::__construct();
    }
}

(new ChunkyBot)->run();
