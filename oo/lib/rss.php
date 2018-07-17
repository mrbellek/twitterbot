<?php
namespace Twitterbot\Lib;

use \Exception;

/**
 * Rss class - retrieves xml/json feed and returns only new items since last fetch
 *
 * @param config:feed feed settings (url, root node, format, timestamp field name)
 * @param config:last_max_timestamp newest timestamp from last run
 *
 * @TODO:
 * - limit number of items from getFeed()
 */
class Rss extends Base
{
    /**
     * Get xml/json feed, return new items since last run
     *
     * @return object
     */
    public function getFeed()
    {
        $oFeed = $this->oConfig->get('feed');

        //DEBUG
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

            file_put_contents('feed.json', json_encode(json_decode($sRssFeedRaw), JSON_PRETTY_PRINT));
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

        //trim object to relevant root node, if set
        if (!empty($oFeed->rootnode)) {
            $oNodes = $this->getRssNodeField($oRssFeed, $oFeed->rootnode);
        } else {
            $oNodes = $oRssFeed;
        }

        //truncate list of nodes to those with at least the max timestamp from last time
        $sLastMaxTimestamp = $this->oConfig->get('last_max_timestamp', 0);
        if ($sLastMaxTimestamp) {
            foreach ($oNodes as $key => $oNode) {

                //get value of timestamp field
                $sTimestamp = $this->getRssNodeField($oNode, $this->oConfig->get('timestamp_field'));

                //remove node from list if timestamp is older than newest timestamp from last run
                if (is_numeric($sTimestamp) && $sTimestamp > 0 && $sTimestamp <= $sLastMaxTimestamp) {
                    unset($oNodes[$key]);
                }
            }
        }

        //get highest timestamp in list of nodes and save it
        $sNewestTimestamp = 0;
        if ($sTimestampField = $this->oConfig->get('timestamp_field')) {
            foreach ($oNodes as $oItem) {

                //get value of timestamp field
                $sTimestamp = $this->getRssNodeField($oItem, $sTimestampField);

                //save highest value of timestamp
                $sNewestTimestamp = (is_numeric($sTimestamp) && $sTimestamp > $sNewestTimestamp ? $sTimestamp : $sNewestTimestamp);
            }

            //save in settings
            if ($sNewestTimestamp > 0) {
                $this->oConfig->set('last_max_timestamp', $sNewestTimestamp);
                //$this->oConfig->writeConfig(); //DEBUG
            }
        }

        return $oNodes;
    }

    /**
     * Gets a subnode of node value from tree based on given 'node>subnode>etc' syntax arg
     *
     * @param object $oNode
     * @param string $sField
     *
     * @return object
     */
    private function getRssNodeField($oNode, $sField)
    {
        foreach (explode('>', $sField) as $sName) {
            if (isset($oNode->$sName)) {
                $oNode = $oNode->$sName;
            } else {
                throw new Exception(sprintf('Rss->getRssNodeField: node does not have %s field (full field: %s', $sName, $sField));
            }
        }

        return $oNode;
    }
}
