<?php
namespace Twitterbot\Custom;

require('gis.inc.php');

//google image search
class Gis {

    private $sBaseCse = 'https://www.googleapis.com/customsearch/v1';

    public function imageSearch($sQuery, $iImageCount = 5)
    {
        $sUrl = $this->sBaseCse . '?' . http_build_query(array(
            'q' => $sQuery,
            'num' => $iImageCount,
            'start' => 1,
            //'imgSize' => 'large',
            'searchType' => 'image',
            'key' => GOOGLE_CSE_API_KEY,
            'cx' => GOOGLE_CSE_ID,
        ));

        $oCurl = curl_init($sUrl);
        curl_setopt($oCurl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($oCurl, CURLOPT_SSL_VERIFYPEER, false);

        $oReturn = json_decode(curl_exec($oCurl));
        if ($oReturn && !curl_errno($oCurl)) {
            curl_close($oCurl);

            $aImages = [];
            foreach ($oReturn->items as $oImage) {
                if (!empty($oImage->link)) {
                    $aImages[] = $oImage->link;
                }
            }

            return ($aImages ? $aImages : false);
        }
        curl_close($oCurl);

        return false;
    }
}
