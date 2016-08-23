<?php
require_once('autoload.php');
require_once('founddildo.inc.php');

use Twitterbot\Lib\Config;
use Twitterbot\Lib\Auth;
use Twitterbot\Lib\Ratelimit;
use Twitterbot\Lib\Search;
use Twitterbot\Lib\Block;
use Twitterbot\Lib\Filter;
use Twitterbot\Lib\Retweet;

class FoundDildo
{
    public function __construct()
    {
        $this->sUsername = 'FoundDildo';
    }

    public function run()
    {
        //load config from username.json file
        $oConfig = new Config;
        if ($oConfig->load($this->sUsername)) {

            //check rate limit before anything else
            if ((new Ratelimit)->check($oConfig->get('min_rate_limit'))) {

                //check correct username
                if ((new Auth)->isUserAuthed($this->sUsername)) {

                    //search using query strings
                    $aTweets = (new Search)
                        ->set('oConfig', $oConfig)
                        ->search($oConfig->get('search_strings'));

                    //filter out blocked users
                    $aTweets = (new Block)->filterBlocked($aTweets);

                    //filter out unwanted tweets/users
                    $aTweets = (new Filter)
                        ->set('oConfig', $oConfig)
                        ->setFilters()
                        ->filter($aTweets);

                    if ($aTweets) {
                        //retweet remaining tweets
                        if ((new Retweet)->post($aTweets)) {
                            $this->logger->output('done!');
                        }
                    }
                }
            }
        }
    }
}

(new FoundDildo)->run();
