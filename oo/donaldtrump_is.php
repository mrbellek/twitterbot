<?php
require_once('autoload.php');
require_once('donaldtrump_is.inc.php');

use Twitterbot\Core\RetweetBot;

class DonaldTrumpIs extends RetweetBot
{
    public function __construct()
    {
        $this->sUsername = 'DonaldTrump_Is';
        parent::__construct();
    }
}

(new DonaldTrumpIs)->run();
