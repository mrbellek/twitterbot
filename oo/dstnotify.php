<?php
require_once('autoload.php');
require_once('dstnotify.inc.php');

/**
 * TODO:
 * v check for DST changes now, tomorrow, next week
 * v move dst info to database
 * v management page
 * . post tweets
 * - reply to mentions with questions
 * - attach picture with visual instructions on what happens to the clock?
 */

use Twitterbot\Lib\Logger;
use Twitterbot\Lib\Config;
use Twitterbot\Lib\Auth;
use Twitterbot\Lib\Ratelimit;
use Twitterbot\Lib\Database;
use Twitterbot\Lib\Format;
use Twitterbot\Lib\Tweet;
use Twitterbot\Lib\Reply;

if (!empty($argv[1]) && $argv[1] == 'mentions') {
    //RUN SCRIPT WITH CLI ARGUMENT 'mentions' TO PARSE & REPLY TO MENTIONS
    (new DSTNotify)->runMentions();
} elseif (!empty($argv[1]) && $argv[1] == 'test') {
    //nothing
} else {
    (new DSTNotify)->run();
}

class DSTNotify 
{
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

                    $this->db = new Database($this->oConfig);
                    //die(var_dump($this->findCountryInText('when does dst start in netherlands?')));

                    $aTweets = $this->checkDST();

                    if ($aTweets) {
                        $this->logger->output('Posting %d tweets..', count($aTweets));
                        foreach ($aTweets as $aTweet) {
                            (new Tweet($this->oConfig))->post($aTweet);
                        }
                    }

