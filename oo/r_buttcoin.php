<?php
require_once('autoload.php');
require_once('r_buttcoin.inc.php');

use Twitterbot\Lib\Logger;
use Twitterbot\Lib\Config;
use Twitterbot\Lib\Auth;
use Twitterbot\Lib\Ratelimit;
use Twitterbot\Lib\Rss;
use Twitterbot\Lib\Format;
use Twitterbot\Lib\Media;
use Twitterbot\Lib\Tweet;

(new rButtcoin)->run();

class rButtcoin
{
    public function __construct()
    {
        $this->sUsername = 'r_Buttcoin';
        $this->logger = new Logger;
    }

    public function run()
    {
        $oConfig = new Config;
        if ($oConfig->load($this->sUsername)) {

            if ((new Auth($oConfig))->isUserAuthed($this->sUsername)) {

                if ($aRssFeed = (new Rss($oConfig))->getFeed()) {

                    $oFormat = new Format($oConfig);

                    foreach ($aRssFeed as $oRssItem) {

                        $sTweet = $oFormat->format($oRssItem);

                        $aMediaIds = [];
                        if ($aAttachment = $oFormat->getAttachment()) {
                            $aMediaIds = (new Media($oConfig))->uploadFromUrl($aAttachment['url'], $aAttachment['type']);
                        }

                        if ($sTweet) {
                            (new Tweet($oConfig))
                                ->setMedia($aMediaIds)
                                ->post($sTweet);
                        }
                    }

                    $this->logger->output('done!');
                }
            }
        }
    }
}
