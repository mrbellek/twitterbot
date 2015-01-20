<?php
require_once('twitteroauth.php');

/*
 * TODO:
 * - commands through mentions, replies through mentions/DMs like retweetbot
 * - somehow r/buttcoin items are sometimes posted multiple times - bug? does the timestamp of items change?
 */

//runs every 15 minutes, mirroring & attaching images might take a while
set_time_limit(15 * 60);

class RssBot {

    private $oTwitter;
    private $sUsername;
    private $sLogFile;

    private $sUrl;
    private $sTweetFormat;
    private $aTweetVars;

    private $sMediaId = FALSE;

    public function __construct($aArgs) {

        //connect to twitter
        $this->oTwitter = new TwitterOAuth(CONSUMER_KEY, CONSUMER_SECRET, ACCESS_TOKEN, ACCESS_TOKEN_SECRET);
        $this->oTwitter->host = "https://api.twitter.com/1.1/";

        //make output visible in browser
        if (!empty($_SERVER['HTTP_HOST'])) {
            echo '<pre>';
        }

        //load args
        $this->parseArgs($aArgs);
    }

    private function parseArgs($aArgs) {

        $this->sUsername = (!empty($aArgs['sUsername']) ? $aArgs['sUsername'] : '');
        $this->sUrl = (!empty($aArgs['sUrl']) ? $aArgs['sUrl'] : '');
        $this->sLastRunFile = (!empty($aArgs['sLastRunFile']) ? $aArgs['sLastRunFile'] : $this->sUsername . '-last.json');

        $this->aTweetSettings = array(
            'sFormat'       => (isset($aArgs['sTweetFormat']) ? $aArgs['sTweetFormat'] : ''),
            'aVars'         => (isset($aArgs['aTweetVars']) ? $aArgs['aTweetVars'] : array()),
            'sTimestampXml' => (isset($aArgs['sTimestampXml']) ? $aArgs['sTimestampXml'] : 'pubDate'),
        );

        $this->sLogFile     = (!empty($aArgs['sLogFile'])      ? $aArgs['sLogFile']         : strtolower($this->sUsername) . '.log');

        if ($this->sLogFile == '.log') {
            $this->sLogFile = pathinfo($_SERVER['SCRIPT_FILENAME'], PATHINFO_FILENAME) . '.log';
        }
    }

    public function run() {

        //verify current twitter user is correct
        if ($this->getIdentity()) {

            if ($this->getRssFeed()) {

                if ($this->postTweets()) {

                    $this->halt();
                }
            }
        }
    }

    private function getIdentity() {

        echo "Fetching identity..\n";

        if (!$this->sUsername) {
            $this->logger(2, 'No username');
            $this->halt('- No username! Set username when calling constructor.');
            return FALSE;
        }

        $oUser = $this->oTwitter->get('account/verify_credentials', array('include_entities' => FALSE, 'skip_status' => TRUE));

        if (is_object($oUser) && !empty($oUser->screen_name)) {
            if ($oUser->screen_name == $this->sUsername) {
                printf("- Allowed: @%s, continuing.\n\n", $oUser->screen_name);
            } else {
                $this->logger(2, sprintf('Authenticated username was unexpected: %s (expected: %s)', $oUser->screen_name, $this->sUsername));
                $this->halt(sprintf('- Not alowed: @%s (expected: %s), halting.', $oUser->screen_name, $this->sUsername));
                return FALSE;
            }
        } else {
            $this->logger(2, sprintf('Twitter API call failed: GET account/verify_credentials (%s)', $oUser->errors[0]->message));
            $this->halt(sprintf('- Call failed, halting. (%s)', $oUser->errors[0]->message));
            return FALSE;
        }

        return TRUE;
    }

    private function getRssFeed() {

        $this->oFeed = simplexml_load_file($this->sUrl);

        return ($this->oFeed ? TRUE : FALSE);
    }

