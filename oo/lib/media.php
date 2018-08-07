<?php
/**
 * TODO:
 * v stop base64 encoding attachments to shrink size (use raw binary)
 * - implement clearing a variable from tweet because it's been attached
 *   i.e. when attaching go.com/image.jpg, don't include url in tweet
 *   but do include when attaching imgur gallery or instagram account
 * - always same filesize is written to disk (var only set once?)
 */
namespace Twitterbot\Lib;

use Twitterbot\Custom\Imgur;
use \DOMDocument;
use \DOMXPath;

/**
 * Media class - upload media to twitter, if possible
 */
class Media extends Base
{
    /**
     * Upload file path to twitter to attach to tweet if possible, return media id
     *
     * @param string $sFilePath
     *
     * @return string|false
     */
    public function upload($sFilePath)
    {
        $this->logger->output(sprintf('Reading file %s..', $sFilePath));

        if (strpos($sFilePath, 'http') === 0) {
            //url, so download and store temp file
            $this->logger->output('- is URL, downloading..');
            $sBinary = file_get_contents($sFilePath);
            if ($sBinary) {
                if (strlen($sBinary) > 5 * 1024 * 1024) {
                    $this->logger->write(2, sprintf('Image is too large for tweet: %s (%d bytes)', $sFilePath, strlen($sBinary)));
                    $this->logger->output('- Error: file is too large! %d bytes, max is 5MB', strlen($sBinary));
                    return false;
                } else {
                    $sFilePath = getcwd() . '/tempimg.jpg';
                    file_put_contents($sFilePath, $sBinary);
                    $this->logger->output('- wrote %s bytes to disk', number_format(strlen($sBinary)));
                }
            }
        }

        $oRet = $this->oTwitter->upload('media/upload', array('media' => $sFilePath));
        if (isset($oRet->errors)) {
            $this->logger->write(2, sprintf('Twitter API call failed: media/upload (%s)', $oRet->errors[0]->message), array('file' => $sFilePath));
            $this->logger->output('- Error: ' . $oRet->errors[0]->message . ' (code ' . $oRet->errors[0]->code . ')');

            return false;

        } elseif (isset($oRet->error)) {
            $this->logger->write(2, sprintf('Twitter API call failed: media/upload(%s)', $oRet->error), array('file' => $sFilePath));
            $this->logger->output(sprintf('- Error: %s', $oRet->error));
        } else {
            $this->logger->output("- Uploaded %s to attach to next tweet", $sFilePath);

            return $oRet->media_id_string;
        }
    }

    /**
     * Wrapper to upload media to twitter according to URL and type, return media type
     *
     * @param string $sUrl
     * @param string $sType
     *
     * @return string|false
     */
    public function uploadFromUrl($sUrl, $sType)
    {
        //albums can have a numeral if they have multiple pics, strip that out for here
        if (preg_match('/album:\d/', $sType)) {
            $sType = 'album';
        }

        switch ($sType) {
            case 'image':
                return $this->upload($sUrl);
                break;
            case 'gallery':
            case 'album':
                return $this->uploadFromGallery($sUrl);
                break;
            case 'instagram':
                return $this->uploadFromInstagram($sUrl);
                break;
            case 'gif':
                return $this->uploadVideoFromGfycat($sUrl);
                break;
            default:
                $this->upload($sUrl);
                break;
        }
    }

    /**
     * Upload media to twitter from imgur gallery page, return media id if possible
     *
     * @param string $sUrl
     *
     * @return string|false
     */
    private function uploadFromGallery($sUrl)
    {
        //use the API
        $aImageUrls = (new Imgur)->getFourAlbumImages($sUrl);

        //if we have at least one image, upload it to attach to tweet
        $aMediaIds = array();
        if ($aImageUrls) {
            foreach ($aImageUrls as $sImage) {
                $aMediaIds[] = $this->upload($sImage);
            }
        }

        return array_filter($aMediaIds);
    }

