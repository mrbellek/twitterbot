<?php
require_once('autoload.php');
require_once('mbibleverses.inc.php');

use Twitterbot\Lib\Config;
use Twitterbot\Lib\Auth;
use Twitterbot\Lib\Ratelimit;
use Twitterbot\Lib\Database;
use Twitterbot\Lib\Format;
use Twitterbot\Lib\Tweet;

class mBibleVerses {

    public function __construct()
    {
        $this->sUsername = 'mBibleVerses';
    }

    public function run()
    {
        $oConfig = new Config;
        if ($oConfig->load($this->sUsername)) {

            if ((new Ratelimit)->check($oConfig->get('min_rate_limit'))) {

                if ((new Auth)->isUserAuthed($this->sUsername)) {

                    $aRecord = (new Database)
                        ->set('oConfig', $oConfig)
                        ->getRecord();

                    if ($aRecord) {
                        $sTweet = (new Format)
                            ->set('oConfig', $oConfig)
                            ->format($aRecord);

                        if ($sTweet) {
                            (new Tweet)
                                ->set('oConfig', $oConfig)
                                ->post($sTweet);

                            $this->logger->output('done!');
                        }
                    }
                }
            }
        }
    }
}

(new mBibleVerses)->run();
