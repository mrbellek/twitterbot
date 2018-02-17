<?php
namespace Twitterbot\Custom;

require('imgur.inc.php');

class Imgur {

    private $sImgurBaseUrl = 'https://api.imgur.com/3/album/%s';
    private $oCurl;

    public function getAlbumImageCount($sUrl)
    {
        //url format will be imgur.com/a/asdf123
        if (!preg_match('/imgur\.com\/a\/([a-z0-9]+)/i', $sUrl, $aMatch)) {
            return false;
        }

        //api url format is https://api.imgur.com/3/album/asdf123
        $sUrl = sprintf($this->sImgurBaseUrl, $aMatch[1]);

        //do the request
        $oResponse = $this->curlGet($sUrl);

        //extract album image count
        if ($oResponse->success && !empty($oResponse->data->images_count)) {
            return (int) $oResponse->data->images_count;
        } else {
            return false;
        }
    }

    public function getFourAlbumImages($sUrl, $bRandom = true)
    {
        //url format will be imgur.com/a/asdf123
        if (!preg_match('/imgur\.com\/a\/([a-z0-9]+)/i', $sUrl, $aMatch)) {
            return [];
        }

        //api url format is https://api.imgur.com/3/album/asdf123
        $sUrl = sprintf($this->sImgurBaseUrl, $aMatch[1] . '/images');

        //do the request
        $oResponse = $this->curlGet($sUrl);

        if ($oResponse->success && !empty($oResponse->data)) {
            $sUrls = [];

            //if 4 or more images, pick either first 4 or 4 random ones
            //(twitter allows only max 4 images with a tweet)
            if (count($oResponse->data) >= 4) {

                if ($bRandom) {
                    $aIndeces = array_rand($oResponse->data, 4);
                } else {
                    $aIndeces = array_slice(array_keys($oResponse->data), 0, 4);
                }
            } else {
                //if less than 4, just use those
                $aIndeces = array_keys($oResponse->data);
            }

            //get urls from image objects
            foreach ($aIndeces as $iIndex) {
                $sUrls[] = $oResponse->data[$iIndex]->link;
            }

            return $sUrls;
        } else {
            return [];
        }
    }

    private function curlGet($sUrl)
    {
        if (empty($this->oCurl)) {
            //init curl
            $this->oCurl = curl_init();

            curl_setopt($this->oCurl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($this->oCurl, CURLOPT_HEADER, false);
            curl_setopt($this->oCurl, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($this->oCurl, CURLOPT_HTTPHEADER, ['Authorization: Client-ID ' . IMGUR_API_CLIENTID]);
        }

        curl_setopt($this->oCurl, CURLOPT_URL, $sUrl);

        //do the request
        $oResponse = json_decode(curl_exec($this->oCurl));
        if (curl_error($this->oCurl)) {
            throw new Exception(sprintf('Imgur cURL call failed: %s', curl_error($this->oCurl)));
        }
        curl_close($this->oCurl);

        return $oResponse;
    }
}
