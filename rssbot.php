<?php
require_once('twitteroauth.php');
require_once('logger.php');

/*
 * TODO:
 * - BUG: tweets with domain names in them are too long since the shortened url is longer than the domain name
 * . attach videos/gifs to tweets if possible
 * - pick up og:image meta tag content for tumblr (and maybe others) and attach image
 * - pick up og:video on imgur.com ? if present it's animated gif
 * v change aMediaIds array so naked image urls, image pages, instagram photos aren't included in tweets, but gallery urls and instagram accounts are
 */

//runs every 15 minutes, mirroring & attaching images might take a while
set_time_limit(15 * 60);

class RssBot {

    private $oTwitter;
    private $sUsername;
    private $sLogFile;
    private $iLogLevel = 3; //increase for debugging

    private $sUrl;
    private $sTweetFormat;
    private $aTweetVars;

    private $aMediaIds = array();

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
		$this->sFeedFormat = (!empty($aArgs['sFeedFormat']) ? $aArgs['sFeedFormat'] : 'xml');

        $this->aTweetSettings = array(
            'sFormat'       => (isset($aArgs['sTweetFormat']) ? $aArgs['sTweetFormat'] : ''),
            'aVars'         => (isset($aArgs['aTweetVars']) ? $aArgs['aTweetVars'] : array()),
            'sTimestampField' => (isset($aArgs['sTimestampField']) ? $aArgs['sTimestampField'] : 'pubDate'),
        );

		$this->aFilters = (isset($aArgs['aFilters']) ? $aArgs['aFilters'] : array());

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

		if ($this->sFeedFormat == 'json') {

			$this->oFeed = json_decode(file_get_contents($this->sUrl));

		} else {

			$this->oFeed = simplexml_load_file($this->sUrl);
		}

