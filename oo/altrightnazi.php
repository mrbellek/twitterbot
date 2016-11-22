<?php
namespace Twitterbot\Core;

require_once('autoload.php');
require_once('altrightnazi.inc.php');

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
class AltRightNazi
{
    public function __construct()
    {
        $this->sUsername = 'AltRightNazi';
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
                        $oRetweet = (new Retweet($oConfig));
                        foreach ($aTweets as $oTweet) {
                            $oRetweet->quote($oTweet, $this->getComment());
                        }
                    }

                    $this->logger->output('done!');
                }
            }
        }
    }

    private function getComment()
    {
        $aComments = [
            'They\'re just nazis.',
            'They\'re nazis.',
            'It\'s ok to say it: they\'re nazis.',
            'They\'re not alt-right, they\'re nazis.',
            'They do the nazi salute. They\'re nazis.',
            'I think the right term for these people is \'nazis\'.',
            'Actually they\'re just nazis.',
            'It\'s not just a racist movement, it\'s straight nazis.',
            'They\'re not just racists, they\'re straight nazis.',
            'You know what? They\'re just nazis.',
            'These guys aren\'t moderate, they\'re nazis.',
            'This is not a joke: they\'re nazis.',
            'Or as they\'re usually called: nazis.',
            'I think you mean nazis.',
        ];

        return $aComments[array_rand($aComments)];
    }
}

(new AltRightNazi)->run();
