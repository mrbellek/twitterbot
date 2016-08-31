<?php
require_once('autoload.php');
require_once('iwantabot.inc.php');

use Twitterbot\Core\RetweetBot;

class IWantABot extends RetweetBot
{
    public function __construct()
    {
        $this->sUsername = 'IWantABot';

        parent::__construct();
    }
}

(new IWantABot)->run();
