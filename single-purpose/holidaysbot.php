<?php
require_once('twitteroauth.php');
require_once('logger.php');
require_once('holidaysbot.inc.php');

/**
 * TODO:
 * v convert to database
 *   v double-check that weird characters are posted correctly to twitter
 * v search google image search for holiday + country and attach first image?
 * v fix '?' characters in json file
 * v tweet random holiday 4x daily, keep track of which we have tweeted about today
 * v edge cases, like countries with notes, regions without countries, etc
 * v denote holidays 'important' that we always want to tweet
 * v bug where " in Excel turns into "" in tweet
 * v take next GIS result when downloading first pick fails
 * v index the rest of the year's holidays lol
 * v variable holidays?
 *   v getHolidays should return dynamic holidays' calculated date
 *   v lee-jackson day is 0 januari?
 *   x watch out for holidays involving easter that span multiple years
 * v holidays that only occur on some years
 *   v chinese calendar: http://stackoverflow.com/questions/23181668/convert-gregorian-to-chinese-lunar-calendar
 * ? international/worldwide note, and remove from name
 * - implement 'parrot' function to repeat something said to bot by someone it follows
 * - replace 'England' with 'England, United Kingdom'?
 * - consciously not included:
 *   - Christian feast days
 *   - 12 days of xmas (except first day)
 *   - holidays spanning multiple days (except first day)
 *   - jan 19 theophany/epiphany?
 *   - eve's of [x] when there's [x] the next day
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
//$o->test();
//$o->importToDb();

class HolidaysBot {

	private $sUsername;			//username we will be tweeting from
	private $oPDO;

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
		
		//connect to database
		try {
			$this->oPDO = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME, DB_USER, DB_PASS);
		} catch(Exception $e) {
			$this->logger(2, sprintf('Database connection failed. (%s)', $e->getMessage()));
			$this->halt(sprintf('- Database connection failed. (%s)', $e->getMessage()));
			exit();
		}
	}

	private function parseArgs($aArgs) {

		$this->sUsername		= (!empty($aArgs['sUsername']) ? $aArgs['sUsername'] : '');
		$this->aTweetFormats	= (!empty($aArgs['aTweetFormats'])	? $aArgs['aTweetFormats']		: array());
		$this->sLastRunFile		= (!empty($aArgs['sLastRunFile'])	? $aArgs['sLastRunFile']		: strtolower(__CLASS__) . '-last.json');
		$this->sLogFile			= (!empty($aArgs['sLogFile'])		? $aArgs['sLogFile']			: strtolower(__CLASS__) . '.log');
	}

	private function query($sQuery, $aParams = array(), $bSingle = FALSE) {

		$sth = $this->oPDO->prepare($sQuery);

		foreach ($aParams as $sKey => $mValue) {
			$sth->bindValue($sKey, $mValue, (is_numeric($mValue) && !is_float($mValue) ? PDO::PARAM_INT : PDO::PARAM_STR));
		}

		if ($sth->execute()) {
			$sQuery = preg_replace('/^\s+/', '', $sQuery);
			if (strpos(strtolower($sQuery), 'select') === 0) {
				//SELECT
				return ($bSingle ? $sth->fetch(PDO::FETCH_ASSOC) : $sth->fetchAll(PDO::FETCH_ASSOC));
			} elseif (strpos(strtolower($sQuery), 'insert') === 0) {
				//INSERT
				$iRowId = $oPDO->lastInsertId();
				return ($iRowId ? $iRowId : TRUE);
			} else {
				//DELETE or UPDATE
				return TRUE;
			}
		} else {
			$sError = $sth->errorCode();
			$this->logger(2, sprintf('Database query failed. (%s, query %s, params %s)', $sError, $sQuery, serialize($aParams)));
			$this->halt(sprintf('- Database connection failed. (%s, query %s, params %s)', $sError, $sQuery, serialize($aParams)));
			return FALSE;
		}
	}

	public function test() {

        if (filter_input(INPUT_GET, 'month', FILTER_SANITIZE_NUMBER_INT) || filter_input(INPUT_GET, 'day', FILTER_SANITIZE_NUMBER_INT)) {
            $aHolidays = $this->getAllHolidays(
                filter_input(INPUT_GET, 'month', FILTER_SANITIZE_NUMBER_INT),
                filter_input(INPUT_GET, 'day', FILTER_SANITIZE_NUMBER_INT)
            );
        } else {
            $aHolidays = $this->getAllHolidays();
        }

		//print tweets for all holidays to verify they fit
		if ($aHolidays) {
			foreach ($aHolidays as $aHoliday) {
				$this->testPostMessage($aHoliday);
			}
		}
		printf('done. %d holidays total', count($aHolidays));
	}

	public function run() {

        if (filter_input(INPUT_GET, 'test') == 1) {
            $this->test();
            return;
        }

		//verify current twitter user is correct
		if ($this->getIdentity()) {

			//get today's holidays
			if ($aDays = $this->getHolidays()) {

				//get random holiday
				if ($aHoliday = $this->getRandomHoliday($aDays)) {

					if ($this->postMessage($aHoliday)) {

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

	private function getAllHolidays($iMonth = FALSE, $iDay = FALSE) {

		try {
			if ($iMonth && $iDay) {

				return $this->query('
					SELECT *
					FROM holidays
					WHERE month = :month
                    AND day = :day
                    AND (year = YEAR(CURDATE()) OR year = 0 OR year IS NULL)',
					array(
						'month' => $iMonth,
						'day' => $iDay,
					)
				);

			} else {

				return $this->query('SELECT * FROM holidays ORDER BY month, day');
			}
		} catch (Exception $e) {
			$aError = $sth->errorInfo();
			$this->logger(1, sprintf('Database error fetching holidays: %s %s %s', $aError[0], $aError[1], $aError[2]));
			$this->halt(sprintf('Database error fetching holidays: %s %s %s', $aError[0], $aError[1], $aError[2]));
			return array();
		}

		return array();
	}

	private function getHolidays() {

		echo "Fetching holidays..\n";

		$aHolidays = $this->getAllHolidays();

		if (!$aHolidays) {
			return FALSE;
		}

        //get all holidays for today
		$aTodayHolidays = $this->getAllHolidays(date('n'), date('j'));

        //remove any dynamic holidays
        foreach ($aTodayHolidays as $key => $aHoliday) {
            if ($aHoliday['dynamic']) {
                unset($aTodayHolidays[$key]);
            }
        }

		//for all dynamic holidays, find date and add back to list if today
		foreach ($aHolidays as $key => $aHoliday) {

			if ($aHoliday['dynamic']) {

				$iDynamicHoliday = $this->calculateDynamicDate($aHoliday);
				if ($iDynamicHoliday) {
					$iDynamicMonth = date('n', $iDynamicHoliday);
					$iDynamicDay = date('j', $iDynamicHoliday);

					if ($iDynamicMonth == date('n') && $iDynamicDay == date('j')) {
						//add to today's holidays
						$aTodayHolidays[] = $aHoliday;
					}
				}
			}
		}

		return $aTodayHolidays;
	}

	private function getRandomHoliday($aHolidays) {

		echo "Getting random holiday from today's that hasn't been posted yet..\n";

		$aLastRun = array();
		if (is_file(MYPATH . '/' . $this->sLastRunFile)) {
			$aLastRun = json_decode(file_get_contents(MYPATH . '/' . $this->sLastRunFile), TRUE);
		}

		//if file is invalid or from yesterday, delete it
		if (!$aLastRun || !isset($aLastRun[date('n-j')])) {
			@unlink($this->sLastRunFile);
			$aLastRun = array();
		}

		//remove all holidays from array that we already picked before
		if (isset($aLastRun[date('n-j')])) {
			foreach ($aLastRun[date('n-j')] as $sChecksum) {
				foreach ($aHolidays as $i => $aHoliday) {
					if ($sChecksum == sha1(json_encode($aHoliday))) {
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
		foreach ($aHolidays as $i => $aHoliday) {
			if ($aHoliday['important']) {
				$aImportantHolidays[] = $aHoliday;
				unset($aHolidays[$i]);
			}
		}

		if ($aImportantHolidays) {

			//pick random important holiday from array
			echo "- Picked a holiday marked important\n";
			$aHoliday = $aImportantHolidays[mt_rand(0, count($aImportantHolidays) - 1)];
		} else {

			//pick random holiday from array
			$aHolidays = array_values($aHolidays);
			$aHoliday = $aHolidays[mt_rand(0, count($aHolidays) - 1)];
		}

		//make note that we picked this holiday to prevent picking it again later
		$aLastRun[date('n-j')][] = sha1(json_encode($aHoliday));
		file_put_contents(MYPATH . '/' . $this->sLastRunFile, json_encode($aLastRun));

		return $aHoliday;
	}

	private function testPostMessage($aHoliday) {

		$sTweet = $this->formatTweet($aHoliday);
		if (!$sTweet) {
			return FALSE;
		}

		$sTempTweet = preg_replace('/https:\/\/\S+/', str_repeat('x', 23), $sTweet);

		//for dynamic holidays, find date
		if ($aHoliday['dynamic']) {
			$iDynamicHoliday = $this->calculateDynamicDate($aHoliday);

			//replace month + date, add log note
			if ($iDynamicHoliday) {
				$aHoliday['month'] = date('m', $iDynamicHoliday);
				$aHoliday['day'] = date('d', $iDynamicHoliday);

				$sTweet .= ' <b>(dynamic)</b>';
			}
		}
		
		//check if formatted tweet has room for attached image (23 + 1 chars)
		if (strlen($sTempTweet) > 140 - 24) {
			printf("<hr>- %d-%d <b style='color: red;'>[%d]</b> %s<hr>\n", $aHoliday['month'], $aHoliday['day'], strlen($sTempTweet), $sTweet);
		} else {
			printf("- %d-%d <b>[%d]</b> %s\n", $aHoliday['month'], $aHoliday['day'], strlen($sTempTweet), $sTweet);
		}

		return TRUE;
	}

	private function postMessage($aHoliday) {

		echo "Posting tweet..\n";

		//construct tweet
		$sTweet = $this->formatTweet($aHoliday);
		if (!$sTweet) {
			return FALSE;
		}

		$sMediaId = $this->attachPicture($aHoliday);
		
		//tweet
		if (!empty($sMediaId)) {
			$oRet = $this->oTwitter->post('statuses/update', array('status' => mb_convert_encoding($sTweet, 'UTF-8', 'HTML-ENTITIES'), 'trim_users' => TRUE, 'media_ids' => $sMediaId));
		} else {
			$oRet = $this->oTwitter->post('statuses/update', array('status' => mb_convert_encoding($sTweet, 'UTF-8', 'HTML-ENTITIES'), 'trim_users' => TRUE));
		}
		if (isset($oRet->errors)) {
			$this->logger(2, sprintf('Twitter API call failed: statuses/update (%s)', $oRet->errors[0]->message), array('tweet' => $sTweet, 'media' => @$sMediaId));
			$this->halt('- Error: ' . $oRet->errors[0]->message . ' (code ' . $oRet->errors[0]->code . ')');
			return FALSE;
		} else {
			printf("- %s\n", $sTweet);
		}

		return TRUE;
	}

	private function formatTweet($aHoliday) {

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
			if (trim($aPlaceholders['country']) == ($aHoliday['country'] ? TRUE : FALSE) &&
				trim($aPlaceholders['region']) == ($aHoliday['region'] ? TRUE : FALSE) &&
				trim($aPlaceholders['note']) == ($aHoliday['note'] ? TRUE : FALSE)) {

				$sTweet = $sTweetFormat;
				break;
			}
		}

		if (!$sTweet) {
			$this->logger(2, sprintf('No tweet format found for holiday. (%s)', $aHoliday['name']));
			$this->halt('- No tweet format could be found for this holiday, halting.');
			return FALSE;
		}

		//construct tweet
		foreach ($aHoliday as $sProperty => $sValue) {
			$sTweet = str_replace(':' . $sProperty, $sValue, $sTweet);
		}

		//trim trailing space for holidays without url
		return trim($sTweet);
	}

	private function imageSearch($aHoliday) {

		echo "- Searching Google Image Search for image\n";

		//use google custom search engine (CSE) to look for images about the holiday name + country + region + note
		$sBaseCse = 'https://www.googleapis.com/customsearch/v1';
		$aParams = array(
			'q' => implode(' ', array_filter(array($aHoliday['name'], $aHoliday['country'], $aHoliday['region'], $aHoliday['note']))) . ' -logo',
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
				return $aImages;
			}

			return FALSE;
		} else {
			$this->logger(2, sprintf('Google CSE return invalid result for "%s"!', $aParams['q']));
		}

		curl_close($hCurl);

		return FALSE;
	}

	private function attachPicture($aHoliday) {

		//get 5 image urls from google image search
		$aImages = $this->imageSearch($aHoliday);

		//loop over urls until we find one that's live and accessible
		$sImageUrl = FALSE;
		shuffle($aImages);
		while($aImages) {
			//take url from array and fetch it
			$sImageUrl = array_pop($aImages);
			$sImageBinary = @file_get_contents($sImageUrl);

			//if we succeeded, break from loop
			if ($sImageBinary) {
				break;
			}
		}

		if ($sImageUrl && $sImageBinary) {

			printf("- Attaching %s..\n", $sImageUrl);

			$sImageBinary = base64_encode($sImageBinary);
			if ($sImageBinary && strlen($sImageBinary) < 5 * pow(1024, 2)) { //max size is 3MB

				$oRet = $this->oTwitter->upload('media/upload', array('media' => $sImageBinary));
				if (isset($oRet->errors)) {
					$this->logger(2, sprintf('Twitter API call failed: media/upload (%s)', $oRet->errors[0]->message), array('holiday' => serialize($aHoliday), 'image' => $sImageUrl, 'length' => strlen($sImageBinary)));
					$this->halt('- Error: ' . $oRet->errors[0]->message . ' (code ' . $oRet->errors[0]->code . ')');
					return FALSE;
				} else {

					return $oRet->media_id_string;
				}
			}
		} else {
			echo "- No images could be fetched\n";
		}

		return FALSE;
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

	private function calculateDynamicDate($aHoliday) {

		if (strpos($aHoliday['dynamic'], ':easter_orthodox') !== FALSE) {

			//holiday related to date of Orthodox Easter (easter according to eastern christianity)
			$iDynamicHoliday = strtotime(str_replace(':easter', date('Y-m-d', $this->easter_orthodox_date()), $aHoliday['dynamic']));
		} elseif (strpos($aHoliday['dynamic'], ':easter') !== FALSE) {

			//holiday related to date of Easter (first Sunday after full moon on or after March 21st
			$iDynamicHoliday = strtotime(str_replace(':easter', date('Y-m-d', easter_date()), $aHoliday['dynamic']));

		} elseif (strpos($aHoliday['dynamic'], ':equinox_vernal') !== FALSE) {

			//holiday related to the vernal equinox (march 20/21)
			$iDynamicHoliday = strtotime(str_replace(':equinox_vernal', date('Y-m-d', $this->equinox_vernal_date()), $aHoliday['dynamic']));

		} elseif (strpos($aHoliday['dynamic'], ':equinox_autumnal') !== FALSE) {

			//holiday related to the autumnal equinox (september 22/23)
			$iDynamicHoliday = strtotime(str_replace(':equinox_autumnal', date('Y-m-d', $this->equinox_autumnal_date()), $aHoliday['dynamic']));

		} elseif (strpos($aHoliday['dynamic'], ':summer_solstice') !== FALSE) {

			//holiday related to the summer solstice (longest day, june 20/21/22)
			$iDynamicHoliday = strtotime(str_replace(':summer_solstice', $this->summer_solstice_date(), $aHoliday['dynamic']));

		} elseif (strpos($aHoliday['dynamic'], ':winter_solstice') !== FALSE) {

			//holiday related to the winter solstice (longest day, june 20/21/22)
			$iDynamicHoliday = strtotime(str_replace(':winter_solstice', $this->winter_solstice_date(), $aHoliday['dynamic']));

		} else {

			//normal relative holiday
			if (isset($aHoliday['day']) && $aHoliday['day']) {
				//e.g. 2009-05-01 next sunday
				//TODO: if there are dynamic dates with day that include '-/+ x day', then add if/else like below
				$iDynamicHoliday = strtotime(sprintf('%s-%s-%s %s', date('Y'), $aHoliday['month'], $aHoliday['day'], $aHoliday['dynamic']));
			} else {
				if (strpos($aHoliday['dynamic'], '%s') !== FALSE) {
					//e.g. first sunday of may 2009 - 3 day (dynamic field will be first sprintf arg)
					$iDynamicHoliday = strtotime(sprintf($aHoliday['dynamic'], date('F', mktime(0, 0, 0, $aHoliday['month'])) . ' ' . date('Y')));
				} else {
					//e.g. first sunday of may 2009
					$iDynamicHoliday = strtotime(sprintf('%s of %s %s', $aHoliday['dynamic'], date('F', mktime(0, 0, 0, $aHoliday['month'])), date('Y')));
				}
			}
		}

		return $iDynamicHoliday;
	}

	//get this year's vernal equinox date (timestamp)
	private function equinox_vernal_date($year = FALSE) {

		//http://www.phpro.org/examples/Get-Vernal-Equinox.html

		$year = ($year ? $year : date('Y'));

		$gmt = gmmktime(0, 0, 0, 1, 1, 2000);
		$days_from_base = 79.3125 + ($year - 2000) * 365.2425;
		$seconds_from_base = $days_from_base * 86400;

		$equinox = round($gmt + $seconds_from_base);

		return $equinox;
	}

	//get this year's autumnal equinox date (timestamp)
	private function equinox_autumnal_date($year = FALSE) {

		$year = ($year ? $year : date('Y'));

		//this is probably not the best way but I can't find any code on it :(
		return $this->equinox_vernal_date($year) + 6 * 36 * 24 * 3600;
	}

	//get this year's summer solstice date (longest day of year, between 20-22 June)
	private function summer_solstice_date() {

		return $this->solstice_date('summer');
	}

	//get this year's summer solstice date (shortest day of year, between 21-23 December)
	private function winter_solstice_date() {

		return $this->solstice_date('winter');
	}

	private function solstice_date($type) {

		//adapted from here, with range just the relevant days instead of the entire year
		//http://stackoverflow.com/questions/23978449/calculating-summer-winter-solstice-in-php

		switch ($type) {
			case 'default':
			case 'summer':
				$start_date = sprintf('%s-%s-%s', date('Y'), 6, 19);
				$end_date = sprintf('%s-%s-%s', date('Y'), 6, 23);
				break;

			case 'winter':
				$start_date = sprintf('%s-%s-%s', date('Y'), 12, 20);
				$end_date = sprintf('%s-%s-%s', date('Y'), 12, 24);
				break;
		}
		$i = 0;

		//loop through the days
		while (strtotime($start_date) <= strtotime($end_date)) { 

			$sunrise = date_sunrise(strtotime($start_date), SUNFUNCS_RET_DOUBLE);
			$sunset = date_sunset(strtotime($start_date), SUNFUNCS_RET_DOUBLE);

			//calculate time difference
			$delta = $sunset - $sunrise;

			//store the time difference
			$delta_array[$i] = $delta;

			//store the date
			$dates_array[$i] = $start_date;

			//next day
			$start_date = date('Y-m-d', strtotime('+1 day', strtotime($start_date)));
			$i++;
		}

		switch ($type) {
			default:
			case 'summer':
				$key = array_search(max($delta_array), $delta_array);
				break;

			case 'winter':
				$key = array_search(min($delta_array), $delta_array);
				break;
		}

		return $dates_array[$key];
	}

	//get this year's orthodox easter date (timestamp)
	private function easter_orthodox_date() {

		//http://php.net/manual/en/function.easter-date.php#83794
		//https://en.wikipedia.org/wiki/Computus#Meeus.27_Julian_algorithm

		$year = date('Y');

		$a = $year % 4;
		$b = $year % 7;
		$c = $year % 19;
		$d = (19 * $c + 15) % 30;
		$e = (2 * $a + 4 * $b - $d + 34) % 7;
		$month = floor(($d + $e + 114) / 31);
		$day = (($d + $e + 114) % 31) + 1;

		return mktime(0, 0, 0, $month, $day + 13, $year);
	}

	public function importToDb() {

		if (!$this->oPDO) {
			$this->logger(1, 'No connection to database.');
			$this->halt('No connection to database.');
			return FALSE;
		}

		$sth = $this->oPDO->prepare('TRUNCATE TABLE holidays');
		$sth->execute();

		$aMonths = $this->getAllHolidays();
		foreach ($aMonths as $iMonth => $aDays) {
			foreach ($aDays as $iDay => $aHolidays) {
				foreach ($aHolidays as $aHoliday) {

					if (!$this->query('
						INSERT INTO holidays
						(month, day, name, region, country, note, important, dynamic, url)
						VALUES
						(:month, :day, :name, :region, :country, :note, :important, :dynamic, :url)',
						array(
							'month'		=> $iMonth,
							'day'		=> $iDay,
							'name'		=> utf8_decode($aHoliday['name']),
							'region'	=> $aHoliday['region'],
							'country'	=> $aHoliday['country'],
							'note'		=> $aHoliday['note'],
							'important'	=> $aHoliday['important'],
							'dynamic'	=> $aHoliday['dynamic'],
							'url'		=> $aHoliday['url'],
						)
					)) {
					}
				}
			}
		}

		echo 'done.';
	}
}
