<?php
/**
 * How to Make Your Own Album Cover
 * 1 Go to wikipedia. Hit 'random'
 * or click http://en.wikipedia.org/wiki/Special:Random
 * The first random wikipedia article you get is the name of your band.
 * 2 Go to 'Random quotations'
 * or click http://www.quotationspage.com/random.php3
 * The last four or five words of the very last quote of the page is the title of your first album.
 * 3 Go to flickr and click on 'explore the last seven days'
 * or click http://www.flickr.com/explore/interesting/7days
 * Third picture, no matter what it is, will be your album cover.
 * 4 Use photoshop or similar to put it all together.
 * 5 Post it with this text in the 'caption' and TAG the friends you want to join.
 *
 * TODO:
 * v random font color
 * v random font angle
 * v random text position
 * v random font size, that fits in image
 * v watermark
 * ? random font
 * ? font shading
 * - properly construct tweet
 * - better contrast on text vs image, either pick text color based on bg or use outline (both reverse color)
 */

require_once('autoload.php');
require_once('randomalbumcovr.inc.php');

use Twitterbot\Lib\Config;
use Twitterbot\Lib\Logger;
use Twitterbot\Lib\Auth;
use Twitterbot\Lib\Ratelimit;
use Twitterbot\Lib\Format;
use Twitterbot\Lib\Media;
use Twitterbot\Lib\Tweet;

(new RandomAlbumCovr)->run();

