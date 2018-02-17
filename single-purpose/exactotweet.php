<?php
require_once('twitteroauth.php');
require_once('logger.php');
require_once('exactotweet.inc.php');

/*
 * TODO:
 * v get followers
 * - follow who follows us (send welcome message?)
 * v get timeline tweets since last run (stream?)
 * v check which tweets are exactly 140 chars
 * v award 1 point to user
 * v notify the user some way that they scored a point. options:
 *   - fav tweet
 *   - reply to tweet
 *   v quote tweet (more room for including running score)
 * v keep scoreboard of past 7 days
 * - reply with scoreboard on demand (possibly top 10 and 'you are here' section?)
 * - BUG: different path is used when called by cronjob then by browser
 */

$o = new ExactoTweet(array('sUsername' => 'ExactoTweet'));
$o->run();

class ExactoTweet {

	private $sUsername;
	private $sSettingsFile;
	private $sLogFile;
	private $iLogLevel = 3; //increase for debuggin
	private $aSettings;
	private $iLeaderboardTime = 2592000; //scores expire after a month

	private $aAnswerPhrases = array(
		'scoreboard'	=> 'Your current scoreboard position is #%d, with %d points.',
		'score'			=> 'Your current score is %d.',
		'point'			=> 'ExactoTweet! 1 point to @%s for a %d-day score of %d. %s',
	);

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

		$this->sUsername		= (!empty($aArgs['sUsername'])			? $aArgs['sUsername']		: '');
		$this->sSettingsFile	= (!empty($aArgs['sSettingsFile'])		? $aArgs['sSettingsFile']	: strtolower(__CLASS__) . '.json');
		$this->sLastMentionFile = (!empty($aArgs['sLastMentionFile'])	? $aArgs['sLastMentionFile'] : strtolower(__CLASS__) . '-last.json');
		$this->bReplyInDM		= (!empty($aArgs['bReplyInDM'])			? $aArgs['bReplyInDM']		: FALSE);
		$this->sLogFile			= (!empty($aArgs['sLogFile'])			? $aArgs['sLogFile']		: strtolower(__CLASS__) . '.log');

		if (is_file(MYPATH . '/' . $this->sSettingsFile) && filesize(MYPATH . '/' . $this->sSettingsFile) > 0) {
			$this->aSettings = @json_decode(file_get_contents(MYPATH . '/' . $this->sSettingsFile), TRUE);
			if (!$this->aSettings) {
				$this->logger(1, sprintf('Failed to load settings file. (json_decode error %s)', json_last_error()));
				$this->halt(sprintf('Failed to load settings files. (json_decode error %s)', json_last_error()));
				die();
			}
		} else {
			$this->aSettings = array();
		}

