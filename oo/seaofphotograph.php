<?php
require_once('autoload.php');
require_once('seaofphotograph.inc.php');

use Twitterbot\Lib\Logger;
use Twitterbot\Lib\Config;
use Twitterbot\Lib\Auth;
use Twitterbot\Lib\Ratelimit;
use Twitterbot\Lib\Rss;
use Twitterbot\Lib\Format;
use Twitterbot\Lib\Media;
use Twitterbot\Lib\Tweet;
use Twitterbot\Lib\Filter;

(new rButtcoin)->run();

class rButtcoin
{
    public function __construct()
    {
        $this->sUsername = 'SeaOfPhotograph';
        $this->logger = new Logger;
    }

    public function run()
    {
        $oConfig = new Config;
        if ($oConfig->load($this->sUsername)) {

            if ((new Auth($oConfig))->isUserAuthed($this->sUsername)) {

                if ($aRssFeed = (new Rss($oConfig))->getFeed()) {

                    $oFormat = new Format($oConfig);
                    $oFilter = new Filter($oConfig);
                    $oFilter->setFilters();

                    foreach ($aRssFeed as $oRssItem) {

                        $sTweet = $oFormat->format($oRssItem);
                        if (!$oFilter->filter([$sTweet])) {
                            continue;
                        }

                        $aMediaIds = [];
                        if ($aAttachment = $oFormat->getAttachment()) {
                            $aMediaIds = (new Media($oConfig))->uploadFromUrl($aAttachment['url'], $aAttachment['type']);
                        }

                        if ($sTweet) {
                            $oTweet = (new Tweet($oConfig));
                            if ($aMediaIds) {
                                $oTweet->setMedia($aMediaIds);
                            }
                            $oTweet->post($sTweet);
                        }
                    }

                    $this->logger->output('Done!');
                }
            }
        }
    }
}
