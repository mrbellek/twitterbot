<?php
require_once('twitteroauth.php');

/*
 * TODO:
 * - for bPostOnlyOnce=TRUE, notification when no more tweets available
 * - commands through mentions, replies through mentions/DMs like retweetbot
 * - fix unicode bug (??)
 * - update picture index cache periodically
 */

//runs every 15 minutes, mirroring & attaching images might take a while
set_time_limit(15 * 60);

class PictureBot {

	private $sUsername;			//username we will be tweeting from
	private $sSettingsFile;		//settings file to cache file list and store postcount
	private $sPictureFolder;	//folder with images
	private $aPictureIndex;		//cached index of pictures
	private $sMediaId;			//media id of uploaded picture

	private $sLogFile;			//where to log stuff
    private $iLogLevel = 3;     //increase for debugging

	private $aTweetSettings;	//tweet format settings

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

		define('DS', DIRECTORY_SEPARATOR);
	}

	private function parseArgs($aArgs) {

		$this->sUsername = (!empty($aArgs['sUsername']) ? $aArgs['sUsername'] : '');
        $this->bReplyToCmds = (!empty($aArgs['bReplyToCmds']) ? $aArgs['bReplyToCmds'] : FALSE);
		$this->sSettingsFile = (!empty($aArgs['sSettingsFile']) ? $aArgs['sSettingsFile'] : strtolower($this->sUsername) . '.json');

		$this->sPictureFolder = (!empty($aArgs['sPictureFolder']) ? $aArgs['sPictureFolder'] : '.');

		//stuff to determine what we're tweeting
		$this->aTweetSettings = array(
			'sFormat'		=> (isset($aArgs['sTweetFormat']) ? $aArgs['sTweetFormat'] : ''),
            'bPostOnlyOnce' => (isset($aArgs['bPostOnlyOnce']) ? $aArgs['bPostOnlyOnce'] : FALSE),
		);

		$this->sLogFile			= (!empty($aArgs['sLogFile'])		? $aArgs['sLogFile']			: strtolower($this->sUsername) . '.log');

		if ($this->sLogFile == '.log') {
			$this->sLogFile = pathinfo($_SERVER['SCRIPT_FILENAME'], PATHINFO_FILENAME) . '.log';
		}
	}

	public function run() {

		//verify current twitter user is correct
		if ($this->getIdentity()) {

            //check messages & reply if needed
            if ($this->bReplyToCmds) {
                $this->checkMentions();
            }

			$this->getIndex();
			die(var_dumP($this->aPictureIndex));

			//fetch random file
			if ($aFile = $this->getFile()) {

				//upload picture
				if ($this->uploadPicture($aFile['filepath'])) {

					//format and post message
					if ($this->postMessage($aFile)) {

						$this->updatePostCount($aFile);

						$this->halt('Done.');
					}
				}
			}
		}
	}

	private function getIdentity() {

		//DEBUG
		return TRUE;

		echo "Fetching identify..\n";

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

	private function getIndex() {

		//read file from disk if present from prev run
		//TODO: rescan every 24h or so
		if (is_file(MYPATH . DS . $this->sSettingsFile) && filesize(MYPATH . DS . $this->sSettingsFile) > 0) {

			$this->aPictureIndex = json_decode(file_get_contents(MYPATH . DS . $this->sSettingsFile), TRUE);

			return TRUE;

		} else {

			//get list of all files
			$aFileList = $this->recursiveScan($this->sPictureFolder);
			if ($aFileList) {
				natcasesort($aFileList);

				//convert list into keys of array with postcount
				$this->aPictureIndex = array();
				foreach ($aFileList as $sFile) {
					$this->aPictureIndex[utf8_encode($sFile)] = 0;
				}
				unset($this->aPictureIndex['.']);
				unset($this->aPictureIndex['..']);

				file_put_contents(MYPATH . DS . $this->sSettingsFile, json_encode($this->aPictureIndex, JSON_PRETTY_PRINT));

				return TRUE;
			}

			return FALSE;
		}
	}

	private function recursiveScan($sFolder) {

		$aFiles = scandir($sFolder);

		foreach ($aFiles as $key => $sFile) {

			if (is_dir($sFolder . DS . $sFile) && !in_array($sFile, array('.', '..'))) {
				unset($aFiles[$key]);
				$aSubFiles = $this->recursiveScan($sFolder . DS . $sFile);
				foreach ($aSubFiles as $sSubFile) {
					if (!in_array($sSubFile, array('.', '..'))) {
						$aFiles[] = $sFile . DS . $sSubFile;
					}
				}
			}
		}

		return $aFiles;
	}

	private function getFile() {

        if ($this->aTweetSettings['bPostOnlyOnce'] == TRUE) {
			echo "Getting random unposted file from folder..\n";
			$aTempIndex = array_filter($this->aPictureIndex, function($i) { return ($i == 0 ? TRUE : FALSE); });
			$sFilename = array_rand($aTempIndex);
		} else {
			echo "Getting random file from folder..\n";
			$sFilename = array_rand($this->aPictureIndex);
		}

		$sFilename = $this->sPictureFolder . DS . $sFilename;
		$aImageInfo = getimagesize($sFilename);

		$aFile = array(
			'filename' => pathinfo($sFilename, PATHINFO_BASENAME),
			'filepath' => $sFilename,
			'size' => number_format(filesize($sFilename) / 1024, 0) . 'k',
			'width' => $aImageInfo[0],
			'height' => $aImageInfo[1],
			'created' => date('Y-m-d', filectime($sFilename)),
			'modified' => date('Y-m-d', filemtime($sFilename)),
		);

		return $aFile;
	}

	private function postMessage($aFile) {

		echo "Posting tweet..\n";

		//construct tweet
		$sTweet = $this->formatTweet($aFile);
		if (!$sTweet) {
			return FALSE;
		}

		//tweet
		if ($this->sMediaId) {
			$oRet = $this->oTwitter->post('statuses/update', array('status' => $sTweet, 'trim_users' => TRUE, 'media_ids' => $this->sMediaId));
			if (isset($oRet->errors)) {
				$this->logger(2, sprintf('Twitter API call failed: statuses/update (%s)', $oRet->errors[0]->message));
				$this->halt('- Error: ' . $oRet->errors[0]->message . ' (code ' . $oRet->errors[0]->code . ')');
				return FALSE;
			} else {
				printf("- %s\n", utf8_decode($sTweet));
			}

			return TRUE;

		} else {
			$this->logger(2, sprintf('Skipping tweet because picture was not uploaded: %s', $aFile['filename']));
		}
	}

	private function formatTweet($aFile) {

		//should get this by API (GET /help/configuration ->short_url_length) but it rarely changes
		$iMaxTweetLength = 140;
		$iShortUrlLength = 22;	//NB: 1 char more for https links

		if (empty($this->aTweetSettings['sFormat'])) {
			$this->logger(2, 'Tweet format settings missing.');
			$this->halt('- The tweet format settings are missing, halting.');
			return FALSE;
		}

		//construct tweet
		$sTweet = $this->aTweetSettings['sFormat'];

		//replace all non-truncated fields
		$aFile['filename'] = utf8_decode($aFile['filename']);
		foreach ($aFile as $sVar => $sValue) {
			$sTweet = str_replace(':' . $sVar, $sValue, $sTweet);
		}

		//determine maximum length left over for truncated field (links are shortened to t.co format of max 22 chars)
		$sTempTweet = preg_replace('/http:\/\/\S+/', str_repeat('x', $iShortUrlLength), $sTweet);
		$sTempTweet = preg_replace('/https:\/\/\S+/', str_repeat('x', $iShortUrlLength + 1), $sTempTweet);
		$iTruncateLimit = $iMaxTweetLength - strlen($sTweet);

		return $sTweet;
	}

	private function uploadPicture($sImage) {

		//upload image and save media id to attach to tweet
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

	private function updatePostCount($aFile) {

		$sFilename = str_replace(MYPATH . DS, '', $aFile['filepath']);

		$this->aPictureIndex[$sFilename]++;
		file_put_contents(MYPATH . DS . $this->sSettingsFile, json_encode($this->aPictureIndex, JSON_PRETTY_PRINT));

		return TRUE;
	}

    private function checkMentions() {

		$aLastSearch = json_decode(@file_get_contents(MYPATH . '/' . sprintf($this->sLastSearchFile, 1)), TRUE);
        printf("Checking mentions since %s for commands..\n", $aLastSearch['timestamp']);

        //fetch new mentions since last run
        $aMentions = $this->oTwitter->get('statuses/mentions_timeline', array(
            'count'         => 10,
			'since_id'		=> ($aLastSearch && !empty($aLastSearch['max_id']) ? $aLastSearch['max_id'] : 1),
        ));

        if (is_object($aMentions) && !empty($aMentions->errors[0]->message)) {
            $this->logger(2, sprintf('Twitter API call failed: GET statuses/mentions_timeline (%s)', $aMentions->errors[0]->message));
            $this->halt(sprintf('- Failed getting mentions, halting. (%s)', $aMentions->errors[0]->message));
        }

        //if we have mentions, get friends for auth (we will only respond to commands from people we follow)
        if (count($aMentions) > 0) {
            $oRet = $this->oTwitter->get('friends/ids', array('screen_name' => $this->sUsername, 'stringify_ids' => TRUE));
            if (!empty($oRet->errors[0]->message)) {
                $this->logger(2, sprintf('Twitter API call failed: GET friends/ids (%s)', $aMentions->errors[0]->message));
                $this->halt(sprintf('- Failed getting friends, halting. (%s)', $aMentions->errors[0]->message));
            }
            $aFollowing = $oRet->ids;

        } else {
            echo "- no new mentions.\n\n";
            return FALSE;
        }

        foreach ($aMentions as $oMention) {

            //only reply to friends (people we are following)
            if (in_array($oMention->user->id_str, $aFollowing)) {

                $bRet = $this->parseCommand($oMention);
                if (!$bRet) {
                    break;
                }
            }
        }
        printf("- replied to %d commands\n\n", count($aMentions));

        return TRUE;
    }

    private function parseCommand($oMention) {

        //reply to commands from friends (people we follow) in DMs
        $sId = $oMention->id_str;
        $sCommand = str_replace('@' . strtolower($this->sUsername) . ' ', '', strtolower($oMention->text));
        printf("Parsing command %s from %s..\n", $sCommand, $oMention->user->screen_name);

        switch ($sCommand) {
            case 'help':
                return $this->replyToCommand($oMention, 'Commands: help lastrun lastlog. Only replies to friends. Lag varies, be patient.');

            case 'lastrun':
                $aLastSearch = json_decode(@file_get_contents(MYPATH . '/' . sprintf($this->sLastSearchFile, 1)), TRUE);

                return $this->replyToCommand($oMention, sprintf('Last script run was: %s', (!empty($aLastSearch['timestamp']) ? $aLastSearch['timestamp'] : 'never')));

            case 'lastlog':
                $aLogFile = @file($this->sLogFile, FILE_IGNORE_NEW_LINES);

                return $this->replyToCommand($oMention, ($aLogFile ? $aLogFile[count($aLogFile) - 1] : 'Log file is empty'));

            case 'stats':

                $aStats = $this->getStats();

                return $this->replyToCommand($oMention, sprintf('Total records: %d, %d aren\'t posted yet.', $aStats['total'], $aStats['unposted']));

            default:
                echo "- command unknown.\n";
                return FALSE;
        }
    }

    private function replyToCommand($oMention, $sReply) {

        //check friendship between bot and command sender
        $oRet = $this->oTwitter->get('friendships/show', array('source_screen_name' => $this->sUsername, 'target_screen_name' => $oMention->user->screen_name));
        if (!empty($oRet->errors)) {
            $this->logger(2, sprintf('Twitter API call failed: GET friendships/show (%s)', $oRet->errors[0]->message));
            $this->halt(sprintf('- Failed to check friendship, halting. (%s)', $oRet->errors[0]->message));
            return FALSE;
        }

        //if we can DM the source of the command, do that
        if ($oRet->relationship->source->can_dm) {

            $oRet = $this->oTwitter->post('direct_messages/new', array('user_id' => $oMention->user->id_str, 'text' => substr($sReply, 0, 140)));

            if (!empty($oRet->errors)) {
                $this->logger(2, sprintf('Twitter API call failed: POST direct_messages/new (%s)', $oRet->errors[0]->message));
                $this->halt(sprintf('- Failed to send DM, halting. (%s)', $oRet->errors[0]->message));
                return FALSE;
            }

        } else {
            //otherwise, use public reply

            $oRet = $this->oTwitter->post('statuses/update', array(
                'in_reply_to_status_id' => $oMention->id_str,
                'trim_user' => TRUE,
                'status' => sprintf('@%s %s',
                    $oMention->user->screen_name,
                    substr($sReply, 0, 140 - 2 - strlen($oMention->user->screen_name))
                )
            ));

            if (!empty($oRet->errors)) {
                $this->logger(2, sprintf('Twitter API call failed: POST statuses/update (%s)', $oRet->errors[0]->message));
                $this->halt(sprintf('- Failed to reply, halting. (%s)', $oRet->errors[0]->message));
                return FALSE;
            }
        }

        printf("- Replied: %s\n", $sReply);
        return TRUE;
    }

    private function getStats() {

        $sth = $this->oPDO->prepare(sprintf('
            SELECT (
             SELECT COUNT(*) FROM %1$s
            ) AS total, (
             SELECT COUNT(*) FROM %1$s WHERE %2$s = 0
            ) AS unposted',
            $this->aDbSettings['sTable'],
            $this->aDbSettings['sCounterCol']
        ));
        if ($sth->execute() == FALSE) {
            $this->logger(2, sprintf('Stats query failed. (%d %s)', $stf->errorCode(), $sth->errorInfo()));
            $this->halt(sprintf('- Stats query failed, halting. (%d %s)', $sth->errorCode(), $sth->errorInfo()));
            return FALSE;
        }

        //get total number of records, as well as records with postcount 0
        if ($aStats = $sth->fetch(PDO::FETCH_ASSOC)) {
            return $aStats;
        } else {
            return array('total' => 0, 'unposted' => 0);
        }
    }

	private function halt($sMessage = '') {
		echo $sMessage . "\n\nDone!\n\n";
		return FALSE;
	}

	private function logger($iLevel, $sMessage) {

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

		$iRet = file_put_contents(MYPATH . '/' . $this->sLogFile, sprintf($sLogLine, $sTimestamp, $sLevel, $sMessage), FILE_APPEND);

		if ($iRet === FALSE) {
			die($sTimestamp . ' [FATAL] Unable to write to logfile!');
		}
	}
}
