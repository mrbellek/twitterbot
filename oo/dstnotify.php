<?php
require_once('autoload.php');
require_once('dstnotify.inc.php');

/**
 * TODO:
 * v check for DST changes now, tomorrow, next week
 * - reply to mentions with questions
 */

use Twitterbot\Lib\Logger;
use Twitterbot\Lib\Config;
use Twitterbot\Lib\Auth;
use Twitterbot\Lib\Ratelimit;
use Twitterbot\Lib\Format;
use Twitterbot\Lib\Tweet;

use Twitterbot\Lib\Reply;

class DSTNotify 
{
	private $aAnswerPhrases = array(
		'reply_default'			=> 'I didn\'t understand your question! You can ask when #DST starts or ends in any country, or since when it\'s (not) used.',
		'reply_no_dst'			=> '%s does not observe DST. %s',
									//e.g.: DST starts in Belgium on the last Sunday of March (2015: 29th. 2016: 28th). More info: ..
		'reply_dst_startstop'	=> '#DST in %s %ss on the %s (%s). %s', 
									//e.g.: DST has not been observed in Russia since 1947. More info: ...
		'reply_dst_since'		=> '#DST has%s been observed in %s since %s. %s', 
									//e.g.: DST is not observed in Mongolia. More info: ...
		'reply_dst'				=> '#DST is%s observed in %s. %s', 
									//e.g.: Next DST change is: DST starts in United States etc...
		'reply_dst_next'		=> 'Next change: %s', 

		'extra_perma_since'		=> 'It has permanently been in effect since %d.',
		'extra_not_since'		=> 'It has not since %d',
		'extra_more_info'		=> ' More info: %s',
		'extra_perma_always'	=> ' DST is permanently in effect.',
        'extra_perma_never'		=> ' DST is permanently not in effect.',
	);

    private $aQuestionKeywords = array(
		'next' => array('next'),                //when is next DST change for..
		'start' => array('start', 'begin'),     //when does DST start in..
		'end' => array('stop', 'end' , 'over'), //when does DST end in..
		'since' => array('since', 'when'),      //since when does .. have DST
	);

    public function __construct()
    {
        $this->sUsername = 'DSTNotify';
        $this->logger = new Logger;
    }

    public function run()
    {
        $this->oConfig = new Config;
        if ($this->oConfig->load($this->sUsername)) {

            if ((new Ratelimit($this->oConfig))->check()) {

                if ((new Auth($this->oConfig))->isUserAuthed($this->sUsername)) {

                    $aTweets = $this->checkDST();

                    if ($aTweets) {
                        $this->logger->output('Posting %d tweets..', count($aTweets));
                        foreach ($aTweets as $aTweet) {
                            (new Tweet($this->oConfig))
                                ->post($aTweet);
                        }
                    }

                    $this->logger->output('done!');
                }
            }
        }
    }

    public function runMentions()
    {
        $this->oConfig = new Config;
        if ($this->oConfig->load($this->sUsername)) {

            if ((new Ratelimit($this->oConfig))->check()) {

                if ((new Auth($this->oConfig))->isUserAuthed($this->sUsername)) {

                    $this->processMentions();
                    $this->logger->output('done!');
                }
            }
        }
    }

