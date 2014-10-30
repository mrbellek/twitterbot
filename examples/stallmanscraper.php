<?php
$sWebsite = 'https://stallman.org/';
$aUrls = array($sWebsite . '/');

function getPage($sUrl) {

    global $aUrls, $sWebsite;
    $sBaseUrl = substr($sUrl, 0, strrpos($sUrl, '/'));
    $sDomain = substr($sUrl, 0, strpos($sUrl, '/', 8));

    //get page
    echo $sUrl . '..';
    $sHTML = @file_get_contents($sUrl);
    if (!$sHTML) {
        echo "404\n";
        return FALSE;
    }
    if (strlen($sHTML) < 1500000) {
        printf(" %d bytes\n", strlen($sHTML));

        //trim whitespaces
        $sText = preg_replace('/\s+/', ' ', $sHTML);

        //filter html from page 
        $sText = preg_replace('/<.+?>/', '', $sText);

        //write to file
        file_put_contents('stallman.txt', $sText, FILE_APPEND);

        //get all links in page
        if (preg_match_all('/href="(.+?)"/', $sHTML, $aMatches)) {

            foreach ($aMatches[1] as $sMatch) {

                //filter out anything that's not a web link on this domain
                if (stripos($sMatch, 'http') === FALSE && strpos($sMatch, '#') !== 0 &&
                    (preg_match('/\.html$/', $sMatch) || preg_match('/\/$/', $sMatch)) &&
                    strpos($sMatch, 'oldlinks') === FALSE && strpos($sMatch, 'photos') === FALSE && strpos($sMatch, '..') === FALSE) {

                    //prevent infinite loop lol
                    if (strpos($sMatch, './') === 0) {
                        $sMatch = substr($sMatch, 2);
                    }

                    //prepend domain and slash if needed
                    if (substr($sMatch, 0, 1) !== '/') {
                        $sMatch = $sBaseUrl . '/' . $sMatch;
                    } else {
                        $sMatch = $sDomain . $sMatch;
                    }

                    //spider url and add to list if not already spidered
                    if (!in_array($sMatch, $aUrls)) {
                        $aUrls[] = $sMatch;

                        //if ($sUrl == 'https://stallman.org') {
                            getPage($sMatch);
                        //}
                    }
                }
            }
        }
    } else {

        echo " page too big, skipping\n";
    }
}

echo "starting spider!\n";
@unlink('stallman.txt');
getPage($sWebsite);
printf("done! spidered %d pages\n", count($aUrls));;
