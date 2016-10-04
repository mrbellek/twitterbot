<?php
require_once('autoload.php');
require_once('r_buttcoin.inc.php');

/**
 * TODO:
 * - check rate limit?
 * - mark which post was last tweeted
 */

use Twitterbot\Lib\Config;
use Twitterbot\Lib\Auth;
use Twitterbot\Lib\Ratelimit;
use Twitterbot\Lib\Rss;
use Twitterbot\Lib\Format;
use Twitterbot\Lib\Media;
use Twitterbot\Lib\Tweet;

class rButtcoin
{
    public function __construct()
    {
        $this->sUsername = 'r_Buttcoin';
    }

    public function run()
    {
        $oConfig = new Config;
        if ($oConfig->load($this->sUsername)) {

            if ((new Auth)->isUserAuthed($this->sUsername)) {

                $aRssFeed = (new Rss)
                    ->set('oConfig', $oConfig)
                    ->getFeed();

                if ($aRssFeed) {
                    foreach ($aRssFeed as $oRssItem) {

                        $oFormat = new Format;
                        $sTweet = $oFormat
                            ->set('oConfig', $oConfig)
                            ->format($oRssItem);

                        $sMediaId = array();
                        if ($aAttachment = $oFormat->getAttachment()) {
                            $sMediaId = (new Media)->uploadFromUrl($aAttachment['url'], $aAttachment['type']);
                        }

                        if ($sTweet) {
                            (new Tweet)
                                ->set('oConfig', $oConfig)
                                ->setMedia($sMediaId)
                                ->post($sTweet);
                        }
                    }

                    $this->logger->output('done!');
                }
            }
        }
    }
}

(new rButtcoin)->run();