    private function checkDST()
    {
        $this->logger->output('Checking for DST start..');
        $aTweets = array();

        $sToday = strtotime(date('Y-m-d UTC'));

        //check if any of the countries are switching to DST (summer time) NOW
        if ($aGroups = $this->checkDSTStart($sToday)) {
            $aTweets = array_merge($aTweets, $this->formatTweetDST('starting', $aGroups, 'today'));
            $this->logger->output('- %s groups start DST today!', count($aGroups));
        } else {
            $this->logger->output('- No groups start DST today.');
            $this->logger->write(5, 'No groups start DST today.');
        }

        //check if any of the countries are switching to DST (summer time) in 24 hours
        if ($aGroups = $this->checkDSTStart($sToday + 24 * 3600)) {
            $aTweets = array_merge($aTweets, $this->formatTweetDST('starting', $aGroups, 'tomorrow'));
            $this->logger->output('- %s groups start DST tomorrow!', count($aGroups));
        } else {
            $this->logger->output('- No groups start DST tomorrow.');
            $this->logger->write(5, 'No groups start DST tomorrow.');
        }

        //check if any of the countries are switching to DST (summer time) in 7 days
        if ($aGroups = $this->checkDSTStart($sToday + 7 * 24 * 3600)) {
            $aTweets = array_merge($aTweets, $this->formatTweetDST('starting', $aGroups, 'next week'));
            $this->logger->output('- %s groups start DST next week!', count($aGroups));
        } else {
            $this->logger->output('- No groups start DST next week.');
            $this->logger->write(5, 'No groups start DST next week.');
        }

        $this->logger->output('Checking for DST end..');

        //check if any of the countries are switching from DST (winter time) NOW
        if ($aGroups = $this->checkDSTEnd($sToday)) {
            $aTweets = array_merge($aTweets, $this->formatTweetDST('ending', $aGroups, 'today'));
            $this->logger->output('- %s groups exit DST today!', count($aGroups));
        } else {
            $this->logger->output('- No groups exit DST today.');
            $this->logger->write(5, 'No groups exit DST today.');
        }

        //check if any of the countries are switching from DST (winter time) in 24 hours
        if ($aGroups = $this->checkDSTEnd($sToday + 24 * 3600)) {
            $aTweets = array_merge($aTweets, $this->formatTweetDST('ending', $aGroups, 'tomorrow'));
            $this->logger->output('- %s groups exit DST tomorrow!', count($aGroups));
        } else {
            $this->logger->output('- No groups exit DST tomorrow.');
            $this->logger->write(5, 'No groups exit DST tomorrow.');
        }

        //check if any of the countries are switching from DST (winter time) in 7 days
        if ($aGroups = $this->checkDSTEnd($sToday + 7 * 24 * 3600)) {
            $aTweets = array_merge($aTweets, $this->formatTweetDST('ending', $aGroups, 'next week'));
            $this->logger->output('- %s groups exit DST next week!', count($aGroups));
        } else {
            $this->logger->output('- No groups exit DST next week.');
            $this->logger->write(5, 'No groups exit DST next week.');
        }

        return $aTweets;
    }

    //check if DST starts (summer time start) for any of the countries
    private function checkDSTStart($iTimestamp) {

        $aGroupsDSTStart = array();
        foreach ($this->oConfig->get('dst') as $sGroup => $oSetting) {

            if ($sGroup != 'no dst') {

                //convert 'last sunday of march 2014' to timestamp (DST independent)
                $iDSTStart = strtotime(sprintf('%s %s UTC', $oSetting->start, date('Y')));

                if ($iDSTStart == $iTimestamp) {

                    //DST will start here
                    $aGroupsDSTStart[$sGroup] = $oSetting;
                }
            }
        }

        return ($aGroupsDSTStart ? $aGroupsDSTStart : array());
    }

    //check if DST ends (winter time start) for any of the countries
    private function checkDSTEnd($iTimestamp) {

        $aGroupsDSTEnd = array();
        foreach ($this->oConfig->get('dst') as $sGroup => $oSetting) {

            if ($sGroup != 'no dst') {

                //convert 'last sunday of march 2014' to timestamp
                $iDSTEnd = strtotime(sprintf('%s %s UTC', $oSetting->end, date('Y')));

                if ($iDSTEnd == $iTimestamp) {

                    //DST will end here
                    $aGroupsDSTEnd[$sGroup] = $oSetting;
                }
            }
        }

        return ($aGroupsDSTEnd ? $aGroupsDSTEnd : array());
    }

    private function formatTweetDST($sEvent, $aGroups, $sDelay) {

        $aTweets = array();
        foreach ($aGroups as $sGroup => $oSetting) {

            $sCountries = (isset($aGroup['name']) ? $aGroup['name'] : ucwords($sGroup));

            $aTweets[] = (new Format($this->oConfig))->format((object) array(
                'event' => $sEvent,
                'delay' => $sDelay,
                'countries' => $sCountries,
            ));
        }

        return $aTweets;
    }

    private function processMentions()
    {
        //fetch new mentions since last run
        $oReply = new Reply($this->oConfig);
        if ($aMentions = $oReply->getMentions()) {
            foreach ($aMentions as $oMention) {
                $this->replyToMention($oMention);
            }
            $this->logger->output('Processed %d mentions.', count($aMentions));
        }
    }

