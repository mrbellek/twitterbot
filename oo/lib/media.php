<?php
/**
 * TODO:
 * - implement clearing a variable from tweet because it's been attached
 *   i.e. when attaching go.com/image.jpg, don't include url in tweet
 *   but do include when attaching imgur gallery or instagram account
 * - stop base64 encoding attachments to shrink size (use raw binary)
 */
namespace Twitterbot\Lib;

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
        $sImageBinary = base64_encode(file_get_contents($sFilePath));
        if ($sImageBinary && strlen($sImageBinary) > 5 * pow(1024, 2)) {
            //max size is 3MB
            $this->logger->write(3, sprintf('File too large to attach: %s (%d bytes)', $sFilePath, strlen($sImageBinary)));
            $this->logger->output(sprintf('File too large: %d bytes.', strlen($sImageBinary)));

            return false;
        }

        $oRet = $this->oTwitter->upload('media/upload', array('media' => $sImageBinary));
        if (isset($oRet->errors)) {
            $this->logger->write(2, sprintf('Twitter API call failed: media/upload (%s)', $oRet->errors[0]->message), array('file' => $sFilePath, 'length' => strlen($sImageBinary)));
            $this->logger->output('- Error: ' . $oRet->errors[0]->message . ' (code ' . $oRet->errors[0]->code . ')');

            return false;

        } elseif (isset($oRet->error)) {
            $this->logger->write(2, sprintf('Twitter API call failed: media/upload(%s)', $oRet->error), array('file' => $sFilePath, 'length' => strlen($sImageBinary)));
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
        switch ($sType) {
            default:
            case 'image':
                return $this->upload($sUrl);
                break;
            case 'gallery':
                return $this->uploadFromGallery($sUrl);
                break;
            case 'instagram':
                return $this->uploadFromInstagram($sUrl);
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
        //imgur implements meta tags that indicate to twitter which urls to use for inline preview
        //so we're going to use those same meta tags to determine which urls to upload
        //format: <meta name="twitter:image[0-3]:src" content="http://i.imgur.com/[a-zA-Z0-9].ext"/>

        //march 2016: imgur changed their meta tags, only the first (or random?) image is listed
        $aImageUrls = array();

        //fetch twitter meta tag values, up to 4
        libxml_use_internal_errors(true);
        $oDocument = new DOMDocument();
        $oDocument->preserveWhiteSpace = false;
        $oDocument->loadHTML(file_get_contents($sUrl));

        $oXpath = new DOMXpath($oDocument);
        $oMetaTags = $oXpath->query('//meta[contains(@name,"twitter:image")]');
        foreach ($oMetaTags as $oTag) {
            $aImageUrls[] = $oTag->getAttribute('content');

            if (count($aImageUrls) == 4) {
                break;
            }
        }

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
     * @TODO: get this to work
     *
     * @param string $sUrl
     *
     * @return string|false
     */
    private function uploadVideoFromGfycat($sUrl) {

        //construct json info url
        $sJsonUrl = str_replace('gfycat.com/', 'gfycat.com/cajax/get/', $sUrl);
        if ($sJsonUrl == $sUrl) {
            return false;
        }

        $oGfycatInfo = @json_decode(file_get_contents($sJsonUrl));
        if ($oGfycatInfo && !empty($oGfycatInfo->gfyItem->webpUrl)) {
            return $this->uploadVideoToTwitter($oGfycatInfo->gfyItem->webpUrl);
        }

        return false;
    }

    /**
     * Upload video to twitter from URL, return media id if possible
     * @TODO: get this to work
     *
     * @param string $sVideo
     * @param string $sName
     *
     * @return string|false
     */
    private function uploadVideoToTwitter($sVideo, $sName = false) {

        if (!$sName) {
            $sName = $sVideo;
        }

        $sVideoBinary = file_get_contents($sVideo);
        if (strlen($sVideoBinary) < 5 * pow(1024, 2)) {

            $oRet = $this->oTwitter->upload('media/upload', array('media' => $sVideoBinary));
            var_dump(strlen($sVideoBinary), $oRet);
            die();
        }
    }
}