class RandomAlbumCovr
{
    public function __construct()
    {
        $this->sUsername = 'RandomAlbumCovr';
        $this->logger = new Logger;

        $this->oCurl = curl_init();
        curl_setopt_array($this->oCurl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 5,
        ]);
    }

    public function __destruct()
    {
        curl_close($this->oCurl);
    }

    public function run()
    {
        $oConfig = new Config;
        if ($oConfig->load($this->sUsername)) {
            if ((new Ratelimit($oConfig))->check()) {

                if ((new Auth($oConfig))->isUserAuthed($this->sUsername)) {

                    $this->logger->output('Fetching band name..');
                    $oBandName = $this->getUrl('http://en.wikipedia.org/wiki/Special:Random');
                    if (!$oBandName) {
                        $this->logger->output('- fetch failed, halting.');
                        return false;
                    }
                    $sBandName = $oBandName->query('//h1[@id="firstHeading"]')->item(0)->textContent;
                    $this->logger->output('- Our band is called "%s"', $sBandName);


                    $this->logger->output('Fetching album title..');
                    $oAlbumName = $this->getUrl('http://www.quotationspage.com/random.php3');
                    if (!$oAlbumName) {
                        $this->logger->output('- fetch failed, halting.');
                        return false;
                    }
                    $sAlbumTitle = $oAlbumName->query('(//dt[@class="quote"]/a)[last()]')->item(0)->textContent;
                    $this->logger->output('- fetched quote "%s"', $sAlbumTitle);
                    $aWords = explode(' ', $sAlbumTitle);
                    if (count($aWords) > 6) {
                        $iSize = mt_rand(4, 6);
                        $aWords = array_slice($aWords, count($aWords) - $iSize);
                        $sAlbumTitle = implode(' ', $aWords);
                    }
                    $this->logger->output('- Our album is called "%s"', $sAlbumTitle);


                    $this->logger->output('Fetching album cover image..');
                    $oAlbumCover = $this->getUrl('http://www.flickr.com/explore/interesting/7days');
                    if (!$oAlbumCover) {
                        $this->logger->output('- fetch failed, halting.');
                        return false;
                    }
                    $sAlbumCoverUrl = $oAlbumCover->query('//td[@class="Photo"]/span/a')->item(3)->getAttribute('href');
                    $oAlbumCoverPage = $this->getUrl('https://www.flickr.com' . $sAlbumCoverUrl);
                    $sAlbumCover = $oAlbumCoverPage->query('//meta[@property="og:image"]')->item(0)->getAttribute('content');
                    $this->logger->output('- Our album cover image is %s', $sAlbumCover);


                    $this->logger->output('Putting it all together..');
                    $oImage = @imagecreatefromjpeg($sAlbumCover);
                    if (!$oImage) {
                        $this->logger->output('- error loading image from URL.');
                        return false;
                    }

                    $sAlbumCoverFilename = MYPATH . '/randomalbumcovr.png';
                    if ($this->generateAlbumCover($sBandName, $sAlbumTitle, $oImage, $sAlbumCoverFilename)) {

                        $this->logger->output('- wrote album cover to %s!', $sAlbumCoverFilename);

                        $this->logger->output('Uploading album cover to Twitter..');
                        $sMediaId = (new Media($oConfig))
                            ->upload($sAlbumCoverFilename);

                        if ($sMediaId) {

                            $this->logger->output('Constructing tweet..');
                            $aData = [
                                'bandname' => $sBandName,
                                'albumtitle' => $sAlbumTitle,
                            ];
                            $sTweet = (new Format($oConfig))->format((object) $aData);

                            (new Tweet($oConfig))
                                ->set('aMediaIds', [$sMediaId])
                                ->post($sTweet);
                        } else {
                            $this->logger->output('Failed to upload image, halting.');
                        }
                    } else {
                        $this->logger->output('Generating album cover failed!');
                    }
                }
            }
        }
        $this->logger->output('Done!');
    }

    private function generateAlbumCover($sBandName, $sAlbumTitle, $oImage, $sFilePath)
    {
        $sFontName = MYPATH . '/arial.ttf';
        $oColor = imagecolorallocate($oImage, mt_rand(1, 255), mt_rand(1, 255), mt_rand(1, 255));
        $iWidth = imagesx($oImage);
        $iHeight = imagesy($oImage);

        $iBandNameFontSize = mt_rand(24, 64);
        $this->logger->output('- band name font size is %dpt', $iBandNameFontSize);
        $iBandNameAngle = mt_rand(1, 2) == 1 ? mt_rand(-10, 10) : 0;
        $aBoundingBox = imagettfbbox($iBandNameFontSize, $iBandNameAngle, $sFontName, $sBandName);
        $iBandNameWidth = $aBoundingBox[2] - $aBoundingBox[0];
        while ($iBandNameWidth > $iWidth) {
            $this->logger->output('- shrinking font size to fit..');
            $iBandNameFontSize = $iBandNameFontSize - 2;
            $aBoundingBox = imagettfbbox($iBandNameFontSize, $iBandNameAngle, $sFontName, $sBandName);
            $iBandNameWidth = $aBoundingBox[2] - $aBoundingBox[0];
        }
        $aBandName = [
            'text'      => $sBandName,
            'fontName'  => $sFontName,
            'fontSize'  => $iBandNameFontSize,
            'angle'     => $iBandNameAngle,
            'x'         => ($iWidth - $iBandNameWidth) * mt_rand(10, 80) / 100,
            'y'         => $iHeight * mt_rand(20, 50) / 100,
            'color'     => $oColor,
        ];

        $iAlbumTitleFontSize = mt_rand(10, 32);
        $this->logger->output('- album title font size is %dpt', $iAlbumTitleFontSize);
        $iAlbumTitleAngle = mt_rand(1, 2) == 1 ? mt_rand(-10, 10) : 0;
        $aBoundingBox = imagettfbbox($iAlbumTitleFontSize, $iAlbumTitleAngle, $sFontName, $sAlbumTitle);
        $iAlbumTitleWidth = $aBoundingBox[2] - $aBoundingBox[0];
        while ($iAlbumTitleWidth > $iWidth) {
            $this->logger->output('- shrinking font size to fit..');
            $iAlbumTitleFontSize--;
            $aBoundingBox = imagettfbbox($iAlbumTitleFontSize, $iAlbumTitleAngle, $sFontName, $sAlbumTitle);
            $iAlbumTitleWidth = $aBoundingBox[2] - $aBoundingBox[0];
        }
        $aAlbumTitle = [
            'text'      => $sAlbumTitle,
            'fontName'  => $sFontName,
            'fontSize'  => $iAlbumTitleFontSize,
            'angle'     => $iAlbumTitleAngle,
            'x'         => ($iWidth - $iAlbumTitleWidth) * mt_rand(20, 90) / 100,
            'y'         => $iHeight * mt_rand(55, 100) / 100,
            'color'     => $oColor,
        ];

        $aWatermark = [
            'text'      => 'generated by @RandomAlbumCovr on Twitter',
            'fontName'  => MYPATH . '/arial.ttf',
            'fontSize'  => 10,
            'color'     => imagecolorallocate($oImage, 255, 0, 0),
            'angle'     => 0,
            'x'         => 2,
            'y'         => $iHeight - 5,
        ];

        $this->drawText($oImage, $aBandName);
        $this->drawText($oImage, $aAlbumTitle);
        $this->drawText($oImage, $aWatermark);

        $this->logger->output('- writing generated file to disk..');
        imagepng($oImage, $sFilePath);

        return true;
    }

    private function drawText($oImage, $aArgs)
    {
        imagettftext($oImage, $aArgs['fontSize'], $aArgs['angle'], $aArgs['x'], $aArgs['y'], $aArgs['color'], $aArgs['fontName'], $aArgs['text']);
    }

    private function getUrl($sUrl)
    {
        curl_setopt($this->oCurl, CURLOPT_URL, $sUrl);

        $sResponse = curl_exec($this->oCurl);
        $sHttpCode = curl_getinfo($this->oCurl, CURLINFO_HTTP_CODE);
        if ($sHttpCode != '200') {
            $this->logger->output('Got HTTP %s fetching url %s.', $sHttpCode, $sUrl);
            return false;
        }

        libxml_use_internal_errors(true);
        $oDom = new DOMDocument;
        $oDom->loadHTML($sResponse);
        $oXpath = new DOMXPath($oDom);

        return $oXpath;
    }
}