    private function postTweets() {

        try {
            $oNewestItem = FALSE;
            $sTimestampVar = $this->aTweetSettings['sTimestampXml'];
            $aLastSearch = json_decode(@file_get_contents(MYPATH . '/' . sprintf($this->sLastRunFile, 1)), TRUE);
            $this->logger(5, sprintf('Starting postTweets() on %d items in XML feed, last search was %s', count($this->oFeed->channel->item), date('Y-m-d H:i:s', $aLastSearch['timestamp'])));

            $i = 0;
            foreach ($this->oFeed->channel->item as $oItem) {
                $i++;

                //save newest item to set timestamp for next run
                if (!$oNewestItem || strtotime($oItem->$sTimestampVar) > strtotime($oNewestItem->$sTimestampVar)) {
                    $this->logger(5, sprintf('Item %d has timestamp %s, currently newest item', $i, date('Y-m-d H:i:s', strtotime($oItem->$sTimestampVar))));
                    $oNewestItem = $oItem;
                }

                //don't tweet items we've already done last in run
                if (!empty($oItem->$sTimestampVar)) {
                    if (strtotime($oItem->$sTimestampVar) <= $aLastSearch['timestamp']) {
                        $this->logger(5, sprintf('Item %d is older (%s) than newest item from last run, skipping', $i, date('Y-m-d H:i:s', strtotime($oItem->$sTimestampVar))));
                        continue;
                    }
                }

                //convert xml item into tweet
                $sTweet = $this->formatTweet($oItem);
                if (!$sTweet) {
                    continue;
                }
                $this->logger(5, sprintf('Formatted item %d into tweet "%s"', $i, $sTweet));

                printf("- %s\n", utf8_decode($sTweet) . ' - ' . $oItem->pubDate);

                if ($this->sMediaId) {
                    $this->logger(5, sprintf('Posting item %d (with picture attached) to Twitter', $i));
                    $oRet = $this->oTwitter->post('statuses/update', array('status' => $sTweet, 'trim_users' => TRUE, 'media_ids' => $this->sMediaId));
                    $this->sMediaId = FALSE;
                } else {
                    $this->logger(5, sprintf('Posting item %d to Twitter', $i));
                    $oRet = $this->oTwitter->post('statuses/update', array('status' => $sTweet, 'trim_users' => TRUE));
                }
                if (isset($oRet->errors)) {
                    $this->logger(2, sprintf('Twitter API call failed: statuses/update (%s)', $oRet->errors[0]->message));
                    $this->halt('- Error: ' . $oRet->errors[0]->message . ' (code ' . $oRet->errors[0]->code . ')');
                    //return FALSE;
                }
            }

            //save timestamp of last item to disk
            if (!empty($oNewestItem->$sTimestampVar)) {
                if(strtotime($oNewestItem->$sTimestampVar) > $aLastSearch['timestamp']) {
                    $this->logger(5, sprintf('Saving timestamp %s of newest item to disk', date('Y-m-d H:i:s', strtotime($oNewestItem->$sTimestampVar))));
                    $aLastItem = array(
                        'timestamp' => strtotime($oNewestItem->$sTimestampVar),
                    );
                    file_put_contents(MYPATH . '/' . $this->sLastRunFile, json_encode($aLastItem));
                } else {
                    $this->logger(5, 'No new items, not updating last search json file');
                }

            } else {
                //very bad
                $this->logger(2, 'Newest item foundu has no timestamp field?!');
                $this->halt('No timestamp found on XML item, halting.');
                return FALSE;
            }

        } catch(Exception $e) {

            $this->logger(2, sprintf('Caught fatal error in postTweets(): %s', $e->getMessage()));
            $this->halt(sprintf('- Fatal error in postTweets(): %s', $e->getMessage()));

            //save timestamp of last item to disk
            $this->logger(5, sprintf('[Exception] Saving timestamp %s of newest item to disk', date('Y-m-d H:i:s', strtotime($oNewestItem->$sTimestampVar))));
            if (!empty($oNewestItem->$sTimestampVar)) {
                $aLastItem = array(
                    'timestamp' => strtotime($oNewestItem->$sTimestampVar),
                );
                file_put_contents(MYPATH . '/' . $this->sLastRunFile, json_encode($aLastItem));
            }

            return FALSE;
        }

        return TRUE;
    }

