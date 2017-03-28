<?php
namespace Twitterbot\Custom;

require('bing.inc.php');

//bing image search
class Bing
{
    private $sBaseUrl = 'https://api.cognitive.microsoft.com/bing/v5.0/images/search';

    public function search($sQuery, $iResultCount = 5)
    {
        $sUrl = $this->sBaseUrl . '?' . http_build_query([
            'q' => urlencode($sQuery),
            'count' => $iResultCount,
            //'offset' => 0,
            //'mkt' => 'en-us',
            'safeSearch' => 'Off',
        ]);

        $oCurl = curl_init($sUrl);

        curl_setopt($oCurl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($oCurl, CURLOPT_TIMEOUT, 30);
        curl_setopt($oCurl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($oCurl, CURLOPT_HTTPHEADER, [
            'Ocp-Apim-Subscription-Key: ' . BING_SEARCH_API_KEY1,
            'Content-Type: multipart/form-data',
        ]);
        //curl_setopt($oCurl, CURLOPT_HEADER, true);

        $sResponse = curl_exec($oCurl);
        curl_close($oCurl);
        $oResponse = json_decode($sResponse);

        if (!empty($oResponse->value)) {
            $aResults = [];
            foreach ($oResponse->value as $oValue) {
                $aResults[] = $oValue->contentUrl;
            }

            return $aResults;
        }

        return [];
    }
}
