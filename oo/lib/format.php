<?php
namespace Twitterbot\Lib;

class Format extends Base
{
    //TODO: complex formatting like for rssbot
    public function format($aRecord)
    {
        //format message according to format in settings, and return it
        $sTweet = $this->oConfig->get('format');

        foreach ($aRecord as $sName => $sValue) {
            $sTweet = str_replace(':' . $sName, $sValue, $sTweet);
        }

        return $sTweet;
    }
}
