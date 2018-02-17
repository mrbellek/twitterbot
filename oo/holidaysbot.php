<?php
require_once('autoload.php');
require_once('holidaysbot.inc.php');

use Twitterbot\Lib\Logger;
use Twitterbot\Lib\Config;
use Twitterbot\Lib\Auth;
use Twitterbot\Lib\Database;
use Twitterbot\Lib\Tweet;
use Twitterbot\Lib\Media;
use Twitterbot\Custom\Gis;

/**
 * TODO:
 * v import database, run locally for testing
 *   - don't forget to change the db connection settings back before uploading
 * v fetch today's holidays
 * v fetch dynamic holidays for today
 * v write posted holidays to config, post only once
 * v format tweet
 * v search google for image
 * v download image
 * v attach image
 * v post tweet
 * v test mode
 * v bug: ':easter - 46 day first Sunday' doesn't work!?
 *   - seems to only happen with easter dates for unknown reasons
 */

if (filter_input(INPUT_GET, 'test') || (!empty($argv[1]) && $argv[1] == 'test')) {
    (new HolidaysBot)->test();
} else {
    (new HolidaysBot)->run();
}

class HolidaysBot
{
    const DAYS_IN_YEAR = 365.2425;
    const SECONDS_IN_DAY = 86400;

    public function __construct()
    {
        $this->sUsername = 'HolidaysBot';
        $this->logger = new Logger;
    }

    public function run()
    {
        $this->oConfig = new Config;
        if ($this->oConfig->load($this->sUsername)) {

            $this->db = new Database($this->oConfig);

            if ((new Auth($this->oConfig))->isUserAuthed($this->sUsername)) {

                //get today's holidays
                if ($aDays = $this->getHolidays()) {

                    //pick random unposted holiday
                    if ($oHoliday = $this->getRandomHoliday($aDays)) {

                        //format tweet
                        $sTweet = $this->formatTweet($oHoliday);

                        //find random picture related to holiday
                        $aUrls = (new Gis)->imageSearch(implode(' ', array_filter([$oHoliday->name, $oHoliday->country, $oHoliday->region, $oHoliday->note, '-logo'])));
                        $sMediaId = '';
                        while ($aUrls && !$sMediaId) {
                            $this->logger->output('- Uploading attachment');
                            $sUrl = array_shift($aUrls);
                            $sMediaId = (new Media($this->oConfig))->upload($sUrl);
                            if (!$sMediaId) {
                                $this->logger->output('- failed, trying next picture');
                            }
                        }
                        if (!$sMediaId) {
                            $this->logger->output('- no pictures left, posting without attachment.');
                        }

                        $this->logger->output('Posting tweet..');
                        $oTweet = new Tweet($this->oConfig);
                        if ($sMediaId) {
                            $oTweet->setMedia($sMediaId);
                        }
                        if ($oTweet->post($sTweet)) {
                            $this->logger->output('- %s', $sTweet);
                        } else {
                            $this->logger->output('Tweet failed!');
                        }
                    } else {
                        $this->logger->output('- No unposted holidays left for today.');
                    }
                }

                $this->logger->output('done!');
            }
        }
    }

    /*private function getHolidayById($id) {

        $aHolidays = $this->db->query('SELECT * FROM holidays WHERE id = :id', [':id' => $id]);

        if ($aHolidays) {
            return (object)$aHolidays[0];
        } else {
            return false;
        }
    }*/

	private function getHolidays() {

		$this->logger->output('Fetching holidays..');

		$aHolidays = $this->getAllHolidays();

		if (!$aHolidays) {
			return false;
		}

        //get all non-dynamic holidays
		$aTodayHolidays = $this->getAllHolidays(date('n'), date('j'));
        foreach ($aTodayHolidays as $key => $oHoliday) {
            if ($oHoliday->dynamic) {
                unset($aTodayHolidays[$key]);
            }
        }

		//check all dynamic holidays, find date and add back to list if today
		foreach ($aHolidays as $oHoliday) {

			if ($oHoliday->dynamic) {

				$iDynamicHoliday = $this->calculateDynamicDate($oHoliday);
				if ($iDynamicHoliday) {
					$iDynamicMonth = date('n', $iDynamicHoliday);
					$iDynamicDay = date('j', $iDynamicHoliday);

					if ($iDynamicMonth == date('n') && $iDynamicDay == date('j')) {
						//add to today's holidays
						$aTodayHolidays[] = $oHoliday;
					}
				}
			}
		}

		return $aTodayHolidays;
	}