		if ($this->sLogFile == '.log') {
			$this->sLogFile = pathinfo($_SERVER['SCRIPT_FILENAME'], PATHINFO_FILENAME) . '.log';
		}
	}

	public function run() {

		//check if auth is ok
		if ($this->getIdentity()) {

			$this->checkTimeline();

			//$this->postLeaderboard();

			//$this->checkMentions();

			$this->halt();
		}
	}

	private function getIdentity() {

		return TRUE;//DEBUG

		echo "Fetching identity..\n";

		if (!$this->sUsername) {
			$this->logger(2, 'No username');
			$this->halt('- No username! Set username when calling constructor.');
			return FALSE;
		}

		$oCurrentUser = $this->oTwitter->get('account/verify_credentials', array('include_entities' => FALSE, 'skip_status' => TRUE));

		if (is_object($oCurrentUser) && !empty($oCurrentUser->screen_name)) {
			if ($oCurrentUser->screen_name == $this->sUsername) {
				printf("- Allowed: @%s, continuing.\n\n", $oCurrentUser->screen_name);
			} else {
				$this->logger(2, sprintf('Authenticated username was unexpected: %s (expected: %s)', $oCurrentUser->screen_name, $this->sUsername));
				$this->halt(sprintf('- Not allowed: @%s (expected: %s), halting.', $oCurrentUser->screen_name, $this->sUsername));
				return FALSE;
			}
		} else {
			$this->logger(2, sprintf('Twitter API call failed: GET account/verify_credentials (%s)', $oCurrentUser->errors[0]->message));
			$this->halt(sprintf('- Call failed, halting. (%s)', $oCurrentUser->errors[0]->message));
			return FALSE;
		}

		return TRUE;
	}

	private function checkTimeline() {

		//get timeline tweets since last update
		printf("Checking timeline since %s\n", (!empty($this->aSettings['last_timeline']['timestamp']) ? $this->aSettings['last_timeline']['timestamp'] : 'never'));
		$sMaxId = (!empty($this->aSettings['last_timeline']['max_id']) ? $this->aSettings['last_timeline']['max_id'] : 1);
		$aTimeline = $this->oTwitter->get('statuses/home_timeline', array(
			'since_id' => $sMaxId,
			'count' => 200,
			'include_entities' => FALSE,
		));

		if (is_object($aTimeline) && !empty($aTimeline->errors)) {
			$this->logger(2, sprintf('Twitter API call failed: GET/statuses/home_timeline (%s)', $aTimeline->errors[0]->message));
			$this->halt(sprintf('- Call failed, halting. (%s)', $aTimeline->errors[0]->message));
			return FALSE;
		}

		foreach ($aTimeline as $oTweet) {

			//check length, filter retweets
			if ((strlen($oTweet->text) == 280 || strlen($oTweet->text) == 140) && substr($oTweet->text, 0, 3) !== 'RT ') {

				//exactotweet!
				printf('Exactotweet by <b>@%s</b>: %s<br>', $oTweet->user->screen_name, str_replace("\n", ' ', $oTweet->text));
				$this->aSettings['scores'][$oTweet->user->screen_name][] = array(
					'tweet'		=> $oTweet->text,
					'id'		=> $oTweet->id_str,
					'timestamp'	=> strtotime($oTweet->created_at),
				);
				$this->pointScored($oTweet, count($this->aSettings['scores'][$oTweet->user->screen_name]));
			}

			$sMaxId = max($sMaxId, $oTweet->id_str);
		}

		$this->aSettings['last_timeline'] = array('max_id' => $sMaxId, 'timestamp' => date('Y-m-d H:i:s'));

		//purge tweets that are too old to count
		foreach ($this->aSettings['scores'] as $sUser => $aTweets) {
			foreach ($aTweets as $key => $aTweet) {
				if ($aTweet['timestamp'] + $this->iLeaderboardTime < time()) {
					printf('removing tweet older than limit: %s<br>', $aTweet['tweet']);
					unset($this->aSettings['scores'][$sUser][$key]);
				}
			}
		}

		//write scores to disk
		file_put_contents(MYPATH . DS . $this->sSettingsFile, json_encode($this->aSettings, JSON_PRETTY_PRINT));

		return TRUE;
	}

	private function pointScored($oTweet, $iTotalScore) {

		$sTweetId = $oTweet->id_str;
		$sTweetUrl = 'https://twitter.com/' . $oTweet->user->screen_name . '/status/' . $sTweetId;
		$sMessage = sprintf($this->aAnswerPhrases['point'],
			$oTweet->user->screen_name,
			$this->iLeaderboardTime / (3600 * 24),
			$iTotalScore,
			$sTweetUrl
		);

		$aReturn = $this->oTwitter->post('statuses/update', array('status' => $sMessage, 'trim_user' => TRUE));
		if (is_object($aReturn) && !empty($aReturn->errors[0]->message)) {
			$this->logger(2, sprintf('Twitter API call failed: POST statuses/update (%s)', $aReturn->errors[0]->message), array('tweet' => $sMessage));
			$this->halt(sprintf('- Failed posting tweet, halting. (%s)', $aReturn->errors[0]->message));
			return FALSE;
		}
	}

	private function postLeaderboard() {

		//NB: image preview in timeline is 1024x512
		$this->aSettings['scores']['test'] = array(1,2,3);
		$this->aSettings['scores']['testuser'] = array(1,2,3);

		//post leaderboard on Sunday 6 AM (-ish)
		if (date('N') == 7 && substr(date('H:i'), 3) == '6:0') {

			$iWidth = 600;
			$iHeight = 300;
			$sFont = MYPATH . '/arialbd.ttf';
			$hImage = imagecreatetruecolor($iWidth, $iHeight);

			$cWhite = imagecolorallocate($hImage, 255, 255, 255);
			$cBlack = imagecolorallocate($hImage, 0, 0, 0);
			imagefilledrectangle($hImage, 0, 0, $iWidth - 1, $iHeight - 1, $cWhite);

			//print header
			$sHeader = sprintf('ExactoTweet leaderboard for %s %s %d', date('M'), date('j') . date('S'), date('Y'));
			imagettftext($hImage, 20, 0, 15, 35, $cBlack, $sFont, $sHeader);

			//print leaderboard (first 5 users)
			$iLineCount = 0;
			foreach ($this->aSettings['scores'] as $sName => $aTweets) {
				$sLine = sprintf('@%s: %d points', $sName, count($aTweets));
				imagettftext($hImage, 10, 0, 15, 65 + 30 * $iLineCount, $cBlack, $sFont, $sLine);
				$iLineCount++;

				if ($iLineCount >= 5) {
					break;
				}
			}

			//print footer
			$sFooter = 'Remember: tweet \'score\' or \'leaderboard\' to @ExactoTweet at any time to hear your score!';
			imagettftext($hImage, 10, 0, 10, $iHeight - 30, $cBlack, $sFont, $sFooter);

			imagepng($hImage, MYPATH . '/image.png');
			imagedestroy($hImage);
			echo('<img src="image.png" style="border: 1px solid black;">');
			die();
		}
	}

	private function checkMentions() {

		printf("Checking mentions since %s..\n", (!empty($this->aSettings['last_mention']['timestamp']) ? $this->aSettings['last_mention']['timestamp'] : 'never'));

		//fetch new mentions since last run
		$aMentions = $this->oTwitter->get('statuses/mentions_timeline', array(
			'count'		=> 20,
			'since_id'	=> (!empty($this->aSettings['last_mention']['max_id']) ? $this->aSettings['last_mention']['max_id'] : 1),
		));

		if (is_object($aMentions) && !empty($aMentions->errors[0]->message)) {
			$this->logger(2, sprintf('Twitter API call failed: GET statuses/mentions_timeline (%s)', $aMentions->errors[0]->message));
			$this->halt(sprintf('- Failed getting mentions, halting. (%s)', $aMentions->errors[0]->message));
		}

		//if we have mentions, get followers for auth (we will only respond to commands from people that follow us)
		if (count($aMentions) > 0) {
			$oRet = $this->oTwitter->get('followers/ids', array('screen_name' => $this->sUsername, 'stringify_ids' => TRUE));
			if (!empty($oRet->errors[0]->message)) {
				$this->logger(2, sprintf('Twitter API call failed: GET followers/ids (%s)', $aMentions->errors[0]->message));
				$this->halt(sprintf('- Failed getting followers, halting. (%s)', $aMentions->errors[0]->message));
			}
			$aFollowers = $oRet->ids;

		} else {
			echo '- no new mentions.<br><br>';
			return FALSE;
		}

		//reply
		$sMaxId = '0';
		foreach ($aMentions as $oMention) {
			if ($oMention->id_str > $sMaxId) {
				$sMaxId = $oMention->id_str;
			}

			//only reply to followers
			if (in_array($oMention->user->id_str, $aFollowers)) {

				$bRet = $this->parseMention($oMention);
				if (!$bRet) {
					break;
				}
			}
		}
		printf("- replied to %d commands\n\n", count($aMentions));

		//save data for next run
		$this->aSettings['last_mention'] = array(
		 'max_id' => $sMaxId,
		 'timestamp' => date('Y-m-d H:i:s'),
		);

		//write settings to disk
		file_put_contents(MYPATH . DS . $this->sSettingsFile, json_encode($this->aSettings, JSON_PRETTY_PRINT));

		return TRUE;
	}

	private function parseMention($oMention) {

		//ignore mentions where our name is not at the start of the tweet
		if (stripos($oMention->text, '@' . $this->sUsername) !== 0) {
			return TRUE;
		}

		//get actual question from tweet
		$sId = $oMention->id_str;
		$sQuestion = str_replace('@' . strtolower($this->sUsername) . ' ', '', strtolower($oMention->text));
		printf("Parsing question '%s' from %s..\n", $sQuestion, $oMention->user->screen_name);


		//find type of question
		if (stripos($sQuestion, 'scoreboard') !== FALSE || stripos($sQuestion, 'leaderboard') !== FALSE) {

			$iScore = count($this->aSettings['scores'][$oMention->user->screen_name]);
			$sAnswer = sprintf($this->aAnswerPhrases['scoreboard'], -1, $iScore);

			return $this->replyToQuestion($oMention, $sAnswer);

		} elseif (stripos($sQuestion, 'score') !== FALSE) {

			$iScore = count($this->aSettings['scores'][$oMention->user->screen_name]);
			$sAnswer = sprintf($this->aAnswerPhrases['score'], -1, $iScore);

			return $this->replyToQuestion($oMention, $sAnswer);
		}

		return FALSE;
	}

	private function replyToQuestion($oMention, $sReply) {

		//remove spaces where needed
		$sReply = trim(preg_replace('/ +/', ' ', $sReply));

		//check friendship between bot and sender
		$oRet = $this->oTwitter->get('friendships/show', array('source_screen_name' => $this->sUsername, 'target_screen_name' => $oMention->user->screen_name));
		if (!empty($oRet->errors)) {
			$this->logger(2, sprintf('Twitter API call failed: GET friendships/show (%s)', $oRet->errors[0]->message));
			$this->halt(sprintf('- Failed to check friendship, halting. (%s)', $oRet->errors[0]->message));
			return FALSE;
		}

		//if we can DM the source of the command, do that
		if ($this->bReplyInDM && $oRet->relationship->source->can_dm) {

			$oRet = $this->oTwitter->post('direct_messages/new', array('user_id' => $oMention->user->id_str, 'text' => $sReply));

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
					substr($sReply, 0, 280 - 2 - strlen($oMention->user->screen_name))
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