        return ($this->oFeed ? TRUE : FALSE);
    }

    private function postTweets() {

        try {
            $oNewestItem = FALSE;
            $sTimestampVar = $this->aTweetSettings['sTimestampField'];
            $aLastSearch = json_decode(@file_get_contents(MYPATH . '/' . sprintf($this->sLastRunFile, 1)), TRUE);
            $this->logger(5, sprintf('Starting postTweets() on %d items in XML feed, last search was %s', count($this->oFeed->data->children), date('Y-m-d H:i:s', $aLastSearch['timestamp'])));

            $i = 0;
			foreach ($this->oFeed->data->children as $oItem) {
                $i++;
				$oItem = $oItem->data;

                //save newest item to set timestamp for next run
                if (!$oNewestItem || $oItem->$sTimestampVar > $oNewestItem->$sTimestampVar) {
                    $this->logger(5, sprintf('Item %d has timestamp %s, currently newest item', $i, date('Y-m-d H:i:s', $oItem->$sTimestampVar)));
                    $oNewestItem = $oItem;
                }

                //don't tweet items we've already done last in run
                if (!empty($oItem->$sTimestampVar)) {
                    if ($oItem->$sTimestampVar <= $aLastSearch['timestamp']) {
                        $this->logger(5, sprintf('Item %d is older (%s) than newest item from last run, skipping', $i, date('Y-m-d H:i:s', $oItem->$sTimestampVar)));
                        continue;
                    }
                }

                //convert xml item into tweet
                $sTweet = $this->formatTweet($oItem);
                if (!$sTweet) {
                    continue;
                }
                $this->logger(5, sprintf('Formatted item %d into tweet "%s"', $i, $sTweet));

                printf("- %s\n", utf8_decode($sTweet) . ' - ' . $oItem->$sTimestampVar);

				//post tweet, attach image if present
                if ($this->aMediaIds) {
                    $this->logger(5, sprintf('Posting item %d (with %d pictures attached) to Twitter', $i, count($this->aMediaIds)));
                    $oRet = $this->oTwitter->post('statuses/update', array('status' => $sTweet, 'trim_users' => TRUE, 'media_ids' => implode(',', $this->aMediaIds)));
                    $this->aMediaIds = array();
                } else {
                    $this->logger(5, sprintf('Posting item %d to Twitter', $i));
                    $oRet = $this->oTwitter->post('statuses/update', array('status' => $sTweet, 'trim_users' => TRUE));
                }
                if (isset($oRet->errors)) {
                    $this->logger(2, sprintf('Twitter API call failed: statuses/update (%s)', $oRet->errors[0]->message), array('object' => serialize($oItem), 'tweet' => $sTweet));
                    $this->halt('- Error: ' . $oRet->errors[0]->message . ' (code ' . $oRet->errors[0]->code . ')');
                    //return FALSE;
                }
            }

            //save timestamp of last item to disk
            if (!empty($oNewestItem->$sTimestampVar)) {
                if($oNewestItem->$sTimestampVar > $aLastSearch['timestamp']) {
                    $this->logger(5, sprintf('Saving timestamp %s of newest item to disk', date('Y-m-d H:i:s', $oNewestItem->$sTimestampVar)));
                    $aLastItem = array(
                        'timestamp' => $oNewestItem->$sTimestampVar,
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
            $this->logger(5, sprintf('[Exception] Saving timestamp %s of newest item to disk', date('Y-m-d H:i:s', $oNewestItem->$sTimestampVar)));
            if (!empty($oNewestItem->$sTimestampVar)) {
                $aLastItem = array(
                    'timestamp' => $oNewestItem->$sTimestampVar,
                );
                file_put_contents(MYPATH . '/' . $this->sLastRunFile, json_encode($aLastItem));
            }

            return FALSE;
        }

        return TRUE;
    }

    private function formatTweet($oItem) {

        //should get this by API (GET /help/configuration ->short_url_length) but it rarely changes
        $iMaxTweetLength = 280;
        $iShortUrlLength = 23;
        $iMediaUrlLength = 23;

        $sTweet = $this->aTweetSettings['sFormat'];

        //replace all non-truncated fields
        foreach ($this->aTweetSettings['aVars'] as $aVar) {
            if (empty($aVar['bTruncate']) || $aVar['bTruncate'] == FALSE) {

                $sVarValue = $aVar['sValue'];
                $sValue = '';

                //get variable value from xml object
                $sValue = $this->getVariable($oItem, $aVar);

				//apply filters, if any
				if ($this->aFilters) {
					foreach ($this->aFilters as $sFilter) {
						//check if it's a regex
						if (preg_match('/^\/.+\/i?$/', $sFilter)) {
							if (preg_match($sFilter, $sValue)) {
								//skip tweet
								return FALSE;
							}
						} else {
							if (strpos($sValue, $sFilter) !== FALSE) {
								//skip tweet
								return FALSE;
							}
						}
					}
				}

				if (!empty($aVar['sPrefix'])) {
					$sValue = $aVar['sPrefix'] . $sValue;
				}

                //if field is image AND bAttachImage is TRUE, don't put the image url in the tweet since it will be included as a pic.twitter.com link
                if (!empty($aVar['bAttachImage']) && $aVar['bAttachImage'] == TRUE && isset($this->aMediaIds[$sValue])) {
                    $sValue = '';
                }

				//if text contains @, replace with #
                if (strpos($sValue, '@') !== FALSE) {
                    $sValue = str_replace($sValue, '@', '#');
                }

                $sTweet = str_replace($aVar['sVar'], $sValue, $sTweet);
            }
        }

        //determine maximum length left over for truncated field (links are shortened to t.co format of 23 chars, always https, plus a space)
        $sTempTweet = preg_replace('/http:\/\/\S+/', str_repeat('x', $iShortUrlLength + 1), $sTweet);
        $sTempTweet = preg_replace('/https:\/\/\S+/', str_repeat('x', $iShortUrlLength + 1), $sTempTweet);

		//June 2016: domain names without http(s) are now also shortened, taking up more space
		$sTempTweet = preg_replace('/\S+\.(?:com|org|net|edu|info|biz|tv|co\.uk|de|nl)/', str_repeat('x', $iShortUrlLength + 1), $sTempTweet);

        $iTruncateLimit = $iMaxTweetLength - strlen($sTempTweet);

        //replace truncated field
        foreach ($this->aTweetSettings['aVars'] as $aVar) {
            if (!empty($aVar['bTruncate']) && $aVar['bTruncate'] == TRUE) {

                //placeholder will get replaced, so add that to char limit
                $iTruncateLimit += strlen($aVar['sVar']);

                //if media is present, substract that plus a space
                if ($this->aMediaIds) {
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

                    //if text contains @, replace with #
                    if (strpos($sValue, '@') !== FALSE) {
                        $sValue = str_replace($sValue, '@', '#');
                    }

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
						//naked image url
                        $sResult = 'image';
                        if ($bAttachImage) {
                            $this->uploadImageToTwitter($sText);
                        }
                    } elseif (preg_match('/reddituploads\.com/i', $sText)) {
                        //reddit hosted file
                        $sResult = 'image';
                        if ($bAttachImage) {
                            $this->uploadImageToTwitter($sText);
                        }
					} elseif (preg_match('/imgur\.com\/.[^\/]/i', $sText) || preg_match('/imgur\/.com\/gallery\//', $sText)) {
						//single image on imgur.com page
						$sResult = 'image';
						if ($bAttachImage) {
							$this->uploadImageFromPage($sText);
						}
                    } elseif (preg_match('/imgur\.com\/a\//i', $sText)) {
						//multiple images on imgur.com page
                        $sResult = 'gallery';
						if ($bAttachImage) {
							$this->uploadImageFromGallery($sText);
						}
					} elseif (preg_match('/instagram\.com\/.[^\/]/i', $sText) || preg_match('/instagram\.com\/p\//i', $sText)) {
						//instagram account or instagram photo
						$sResult = 'instagram';
						if ($bAttachImage) {
							$this->uploadImageFromInstagram($sText);
						}
					/*} elseif (preg_match('/gfycat\.com\//i', $sText)) {
						$sResult = 'gif';
						if ($bAttachImage) {
							$this->uploadVideoFromGfycat($sText);
						}*/

                    } elseif (preg_match('/\.gifv|\.webm|youtube\.com\/|youtu\.be\/|vine\.co\/|vimeo\.com\/|liveleak\.com\//i', $sText)) {
                        $sResult = 'video';
                    } elseif (preg_match('/pornhub\.com|xhamster\.com/i', $sText)) {
                        //porn video hosting websites
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

	private function uploadImageFromGallery($sUrl) {

		//imgur implements meta tags that indicate to twitter which urls to use for inline preview
		//so we're going to use those same meta tags to determine which urls to upload
		//format: <meta name="twitter:image[0-3]:src" content="http://i.imgur.com/[a-zA-Z0-9].ext"/>

		//march 2016: imgur changed their meta tags, only the first (or random?) image is listed
		$aImageUrls = array();

		//fetch twitter meta tag values, up to 4
		libxml_use_internal_errors(TRUE);
		$oDocument = new DOMDocument();
		$oDocument->preserveWhiteSpace = FALSE;
		$oDocument->loadHTML(file_get_contents($sUrl));

		$oXpath = new DOMXpath($oDocument);
		$oMetaTags = $oXpath->query('//meta[contains(@name,"twitter:image")]');
		foreach ($oMetaTags as $oTag) {
			$aImageUrls[] = $oTag->getAttribute('content');

			if (count($aImageUrls) == 4) {
				break;
			}
		}

		//if we have at least one image, upload it to attach to tweet
		if ($aImageUrls) {

			foreach ($aImageUrls as $sImage) {
				$this->uploadImageToTwitter($sImage);
			}

			return TRUE;
		}

		return FALSE;
	}

	private function uploadImageFromPage($sUrl) {

		//imgur implements meta tags that indicate to twitter which urls to use for inline preview
		//so we're going to use those same meta tags to determine which urls to upload
		//format: <meta name="twitter:image:src" content="http://i.imgur.com/[a-zA-Z0-9].ext"/>

		//fetch image from twitter meta tag
		libxml_use_internal_errors(TRUE);
		$oDocument = new DOMDocument();
		$oDocument->preserveWhiteSpace = FALSE;
		$oDocument->loadHTML(file_get_contents($sUrl));

		$sImage = '';
		$oXpath = new DOMXpath($oDocument);
		$oMetaTags = $oXpath->query('//meta[@name="twitter:image:src"]');
		foreach ($oMetaTags as $oTag) {
			$sImage = $oTag->getAttribute('content');
			break;
		}

		//march 2016: imgur changed their meta tags
		if (empty($sImage)) {
			$oMetaTags = $oXpath->query('//meta[@name="twitter:image"]');
			foreach ($oMetaTags as $oTag) {
				$sImage = $oTag->getAttribute('content');
				break;
			}
		}

		if (!empty($sImage)) {
			//we want the page url truncated from the tweet, so use it as the index name
			return $this->uploadImageToTwitter($sImage, $sUrl);
		}

		return FALSE;
	}

	private function uploadImageFromInstagram($sUrl) {

		//instagram implements og:image meta tag listing exact url of image
		//this works on both account pages (tag contains user avatar) and photo pages (tag contains photo url)

		//we want instagram photo urls to be truncated from the tweet, but not instagram account urls
		if (preg_match('/instagram\.com\/p\//i', $sUrl)) {
			//custom name equal to original url
			$sName = $sUrl;
		} else {
			//use url as index name
			$sName = FALSE;
		}

		//fetch image from twitter meta tag
		libxml_use_internal_errors(TRUE);
		$oDocument = new DOMDocument();
		$oDocument->preserveWhiteSpace = FALSE;
		$oDocument->loadHTML(file_get_contents($sUrl));

		$oXpath = new DOMXpath($oDocument);
		$oMetaTags = $oXpath->query('//meta[@property="og:image"]');
		foreach ($oMetaTags as $oTag) {
			$sImage = $oTag->getAttribute('content');
			break;
		}

		if (!empty($sImage)) {
			return $this->uploadImageToTwitter($sImage, $sName);
		}

		return FALSE;
	}

	private function uploadVideoFromGfycat($sUrl) {

		//construct json info url
		$sJsonUrl = str_replace('gfycat.com/', 'gfycat.com/cajax/get/', $sUrl);
		if ($sJsonUrl == $sUrl) {
			return FALSE;
		}

		$oGfycatInfo = @json_decode(file_get_contents($sJsonUrl));
		if ($oGfycatInfo && !empty($oGfycatInfo->gfyItem->webpUrl)) { 
			return $this->uploadVideoToTwitter($oGfycatInfo->gfyItem->webpUrl);
		}

		return FALSE;
	}

	private function uploadVideoToTwitter($sVideo, $sName = FALSE) {

		if (!$sName) {
			$sName = $sVideo;
		}

		$sVideoBinary = file_get_contents($sVideo);
		if (strlen($sVideoBinary) < 5 * pow(1024, 2)) {

			$oRet = $this->oTwitter->upload('media/upload', array('media' => $sVideoBinary));
			var_dump(strlen($sVideoBinary), $oRet);
			die();
		}
	}

	private function uploadImageToTwitter($sImage, $sName = FALSE) {

		if (!$sName) {
			$sName = $sImage;
		}

        //make sure file is not too large before it makes us run out of memory
        $sImageBinarySize = $this->curl_get_file_size($sImage);
        if ($sImageBinarySize > 3 * pow(1024, 2)) {
            return FALSE;
        }

		//upload image and save media id to attach to tweet
		$sImageBinary = base64_encode(file_get_contents($sImage));
		if ($sImageBinary && strlen($sImageBinary) < 3 * pow(1024, 2)) { //max size is 3MB

			$oRet = $this->oTwitter->upload('media/upload', array('media' => $sImageBinary));
			if (isset($oRet->errors)) {
				$this->logger(2, sprintf('Twitter API call failed: media/upload (%s)', $oRet->errors[0]->message), array('image' => $sImage, 'size' => strlen($sImageBinary)));
				$this->halt('- Error: ' . $oRet->errors[0]->message . ' (code ' . $oRet->errors[0]->code . ')');
				return FALSE;
			} else {
				//the index used here is the image url by default, and a later check removes the url from the
				//tweet if it's equal to the index. if it needs to stay in the tweet, give a custom index.
				$this->aMediaIds[$sName] = $oRet->media_id_string;
				printf("- uploaded %s to attach to next tweet\n", $sImage);
			}

			return TRUE;
		}

		return FALSE;
	}

    private function curl_get_file_size( $url ) {
        // Assume failure.
        $result = -1;

        $curl = curl_init( $url );

        // Issue a HEAD request and follow any redirects.
        curl_setopt( $curl, CURLOPT_NOBODY, true );
        curl_setopt( $curl, CURLOPT_HEADER, true );
        curl_setopt( $curl, CURLOPT_RETURNTRANSFER, true );
        curl_setopt( $curl, CURLOPT_FOLLOWLOCATION, true );
        //curl_setopt( $curl, CURLOPT_USERAGENT, get_user_agent_string() );

        $data = curl_exec( $curl );
        curl_close( $curl );

        if( $data ) {
            $content_length = "unknown";
            $status = "unknown";

            if( preg_match( "/^HTTP\/1\.[01] (\d\d\d)/", $data, $matches ) ) {
                $status = (int)$matches[1];
            }

            if( preg_match( "/Content-Length: (\d+)/", $data, $matches ) ) {
                $content_length = (int)$matches[1];
            }

            // http://en.wikipedia.org/wiki/List_of_HTTP_status_codes
            if( $status == 200 || ($status > 300 && $status <= 308) ) {
                $result = $content_length;
            }
        }

        return $result;
    }

    private function halt($sMessage = '') {
        echo $sMessage . "\n\nDone!\n\n";
        return FALSE;
    }

    private function logger($iLevel, $sMessage, $aExtra = array()) {

        if ($iLevel > $this->iLogLevel) {
            return FALSE;
        }

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

		$aBacktrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
		TwitterLogger::write($this->sUsername, $sLevel, $sMessage, pathinfo($aBacktrace[0]['file'], PATHINFO_BASENAME), $aBacktrace[0]['line'], $aExtra);

        $iRet = file_put_contents(MYPATH . '/' . $this->sLogFile, sprintf($sLogLine, $sTimestamp, $sLevel, $sMessage), FILE_APPEND);

        if ($iRet === FALSE) {
            die($sTimestamp . ' [FATAL] Unable to write to logfile!');
        }
    }
}
