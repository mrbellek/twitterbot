<?php
require_once('autoload.php');
require_once('factsdumps.inc.php');

use Twitterbot\Lib\Logger;
use Twitterbot\Lib\Config;
use Twitterbot\Lib\Auth;
use Twitterbot\Lib\Media;
use Twitterbot\Lib\Tweet;
use Twitterbot\Custom\Imgur;

(new FactsDumps)->run();

class FactsDumps
{
    public function __construct()
    {
        $this->sUsername = 'FactsDumps';
        $this->logger = new Logger;
    }

    public function run()
    {
        $oConfig = new Config;
        if ($oConfig->load($this->sUsername)) {

            if ((new Auth($oConfig))->isUserAuthed($this->sUsername)) {

                $this->aFactsDumps = $oConfig->get('dumps');

                $this->oImgur = new Imgur;

                $iAlbumKey = array_rand($this->aFactsDumps);
                $sImageUrl = $this->getRandomImageFromAlbum($this->aFactsDumps[$iAlbumKey]);
                $this->logger->output('- Picked image to upload: %s', $sImageUrl);
                $sMediaId = (new Media($oConfig))
                    ->upload($sImageUrl);

                if ($sMediaId) {
                    $this->logger->output('- Image uploaded ok.');
                    $oTweet = new Tweet($oConfig);
                    $oTweet->set('aMediaIds', [$sMediaId]);
                    if ($oTweet->post('')) {
                        $this->logger->output('Image posted!');
                    }
                } else {
                    $this->logger->output('- Image upload FAILED.');
                }
            }
        }
        $this->logger->output('Done!');
    }

    private function getRandomImageFromAlbum($sUrl)
    {
        $aImages = $this->oImgur->getAllAlbumImages($sUrl);

        return $aImages[array_rand($aImages)];
    }
}
