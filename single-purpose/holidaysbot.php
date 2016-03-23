<?php
require_once('twitteroauth.php');
require_once('holidaysbot.inc.php');

/**
 * TODO:
 * v search google image search for holiday + country and attach first image?
 * v fix '?' characters in json file
 * v tweet random holiday 4x daily, keep track of which we have tweeted about today
 * v edge cases, like countries with notes, regions without countries, etc
 * v denote holidays 'important' that we always want to tweet
 * . index the rest of the year's holidays lol
 * ? international/worldwide note, and remove from name
 * - replace 'England' with 'England, United Kingdom'?
 * - consciously not included:
 *   - Christian feast days
 *   - 12 days of xmas
 *   - holidays spanning multiple days
 *   - variable holidays?
 *   - jan 19 theophany/epiphany?
 *   - eve's
 */

$o = new HolidaysBot(array(
	'sUsername' => 'HolidaysBot',
	'aTweetFormats' => array(
		 'Today is :name :url'												=> array('country' => FALSE, 'region' => FALSE, 'note' => FALSE),	//nothing
		 'Today is :name in :country :url'									=> array('country' => TRUE,  'region' => FALSE, 'note' => FALSE),	//country
		 'Today is :name in :region :url'									=> array('country' => FALSE, 'region' => TRUE,  'note' => FALSE),	//region
		 'Today is :name in :region (:country) :url'						=> array('country' => TRUE,  'region' => TRUE,  'note' => FALSE),	//region + country
		 'Today, :name is celebrated by :note :url'							=> array('country' => FALSE, 'region' => FALSE, 'note' => TRUE),	//note
		 'Today, :name is celebrated by :note in :country :url'				=> array('country' => TRUE,  'region' => FALSE, 'note' => TRUE),	//note + country
		 'Today, :name is celebrated by :note in :region (:country) :url'	=> array('country' => TRUE,  'region' => TRUE,  'note' => TRUE),	//note + region + country
	),
));

$o->run();
//$o->importCsv();
//$o->test();

class HolidaysBot {

	private $sUsername;			//username we will be tweeting from

