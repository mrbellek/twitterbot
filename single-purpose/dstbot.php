<?php
require_once('twitteroauth.php');
require_once('dstbot.inc.php');

/*
 * TODO:
 * V get data for all dst settings per country
 *   V aliases per country
 *     - exclude e.g. 'american samoa' being detected as 'samoa'?
 *   v timezone offset
 * V tweet warning about DST clock change 7 days, 1 day in advance
 *   x moment of change, with proper timezone - not possible since countries in a group have multiple timezones?
 *   V just change it to 'today' instead of 'now'
 * V only check for DST changes every 30 minutes, but check mentions every 5 minutes (cronjob)
 * V only reply to mentions that have our name at the start
 * V only reply to ppl following me
 * V answer question 'when is next DST change?'
 *   V either in coutry or 'worldwide'
 * V abstract formatting of aGroup into aCountryInfo into a function
 *
 * - refactor this horrible mess, use flowcharts and shit
 */

$o = new DstBot(array('sUsername' => 'DSTnotify'));
$o->run();

class DstBot {

    private $sUsername;
    private $sLogFile;
    private $iLogLevel = 3; //increase for debugging
    private $aSettings;

    //error margin for cronjobs not firing exactly when they should
    private $iErrorMargin = 180;

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

        $this->sUsername        = (!empty($aArgs['sUsername'])          ? $aArgs['sUsername']       : '');
        $this->sSettingsFile    = (!empty($aArgs['sSettingsFile'])      ? $aArgs['sSettingsFile']   : strtolower(__CLASS__) . '.json');
        $this->sLastMentionFile = (!empty($aArgs['sLastMentionFile'])   ? $aArgs['sLastMentionFile'] : strtolower(__CLASS__) . '-last.json');
        $this->sLogFile         = (!empty($aArgs['sLogFile'])           ? $aArgs['sLogFile']        : strtolower(__CLASS__) . '.log');
        $this->bReplyInDM       = (!empty($aArgs['bReplyInDM'])         ? $aArgs['bReplyInDM']      : FALSE);

        /*
         * NOTES FOR SETTINGS.JSON FORMAT:
         * - groups for countries with identical start/ends (e.g. Europe)
         *   - key is internal name of group
         *   - 'name' key is display name
         *   - 'start' is start of DST
         *   - 'end' is end of DST
         *   - 'includes' array is list of participating countries
         *     - key is display name
         *     - 'since' is year since start/end of DST observation
         *     - 'alias' is array of other names country is known as
         *     - 'timezone' is timezone, in +dddd format (or -dddd)
         *     - 'info' is url to wiki page for complex DST rules
         *     - 'note' is additional notes for country
         *   - 'excludes' array is list of not participating countries, even though they'd be expected to fall in group
         * - separate countries
         *   - 'start'
         *   - 'end'
         *   - 'since'
         *   - 'alias'
         *   - 'timezone'
         *   - 'info'
         *   - 'note'
         * - group 'no dst' for countries that do not observe DST right now
         *   - 'includes' array is list of participating countries
         *     - key is country display name
         *     - 'since'
         *     - 'alias'
         *     - 'permanent' is if the country is in permanent DST mode
         */
        $this->aSettings = @json_decode(file_get_contents(MYPATH . '/' . $this->sSettingsFile), TRUE);
        if (!$this->aSettings) {
            $this->logger(1, sprintf('Failed to load settings file. (json_decode error %s)', json_last_error()));
            $this->halt(sprintf('Failed to load settings files. (json_decode error %s)', json_last_error()));
            die();
        }

