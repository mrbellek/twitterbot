<?php
require_once('twitteroauth.php');

class TweetBot {

	private $sUsername;			//username we will be tweeting from

	private $sLogFile;			//where to log stuff

	private $aDbSettings;		//database query settings
	private $aTweetSettings;	//tweet format settings

	public function __construct($aArgs) {

		//connect to twitter
		$this->oTwitter = new TwitterOAuth(CONSUMER_KEY, CONSUMER_SECRET, ACCESS_TOKEN, ACCESS_TOKEN_SECRET);
		$this->oTwitter->host = "https://api.twitter.com/1.1/";

		//load args
		$this->parseArgs($aArgs);
	}

	private function parseArgs($aArgs) {

		$this->sUsername = (!empty($aArgs['sUsername']) ? $aArgs['sUsername'] : '');

		//stuff to determine what to get from database
		$this->aDbSettings = (!empty($aArgs['aDbVars']) ? $aArgs['aDbVars'] : array());

		//stuff to determine what we're tweeting
		$this->aTweetSettings = array(
			'sFormat'		=> (isset($aArgs['sTweetFormat']) ? $aArgs['sTweetFormat'] : ''),
			'aTweetVars'	=> (isset($aArgs['aTweetVars']) ? $aArgs['aTweetVars'] : array()),
		);

		$this->sLogFile			= (!empty($aArgs['sLogFile'])		? $aArgs['sLogFile']			: strtolower($this->sUsername) . '.log');

		if ($this->sLogFile == '.log') {
			$this->sLogFile = pathinfo($_SERVER['SCRIPT_FILENAME'], PATHINFO_FILENAME) . '.log';
		}
	}

	public function run() {

		//verify current twitter user is correct
		if ($this->getIdentity()) {

			//fetch database record
			if ($aRecord = $this->getRecord()) {

				//format and post message
				if ($this->postMessage($aRecord)) {

					$this->halt('Done.');
				}
			}
		}
	}

