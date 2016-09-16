<?php
namespace Twitterbot\Lib;

class Rss extends Base
{
    public function getFeed()
    {
        $oFeed = $this->oConfig->get('feed');
        if (!is_file('feed.json')) {

            $hCurl = curl_init();
            curl_setopt_array($hCurl, array(
                CURLOPT_RETURNTRANSFER  => true,
                CURLOPT_SSL_VERIFYPEER  => false,
                CURLOPT_FOLLOWLOCATION  => true,
                CURLOPT_AUTOREFERER     => true,
                CURLOPT_CONNECTTIMEOUT  => 5,
                CURLOPT_TIMEOUT         => 5,
                CURLOPT_URL             => $oFeed->url,
            ));

            $sRssFeedRaw = curl_exec($hCurl);
            curl_close($hCurl);

            file_put_contents('feed.json', $sRssFeedRaw);
        } else {
            $sRssFeedRaw = file_get_contents('feed.json');
        }

        switch ($oFeed->format) {
            case 'xml':
                $oRssFeed = simplexml_load_string($sRssFeedRaw);
                break;
            case 'json':
            default:
                $oRssFeed = json_decode($sRssFeedRaw);
        }

        if (!empty($oFeed->rootnode)) {
            $oNode = $oRssFeed;
            foreach (explode('>', $oFeed->rootnode) as $sNode) {
                $oNode = $oNode->$sNode;
            }
        } else {
            $oNode = $oRssFeed;
        }

        return $oNode;
    }
}