	private $sSettingsFile;		//where to get settings from
	private $sLogFile;			//where to log stuff
    private $iLogLevel = 3;     //increase for debugging

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
		$this->sSettingsFile	= (!empty($aArgs['sSettingsFile'])	? $aArgs['sSettingsFile']		: strtolower(__CLASS__) . '.json');
		$this->aTweetFormats	= (!empty($aArgs['aTweetFormats'])	? $aArgs['aTweetFormats']		: array());
		$this->sLastRunFile		= (!empty($aArgs['sLastRunFile'])	? $aArgs['sLastRunFile']		: strtolower(__CLASS__) . '-last.json');
		$this->sLogFile			= (!empty($aArgs['sLogFile'])		? $aArgs['sLogFile']			: strtolower(__CLASS__) . '.log');
	}

	public function test() {

		//print tweets for all holidays to verify they fit
		if ($aDays = $this->getAllHolidays()) {

			foreach ($aDays as $iMonth => $aDays) {
				foreach ($aDays as $iDay => $aHolidays) {
					foreach ($aHolidays as $aHoliday) {
						$aHoliday['month'] = $iMonth;
						$aHoliday['day'] = $iDay;
						$this->testPostMessage((object)$aHoliday);
					}
				}
			}
		}
		echo 'done.';
	}

	public function run() {

		//verify current twitter user is correct
		if ($this->getIdentity()) {

			//get today's holidays
			if ($aDays = $this->getHolidays()) {

				//get random holiday
				if ($oHoliday = $this->getRandomHoliday($aDays)) {

					if ($this->postMessage($oHoliday)) {

						$this->halt('Done.');
					}
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

	private function getAllHolidays() {

		return json_decode(file_get_contents(MYPATH . '/' . $this->sSettingsFile), TRUE);
	}

	private function getHolidays() {

		echo "Fetching holidays..\n";

		$oHolidays = json_decode(file_get_contents(MYPATH . '/' . $this->sSettingsFile));

		if (!$oHolidays || json_last_error()) {

			$this->logger(1, sprintf('Settings file is empty or invalid: %s', $this->sSettingsFile));
			$this->halt(sprintf('- Settings file is empty or invalid, halting. (%d %s)', json_last_error(), json_last_error_msg()));
			return FALSE;
		}

		return $oHolidays->{date('n')}->{date('j')};
	}

	private function getRandomHoliday($aHolidays) {

		echo "Getting random holiday from today's that hasn't been posted yet..\n";

		$aLastRun = json_decode(file_get_contents(MYPATH . '/' . $this->sLastRunFile), TRUE);

		//if file is invalid or from yesterday, delete it
		if (!$aLastRun || !isset($aLastRun[date('n-j')])) {
			@unlink($this->sLastRunFile);
			$aLastRun = array();
		}

		//remove all holidays from array that we already picked before
		if (isset($aLastRun[date('n-j')])) {
			foreach ($aLastRun[date('n-j')] as $sChecksum) {
				foreach ($aHolidays as $i => $oHoliday) {
					if ($sChecksum == sha1(json_encode($oHoliday))) {
						unset($aHolidays[$i]);
					}
				}
			}
		}

		//if nothing left to pick, return
		if (!$aHolidays) {
			echo "- No holidays left for today\n";
			return FALSE;
		}

		//split into holidays marked 'important' to tweet those first
		$aImportantHolidays = array();
		foreach ($aHolidays as $i => $oHoliday) {
			if ($oHoliday->important) {
				$aImportantHolidays[] = $oHoliday;
				unset($aHolidays[$i]);
			}
		}

		if ($aImportantHolidays) {

			//pick random important holiday from array
			echo "- Picked a holiday marked important\n";
			$oHoliday = $aImportantHolidays[mt_rand(0, count($aImportantHolidays) - 1)];
		} else {

			//pick random holiday from array
			$aHolidays = array_values($aHolidays);
			$oHoliday = $aHolidays[mt_rand(0, count($aHolidays) - 1)];
		}

		//make note that we picked this holiday to prevent picking it again later
		$aLastRun[date('n-j')][] = sha1(json_encode($oHoliday));
		file_put_contents(MYPATH . '/' . $this->sLastRunFile, json_encode($aLastRun));

		return $oHoliday;
	}

	private function testPostMessage($oHoliday) {

		$sTweet = $this->formatTweet($oHoliday);
		if (!$sTweet) {
			return FALSE;
		}
		
		$sTempTweet = preg_replace('/https:\/\/\S+/', str_repeat('x', 23), $sTweet);

		//check if formatted tweet has room for attached image (23 + 1 chars)
		if (strlen($sTempTweet) > 140 - 24) {
			printf("<hr>- %d-%d <b style='color: red;'>[%d]</b> %s<hr>\n", $oHoliday->day, $oHoliday->month, strlen($sTempTweet), $sTweet);
		} else {
			printf("- %d-%d <b>[%d]</b> %s\n", $oHoliday->day, $oHoliday->month, strlen($sTempTweet), $sTweet);
		}

		return TRUE;
	}

	private function postMessage($oHoliday) {

		echo "Posting tweet..\n";

		//construct tweet
		$sTweet = $this->formatTweet($oHoliday);
		if (!$sTweet) {
			return FALSE;
		}

		$sMediaId = $this->attachPicture($oHoliday);
		
		//tweet
		if (!empty($sMediaId)) {
			//TODO: do we need the mb convert here?
			$oRet = $this->oTwitter->post('statuses/update', array('status' => $sTweet, 'trim_users' => TRUE, 'media_ids' => $sMediaId));
		} else {
			$oRet = $this->oTwitter->post('statuses/update', array('status' => $sTweet, 'trim_users' => TRUE));
		}
		if (isset($oRet->errors)) {
			$this->logger(2, sprintf('Twitter API call failed: statuses/update (%s)', $oRet->errors[0]->message));
			$this->halt('- Error: ' . $oRet->errors[0]->message . ' (code ' . $oRet->errors[0]->code . ')');
			return FALSE;
		} else {
			printf("- %s\n", htmlentities($sTweet));
		}

		return TRUE;
	}

	private function formatTweet($oHoliday) {

		/*
		 * formats:
		 * - Today is %s								(no country or note)
		 * - Today is %s in %s							(country)
		 * - Today is %s in %s (%s)						(region + country)
		 * - Today %s is celebrated by %s				(no country, note)
		 * - Today %s is celebrated by %s in %s			(note + country)
		 * - Today %s is celebrated by %s in %s (%s)	(note + region + country)
		 */

		if (empty($this->aTweetFormats)) {
			$this->logger(2, 'Tweet format settings missing.');
			$this->halt('- One or more of the tweet format settings are missing, halting.');
			return FALSE;
		}

		//find correct tweet format for holiday information
		$sTweet = '';
		foreach ($this->aTweetFormats as $sTweetFormat => $aPlaceholders) {
			if (trim($aPlaceholders['country']) == ($oHoliday->country ? TRUE : FALSE) &&
				trim($aPlaceholders['region']) == ($oHoliday->region ? TRUE : FALSE) &&
				trim($aPlaceholders['note']) == ($oHoliday->note ? TRUE : FALSE)) {

				$sTweet = $sTweetFormat;
				break;
			}
		}

		if (!$sTweet) {
			$this->logger(2, sprintf('No tweet format found for holiday. (%s)', $oHoliday->name));
			$this->halt('- No tweet format could be found for this holiday, halting.');
			return FALSE;
		}

		//construct tweet
		foreach (get_object_vars($oHoliday) as $sProperty => $sValue) {
			$sTweet = str_replace(':' . $sProperty, $sValue, $sTweet);
		}

		//trim trailing space for holidays without url
		return trim($sTweet);
	}

	private function imageSearch($oHoliday) {

		//use google custom search engine (CSE) to look for images about the holiday name + country + region + note
		$sBaseCse = 'https://www.googleapis.com/customsearch/v1';
		$aParams = array(
			'q' => implode(' ', array_filter(array($oHoliday->name, $oHoliday->country, $oHoliday->region, $oHoliday->note))),
			'num' => 5,
			'start' => 1,
			//'imgSize' => 'large',
			'searchType' => 'image',
			'key' => GOOGLE_CSE_API_KEY,
			'cx' => '016694130760739954414:myhrixvr3k8',
		);
		$sUrl = $sBaseCse . '?' . http_build_query($aParams);

		$hCurl = curl_init($sUrl);
		curl_setopt($hCurl, CURLOPT_RETURNTRANSFER, TRUE);
		curl_setopt($hCurl, CURLOPT_SSL_VERIFYPEER, FALSE);

		$sReturn = curl_exec($hCurl);

		if ($sReturn && !curl_errno($hCurl)) {
			curl_close($hCurl);

			$oResults = json_decode($sReturn);

			$aImages = array();
			foreach ($oResults->items as $oImage) {
				if (!empty($oImage->link)) {
					$aImages[] = $oImage->link;
				}
			}

			if ($aImages) {
				$sImage = $aImages[mt_rand(0, count($aImages) - 1)];
				//printf('<img src="%s" /><br>', $sImage);

				return $sImage;
			}

			return FALSE;
		} else {
			$this->logger(2, sprintf('Google CSE return invalid result for "%s"!', $aParams['q']));
		}

		curl_close($hCurl);

		return FALSE;
	}

	private function attachPicture($oHoliday) {

		$sImageUrl = $this->imageSearch($oHoliday);

		if ($sImageUrl) {
			$sImageBinary = base64_encode(file_get_contents($sImageUrl));
			if ($sImageBinary && (
				(preg_match('/\.gif/i', $sImageUrl) && strlen($sImageBinary) < 3 * 1024 ^ 2) ||		//max size is 3MB for gif
				(preg_match('/\.png|\.jpe?g/i', $sImageUrl) && strlen($sImageBinary) < 5 * 1024 ^ 2) //max size is 5MB for png/jpg
			)) {
				$oRet = $this->oTwitter->upload('media/upload', array('media' => $sImageBinary));
				if (isset($oRet->errors)) {
					$this->logger(2, sprintf('Twitter API call failed: media/upload (%s)', $oRet->errors[0]->message));
					$this->halt('- Error: ' . $oRet->errors[0]->message . ' (code ' . $oRet->errors[0]->code . ')');
					return FALSE;
				} else {

					return $oRet->media_id_string;
				}
			}
		}

		return FALSE;
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

	public function importCsv() {

		$sFile = 'C:/Users/merijn.MBICASH/Downloads/holidaysbot.txt';
		$sContents = file_get_contents($sFile);
		$sContents = explode("\r\n", mb_convert_encoding($sContents, 'UTF-8', 'UTF-16'));

		//replace the wikipedia nbsp (\u00a0)
		$sContents = str_replace(' ', ' ', $sContents);

		$aHolidays = array();
		foreach ($sContents as $sData) {
			$aData = explode("\t", $sData);

			//skip first line with column headers
			if (is_numeric(trim($aData[0]))) {

				list($iMonth, $iDay, $sCountry, $sRegion, $sNote, $bImportant, $sName, $sUrl) = $aData;

				$aHolidays[$iMonth][$iDay][] = array(
					'country' => $sCountry,
					'region' => $sRegion,
					'note' => $sNote,
					'important' => ($bImportant ? 1 : 0),
					'name' => $sName,
					'url' => rtrim($sUrl),
				);
			}
		}

		file_put_contents('C:/Users/merijn.MBICASH/Documents/twitterbot.localhost/single-purpose/holidaysbot.json', json_encode($aHolidays, JSON_PRETTY_PRINT));
		var_dumP(json_last_error_msg());
		echo "done.\n";
	}
}