    /**
     * Upload media to twitter from imgur page, return media id if possible
     *
     * @param string $sUrl
     *
     * @return string|false
     */
    private function uploadFromPage($sUrl)
    {
        //imgur implements meta tags that indicate to twitter which urls to use for inline preview
        //so we're going to use those same meta tags to determine which urls to upload
        //format: <meta name="twitter:image:src" content="http://i.imgur.com/[a-zA-Z0-9].ext"/>

        //fetch image from twitter meta tag
        libxml_use_internal_errors(true);
        $oDocument = new DOMDocument();
        $oDocument->preserveWhiteSpace = false;
        $oDocument->loadHTML(file_get_contents($sUrl));

        $sImage = '';
        $oXpath = new DOMXpath($oDocument);
        $oMetaTags = $oXpath->query('//meta[@name="twitter:image:src"]');
        foreach ($oMetaTags as $oTag) {
            $sImage = $oTag->getAttribute('content');
            break;
        }

        //march 2016: imgur changed their meta tags
        if (empty($sImage)) {
            $oMetaTags = $oXpath->query('//meta[@name="twitter:image"]');
            foreach ($oMetaTags as $oTag) {
                $sImage = $oTag->getAttribute('content');
                break;
            }
        }

        if (!empty($sImage)) {
            //we want the page url truncated from the tweet, so use it as the index name
            return $this->upload($sImage, $sUrl);
        }

        return false;
    }

    /**
     * Upload media to twitter from instagram, return media id if possible
     *
     * @param string $sUrl
     *
     * @return string|false
     */
    private function uploadFromInstagram($sUrl)
    {
        //instagram implements og:image meta tag listing exact url of image
        //this works on both account pages (tag contains user avatar) and photo pages (tag contains photo url)

        //we want instagram photo urls to be truncated from the tweet, but not instagram account urls
        if (preg_match('/instagram\.com\/p\//i', $sUrl)) {
            //custom name equal to original url
            $sName = $sUrl;
        } else {
            //use url as index name
            $sName = false;
        }

        //fetch image from twitter meta tag
        libxml_use_internal_errors(true);
        $oDocument = new DOMDocument();
        $oDocument->preserveWhiteSpace = false;
        $oDocument->loadHTML(file_get_contents($sUrl));

        $oXpath = new DOMXpath($oDocument);
        $oMetaTags = $oXpath->query('//meta[@property="og:image"]');
        foreach ($oMetaTags as $oTag) {
            $sImage = $oTag->getAttribute('content');
            break;
        }

        if (!empty($sImage)) {
            return $this->upload($sImage, $sName);
        }

        return false;
    }

    /**
     * Upload media to twitter from gfycat, return media id if possible
     *
     * @param string $sUrl
     *
     * @return string|false
     */
    public function uploadVideoFromGfycat($sUrl) {

        //construct json info url
        $sJsonUrl = str_replace('gfycat.com/', 'api.gfycat.com/v1/gfycats/', $sUrl);
        if ($sJsonUrl == $sUrl) {
            return false;
        }

        $oGfycatInfo = @json_decode(file_get_contents($sJsonUrl));
        if ($oGfycatInfo && !empty($oGfycatInfo->gfyItem->mp4Url)) {
            return $this->uploadVideoToTwitter($oGfycatInfo->gfyItem->mp4Url, 'video/mp4');
        }

        return false;
    }

    /**
     * Upload video to twitter from URL, return media id if possible
     *
     * @param string $sVideo
     * @param string $sType
     *
     * @return string|false
     */
    private function uploadVideoToTwitter($sFilePath, $sType) {

        $this->logger->output(sprintf('Reading file %s..', $sFilePath));

        //need to download and save the file since the library expects a local file
        $sVideoBinary = file_get_contents($sFilePath);
        if (strlen($sVideoBinary) < 15 * pow(1024, 2)) {

            $this->logger->output('- Saving %s bytes to disk..', number_format(strlen($sVideoBinary)));
            $sTempFilePath = getcwd() . '/video.mp4';
            file_put_contents($sTempFilePath, $sVideoBinary);

            $this->logger->output('- Uploading to twitter (chunked)..');
            $oRet = $this->oTwitter->upload('media/upload', array('media' => $sTempFilePath, 'media_type' => $sType), true);
            if (isset($oRet->errors)) {
                $this->logger->write(2, sprintf('Twitter API call failed: media/upload (%s, chunked)', $oRet->errors[0]->message), array('file' => $sFilePath));
                $this->logger->output('- Error: ' . $oRet->errors[0]->message . ' (code ' . $oRet->errors[0]->code . ')');

                return false;

            } elseif (isset($oRet->error)) {
                $this->logger->write(2, sprintf('Twitter API call failed: media/upload (%s, chunked)', $oRet->error), array('file' => $sFilePath));
                $this->logger->output(sprintf('- Error: %s', $oRet->error));
            } else {
                $this->logger->output("- Uploaded video %s to attach to next tweet", $sFilePath);

                return $oRet->media_id_string;
            }
        } else {
            $this->logger->write(2, sprintf('File is too large! File is %d bytes, max is 15MB (%s)', strlen($sVideoBinary), $sFilePath));
            $this->logger->output('- File is too large! %d bytes, max is 15MB', strlen($sVideoBinary));
        }

        return false;
    }
}
