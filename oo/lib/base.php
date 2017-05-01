<?php
namespace Twitterbot\Lib;

require_once(MYPATH . '/twitteroauth/autoload.php');
require_once('base.inc.php');

use Twitterbot\Lib\Logger;
use Abraham\TwitterOAuth\TwitterOAuth;

/**
 * Base lib class - creates twitter API object and logger, basic setter
 */
class Base
{
    public function __construct($oConfig)
    {
        $this->oConfig = $oConfig;

        $this->oTwitter = new TwitterOAuth(CONSUMER_KEY, CONSUMER_SECRET, ACCESS_TOKEN, ACCESS_TOKEN_SECRET);

        $this->logger = new Logger;
    }

    public function set($sName, $mValue)
    {
        $this->$sName = $mValue;

        return $this;
    }
}
