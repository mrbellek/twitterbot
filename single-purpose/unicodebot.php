<?php
require_once('twitteroauth.php');
require_once('logger.php');
require_once('unicodebot.inc.php');

define('DS', DIRECTORY_SEPARATOR);

/*
 * TODO:
 * v emojis on sunday
 * - replace unicode entities in description with unicode character ('ancient form of \u6055')
 * - random skin color for emojis that support it - http://www.unicode.org/reports/tr51/index.html
 * ? attach picture of character with google noto font array
 */

$oTweetBot = new TweetBot(array(
	'sUsername' => 'UnicodeTweet',
	'sJsonFile' => 'unicode.json',
	'sTweetFormat' => ':description: &#:dec; (U+:hex) http://unicode-table.com/en/:hex/',
	'aTweetVars' => array(
		array('sVar' => ':description', 'sField' => 'description', 'bTruncate' => TRUE),
		array('sVar' => ':dec', 'sField' => 'dec'),
		array('sVar' => ':hex', 'sField' => 'hex'),
	),
));
$oTweetBot->run();

class TweetBot {

	private $sUsername;			//username we will be tweeting from

	private $sLogFile;			//where to log stuff
    private $iLogLevel = 3;     //increase for debugging

	private $sJsonFile;			//json source file
	private $sTweetFormat;		//tweet format settings

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

		//stuff to determine what to get from json file
		$this->sJsonFile = (!empty($aArgs['sJsonFile']) ? $aArgs['sJsonFile'] : array());

		//stuff to determine what we're tweeting
		$this->aTweetSettings = array(
			'sFormat' => (!empty($aArgs['sTweetFormat']) ? $aArgs['sTweetFormat'] : ''),
			'aTweetVars' => (!empty($aArgs['aTweetVars']) ? $aArgs['aTweetVars'] : array()),
		);

		$this->sLogFile			= (!empty($aArgs['sLogFile'])		? $aArgs['sLogFile']			: strtolower($this->sUsername) . '.log');

