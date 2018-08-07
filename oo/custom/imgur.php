<?php
namespace Twitterbot\Custom;

require('imgur.inc.php');

class Imgur {

    private $sImgurAlbumUrl = 'https://api.imgur.com/3/album/%s';
    private $sImgurAccountUrl = 'https://api.imgur.com/3/account/%s';
    private $oCurl;

    public function getFavoritesAlbums($sUsername = 'mrbellek')
    {
        $aFactsDumpAlbums = [];

        //fetch 3 pages of favorites
        for ($i = 1; $i <= 3; $i++) {
            $sUrl = sprintf($this->sImgurAccountUrl, $sUsername . '/gallery_favorites/' . $i);

            $oResponse = $this->curlGet($sUrl);
            if ($oResponse->success) {
                foreach ($oResponse->data as $oItem) {
                    if (isset($oItem->is_album) && $oItem->is_album && stripos($oItem->title, 'fact') !== false) {
                        $aFactsDumpAlbums[] = $oItem->link;
                        /*printf('Facts dump album: %s (id: %s) - %s - %d images' . PHP_EOL,
                            $oItem->title,
                            $oItem->id,
                            $oItem->link,
                            $oItem->images_count
                        );*/
                    }
                }
            }
        }

        return $aFactsDumpAlbums;
    }

    public function getAlbumImageCount($sUrl)
    {
        //url format will be imgur.com/a/asdf123 or imgur.com/gallery/asdf123
        if (!preg_match('/imgur\.com\/a\/([a-z0-9]+)/i', $sUrl, $aMatch)) {
            if (!preg_match('/imgur\.com\/gallery\/([a-z0-9]+)/i', $sUrl, $aMatch)) {
                return false;
            }
        }

        //api url format is https://api.imgur.com/3/album/asdf123
        $sUrl = sprintf($this->sImgurAlbumUrl, $aMatch[1]);

        //do the request
        $oResponse = $this->curlGet($sUrl);

        //extract album image count
        if ($oResponse->success && !empty($oResponse->data->images_count)) {
            return (int) $oResponse->data->images_count;
        } else {
            return false;
        }
    }

    public function getAllAlbumImages($sUrl)
    {
        if (!preg_match('/imgur\.com\/a\/([a-z0-9]+)/i', $sUrl, $aMatch)) {
            if (!preg_match('/imgur\.com\/gallery\/([a-z0-9]+)/i', $sUrl, $aMatch)) {
                return [];
            }
        }

        //api url format is https://api.imgur.com/3/album/asdf123
        $sUrl = sprintf($this->sImgurAlbumUrl, $aMatch[1] . '/images');

        //do the request
        $oResponse = $this->curlGet($sUrl);

        if ($oResponse->success && !empty($oResponse->data)) {
            //@TODO: filter out animated gifs as long as I
            //don't have posting those to twitter working..
            $aImageUrls = [];
            foreach ($oResponse->data as $oImage) {
                $aImageUrls[] = $oImage->link;
            }

            return $aImageUrls;
        } else {
            return [];
        }
    }

    public function getFourAlbumImages($sUrl, $bRandom = true)
    {
        //url format will be imgur.com/a/asdf123
        if (!preg_match('/imgur\.com\/a\/([a-z0-9]+)/i', $sUrl, $aMatch)) {
            return [];
        }

        //api url format is https://api.imgur.com/3/album/asdf123
        $sUrl = sprintf($this->sImgurAlbumUrl, $aMatch[1] . '/images');

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

        return $oResponse;
    }

    public function __destruct()
    {
        if (!empty($this->oCurl)) {
            curl_close($this->oCurl);
        }
    }
}
