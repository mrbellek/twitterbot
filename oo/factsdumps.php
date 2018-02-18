<?php
require_once('autoload.php');
require_once('factsbot.inc.php');

use Twitterbot\Lib\Logger;
use Twitterbot\Lib\Config;
use Twitterbot\Lib\Auth;
use Twitterbot\Lib\Tweet;
use Twitterbot\Lib\Media;
use Twitterbot\Lib\Tweet;
use Twitterbot\Custom\Imgur;

(new FactsBot)->run();

class FactsBot
{
    private $aFactsDumps = [
        'https://imgur.com/gallery/bEDYg',
        'https://imgur.com/gallery/Ht41C',
        'https://imgur.com/gallery/Vm4t4',
        'https://imgur.com/gallery/Ue3BT',
        'https://imgur.com/gallery/a5KAB',
        'https://imgur.com/gallery/cTbro',
        'https://imgur.com/gallery/6PHE5',
        'https://imgur.com/gallery/HXBDE',
        'https://imgur.com/gallery/wdu4ogp',
        'https://imgur.com/gallery/lL4kt',
        'https://imgur.com/gallery/OSIr0',
    ];

    public function __construct()
    {
        $this->sUsername = 'FactsBot';
        $this->logger = new Logger;
    }

    public function run()
    {
        $this->oConfig = new Config;
        if ($this->oConfig->load($this->sUsername)) {

            if ((new Auth($this->oConfig))->isUserAuthed($this->sUsername)) {

                $iAlbumKey = array_rand($this->aFactsDumps);
                $sImageUrl = $this->getRandomImageFromAlbum($this->aFactsDumps[$iAlbumKey]);
                $this->logger->output('- Picked image to upload %s', $sImageUrl);
                $lMediaId = $this->uploadImage($sImageUrl);

                if ($lMediaId) {
                    $this->logger->output('- Image uploaded ok.');
                    $oTweet = new Tweet($this->oConfig);
                    $oTweet->setMediaId($lMediaId);
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
}