        if ($this->sLogFile == '.log') {
            $this->sLogFile = pathinfo($_SERVER['SCRIPT_FILENAME'], PATHINFO_FILENAME) . '.log';
        }
    }

    public function run() {

        /* TEST SUITE
        $oMention = new stdClass;
        $oMention->id_str = 1;
        $oMention->user = new stdClass;
        $oMention->user->screen_name = 'testuser';
        $oMention->text = '@dstnotify when does dst start?';
        $oMention->text = '@dstnotify when is next dst change?';
        $oMention->user->location = 'Netherlands';
        $oMention->text = '@dstnotify when does dst start here?';
        $oMention->text = '@dstnotify when does dst start in belgium?';
        $oMention->text = '@dstnotify since when do united states observe dst?';
        $oMention->text = '@dstnotify since when do we observe dst?';
        $oMention->text = '@dstnotify when is next dst change?';
        $oMention->text = '@dstnotify when is next worldwide dst change?';
        $oMention->user->location = '';
        $oMention->text = '@dstnotify when is next worldwide dst change?';
        $this->parseMention($oMention);
        die();*/

        //check if auth is ok
        if ($this->getIdentity()) {

            //check for upcoming DST changes and tweet about it
            $this->checkDST();

            //check for mentions and reply
            $this->checkMentions();

            $this->halt();
        }
    }

    private function getIdentity() {

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

    private function checkDST() {

        //only check every half hour (with 3min margin)
        $iClockMinutes = (int)date('i');
        if ($iClockMinutes > 60 - ($this->iErrorMargin / 60) ||  //minutes is >57
            $iClockMinutes < ($this->iErrorMargin / 60) ||       //minutes is <3
            ($iClockMinutes > 30  - ($this->iErrorMargin / 60) && $iClockMinutes < 30 + ($this->iErrorMargin / 60))) {    //minutes between 27 and 33

            echo "Checking for DST start..\n";

            //check if any of the countries are switching to DST (summer time) NOW
            if ($aGroups = $this->checkDSTStart(time())) {

                if (!$this->postTweetDST('starting', $aGroups, 'today')) {
                    return FALSE;
                }
            }

            //check if any of the countries are switching to DST (summer time) in 24 hours
            if ($aGroups = $this->checkDSTStart(time() + 24 * 3600)) {

                if (!$this->postTweetDST('starting', $aGroups, 'tomorrow')) {
                    return FALSE;
                }
            } else {
                echo "- No DST starts 24 from now.\n";
                $this->logger(5, 'no dst starts 24h from midnight today');
            }

            //check if any of the countries are switching to DST (summer time) in 7 days
            if ($aGroups = $this->checkDSTStart(time() + 7 * 24 * 3600)) {

                if (!$this->postTweetDST('starting', $aGroups, 'next week')) {
                    return FALSE;
                }
            }

            echo "Checking for DST end..\n";

            //check if any of the countries are switching from DST (winter time) NOW
            if ($aGroups = $this->checkDSTEnd(time())) {

                if (!$this->postTweetDST('ending', $aGroups, 'today')) {
                    return FALSE;
                }
            }

            //check if any of the countries are switching from DST (winter time) in 24 hours
            if ($aGroups = $this->checkDSTEnd(time() + 24 * 3600)) {

                if (!$this->postTweetDST('ending', $aGroups, 'tomorrow')) {
                    return FALSE;
                }
            } else {
                echo "- No DST ends 24 from now.\n";
                $this->logger(5, 'no dst ends 24h from midnight today');
            }

            //check if any of the countries are switching from DST (winter time) in 7 days
            if ($aGroups = $this->checkDSTEnd(time() + 7 * 24 * 3600)) {

                if (!$this->postTweetDST('ending', $aGroups, 'next week')) {
                    return FALSE;
                }
            }

            return TRUE;

        } else {
            echo "Skipping checkDST() because it's not on full or half hour.\n";
            $this->logger(5, sprintf('skipping checkDST() because its not on the half hour (with %ds margin)', $this->iErrorMargin));
        }

        return FALSE;
    }

    //check if DST starts (summer time start) for any of the countries
    private function checkDSTStart($iTimestamp) {

        $this->logger(6, sprintf('running checkDSTstart for %d (%s)', $iTimestamp, date('Y-m-d H:i:s', $iTimestamp)));
        $aGroupsDSTStart = array();
        foreach ($this->aSettings as $sGroup => $aSetting) {

            if ($sGroup != 'no dst') {

                //convert 'last sunday of march 2014' to timestamp
                $iDSTStart = strtotime($aSetting['start'] . ' ' . date('Y'));

                //error margin of 3 minutes
                if ($iDSTStart >= $iTimestamp - $this->iErrorMargin && $iDSTStart <= $iTimestamp + $this->iErrorMargin) {

                    //DST will start here
                    $aGroupsDSTStart[] = $sGroup;

                    $this->logger(6, sprintf('checkDSTstart %s: yes, %d is near %d (%ds margin)', $sGroup, $iDSTStart, $iTimestamp, $this->iErrorMargin));
                } else {
                    $this->logger(6, sprintf('checkDSTstart %s: no, %d is not near %d (%ds margin)', $sGroup, $iDSTStart, $iTimestamp, $this->iErrorMargin));
                }
            }
        }

        return ($aGroupsDSTStart ? $aGroupsDSTStart : FALSE);
    }

    //check if DST ends (winter time start) for any of the countries
    private function checkDSTEnd($iTimestamp) {

        $this->logger(6, sprintf('running checkDSTstop for %d (%s)', $iTimestamp, date('Y-m-d H:i:s', $iTimestamp)));
        $aGroupsDSTEnd = array();
        foreach ($this->aSettings as $sGroup => $aSetting) {

            if ($sGroup != 'no dst') {

                //convert 'last sunday of march 2014' to timestamp
                $iDSTEnd = strtotime($aSetting['end'] . ' ' . date('Y'));

                //error margin of 1 minute
                if ($iDSTEnd >= $iTimestamp - $this->iErrorMargin && $iDSTEnd <= $iTimestamp + $this->iErrorMargin) {

                    //DST will end here
                    $aGroupsDSTEnd[] = $sGroup;

                    $this->logger(6, sprintf('checkDSTstop %s: yes, %d is near %d (%ds margin)', $sGroup, $iDSTEnd, $iTimestamp, $this->iErrorMargin));
                } else {
                    $this->logger(6, sprintf('checkDSTstop %s: no, %d is not near %d (%ds margin)', $sGroup, $iDSTEnd, $iTimestamp, $this->iErrorMargin));
                }
            }
        }

        return ($aGroupsDSTEnd ? $aGroupsDSTEnd : FALSE);
    }

    private function postTweetDST($sEvent, $aGroups, $sDelay) {

        foreach ($aGroups as $sGroupName) {

            foreach ($this->aSettings as $sGroup => $aGroup) {
                if ($sGroup == $sGroupName) {
                    $sCountries = (isset($aGroup['name']) ? $aGroup['name'] : ucwords($sGroup));

                    $sTweet = sprintf('Daylight Savings Time %s %s in %s. #DST', $sEvent, $sDelay, $sCountries);
                    if (!$this->postTweet($sTweet)) {
                        return FALSE;
                    }
                    break;
                }
            }
        }

        return TRUE;
    }

    private function postTweet($sTweet) {

        printf("- [%d] %s\n", strlen($sTweet), $sTweet);

        $oRet = $this->oTwitter->post('statuses/update', array('status' => $sTweet, 'trim_users' => TRUE));
        if (isset($oRet->errors)) {
            $this->logger(2, sprintf('Twitter API call failed: statuses/update (%s)', $oRet->errors[0]->message));
            $this->halt('- Error: ' . $oRet->errors[0]->message . ' (code ' . $oRet->errors[0]->code . ')');
            return FALSE;
        }
    }

    private function checkMentions() {

      $aLastMention = json_decode(@file_get_contents(MYPATH . '/' . $this->sLastMentionFile), TRUE);
        printf("Checking mentions since %s..\n", ($aLastMention ? $aLastMention['timestamp'] : 'never'));

        //fetch new mentions since last run
        $aMentions = $this->oTwitter->get('statuses/mentions_timeline', array(
            'count'         => 20,
            'since_id'      => ($aLastMention && !empty($aLastMention['max_id']) ? $aLastMention['max_id'] : 1),
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

        //reply to followers only
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
      $aThisCheck = array(
         'max_id'   => $sMaxId,
         'timestamp'   => date('Y-m-d H:i:s'),
      );
      file_put_contents(MYPATH . '/' . $this->sLastMentionFile, json_encode($aThisCheck));

        return TRUE;
    }

    private function parseMention($oMention) {

        $sDefaultReply = 'I didn\'t understand your question! You can ask when #DST starts or ends in any country, or since when it\'s (not) used.';
        $sNoDSTReply = '%s does not observe DST. %s';

        //ignore mentions where our name is not at the start of the tweet
        if (stripos($oMention->text, '@' . $this->sUsername) !== 0) {
            return TRUE;
        }

        //reply to questions from everyon in DMs if possible, mention otherwise
        $sId = $oMention->id_str;
        $sQuestion = str_replace('@' . strtolower($this->sUsername) . ' ', '', strtolower($oMention->text));
        printf("Parsing question '%s' from %s..\n", $sQuestion, $oMention->user->screen_name);

        //find type of question
        $sEvent = '';
        $aKeywords = array(
            'next' => array('next'),                //when is next DST change for..
            'start' => array('start', 'begin'),     //when does DST start in..
            'end' => array('stop', 'end' , 'over'), //when does DST end in..
            'since' => array('since', 'when'),      //since when does .. have DST
        );

        //the order of the arrays above is the order we're searching in
        foreach ($aKeywords as $sEvent => $aKeywords) {
            if ($this->stringContainsWord($sQuestion, $aKeywords)) {
                //keyword match, break so $sEvent has the proper event
                break;
            }
        }

        if (!$sEvent) {
            return $this->replyToQuestion($oMention, $sDefaultReply);
        }

        //try to find country in tweet
        $aCountryInfo = $this->findCountryInQuestion($oMention->text);
        if (!$aCountryInfo && !empty($oMention->user->location) && stripos($oMention->text, 'worldwide') === FALSE) {

            //try to find country name in 'location' field of user profile (unless tweet contains 'worldwide')
            $aCountryInfo = $this->findCountryInQuestion($oMention->user->location);
        }

        if (!$aCountryInfo) {
            if ($sEvent == 'next') {
                //for 'next' event without country, use 'worldwide' i.e. get next DST change anywhere
                $aCountryInfo = $this->getNextDSTChange();
            } else {
                //no country found, reply default
                return $this->replyToQuestion($oMention, $sDefaultReply);
            }

        } elseif ($aCountryInfo && $sEvent == 'next') {
            //if country was found AND no event was specified ('when is next change'), find whichever is soonest
            $aNextChange = $this->getNextChange($aCountryInfo);
            if ($aNextChange['event']) {
                $aCountryInfo['event'] = $aNextChange['event'];
            }
        }

        //skip question type parsing if country does not observe DST
        if ($aCountryInfo['group'] == 'no dst') {
            $sExtra = '';
            if (!empty($aCountryInfo['since'])) {
                if (isset($aCountryInfo['permanent']) && $aCountryInfo['permanent'] == 1) {
                    $sExtra = sprintf('It has been in permanent DST time since %d.', $aCountryInfo['since']);
                } else {
                    $sExtra = sprintf('It has not since %d', $aCountryInfo['since']);
                }
            }
            return $this->replyToQuestion($oMention, sprintf($sNoDSTReply, $aCountryInfo['name'], $sExtra));
        }

        //construct extra information
        $sExtra = '';
        if (!empty($aCountryInfo['note'])) {

            //some countries have a note in parentheses
            $sExtra .= $aCountryInfo['note'];
        }
        if (!empty($aCountryInfo['info'])) {

            //some countries are complicated, and have their own wiki page with more info
            $sExtra .= ' More info: ' . $aCountryInfo['info'];
        }

        if (isset($aCountryInfo['permanent'])) {
            //some countries have permanent DST in effect
            if ($aCountryInfo['permanent'] == 1) {
                $sExtra .= ' DST is permanently in effect.';
            } else {
                //just in case, never used
                $sExtra .= ' DST is permanently not in effect.';
            }
        }

        if ($sEvent == 'start' || $sEvent == 'end') {

            //example: DST starts in Belgium on the last sunday of march (2014-03-21)
            $this->replyToQuestion($oMention, sprintf('#DST in %s %ss on the %s (%s). %s',
                $aCountryInfo['name'],
                $sEvent,
                $aCountryInfo[$sEvent],
                $aCountryInfo[$sEvent . 'day'],
                trim($sExtra)
            ));

        } elseif ($sEvent == 'since') {

            //example: DST has not been observed in Russia since 1947
            if (!empty($aCountryInfo['since'])) {

                $this->replyToQuestion($oMention, sprintf('#DST has%s been observed in %s since %s. %s',
                    ($aCountryInfo['group'] == 'no dst' ? ' not' : ''),
                    $aCountryInfo['name'],
                    $aCountryInfo['since'],
                    trim($sExtra)
                ));

            } else {

                $this->replyToQuestion($oMention, sprintf('#DST is%s observed in %s. %s',
                    ($aCountryInfo['group'] == 'no dst' ? ' not' : ''),
                    $aCountryInfo['name'],
                    trim($sExtra)
                ));
            }

        } elseif ($sEvent == 'next') {
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

            //example: The next DST change is: DST starts in United States on the last sunday of march (2014-03-21)
            $this->replyToQuestion($oMention, sprintf('The next %s change is: #DST %ss in %s on the %s (%s). %s',
                ($aCountryInfo['group'] == 'worldwide' ? $aCountryInfo['group'] : ''),
                $sEvent,
                $aCountryInfo['name'],
                $aCountryInfo[$sEvent],
                $aCountryInfo[$sEvent . 'day'],
                trim($sExtra)
            ));
        }

        return TRUE;
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

            $oRet = $this->oTwitter->post('direct_messages/new', array('user_id' => $oMention->user->id_str, 'text' => substr($sReply, 0, 140)));

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
                    substr($sReply, 0, 140 - 2 - strlen($oMention->user->screen_name))
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

    private function findCountryInQuestion($sQuestion) {

        $aCountryInfo = array();

        //try to find country in text
        foreach ($this->aSettings as $sGroupName => $aGroup) {

            //check for group name
            if (stripos($sQuestion, $sGroupName) !== FALSE) {
                $aFoundCountry[$sGroupName] = $aGroup;
                $aFoundCountry[$sGroupName]['name'] = ucwords($sGroupName);
                break;
            }

            //check 'includes' array
            if (isset($aGroup['includes'])) {
                foreach ($aGroup['includes'] as $sName => $aInclude) {

                    //check name of country against question
                    if (stripos($sQuestion, $sName) !== FALSE) {
                        $aFoundCountry[$sGroupName] = array_merge($aGroup, $aInclude);
                        $aFoundCountry[$sGroupName]['name'] = ucwords($sName);
                        unset($aFoundCountry[$sGroupName]['includes']);
                        unset($aFoundCountry[$sGroupName]['excludes']);
                        unset($aFoundCountry[$sGroupName]['alias']);
                        break 2;
                    }

                    if (!empty($aInclude['alias'])) {
                        foreach ($aInclude['alias'] as $sAlias) {
                            if (stripos($sQuestion, $sAlias) !== FALSE) {
                                $aFoundCountry[$sGroupName] = array_merge($aGroup, $aInclude);
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

            return $this->formatCountryInfo($aGroup);
        }

        return FALSE;
    }

    private function getNextDSTChange() {

        $iNextChange = strtotime('+1 year');
        $aNextCountry = array();

        foreach ($this->aSettings as $sGroupName => $aGroup) {

            if ($sGroupName != 'no dst') {
                $aGroup['group'] = $sGroupName;

                //get next DST change for this group
                $aChange = $this->getNextChange($aGroup);

                //save closest change
                if ($aChange['event'] && $aChange['timestamp'] < $iNextChange) {
                    $aNextCountry = $aGroup;
                    $aNextCountry['event'] = $aChange['event'];
                    $iNextChange = $aChange['timestamp'];
                }

                if (empty($aNextCountry['name'])) {
                    $aNextCountry['name'] = ucwords($sGroupName);
                }
            }
        }

        return $this->formatCountryInfo($aNextCountry);;
    }

    private function formatCountryInfo($aGroup) {

        //format some fields, add some more for convenience
        $aCountryInfo = array(
            'group'     => (isset($aGroup['group'])     ? $aGroup['group'] : FALSE),
            'name'      => (isset($aGroup['name'])      ? ucwords($aGroup['name']) : FALSE),
            'since'     => (isset($aGroup['since'])     ? $aGroup['since'] : FALSE),
            'info'      => (isset($aGroup['info'])      ? $aGroup['info'] : FALSE),
            'note'      => (isset($aGroup['note'])      ? $aGroup['note'] : FALSE),
            'timezone'  => (isset($aGroup['timezone'])  ? $aGroup['timezone'] : FALSE),
            'event'     => (isset($aGroup['event'])     ? $aGroup['event'] : FALSE),
        );
        if ($aCountryInfo['group'] != 'no dst') {
            //start and end in relative terms (last sunday of september)
            $aCountryInfo['start'] = $this->capitalizeStuff($aGroup['start']);
            $aCountryInfo['end'] = $this->capitalizeStuff($aGroup['end']);

            //work out when that is for current + next year
            $sStartDayNow = date('jS', strtotime($aGroup['start'] . ' ' . date('Y')));
            $sStartDayNext = date('jS', strtotime($aGroup['start'] . ' ' . (date('Y') + 1)));
            $sEndDayNow = date('jS', strtotime($aGroup['end'] . ' ' . date('Y')));
            $sEndDayNext = date('jS', strtotime($aGroup['end'] . ' ' . (date('Y') + 1)));
            $aCountryInfo['startday'] = sprintf('%d: %s, %d: %s', date('Y'), $sStartDayNow, (date('Y') + 1), $sStartDayNext);
            $aCountryInfo['endday'] = sprintf('%d: %s, %d: %s', date('Y'), $sEndDayNow, (date('Y') + 1), $sEndDayNext);
        }
        if (isset($aGroup['permanent'])) {
            $aCountryInfo['permanent'] = $aGroup['permanent'];
        }

        return $aCountryInfo;
    }

    private function getNextChange($aGroup) {

        $aReturn = array(
            'event' => FALSE,
            'timestamp' => FALSE,
        );

        if (isset($aGroup['start'])) {

            //check if this DST start is this year or next
            if (strtotime($aGroup['start'] . ' ' . date('Y')) > time()) {
                $iChange = strtotime($aGroup['start'] . ' ' . date('Y'));
            } else {
                $iChange = strtotime($aGroup['start'] . ' ' . (date('Y') + 1));
            }

            $aReturn['event'] = 'start';
            $aReturn['timestamp'] = $iChange;
        }

        if (isset($aGroup['end'])) {
            //check if this DST stop is this year or next
            if (strtotime($aGroup['end'] . ' ' . date('Y')) > time()) {
                $iChange = strtotime($aGroup['end'] . ' ' . date('Y'));
            } else {
                $iChange = strtotime($aGroup['end'] . ' ' . (date('Y') + 1));
            }

            if ($iChange < $aReturn['timestamp']) {
                $aReturn['event'] = 'end';
                $aReturn['timestamp'] = $iChange;
            }
        }

        return $aReturn;
    }

    private function capitalizeStuff($sString) {

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

    private function stringContainsWord($sString, $aWords) {

        foreach ($aWords as $sWord) {
            if (stripos($sString, $sWord) !== FALSE) {
                return TRUE;
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
}
