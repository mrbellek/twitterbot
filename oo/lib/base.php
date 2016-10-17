<?php
namespace Twitterbot\Lib;
use Twitterbot\Lib\Logger;
use \TwitterOAuth;

/**
 * Base lib class - creates twitter API object and logger, basic setter
 */
class Base
{
    public function __construct()
    {
        $this->oTwitter = new TwitterOAuth(CONSUMER_KEY, CONSUMER_SECRET, ACCESS_TOKEN, ACCESS_TOKEN_SECRET);
        $this->oTwitter->host = 'https://api.twitter.com/1.1/';

        $this->logger = new Logger;
    }

    public function set($sName, $mValue)
    {
        $this->$sName = $mValue;

        return $this;
    }
}
