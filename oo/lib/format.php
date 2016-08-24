<?php
namespace Twitterbot\Lib;

class Format extends Base
{
    //TODO: complex formatting like for rssbot
    public function format($aRecord)
    {
        $iMaxTweetLength = $this->oConfig->get('max_tweet_lengh', 140);
        $iShortUrlLength = $this->oConfig->get('short_url_length', 23);

        //format message according to format in settings, and return it
        $aTweetVars = $this->oConfig->get('tweet_vars');
        $sTweet = $this->oConfig->get('format');

		//replace all non-truncated fields
        foreach ($aTweetVars as $oTweetVar) {
            if (empty($oTweetVar->truncate) || $oTweetVar->truncate == false) {
                $sTweet = str_replace($oTweetVar->var, $aRecord[$oTweetVar->recordfield], $sTweet);
            }
        }

		//determine maximum length left over for truncated field (links are shortened to t.co format of max 22 chars)
		$sTempTweet = preg_replace('/http:\/\/\S+/', str_repeat('x', $iShortUrlLength), $sTweet);
		$sTempTweet = preg_replace('/https:\/\/\S+/', str_repeat('x', $iShortUrlLength + 1), $sTempTweet);
		$iTruncateLimit = $iMaxTweetLength - strlen($sTempTweet);

		//replace truncated field
        foreach ($aTweetVars as $oTweetVar) {
			if (!empty($oTweetVar->truncate) && $oTweetVar->truncate == true) {

				//placeholder will get replaced, so add that to char limit
				$iTruncateLimit += strlen($oTweetVar->var);

				//get text to replace placeholder with
				$sText = html_entity_decode($aRecord[$oTweetVar->recordfield], ENT_QUOTES, 'UTF-8');

				//get length of text with url shortening
				$sTempText = preg_replace('/http:\/\/\S+/', str_repeat('x', $iShortUrlLength), $sText);
				$sTempText = preg_replace('/https:\/\/\S+/', str_repeat('x', $iShortUrlLength + 1), $sTempText);
				$iTextLength = strlen($sTempText);

				//if text with url shortening falls under limit, keep it - otherwise truncate
				if ($iTextLength <= $iTruncateLimit) {
					$sTweet = str_replace($oTweetVar->var, $sText, $sTweet);
				} else {
					$sTweet = str_replace($oTweetVar->var, substr($sText, 0, $iTruncateLimit), $sTweet);
				}

				//only 1 truncated field allowed
				break;
			}
		}

        return $sTweet;
    }
}
