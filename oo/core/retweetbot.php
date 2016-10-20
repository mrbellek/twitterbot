<?php
namespace Twitterbot\Core;

use Twitterbot\Lib\Logger;
use Twitterbot\Lib\Config;
use Twitterbot\Lib\Auth;
use Twitterbot\Lib\Ratelimit;
use Twitterbot\Lib\Search;
use Twitterbot\Lib\Block;
use Twitterbot\Lib\Filter;
use Twitterbot\Lib\Retweet;

/**
 * Retweetbot class - generic framework to find and retweet posts based on given search terms
 *
 * @param config:min_rate_limit
 * @param config:search_strings
 */
class RetweetBot
{
    public function __construct()
    {
        $this->logger = new Logger;
    }

    public function run()
    {
        if (empty($this->sUsername)) {
            $this->logger->output('Username not set! Halting.');
            exit;
        }

        //load config from username.json file
        $oConfig = new Config();
        if ($oConfig->load($this->sUsername)) {

            //check rate limit before anything else
            if ((new Ratelimit($oConfig))->check()) {

                //check correct username
                if ((new Auth($oConfig))->isUserAuthed($this->sUsername)) {

                    //search for new tweets
                    $aTweets = (new Search($oConfig))
                        ->search();

                    //filter out tweets from blocked accounts
                    $aTweets = (new Block($oConfig))->filterBlocked($aTweets);

                    //filter out unwanted tweets/users
                    $aTweets = (new Filter($oConfig))
                        ->setFilters()
                        ->filter($aTweets);

                    if ($aTweets) {
                        //retweet remaining tweets
                        (new Retweet($oConfig))->post($aTweets);
                    }

                    $this->logger->output('done!');
                }
            }
        }
    }
}
