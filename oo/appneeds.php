<?php
require_once('autoload.php');
require_once('appneeds.inc.php');

use Twitterbot\Core\RetweetBot;

class AppNeeds extends RetweetBot
{
    public function __construct()
    {
        $this->sUsername = 'AppNeeds';
        parent::__construct();
    }
}

(new AppNeeds)->run();