    private function formatTweet($oItem) {

        //should get this by API (GET /help/configuration ->short_url_length) but it rarely changes
        $iMaxTweetLength = 140;
        $iShortUrlLength = 22;   //NB: 1 char more for https links
        $iMediaUrlLength = 23;

        $sTweet = $this->aTweetSettings['sFormat'];

        //replace all non-truncated fields
        foreach ($this->aTweetSettings['aVars'] as $aVar) {
            if (empty($aVar['bTruncate']) || $aVar['bTruncate'] == FALSE) {

                $sVarValue = $aVar['sValue'];
                $sValue = '';

                //get variable value from xml object
                $sValue = $this->getVariable($oItem, $aVar);

                //if field is image AND bAttachImage is TRUE, don't put the image url in the tweet since it will be included as a pic.twitter.com link
                if (!empty($aVar['bAttachImage']) && $aVar['bAttachImage'] == TRUE && $this->sMediaId) {
                    $sValue = '';
                }

                $sTweet = str_replace($aVar['sVar'], $sValue, $sTweet);
            }
        }

        //determine maximum length left over for truncated field (links are shortened to t.co format of max 22 chars)
        $sTempTweet = preg_replace('/http:\/\/\S+/', str_repeat('x', $iShortUrlLength), $sTweet);
        $sTempTweet = preg_replace('/https:\/\/\S+/', str_repeat('x', $iShortUrlLength + 1), $sTempTweet);
        $iTruncateLimit = $iMaxTweetLength - strlen($sTempTweet);

        //replace truncated field
        foreach ($this->aTweetSettings['aVars'] as $aVar) {
            if (!empty($aVar['bTruncate']) && $aVar['bTruncate'] == TRUE) {

                //placeholder will get replaced, so add that to char limit
                $iTruncateLimit += strlen($aVar['sVar']);

                //if media is present, substract that plus a space
                if ($this->sMediaId) {
                    $iTruncateLimit -= ($iMediaUrlLength + 1);
                }

                $sVarValue = $aVar['sValue'];
                $sText = '';

                //get text to replace placeholder with from xml object
                if (!empty($oItem->$sVarValue)) {
                    $sText = html_entity_decode($oItem->$sVarValue, ENT_QUOTES, 'UTF-8');

                    //get length of text with url shortening
                    $sTempText = preg_replace('/http:\/\/\S+/', str_repeat('x', $iShortUrlLength), $sText);
                    $sTempText = preg_replace('/https:\/\/\S+/', str_repeat('x', $iShortUrlLength + 1), $sTempText);
                    $iTextLength = strlen($sTempText);

                    //if text with url shortening falls under limit, keep it - otherwise truncate
                    if ($iTextLength <= $iTruncateLimit) {
                        $sTweet = str_replace($aVar['sVar'], $sText, $sTweet);
                    } else {
                        $sTweet = str_replace($aVar['sVar'], substr($sText, 0, $iTruncateLimit), $sTweet);
                    }
                } else {
                    $sTweet = str_replace($aVar['sVar'], '', $sTweet);
                }

                //only 1 truncated field allowed
                break;
            }
        }

        return $sTweet;
    }

    private function getVariable($oItem, $aVar) {

        $sVarValue = $aVar['sValue'];
        if (!empty($oItem->$sVarValue)) {

            $sValue = $oItem->$sVarValue;

            //if set, and it matches, apply regex to value
            if (!empty($aVar['sRegex'])) {
                if (preg_match($aVar['sRegex'], $sValue, $aMatches)) {
                    $sValue = $aMatches[1];
                } else {
                    $sValue = (!empty($aVar['sDefault']) ? $aVar['sDefault'] : '');
                }
            }

        } elseif (substr($aVar['sValue'], 0, 8) == 'special:') {
            $sValue = $this->getSpecialVar($aVar['sValue'], $aVar['sSubject'], $oItem);

        } else {
            $sValue = '';
        }

        return $sValue;
    }

