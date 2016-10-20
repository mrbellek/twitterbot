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

            if ((new Ratelimit($oConfig))->check()) {

                if ((new Auth)->isUserAuthed($this->sUsername)) {

                    $aRecord = (new Database($oConfig))
                        ->getRecord();

                    if ($aRecord) {
                        $sTweet = (new Format($oConfig))
                            ->format($aRecord);

                        if ($sTweet) {
                            (new Tweet($oConfig))
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