		if ($this->sLogFile == '.log') {
			$this->sLogFile = pathinfo($_SERVER['SCRIPT_FILENAME'], PATHINFO_FILENAME) . '.log';
		}
	}

	public function run() {

		//verify current twitter user is correct
		if ($this->getIdentity()) {

			//fetch row from json
			if ($aUnicode = $this->getRow()) {

				//format and post message
				if ($this->postMessage($aUnicode)) {

					$this->halt('Done.');
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

	private function getRow() {

		if (date('w') == 0) {
			echo "Generating smiley record!\n";

			return $this->getSmileyRow();
		}

		echo "Getting random record from json file..\n";

		$aUnicodeLines = file(MYPATH . DS . $this->sJsonFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
		if ($aUnicodeLines) {
			unset($aUnicodeLines[0]);
			unset($aUnicodeLines[count($aUnicodeLines) - 1]);

			$sLine = $aUnicodeLines[mt_rand(0, count($aUnicodeLines) - 1)];
			list($sChar, $sDesc) = explode(':', $sLine);
			$sChar = trim($sChar, "\t \",");
			$sDesc = trim($sDesc, "\t \",");

			return array(
				'description' => $sDesc,
				'hex' => $sChar,
				'dec' => hexdec($sChar),
			);
		}

		return FALSE;
	}

	private function getSmileyRow() {

		//hardcoded ranges for emoticons and stuff, via http://en.wikipedia.org/wiki/Emoji
		$aBlocks = array(
			array(
				'name' => 'Miscellaneous Symbols',
				'ranges' => array(
					array(
						'start' => 0x1F300,
						'end' => 0x1F3CE,
					),
					array(
						'start' => 0x1F3D0,
						'end' => 0x1F579,
					),
					array(
						'start' => 0x1F57B,
						'end' => 0x1F5A3,
					),
					array(
						'start' => 0x1F5A5,
						'end' => 0x1F4FF,
					),
				),
			),
			array(
				'name' => 'Supplemental Symbolcs and Pictographs',
				'ranges' => array(
					array(
						'start' => 0x1F910,
						'end' => 0x1F918,
					),
					array(
						'start' => 0x1F980,
						'end' => 0x1F984,
					),
					array(
						'start' => 0x1F9C0,
						'end' => 0x1F9C0,
					),
				),
			),
			array(
				'name' => 'Emoticons',
				'ranges' => array(
					'start' => 0x1F600,
					'end' => 0x1F64F,
				),
			),
			array(
				'name' => 'Transport and Map Symbols',
				'ranges' => array(
					array(
						'start' => 0x1F680,
						'end' => 0x1F6D0,
					),
					array(
						'start' => 0x1F6D0,
						'end' => 0x1F6D0,
					),
					array(
						'start' => 0x1F6E0,
						'end' => 0x1F6EC,
					),
					array(
						'start' => 0x1F6F0,
						'end' => 0x1F6F3,
					),
				),
			),
			array(
				'name' => 'Miscelanneous Symbols',
				'ranges' => array(
					array(
						'start' => 0x2600,
						'end' => 0x26FF,
					),
				),
			),
			array(
				'name' => 'Dingbats',
				'ranges' => array(
					array(
						'start' => 0x2700,
						'end' => 0x27BF,
					),
				),
			)
		);

		$aEmoticons = array();
		foreach ($aBlocks as $aBlock) {
			foreach ($aBlock['ranges'] as $aRange) {
				for ($i = $aRange['start']; $i <= $aRange['end']; $i++) {
					$aEmoticons[] = $i;
				}
			}
		}
		$iChar = $aEmoticons[mt_rand(0, count($aEmoticons) - 1)];
		$aUnicodeLines = file(MYPATH . DS . $this->sJsonFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
		$sDescription = '';
		foreach ($aUnicodeLines as $sLine) {
			if (strpos($sLine, '"' . strtoupper(dechex($iChar)) . '"') !== FALSE) {
				list($sChar, $sDescription) = explode(':', $sLine);
				$sDescription = trim($sDescription, ' ":,');
				break;
			}
		}

		if (!$sDescription) {
			$sDescription = '(unnamed)';
		}

		return array(
			'description' => $sDescription,
			'hex' => dechex($iChar),
			'dec' => $iChar,
		);
	}

	private function postMessage($aRow, $sImageFile = FALSE) {

		echo "Posting tweet..\n";

		//construct tweet
		$sTweet = $this->formatTweet($aRow);
		if (!$sTweet) {
			return FALSE;
		}

		//tweet
		$oRet = $this->oTwitter->post('statuses/update', array('status' => mb_convert_encoding($sTweet, 'UTF-8', 'HTML-ENTITIES'), 'trim_users' => TRUE));
		if (isset($oRet->errors)) {
			$this->logger(2, sprintf('Twitter API call failed: statuses/update (%s)', $oRet->errors[0]->message));
			$this->halt('- Error: ' . $oRet->errors[0]->message . ' (code ' . $oRet->errors[0]->code . ')');
			return FALSE;
		} else {
			printf("- %s\n", utf8_decode($sTweet));
		}

		return TRUE;
	}

	private function formatTweet($aRow) {

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
				$sTweet = str_replace($aVar['sVar'], $aRow[$aVar['sField']], $sTweet);
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
				$sText = html_entity_decode($aRow[$aVar['sField']], ENT_QUOTES, 'UTF-8');

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

		$aBacktrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
		TwitterLogger::write($this->sUsername, $sLevel, $sMessage, pathinfo($aBacktrace[0]['file'], PATHINFO_BASENAME), $aBacktrace[0]['line']);

		$iRet = file_put_contents(MYPATH . '/' . $this->sLogFile, sprintf($sLogLine, $sTimestamp, $sLevel, $sMessage), FILE_APPEND);

		if ($iRet === FALSE) {
			die($sTimestamp . ' [FATAL] Unable to write to logfile!');
		}
	}
}