    private function getAllHolidays($iMonth = false, $iDay = false)
    {
        if ($iMonth && $iDay) {

            $aHolidays = $this->db->query('
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

            $aHolidays = $this->db->query('SELECT * FROM holidays ORDER BY month, day');
        }

        //force records into object form
        $aHolidayObjs = [];
        foreach ($aHolidays as $aHoliday) {
            $aHolidayObjs[] = (object)$aHoliday;
        }

        return $aHolidayObjs;
	}

	private function getRandomHoliday($aHolidays) {

		$this->logger->output('Getting random holiday from today\'s %d that hasn\'t been posted yet..', count($aHolidays));

        // get info about past posted holidays today
        $aPosted = [];
        $oLastRun = $this->oConfig->get('lastrun');

        // get hashes of already posted holidays, if last run was today
        if ($oLastRun && $oLastRun->date == date('Y-m-d')) {
            $aPosted = $oLastRun->posted;
        }

		//remove all holidays from array that we already picked before
        foreach ($aPosted as $sChecksum) {
            foreach ($aHolidays as $i => $oHoliday) {
                if ($sChecksum == sha1(json_encode($oHoliday))) {
                    unset($aHolidays[$i]);
                }
            }
        }

		//if nothing left to pick, return
		if (!$aHolidays) {
			$this->logger->output('- No holidays left for today');
			return false;
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
			$this->logger->output('- Picked a holiday marked important');
			$oHoliday = $aImportantHolidays[mt_rand(0, count($aImportantHolidays) - 1)];
		} else {

			//pick random holiday from array
			$aHolidays = array_values($aHolidays);
			$oHoliday = $aHolidays[mt_rand(0, count($aHolidays) - 1)];
		}

		//make note that we picked this holiday to prevent picking it again later
        $aPosted[] = sha1(json_encode($oHoliday));
        $this->oConfig->set('lastrun', 'date', date('Y-m-d'));
        $this->oConfig->set('lastrun', 'posted', $aPosted);
        $this->oConfig->writeConfig();

		return $oHoliday;
	}

    public function test()
    {
        $this->oConfig = new Config;
        if ($this->oConfig->load($this->sUsername)) {

            $this->db = new Database($this->oConfig);

            $iMonth = filter_input(INPUT_GET, 'month', FILTER_SANITIZE_NUMBER_INT);
            $iDay = filter_input(INPUT_GET, 'day', FILTER_SANITIZE_NUMBER_INT);

            if ($iMonth || $iDay) {
                $aHolidays = $this->getAllHolidays($iMonth, $iDay);
            } else {
                $aHolidays = $this->getAllHolidays();
            }

            //print tweets for all holidays to verify they fit
            $iDynamic = 0;
            $iImportant = 0;
            $iErrors = 0;
            $iOneTime = 0;
            if ($aHolidays) {
                foreach ($aHolidays as $oHoliday) {
                    if ($oHoliday->dynamic) {
                        $iDynamic++;
                    }
                    if ($oHoliday->important) {
                        $iImportant++;
                    }
                    if ($oHoliday->year) {
                        $iOneTime++;
                    }
                    if (!$this->testPostMessage($oHoliday)) {
                        $iErrors++;
                    }
                }
            }
            $this->logger->output('<hr>done. %d holidays total (<font color="blue">%d important</font>, <font color="orange">%d dynamic</font>, <font color="green">%d one-time</font>), <b>%d errors</b>',
                count($aHolidays),
                $iImportant,
                $iDynamic,
                $iOneTime,
                $iErrors
            );
        }
    }

    private function testPostMessage($oHoliday)
    {
        $sTweet = $this->formatTweet($oHoliday);
        if (!$sTweet) {
            $this->logger->output('Formatting tweet for holiday %s failed.', $oHoliday->name);
            return false;
        }

		$sTempTweet = preg_replace('/https:\/\/\S+/', str_repeat('x', 23), $sTweet);

		//for dynamic holidays, find date
		if ($oHoliday->dynamic) {
			$iDynamicHoliday = $this->calculateDynamicDate($oHoliday);

			//replace month + date, add log note
			if ($iDynamicHoliday) {
				$oHoliday->month = date('m', $iDynamicHoliday);
				$oHoliday->day = date('d', $iDynamicHoliday);
			}
		}
		
		//check if formatted tweet has room for attached image (23 + 1 chars)
		if (strlen($sTempTweet) > 280 - 24) {
			$this->logger->output("<hr>- %d-%d <b style='color: red;'>[%d]</b> %s<hr>", $oHoliday->month, $oHoliday->day, strlen($sTempTweet), utf8_decode($sTweet));
            return false;
		} else {
            if ($oHoliday->important) {
                $sColor = 'blue';
            } elseif ($oHoliday->dynamic) {
                $sColor = 'orange';
            } elseif ($oHoliday->year) {
                $sColor = 'green';
            } else {
                $sColor = 'black';
            }
            $this->logger->output("- <font color='%s'>%d-%d <b>[%d]</b> %s</font>", $sColor, $oHoliday->month, $oHoliday->day, strlen($sTempTweet), utf8_encode($sTweet));

            return true;
		}
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

        $aTweetFormats = $this->oConfig->get('formats');

		if (empty($aTweetFormats)) {
			$this->logger->write(2, 'Tweet format settings missing.');
			$this->logger->output('- One or more of the tweet format settings are missing, halting.');
			return false;
		}

		//find correct tweet format for holiday information
		$sTweet = '';
		foreach ($aTweetFormats as $sTweetFormat => $oPlaceholders) {
			if (trim($oPlaceholders->country) == ($oHoliday->country ? true : false) &&
				trim($oPlaceholders->region) == ($oHoliday->region ? true : false) &&
				trim($oPlaceholders->note) == ($oHoliday->note ? true : false)) {

				$sTweet = $sTweetFormat;
				break;
			}
		}

		if (!$sTweet) {
			$this->logger->write(2, sprintf('No tweet format found for holiday. (%s)', $oHoliday->name));
			$this->logger->output('- No tweet format could be found for this holiday, halting.');
			return false;
		}

		//construct tweet
		foreach (get_object_vars($oHoliday) as $sProperty => $sValue) {
			$sTweet = str_replace(':' . $sProperty, $sValue, $sTweet);
		}

		//trim trailing space for holidays without url
		return trim($sTweet);
    }

	private function calculateDynamicDate($oHoliday) {

        //WARNING: strtotime will not parse stuff like '2010-01-01 - 46 day first Sunday'!!

		if (strpos($oHoliday->dynamic, ':easter_orthodox') !== false) {

			//holiday related to date of Orthodox Easter (easter according to eastern christianity)
			$iDynamicHoliday = strtotime(str_replace(':easter', date('Y-m-d', $this->easter_orthodox_date()), $oHoliday->dynamic));

		} elseif (strpos($oHoliday->dynamic, ':easter') !== false) {

			//holiday related to date of Easter (first Sunday after full moon on or after March 21st
			$iDynamicHoliday = strtotime(str_replace(':easter', date('Y-m-d', easter_date()), $oHoliday->dynamic));

		} elseif (strpos($oHoliday->dynamic, ':equinox_vernal') !== false) {

			//holiday related to the vernal equinox (march 20/21)
			$iDynamicHoliday = strtotime(str_replace(':equinox_vernal', date('Y-m-d', $this->equinox_vernal_date()), $oHoliday->dynamic));

		} elseif (strpos($oHoliday->dynamic, ':equinox_autumnal') !== false) {

			//holiday related to the autumnal equinox (september 22/23)
			$iDynamicHoliday = strtotime(str_replace(':equinox_autumnal', date('Y-m-d', $this->equinox_autumnal_date()), $oHoliday->dynamic));

		} elseif (strpos($oHoliday->dynamic, ':summer_solstice') !== false) {

			//holiday related to the summer solstice (longest day, june 20/21/22)
			$iDynamicHoliday = strtotime(str_replace(':summer_solstice', $this->summer_solstice_date(), $oHoliday->dynamic));

		} elseif (strpos($oHoliday->dynamic, ':winter_solstice') !== false) {

			//holiday related to the winter solstice (longest day, december 21/22/23)
			$iDynamicHoliday = strtotime(str_replace(':winter_solstice', $this->winter_solstice_date(), $oHoliday->dynamic));

		} else {

			//normal relative holiday
			if (isset($oHoliday->day) && $oHoliday->day) {
				//e.g. 2009-05-01 next sunday
				//TODO: if there are dynamic dates with day that include '-/+ x day', then add if/else like below
				$iDynamicHoliday = strtotime(sprintf('%s-%s-%s %s', date('Y'), $oHoliday->month, $oHoliday->day, $oHoliday->dynamic));
			} else {
				if (strpos($oHoliday->dynamic, '%s') !== false) {
					//e.g. first sunday of may 2009 - 3 day (dynamic field will be first sprintf arg)
					$iDynamicHoliday = strtotime(sprintf($oHoliday->dynamic, date('F', mktime(0, 0, 0, $oHoliday->month)) . ' ' . date('Y')));
				} else {
					//e.g. first sunday of may 2009
					$iDynamicHoliday = strtotime(sprintf('%s of %s %s', $oHoliday->dynamic, date('F', mktime(0, 0, 0, $oHoliday->month)), date('Y')));
				}
			}
		}

		return $iDynamicHoliday;
	}

	//get this year's vernal equinox date (timestamp)
	private function equinox_vernal_date($year = false) {

		//http://www.phpro.org/examples/Get-Vernal-Equinox.html

		$year = ($year ? $year : date('Y'));

		$gmt = gmmktime(0, 0, 0, 1, 1, 2000);
		$days_from_base = 79.3125 + ($year - 2000) * self::DAYS_IN_YEAR;
		$seconds_from_base = $days_from_base * self::SECONDS_IN_DAY;

		$equinox = round($gmt + $seconds_from_base);

		return $equinox;
	}

	//get this year's autumnal equinox date (timestamp)
	private function equinox_autumnal_date($year = false) {

		$year = ($year ? $year : date('Y'));

		//this is probably not the best way but I can't find any code on it :(
		return $this->equinox_vernal_date($year) + 6 * 36 * self::SECONDS_IN_DAY;
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
}