    private function replyToMention($oMention)
    {
        //ignore mentions where our name is not at the start of the tweet
        if (stripos($oMention->text, '@' . $this->sUsername) !== 0) {
            return true;
        }

        //get actual question from tweet
        $sId = $oMention->id_str;
        $sQuestion = str_replace('@' . strtolower($this->sUsername) . ' ', '', strtolower($oMention->text));
        $this->logger->output('Parsing question "%s" from %s..', $sQuestion, $oMention->user->screen_name);

        //find type of question
		$sEvent = $this->findQuestionType($sQuestion);
        if (!$sEvent) {
            return $this->replyToQuestion($oMention, $this->aAnswerPhrases['reply_default']);
        }

		//find country in question, if any
		$aCountryInfo = $this->findQuestionCountry($oMention, $sEvent);

		//construct info beyond basic reply
		$sExtra = $this->getExtraInfo($aCountryInfo);

		if (!$aCountryInfo) {
            //couldn't understand question or find country, default reply
            return $this->replyToQuestion($oMention, $this->aAnswerPhrases['reply_default']);
		} elseif ($aCountryInfo['group'] == 'no dst') {
            //DST not in effect in target country
            return $this->replyToQuestion($oMention, sprintf($this->aAnswerPhrases['reply_no_dst'], $aCountryInfo['name'], $sExtra));
		}

		//reply based on event
		switch ($sEvent) {
			case 'start':
			case 'end':

				//example: #DST [start]s in [Belgium] on the [last sunday of march] ([2015: 29th, 2016: 28th). [More info: ...]
				return $this->replyToQuestion($oMention, sprintf($this->aAnswerPhrases['reply_dst_startstop'],
					$aCountryInfo['name'],
					$sEvent,
					$aCountryInfo[$sEvent],
					$aCountryInfo[$sEvent . 'day'],
					trim($sExtra)
				));
				break;

			case 'since':

				//example: DST has not been observed in Russia since 1947
				//return $this->replyToQuestion($oMention, sprintf('#DST has%s been observed in %s since %s. %s',
				if (!empty($aCountryInfo['since'])) {

					return $this->replyToQuestion($oMention, sprintf($this->aAnswerPhrases['reply_dst_since'],
						($aCountryInfo['group'] == 'no dst' ? ' not' : ''),
						$aCountryInfo['name'],
						$aCountryInfo['since'],
						trim($sExtra)
					));

				} else {

					return $this->replyToQuestion($oMention, sprintf($this->aAnswerPhrases['reply_dst'],
						($aCountryInfo['group'] == 'no dst' ? ' not' : ''),
						$aCountryInfo['name'],
						trim($sExtra)
					));
				}
				break;

			case 'next':

				//'next' event is special: aCountryInfo can either contain start or stop event info
				//so determine which of the two occurs first from now
				if (!isset($aCountryInfo['event'])) {
					$iNextStart = strtotime($aCountryInfo['start'] . ' ' . date('Y'));
					$iNextStartY = strtotime($aCountryInfo['start'] . ' ' . (date('Y') + 1));
					$iNextEnd = strtotime($aCountryInfo['end'] . ' ' . date('Y'));
					$iNextEndY = strtotime($aCountryInfo['end'] . ' ' . (date('Y') + 1));

					$iNextStart = ($iNextStart < time() ? $iNextStartY : $iNextStart);
					$iNextEnd = ($iNextEnd < time() ? $iNextEndY : $iNextEnd);

					$sEvent = ($iNextStart < $iNextEnd ? 'start' : 'end');
				} else {
					$sEvent = $aCountryInfo['event'];
				}

				//example: Next change: DST starts in United States on the last Sunday of March (2014: 29th, 2015: 28th).
				return $this->replyToQuestion($oMention, sprintf(sprintf($this->aAnswerPhrases['reply_dst_next'],
						$this->aAnswerPhrases['reply_dst_startstop']),
					$aCountryInfo['name'],
					$sEvent,
					$aCountryInfo[$sEvent],
					$aCountryInfo[$sEvent . 'day'],
					trim($sExtra)
				));
		}

        //event not understood, send default reply
        return $this->replyToQuestion($oMention, $this->aAnswerPhrases['reply_default']);
    }

    private function findQuestionType($sTweet)
    {
        //the order of the arrays at the top of the class is the order we're searching in
        foreach ($this->aQuestionKeywords as $sEvent => $aWords) {
			foreach ($aWords as $sWord) {
				if (stripos($sTweet, $sWord) !== false) {
					return $sEvent;
				}
			}
        }

		return false;
    }