    //special variables specific to certain rss feeds
    private function getSpecialVar($sValue, $sSubject, $oItem) {

        //get the value of the variable we're working on
        $sText = '';
        $bAttachImage = FALSE;
        foreach ($this->aTweetSettings['aVars'] as $aVar) {
            if ($aVar['sVar'] == $sSubject) {

                //get value
                $sText = $this->getVariable($oItem, $aVar);

                //check if 'attach if image' option is set
                if (!empty($aVar['bAttachImage']) && $aVar['bAttachImage']) {
                    $bAttachImage = TRUE;
                }
                break;
            }
        }

        if ($sText) {

            $sResult = '';
            switch($sValue) {
                //determines type of linked resource in reddit post
                case 'special:redditmediatype':

                    //get self subreddit from url
                    $sSelfReddit = '';
                    if (preg_match('/reddit\.com\/r\/(.+?)\//i', $this->sUrl, $aMatches)) {
                        $sSelfReddit = $aMatches[1];
                    }

                    if ($sSelfReddit && stripos($sText, $sSelfReddit) !== FALSE) {
                        $sResult = 'self';
                    } elseif (preg_match('/reddit\.com/i', $sText)) {
                        $sResult = 'internal';
                    } elseif (preg_match('/\.png|\.gif$|\.jpe?g/i', $sText)) {
                        $sResult = 'image';
                        if ($bAttachImage) {
                            $this->uploadImage($sText);
                        }
                    } elseif (preg_match('/imgur\.com\/a\/|imgur\.com\/.[^\/]/i', $sText)) {
                        $sResult = 'gallery';
                    } elseif (preg_match('/\.gifv|youtube\.com\/|youtu\.be\/|vine\.co\/|vimeo\.com\/|liveleak\.com\//i', $sText)) {
                        $sResult = 'video';
                    } else {
                        $sResult = 'external';
                    }
                    break;
            }

            return $sResult;
        } else {
            return FALSE;
        }
    }

    private function uploadImage($sImage) {

        $sImageBinary = base64_encode(file_get_contents($sImage));
        if ($sImageBinary && (
                (preg_match('/\.gif/i', $sImage) && strlen($sImageBinary) < 3 * 1024^2) ||      //max size is 3MB for gif
                (preg_match('/\.png|\.jpe?g/i', $sImage) && strlen($sImageBinary) < 5 * 1024^2) //max size is 5MB for png or jpeg
                )) {
            $oRet = $this->oTwitter->upload('media/upload', array('media' => $sImageBinary));
            if (isset($oRet->errors)) {
                $this->logger(2, sprintf('Twitter API call failed: media/upload (%s)', $oRet->errors[0]->message));
                $this->halt('- Error: ' . $oRet->errors[0]->message . ' (code ' . $oRet->errors[0]->code . ')');
                return FALSE;
            } else {
                $this->sMediaId = $oRet->media_id_string;
                printf("- uploaded %s to attach to next tweet\n", $sImage);
            }

            return TRUE;
        }

        return FALSE;
    }

    private function halt($sMessage = '') {
        echo $sMessage . "\n\nDone!\n\n";
        return FALSE;
    }

    private function logger($iLevel, $sMessage) {

        $sLogLine = "%s [%s] %s\n";
        $sTimestamp = date('Y-m-d H:i:s');

        switch($iLevel) {
        case 1:
            $sLevel = 'FATAL';
            break;
        case 2:
            $sLevel = 'ERROR';
            break;
        case 3:
            $sLevel = 'WARN';
            break;
        case 4:
        default:
            $sLevel = 'INFO';
            break;
        case 5:
            $sLevel = 'DEBUG';
            break;
        case 6:
            $sLevel = 'TRACE';
            break;
        }

        $iRet = file_put_contents(MYPATH . '/' . $this->sLogFile, sprintf($sLogLine, $sTimestamp, $sLevel, $sMessage), FILE_APPEND);

        if ($iRet === FALSE) {
            die($sTimestamp . ' [FATAL] Unable to write to logfile!');
        }
    }
}