	private function getIdentity() {

		echo 'Fetching identify..<br>';

		if (!$this->sUsername) {
			$this->logger(2, 'No username');
			$this->halt('- No username! Set username when calling constructor.');
			return FALSE;
		}

		$oUser = $this->oTwitter->get('account/verify_credentials', array('include_entities' => FALSE, 'skip_status' => TRUE));

		if (is_object($oUser) && !empty($oUser->screen_name)) {
			if ($oUser->screen_name == $this->sUsername) {
				printf('- Allowed: @%s, continuing.<br><br>', $oUser->screen_name);
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

	private function getRecord() {

		echo 'Getting random record from database..<br>';

		if (!defined('DB_HOST') || !defined('DB_NAME') ||
			!defined('DB_USER') || !defined('DB_PASS')) {

			$this->logger(2, 'MySQL database credentials missing.');
			$this->halt('- One or more of the MySQL database credentials are missing, halting.');
			return FALSE;
		}

		if (empty($this->aDbSettings) || empty($this->aDbSettings['sTable']) || empty($this->aDbSettings['sIdCol']) || 
			empty($this->aDbSettings['sCounterCol']) || empty($this->aDbSettings['sTimestampCol'])) {

			$this->logger(2, 'Database table settings missing.');
			$this->halt('- One or more of the database table settings are missing, halting.');
			return FALSE;
		}

		try {
			$oPDO = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME, DB_USER, DB_PASS);
		} catch(Exception $e) {
			$this->logger(2, sprintf('Database connection failed. (%s)', $e->getMessage()));
			$this->halt(sprintf('- Database connection failed. (%s)', $e->getMessage()));
			return FALSE;
		}

		//fetch random record out of those with the lowest counter value
		$sth = $oPDO->prepare(sprintf('
			SELECT *
			FROM %1$s
			WHERE %2$s = (
				SELECT MIN(%2$s)
				FROM %1$s
			)
			ORDER BY RAND()
			LIMIT 1',
			$this->aDbSettings['sTable'],
			$this->aDbSettings['sCounterCol']
		));

		if ($sth->execute() == FALSE) {
			$this->logger(2, sprintf('Select query failed. (%d %s)', $sth->errorCode(), $sth->errorInfo()));
			$this->halt(sprintf('- Select query failed, halting. (%d %s)', $sth->errorCode(), $sth->errorInfo()));
			return FALSE;
		}

		$aRecord = $sth->fetch(PDO::FETCH_ASSOC);
		printf('- Found record that has been posted %d times before.<br>', $aRecord['postcount']);

		//update record with postcount and timestamp of last post
		$sth = $oPDO->prepare(sprintf('
			UPDATE %1$s
			SET %3$s = %3$s + 1,
				%4$s = NOW()
			WHERE %2$s = :id
			LIMIT 1',
			$this->aDbSettings['sTable'],
			$this->aDbSettings['sIdCol'],
			$this->aDbSettings['sCounterCol'],
			$this->aDbSettings['sTimestampCol']
		));
		$sth->bindValue(':id', $aRecord[$this->aDbSettings['sIdCol']], PDO::PARAM_INT);
		if ($sth->execute() == FALSE) {
			$this->logger(2, sprintf('Update query failed. (%d %s)', $stf->errorCode(), $sth->errorInfo()));
			$this->halt(sprintf('- Update query failed, halting. (%d %s)', $sth->errorCode(), $sth->errorInfo()));
			return FALSE;
		}

		return $aRecord;
	}

	private function postMessage($aRecord) {

		echo 'Posting tweet..<br>';

		//construct tweet
		$sTweet = $this->formatTweet($aRecord);
		if (!$sTweet) {
			return FALSE;
		}

        //check if message is tweet url
        if (preg_match('/https:\/\/twitter\.com\/[^\/]+\/status\/\d+/', $sTweet)) {

            //retweet
            $aTweetUrls = explode("\n", $sTweet);
            foreach ($aTweetUrls as $sTweetUrl) {
                if (preg_match('/https:\/\/twitter\.com\/[^\/]+\/status\/(\d+)/', $sTweetUrl, $m)) {

                    //do retweet
                    $oRet = $this->oTwitter->post('statuses/retweet/' . $m[1], array('trim_user' => TRUE));

                    if (!empty($oRet->error)) {
                        $this->logger(2, sprintf('Twitter API call failed: POST statuses/retweet (%s)', $oRet->error));
                        $this->halt(sprintf('- Retweet failed, halting. (*%s)', $oRet->error));
                        return FALSE;
                    } else {
                        printf('- Retweeted: <b>%s</b><br>', $sTweetUrl);
                    }
                }
            }

        } else {

            //tweet
            $oRet = $this->oTwitter->post('statuses/update', array('status' => $sTweet, 'trim_users' => TRUE));
            if (isset($oRet->errors)) {
                $this->logger(2, sprintf('Twitter API call failed: statuses/update (%s)', $oRet->errors[0]->message));
                $this->halt('- Error: ' . $oRet->errors[0]->message . ' (code ' . $oRet->errors[0]->code . ')');
                return FALSE;
            } else {
                printf('- <b>%s</b><br>', utf8_decode($sTweet));
            }
        }

		return TRUE;
	}

	private function formatTweet($aRecord) {

		//should get this by API (GET /help/configuration ->short_url_length) but it rarely changes
		$iMaxTweetLength = 140;
		$iShortUrlLength = 22;	//NB: 1 char more for https links

		if (empty($this->aTweetSettings['sFormat']) || empty($this->aTweetSettings['aTweetVars'])) {
			$this->logger(2, 'Tweet format settings missing.');
			$this->halt('- One or more of the tweet format settings are missing, halting.');
			return FALSE;
		}

		//construct tweet
		$sTweet = $this->aTweetSettings['sFormat'];

		//replace all non-truncated fields
		foreach ($this->aTweetSettings['aTweetVars'] as $aVar) {
			if (empty($aVar['bTruncate']) || $aVar['bTruncate'] == FALSE) {
				$sTweet = str_replace($aVar['sVar'], $aRecord[$aVar['sRecordField']], $sTweet);
			}
		}

		//determine maximum length left over for truncated field (links are shortened to t.co format of max 22 chars)
		$sTempTweet = preg_replace('/http:\/\/\S+/', str_repeat('x', $iShortUrlLength), $sTweet);
		$sTempTweet = preg_replace('/https:\/\/\S+/', str_repeat('x', $iShortUrlLength + 1), $sTempTweet);
		$iTruncateLimit = $iMaxTweetLength - strlen($sTempTweet);

		//replace truncated field
		foreach ($this->aTweetSettings['aTweetVars'] as $aVar) {
			if (!empty($aVar['bTruncate']) && $aVar['bTruncate'] == TRUE) {

				//placeholder will get replaced, so add that to char limit
				$iTruncateLimit += strlen($aVar['sVar']);

				//get text to replace placeholder with
				$sText = html_entity_decode($aRecord[$aVar['sRecordField']], ENT_QUOTES, 'UTF-8');

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

				//only 1 truncated field allowed
				break;
			}
		}

		return $sTweet;
	}

	private function halt($sMessage = '') {
		echo $sMessage . '<br><br>Done!<br><br>';
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

		$iRet = file_put_contents($this->sLogFile, sprintf($sLogLine, $sTimestamp, $sLevel, $sMessage), FILE_APPEND);

		if ($iRet === FALSE) {
			die($sTimestamp . ' [FATAL] Unable to write to logfile!');
		}
	}
}