    private function findQuestionCountry($oMention, $sEvent)
    {

        //try to find country in tweet
        $aCountryInfo = $this->findCountryInText($oMention->text);
        if (!$aCountryInfo && !empty($oMention->user->location) && stripos($oMention->text, 'worldwide') === false) {

            //try to find country name in 'location' field of user profile (unless tweet contains 'worldwide')
            $aCountryInfo = $this->findCountryInText($oMention->user->location);
        }

        if (!$aCountryInfo) {
            if ($sEvent == 'next') {
                //for 'next' event without country, use 'worldwide' i.e. get next DST change anywhere
                $aCountryInfo = $this->getNextDSTChange();
            } else {
                //no country found, reply default
                return false;
            }

        } elseif ($aCountryInfo && $sEvent == 'next') {
            //if country was found AND no event was specified ('when is next change'), find whichever is soonest
            $aNextChange = $this->getNextCountryChange($aCountryInfo);
            if ($aNextChange['event']) {
                $aCountryInfo['event'] = $aNextChange['event'];
            }
        }

		return $aCountryInfo;
    }

    private function findCountryInText($sText)
    {
        $aCountryInfo = array();

        //try to find country in text
        foreach ($this->oConfig->get('dst') as $sGroupName => $oGroup) {

            //check for group name
            if (stripos($sText, $sGroupName) !== false) {
                $aFoundCountry[$sGroupName] = (array) $oGroup;
                $aFoundCountry[$sGroupName]['name'] = ucwords($sGroupName);
                break;
            }

            //check 'includes' array
            if (isset($oGroup->includes)) {
                foreach ($oGroup->includes as $sName => $oInclude) {

                    //check name of country against question
                    if (stripos($sText, $sName) !== false) {
                        $aFoundCountry[$sGroupName] = array_merge((array) $oGroup, (array) $oInclude);
                        $aFoundCountry[$sGroupName]['name'] = ucwords($sName);
                        unset($aFoundCountry[$sGroupName]['includes']);
                        unset($aFoundCountry[$sGroupName]['excludes']);
                        unset($aFoundCountry[$sGroupName]['alias']);
                        break 2;
                    }

                    if (!empty($oInclude->alias)) {
                        foreach ($oInclude->alias as $sAlias) {
                            if (stripos($sText, $sAlias) !== false) {
                                $aFoundCountry[$sGroupName] = array_merge((array) $aGroup, (array) $oInclude);
                                $aFoundCountry[$sGroupName]['name'] = ucwords($sName);
                                unset($aFoundCountry[$sGroupName]['includes']);
                                unset($aFoundCountry[$sGroupName]['excludes']);
                                unset($aFoundCountry[$sGroupName]['alias']);
                                break 2;
                            }
                        }
                    }
                }
            }
        }

        if (isset($aFoundCountry)) {

            $sGroupName = key($aFoundCountry);
            $aGroup = $aFoundCountry[$sGroupName];
            $aGroup['group'] = $sGroupName;

            return $this->formatCountryInfo((object) $aGroup);
        }

        return false;
    }

    private function getNextDSTChange()
    {
        $iNextChange = strtotime('+1 year');
        $aNextCountry = array();

        foreach ($this->oConfig->get('dst') as $sGroupName => $oGroup) {

            if ($sGroupName != 'no dst') {
                $oGroup->group = $sGroupName;

                //get next DST change for this group
                $aChange = $this->getNextCountryChange($oGroup); //TODO: check arg use in this function

                //save closest change
                if ($aChange['event'] && $aChange['timestamp'] < $iNextChange) {
                    $oNextCountry = $oGroup;
                    $oNextCountry->event = $aChange['event'];
                    $iNextChange = $aChange['timestamp'];
                }

                if (empty($oNextCountry->name)) {
                    $oNextCountry->name = ucwords($sGroupName);
                }
            }
        }

        return $this->formatCountryInfo($oNextCountry);
    }

    private function getNextCountryChange($oGroup)
    {
        $aReturn = array(
            'event' => false,
            'timestamp' => false,
        );

        if (isset($oGroup->start)) {

            //check if this DST start is this year or next
            if (strtotime($oGroup->start . ' ' . date('Y')) > time()) {
                $iChange = strtotime($oGroup->start . ' ' . date('Y'));
            } else {
                $iChange = strtotime($oGroup->start . ' ' . (date('Y') + 1));
            }

            $aReturn['event'] = 'start';
            $aReturn['timestamp'] = $iChange;
        }

        if (isset($oGroup->end)) {
            //check if this DST stop is this year or next
            if (strtotime($oGroup->end . ' ' . date('Y')) > time()) {
                $iChange = strtotime($oGroup->end . ' ' . date('Y'));
            } else {
                $iChange = strtotime($oGroup->end . ' ' . (date('Y') + 1));
            }

            if ($iChange < $aReturn['timestamp']) {
                $aReturn['event'] = 'end';
                $aReturn['timestamp'] = $iChange;
            }
        }

        return $aReturn;
    }

