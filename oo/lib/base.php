<?php
namespace Twitterbot\Lib;
use Twitterbot\Lib\Logger;
use \TwitterOAuth;

class Base
{
    public function __construct()
    {
        $this->oTwitter = new TwitterOAuth(CONSUMER_KEY, CONSUMER_SECRET, ACCESS_TOKEN, ACCESS_TOKEN_SECRET);
        $this->oTwitter->host = 'https://api.twitter.com/1.1/';

        $this->logger = new Logger;
    }
}
