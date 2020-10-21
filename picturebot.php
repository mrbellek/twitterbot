<?php
require_once('twitteroauth.php');
require_once('logger.php');

//runs every 15 minutes, mirroring & attaching images might take a while
set_time_limit(15 * 60);

class PictureBot {

	private $sUsername;			//username we will be tweeting from
	private $sSettingsFile;		//settings file to cache file list and store postcount
	private $sSettingsExtraFile;//settings file with additional metadata info on files
	private $sPictureFolder;	//folder with images
	private $aPictureIndex;		//cached index of pictures
	private $sMediaId;			//media id of uploaded picture
	private $iMaxIndexAge;		//max age of picture index

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
		$this->sSettingsExtraFile = (!empty($aArgs['sSettingsFile']) ? $aArgs['sSettingsFile'] : strtolower($this->sUsername) . '-extra.json');

		$this->sPictureFolder = (!empty($aArgs['sPictureFolder']) ? $aArgs['sPictureFolder'] : '.');
		$this->iMaxIndexAge = (!empty($aArgs['iMaxIndexAge']) ? $aArgs['iMaxIndexAge'] : 3600 * 24);

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

			//build picture index
			$this->getIndex();

			//fetch random file
			if ($aFile = $this->getFile()) {
                $aFile = $this->getExtraFileInfo($aFile);

				//upload picture
				if ($this->uploadPicture($aFile['filepath'])) {

					//format and post message
					if ($this->postMessage($aFile)) {

						$this->halt('Done.');
					}

                    $this->updatePostCount($aFile);
				}
			}
		}
	}

	private function getIdentity() {

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
		if (is_file(MYPATH . DS . $this->sSettingsFile) && filesize(MYPATH . DS . $this->sSettingsFile) > 0 && filemtime(MYPATH . DS . $this->sSettingsFile) + $this->iMaxIndexAge < time()) {

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

				//merge with existing index
				if (is_file(MYPATH . DS . $this->sSettingsFile) && filesize(MYPATH . DS . $this->sSettingsFile) > 0) {

					$aOldPictureIndex = json_decode(file_get_contents(MYPATH . DS . $this->sSettingsFile), TRUE);
					foreach ($aOldPictureIndex as $sFile => $iPostcount) {

						//carry over postcount from existing files
						if (isset($this->aPictureIndex[$sFile])) {
							$this->aPictureIndex[$sFile] = $iPostcount;
						}
					}
				}
				file_put_contents(MYPATH . DS . $this->sSettingsFile, json_encode($this->aPictureIndex));

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

			//create temp array of all files that have postcount = 0 
			$aTempIndex = array_filter($this->aPictureIndex, function($i) { return ($i == 0 ? TRUE : FALSE); });

			//pick random file
			$sFilename = array_rand($aTempIndex);
		} else {
			echo "Getting random file with lowest postcount from folder..\n";

			//get lowest postcount in index
			global $iLowestCount;
			$iLowestCount = FALSE;
			foreach ($this->aPictureIndex as $sFilename => $iCount) {
				if ($iLowestCount === FALSE || $iCount < $iLowestCount) {
					$iLowestCount = $iCount;
				}
			}

			//create temp array of files with lowest postcount
			$aTempIndex = array_filter($this->aPictureIndex, function($i) { global $iLowestCount; return ($i == $iLowestCount ? TRUE : FALSE); });

			//pick random file
			$sFilename = array_rand($aTempIndex);
		}

		$sFilePath = $this->sPictureFolder . DS . utf8_decode($sFilename);
		$aImageInfo = getimagesize($sFilePath);

		$aFile = array(
			'filepath' => $sFilePath,
			'dirname' => pathinfo($sFilename, PATHINFO_DIRNAME),
			'filename' => $sFilename,
			'basename' => pathinfo($sFilePath, PATHINFO_FILENAME),
			'extension' => pathinfo($sFilePath, PATHINFO_EXTENSION),
			'size' => number_format(filesize($sFilePath) / 1024, 0) . 'k',
			'width' => $aImageInfo[0],
			'height' => $aImageInfo[1],
			'created' => date('Y-m-d', filectime($sFilePath)),
			'modified' => date('Y-m-d', filemtime($sFilePath)),
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
				$this->logger(2, sprintf('Twitter API call failed: statuses/update (%s)', $oRet->errors[0]->message), array('tweet' => $sTweet, 'file' => $aFile, 'media' => $this->sMediaId));
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
		$iMaxTweetLength = 280;
		$iShortUrlLength = 23;

		if (empty($this->aTweetSettings['sFormat'])) {
			$this->logger(2, 'Tweet format settings missing.');
			$this->halt('- The tweet format settings are missing, halting.');
			return FALSE;
		}

		//construct tweet
		$sTweet = $this->aTweetSettings['sFormat'];

		//replace all non-truncated fields
		$aFile['filename'] = $aFile['filename'];
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
        printf("Uploading to Twitter: %s\n", $sImage);
		$sImageBinary = base64_encode(file_get_contents($sImage));
		if ($sImageBinary && strlen($sImageBinary) < 15 * pow(1024, 2)) { //max size is 15MB

			$oRet = $this->oTwitter->upload('media/upload', array('media' => $sImageBinary));
			if (isset($oRet->errors)) {
				$this->logger(2, sprintf('Twitter API call failed: media/upload (%s)', $oRet->errors[0]->message), array('file' => $sImage, 'length' => strlen($sImageBinary)));
				$this->halt('- Error: ' . $oRet->errors[0]->message . ' (code ' . $oRet->errors[0]->code . ')');
				return FALSE;
			} else {
				$this->sMediaId = $oRet->media_id_string;
				printf("- uploaded %s to attach to next tweet\n", $sImage);
			}

			return TRUE;
        } else {
            printf("- picture is too large!\n");
		}

		return FALSE;

	}

    private function getExtraFileInfo($aFile) {

        if (!is_readable($this->sSettingsExtraFile)) {
            return $aFile;
        }

        try {
            $oExtra = json_decode(file_get_contents($this->sSettingsExtraFile));
        } catch (\Exception $e) {
            $this->logger(3, sprintf('Failed to read or parse extra settings file at "%s"', $this->sSettingsExtraFile));
            return $aFile;
        }

        if (!empty($oExtra->{$aFile['filename']})) {
            foreach (get_object_vars($oExtra->{$aFile['filename']}) as $var => $value) {
                if (!isset($aFile[$var])) {
                    $aFile[$var] = $value;
                } else {
                    printf('WARNING: Skipping extra info var "%s" because it already exists.', $var);
                    $this->logger(3, sprintf('Skipping extra info var "%s" because it already exists.', $var));
                }
            }
        }

        return $aFile;
    }

	private function updatePostCount($aFile) {

		$this->aPictureIndex[$aFile['filename']]++;
		file_put_contents(MYPATH . DS . $this->sSettingsFile, json_encode($this->aPictureIndex));

		return TRUE;
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