    private function formatCountryInfo($oGroup)
    {
        //format some fields, add some more for convenience
        $aCountryInfo = array(
            'group'     => (isset($oGroup->group)     ? $oGroup->group : FALSE),
            'name'      => (isset($oGroup->name)      ? ucwords($oGroup->name) : FALSE),
            'since'     => (isset($oGroup->since)     ? $oGroup->since : FALSE),
            'info'      => (isset($oGroup->info)      ? $oGroup->info : FALSE),
            'note'      => (isset($oGroup->note)      ? $oGroup->note : FALSE),
            'timezone'  => (isset($oGroup->timezone)  ? $oGroup->timezone : FALSE),
            'event'     => (isset($oGroup->event)     ? $oGroup->event : FALSE),
        );
        if ($aCountryInfo['group'] != 'no dst') {
            //start and end in relative terms (last sunday of september)
            $aCountryInfo['start'] = $this->capitalizeStuff($oGroup->start);
            $aCountryInfo['end'] = $this->capitalizeStuff($oGroup->end);

            //work out when that is for current + next year
            $sStartDayNow = date('jS', strtotime($oGroup->start . ' ' . date('Y')));
            $sStartDayNext = date('jS', strtotime($oGroup->start . ' ' . (date('Y') + 1)));
            $sEndDayNow = date('jS', strtotime($oGroup->end . ' ' . date('Y')));
            $sEndDayNext = date('jS', strtotime($oGroup->end . ' ' . (date('Y') + 1)));
            $aCountryInfo['startday'] = sprintf('%d: %s, %d: %s', date('Y'), $sStartDayNow, (date('Y') + 1), $sStartDayNext);
            $aCountryInfo['endday'] = sprintf('%d: %s, %d: %s', date('Y'), $sEndDayNow, (date('Y') + 1), $sEndDayNext);
        }
        if (isset($oGroup->permanent)) {
            $aCountryInfo['permanent'] = $oGroup->permanent;
        }

        return $aCountryInfo;
    }

    private function capitalizeStuff($sString)
    {
        //capitalize days of week and months
        $aCapitalize = array(
            'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday',
            'january', 'february', 'march', 'april', 'may', 'june',
            'july', 'august', 'september', 'october', 'november', 'december',
        );

        foreach ($aCapitalize as $sWord) {
            $sString = str_ireplace($sWord, ucfirst($sWord), $sString);
        }

        return $sString;
    }

    private function getExtraInfo($aCountryInfo)
    {
		if ($aCountryInfo['group'] == 'no dst') {
			//skip question type parsing if country does not observe DST
            $sExtra = '';
            if (!empty($aCountryInfo['since'])) {
				//normally just mention the year they stopped observing DST, unless it's permanently in effect
                if (isset($aCountryInfo['permanent']) && $aCountryInfo['permanent'] == 1) {
					$sExtra = sprintf($this->aAnswerPhrases['extra_perma_since'], $aCountryInfo['since']);
                } else {
					$sExtra = sprintf($this->aAnswerPhrases['extra_not_since'], $aCountryInfo['since']);
                }
            }

			return $sExtra;
        }

        //construct extra information
        $sExtra = '';
        if (!empty($aCountryInfo['note'])) {

            //some countries have a note in parentheses
            $sExtra .= $aCountryInfo['note'];
        }
        if (!empty($aCountryInfo['info'])) {

            //some countries are complicated, and have their own wiki page with more info
            $sExtra .= sprintf($this->aAnswerPhrases['extra_more_info'], $aCountryInfo['info']);
        }

        if (isset($aCountryInfo['permanent'])) {
            //some countries have permanent DST in effect
            if ($aCountryInfo['permanent'] == 1) {
				$sExtra .= $this->aAnswerPhrases['extra_perma_always'];
            } else {
                //just in case, never used
				$sExtra .= $this->aAnswerPhrases['extra_perma_never'];
            }
        }

		return $sExtra;
    }

    private function replyToQuestion($oMention, $sReply)
    {
        $oTweet = new Tweet($this->oConfig);

        //remove spaces where needed
        $sReply = trim(preg_replace('/ +/', ' ', $sReply));

        return $oTweet->replyTo($oMention, $sReply);
    }
}

//RUN SCRIPT WITH CLI ARGUMENT 'mentions' TO PARSE & REPLY TO MENTIONS
if (!empty($argv[1]) && $argv[1] == 'mentions') {
    (new DSTNotify)->runMentions();
} else {
    (new DSTNotify)->run();
}
