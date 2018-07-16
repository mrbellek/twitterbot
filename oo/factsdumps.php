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
    private $sFactsDumpAlbum = 'https://imgur.com/user/mrbellek/favorites/folder/4230993/facts-dumps';

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

                $this->oImgur = new Imgur;
                $this->logger->output('Finding favorites albums with facts on user account..');
                $aFactsDumps = $this->oImgur->getFavoritesAlbums('mrbellek');
                if (!$aFactsDumps) {
                    $aFactsDumps = $oConfig->get('dumps');
                    $this->logger->output('- No facts dumps found in favorites, using %s from settings.', count($aFactsDumps));
                } else {
                    $this->logger->output('- Found %d facts dumps albums in favorites.', count($aFactsDumps));
                }

                $iAlbumKey = array_rand($aFactsDumps);
                $sImageUrl = $this->getRandomImageFromAlbum($aFactsDumps[$iAlbumKey]);
                if (!$sImageUrl) {
                    $this->logger->output('- Failed to pick image from album.');
                    return false;
                }
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
        if (!$aImages) {
            $this->logger->output('Failed to get images in album %s!', $sUrl);

            return false;
        }

        return $aImages[array_rand($aImages)];
    }
}
