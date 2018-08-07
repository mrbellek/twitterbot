<?php
require_once('autoload.php');
require_once('imdbtriviabot.inc.php');

use Twitterbot\Lib\Logger;
use Twitterbot\Lib\Config;
use Twitterbot\Lib\Auth;
use Twitterbot\Lib\Database;
use Twitterbot\Lib\Format;
use Twitterbot\Lib\Tweet;

class IMDbTriviaBot
{
    public function __construct()
    {
        $this->sUsername = 'IMDbTriviaBot';
        $this->logger = new Logger;
    }

    public function run()
    {
        $oConfig = new Config;
        if ($oConfig->load($this->sUsername)) {

            if (new Auth($oConfig)) {
                $aRecord = (new Database($oConfig))
                    ->getRecord();

                if ($aRecord) {

                    //hardcoded var since it's a tinyint field
                    $aRecord['spoiler'] = $aRecord['spoiler'] ? '[SPOILER] ' : '';

                    $sTweet = (new Format($oConfig))
                        ->format($aRecord);

                    if ($sTweet) {
                        (new Tweet($oConfig))
                            ->post($sTweet);

                        $this->logger->output('Done!');
                    }
                }
            }
        }
    }
}

(new IMDbTriviaBot)->run();
