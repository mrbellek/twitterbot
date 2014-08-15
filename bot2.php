<?php
require_once('twitteroauth.php');

class TweetBot {

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
			$this->halt('- No username! Set username when calling constructor.');
			return FALSE;
		}

		$oUser = $this->oTwitter->get('account/verify_credentials');

		if (is_object($oUser)) {
			if ($oUser->screen_name = $this->sUsername) {
				printf('- Allowed: @%s, continuing.<br><br>', $oUser->screen_name);
			} else {
				$this->halt(sprintf('- Not alowed: @%s (expected: %s), halting.', $oUser->screen_name, $this->sUsername));
				return FALSE;
			}
		} else {
			$this->halt(sprintf('- Call failed, halting. (%s)', $oUser->errors[0]->message));
			return FALSE;
		}

		return TRUE;
	}

	private function getRecord() {

		echo 'Getting random record from database..<br>';

		if (!defined('DB_HOST') || !defined('DB_NAME') ||
			!defined('DB_USER') || !defined('DB_PASS')) {

			$this->halt('- One or more of the MySQL database credentials are missing, halting.');
			return FALSE;
		}

		if (empty($this->aDbSettings) || empty($this->aDbSettings['sTable']) || empty($this->aDbSettings['sIdCol']) || 
			empty($this->aDbSettings['sCounterCol']) || empty($this->aDbSettings['sTimestampCol'])) {

			$this->halt('- One or more of the database table settings are missing, halting.');
			return FALSE;
		}

		try {
			$oPDO = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME, DB_USER, DB_PASS);
		} catch(Exception $e) {
			$this->halt(sprintf('- Database connection failed. (%s)', $e->getMessage()));
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
			$this->halt(sprintf('- Select query failed, halting. (%d %s)', $sth->errorCode(), $sth->errorInfo()));
			return FALSE;
		}

		$aRecord = $sth->fetch(PDO::FETCH_ASSOC);
		printf('- Found note that has been posted %d times before.<br>', $aRecord['postcount']);

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

		//tweet
		$oRet = $this->oTwitter->post('statuses/update', array('status' => $sTweet));
		if (isset($oRet->errors)) {
			$this->halt('- Error: ' . $oRet->errors[0]->message . ' (code ' . $oRet->errors[0]->code . ')');
			return FALSE;
		} else {
			printf('- <b>%s</b><br>', utf8_decode($sTweet));
		}

		return TRUE;
	}

	private function formatTweet($aRecord) {

		//should get this by API (GET /help/configuration ->short_url_length) but it rarely changes
		$iMaxTweetLength = 140;
		$iShortUrlLength = 22;	//NB: 1 char more for https links

		if (empty($this->aTweetSettings['sFormat']) || empty($this->aTweetSettings['aTweetVars'])) {
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

		if (strlen($sTweet) > 140) {
			$this->halt('- Something stupid happened formatting tweet: it\'s too long!');
			return FALSE;
		}

		return $sTweet;
	}

	private function halt($sMessage = '') {
		echo $sMessage . '<br><br>Done!><br><br>';
		return FALSE;
	}
}
