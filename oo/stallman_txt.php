<?php
require_once('autoload.php');
require_once('stallman_txt.inc.php');

use Twitterbot\Lib\Logger;
use Twitterbot\Lib\Config;
use Twitterbot\Lib\Auth;
use Twitterbot\Lib\Ratelimit;
use Twitterbot\Lib\Database;
use Twitterbot\Lib\Format;
use Twitterbot\Lib\Tweet;

(new StallmanTxt)->run();

class StallmanTxt {

    public function __construct()
    {
        $this->sUsername = 'stallman_txt';
        $this->logger = new Logger;
    }

    public function run()
    {
        $oConfig = new Config;
        if ($oConfig->load($this->sUsername)) {

            $this->db = (new Database($oConfig));

            if ((new Ratelimit($oConfig))->check()) {

                if ((new Auth($oConfig))->isUserAuthed($this->sUsername)) {

                    $aRecord = $this->db->getRecord();

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