                    $this->logger->output('done!');
                }
            }
        }
    }

    public function runTest()
    {
        $this->oConfig = new Config;
        if ($this->oConfig->load($this->sUsername)) {
            $this->db = new Database($this->oConfig);
        }
    }

    public function runMentions()
    {
        $this->oConfig = new Config;
        if ($this->oConfig->load($this->sUsername)) {

            if ((new Ratelimit($this->oConfig))->check()) {

                if ((new Auth($this->oConfig))->isUserAuthed($this->sUsername)) {

                    $this->db = new Database($this->oConfig);
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

        $sToday = strtotime(gmdate('Y-m-d'));

        $aDelays = [
            'today' => 0,
            'tomorrow' => 24 * 3600,
            'next week' => 7 * 24 * 3600,
        ];

        //check if any of the countries are switching to DST (summer time) either today, tomorrow or next week
        foreach ($aDelays as $sDelay => $iDelaySeconds) {
            if ($aGroups = $this->checkDSTStart($sToday + $iDelaySeconds)) {
                $aTweets = array_merge($aTweets, $this->formatTweetDST('starting', $aGroups, $sDelay));
                $this->logger->output('- %s groups start DST %s!', count($aGroups), $sDelay);
            } else {
                $this->logger->output('- No groups start DST %s.', $sDelay);
                $this->logger->write(5, 'No groups start DST %s.', $sDelay);
            }
        }

        $this->logger->output('Checking for DST end..');

        //check if any of the countries are switching from DST (winter time) today, tomorrow or next week
        foreach ($aDelays as $sDelay => $iDelaySeconds) {
            if ($aGroups = $this->checkDSTEnd($sToday + $iDelaySeconds)) {
                $aTweets = array_merge($aTweets, $this->formatTweetDST('ending', $aGroups, $sDelay));
                $this->logger->output('- %s groups exit DST %s!', count($aGroups), $sDelay);
            } else {
                $this->logger->output('- No groups exit DST %s.', $sDelay);
                $this->logger->write(5, 'No groups exit DST %s.', $sDelay);
            }
        }

        return $aTweets;
    }

    //check if DST starts (summer time start) for any of the countries
    private function checkDSTStart($iTimestamp)
    {
        $aGroupsDSTStart = array();

        //check groups
        foreach ($this->getAllGroups() as $aGroup) {
            if (strtolower($aGroup['shortname'] != 'no dst')) {

                //convert 'last sunday of march 2014' to timestamp 
                $iDSTStart = strtotime(sprintf('%s %s', $aGroup['start'], date('Y')));

                if ($iDSTStart == $iTimestamp) {

                    //DST will start here
                    $aGroup['includes'] = $this->getGroupCountries($aGroup['id']);
                    $aGroupsDSTStart[$aGroup['shortname']] = $aGroup;
                }
            }
        }

        //check countries without group
        foreach ($this->getUngroupedCountries() as $aCountry) {
            $iDSTStart = strtotime(sprintf('%s %s', $aCountry['start'], date('Y')));
            if ($iDSTStart == $iTimestamp) {
                $aGroupsDSTStart[$aCountry['name']] = $aCountry;
            }
        }

        return ($aGroupsDSTStart ? $aGroupsDSTStart : array());
    }

    //check if DST ends (winter time start) for any of the countries
    private function checkDSTEnd($iTimestamp) {

        $aGroupsDSTEnd = array();

        //check groups
        foreach ($this->getAllGroups() as $aGroup) {
            if (strtolower($aGroup['shortname'] != 'no dst')) {

                //convert 'last sunday of march 2014' to timestamp 
                $iDSTEnd = strtotime(sprintf('%s %s', $aGroup['end'], date('Y')));

                if ($iDSTEnd == $iTimestamp) {

                    //DST will end here
                    $aGroup['includes'] = $this->getGroupCountries($aGroup['id']);
                    $aGroupsDSTEnd[$aGroup['shortname']] = $aGroup;
                }
            }
        }

        //check countries without group
        foreach ($this->getUngroupedCountries() as $aCountry) {
            $iDSTEnd = strtotime(sprintf('%s %s', $aCountry['end'], date('Y')));
            if ($iDSTEnd == $iTimestamp) {
                $aGroupsDSTEnd[$aCountry['name']] = $aCountry;
            }
        }

        return ($aGroupsDSTEnd ? $aGroupsDSTEnd : array());
    }

    public function formatTweetDST($sEvent, $aGroups, $sDelay) {

        $aTweets = array();
        foreach ($aGroups as $sShortName => $aGroup) {
            if ($sShortName != 'No dst') {
                $sName = (isset($aGroup['name']) ? $aGroup['name'] : ucwords($aShortName));

                $aTweets[] = (new Format($this->oConfig))->format((object) [
                    'event' => $sEvent . 's',   //start[s] or end[s]
                    'delay' => $sDelay,         //today/tomorrow/next week
                    'countries' => $sName,      //group name or country name
                ]);
            }
        }

        return $aTweets;
    }

    private function getAllGroups()
    {
        return $this->db->query('
            SELECT g.*, GROUP_CONCAT(e.exclude SEPARATOR "|") AS excludes
            FROM dst_group g
            LEFT JOIN dst_exclude e ON e.group_id = g.id
            GROUP BY g.id'
        );
    }

    private function getGroupCountries($groupId)
    {
        return $this->db->query('
            SELECT c.*, GROUP_CONCAT(ca.alias SEPARATOR "|") AS aliases
            FROM dst_country c
            LEFT JOIN dst_country_alias ca ON ca.country_id = c.id
            WHERE c.group_id = :group_id
            GROUP BY c.id',
            [':group_id' => $groupId]
        );
    }

    private function getUngroupedCountries()
    {
        return $this->db->query('
            SELECT c.*, GROUP_CONCAT(ca.alias SEPARATOR "|") AS aliases
            FROM dst_country c
            LEFT JOIN dst_country_alias ca ON ca.country_id = c.id
            WHERE c.group_id IS NULL
            GROUP BY c.id'
        );
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
        $aAnswerPhrases = $this->oConfig->get('answers');

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
            return $this->replyToQuestion($oMention, $aAnswerPhrases->reply_default);
        }

		//find country in question, if any
		$aCountryInfo = $this->findQuestionCountry($oMention, $sEvent);

		//construct info beyond basic reply
		$sExtra = $this->getExtraInfo($aCountryInfo);

		if (!$aCountryInfo) {
            //couldn't understand question or find country, default reply
            return $this->replyToQuestion($oMention, $aAnswerPhrases->reply_default);
		} elseif ($aCountryInfo['group'] == 'no dst') {
            //DST not in effect in target country
            return $this->replyToQuestion($oMention, sprintf($aAnswerPhrases->reply_no_dst, $aCountryInfo['name'], $sExtra));
		}

		//reply based on event
		switch ($sEvent) {
			case 'start':
			case 'end':

				//example: #DST [start]s in [Belgium] on the [last sunday of march] ([2015: 29th, 2016: 28th). [More info: ...]
				return $this->replyToQuestion($oMention, sprintf($aAnswerPhrases->reply_dst_startstop,
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

					return $this->replyToQuestion($oMention, sprintf($aAnswerPhrases->reply_dst_since,
						($aCountryInfo['group'] == 'no dst' ? ' not' : ''),
						$aCountryInfo['name'],
						$aCountryInfo['since'],
						trim($sExtra)
					));

				} else {

					return $this->replyToQuestion($oMention, sprintf($aAnswerPhrases->reply_dst,
						($aCountryInfo['group'] == 'no dst' ? ' not' : ''),
						$aCountryInfo['name'],
						trim($sExtra)
					));
				}
				break;

			case 'next':

				//'next' event is special: aCountryInfo can either contain start or stop event info
				//so determine which of the two occurs first from now
				if (empty($aCountryInfo['event'])) {
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
				return $this->replyToQuestion($oMention, sprintf(sprintf($aAnswerPhrases->reply_dst_next,
						$aAnswerPhrases->reply_dst_startstop),
					$aCountryInfo['name'],
					$sEvent,
					$aCountryInfo[$sEvent],
					$aCountryInfo[$sEvent . 'day'],
					trim($sExtra)
				));
		}

        //event not understood, send default reply
        return $this->replyToQuestion($oMention, $aAnswerPhrases->reply_default);
    }

    private function findQuestionType($sTweet)
    {
        //the order of the arrays at the top of the class is the order we're searching in
        foreach ($this->oConfig->get('question_keywords') as $sEvent => $aWords) {
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
        foreach ($this->db->getAllGroups() as $aGroup) {

            //check for group name
            if (stripos($sText, $aGroup['shortname']) !== false) {
                $aFoundCountry[$aGroup['shortname']] = $aGroup;
                break;
            }

            //check 'includes' array
            if (isset($aGroup['includes'])) {
                foreach ($aGroup['includes'] as $aInclude) {

                    //check name of country against question
                    if (stripos($sText, $aInclude['include']) !== false) {
                        //TODO
                    }
                }
            }
        }
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
        $aAnswerPhrases = $this->oConfig->get('answers');

		if ($aCountryInfo['group'] == 'no dst') {
			//skip question type parsing if country does not observe DST
            $sExtra = '';
            if (!empty($aCountryInfo['since'])) {
				//normally just mention the year they stopped observing DST, unless it's permanently in effect
                if (isset($aCountryInfo['permanent']) && $aCountryInfo['permanent'] == 1) {
					$sExtra = sprintf($aAnswerPhrases->extra_perma_since, $aCountryInfo['since']);
                } else {
					$sExtra = sprintf($aAnswerPhrases->extra_not_since, $aCountryInfo['since']);
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
            $sExtra .= sprintf($aAnswerPhrases->extra_more_info, $aCountryInfo['info']);
        }

        if (isset($aCountryInfo['permanent'])) {
            //some countries have permanent DST in effect
            if ($aCountryInfo['permanent'] == 1) {
				$sExtra .= $aAnswerPhrases->extra_perma_always;
            } else {
                //just in case, never used
				$sExtra .= $aAnswerPhrases->extra_perma_never;
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
