<?php
require_once('autoload.php');
require_once('r_buttcoin.inc.php');

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

            //TODO: does this check the proper limits for a tweetbot?
            if ((new Ratelimit)->check($oConfig->get('min_rate_limit'))) {

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

                            if ($sAttachFile = $oFormat->getAttachFile()) {
                                $aMediaIds = (new Media)->upload($sAttachFile);
                            }
                            die(var_dump($sTweet, $aMediaIds));

                            if ($sTweet) {
                                (new Tweet)
                                    ->set('oConfig', $oConfig)
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
}

(new rButtcoin)->run();
