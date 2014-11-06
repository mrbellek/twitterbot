<?php
require_once('./twitteroauth.php');
require_once('./dstbot.inc.php');

/*
 * TODO:
 * V get data for all dst settings per country
 *   - aliases per country
 *   - year since start of use DST or stop use of DST
 *   - timezone offset
 *   - notes for special cases (like brazil with carnival)
 * V tweet warning about DST clock change 7 days, 1 day in advance
 *   - moment of change, with proper timezone
 *   - update checkDSTStart() to take timezones into account
 * - reply to command: when is next DST in (country)?
 * - reply to command: what time is it now in (country)?
 *   - or get country from profile
 *
 * SPECIAL CASES:
 * - brazil dst end is delayed by 1 week during carnival week, so would be 4th sunday of february instead of 3rd
 */

$o = new DstBot(array('sUsername' => 'DSTnotify'));
$o->run();

class DstBot {

    private $sUsername;
    private $sLogFile;

    /*
     * GROUPS:
     * - Europe, except Armenia, Belarus, Georgia, Iceland, Russia (and Crimea of Ukrain)
     *   - also includes Lebanon, Morocco, Western Sahara
     * - North America, except Mexico and Greenland
     *   - also includes Cuba, Haiti, Turks and Caicos
     * - Jordan, Palestine, Syria
     * - Samoa, New Zealand
     * - [everything else, single countries]
     * - no DST group
     */

    private $aSettings;

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

        $this->aSettings = @json_decode(file_get_contents($this->sSettingsFile), TRUE);
        if (!$this->aSettings) {
            $this->logger(1, sprintf('Failed to load settings file. (%s)', json_last_error_msg()));
            $this->halt(sprintf('Failed to load settings files. (%s)', json_last_error_msg()));
            die();
        }

        if ($this->sLogFile == '.log') {
            $this->sLogFile = pathinfo($_SERVER['SCRIPT_FILENAME'], PATHINFO_FILENAME) . '.log';
        }
    }

    public function run() {

        //check if auth is ok
        if ($this->getIdentity()) {

            //check for upcoming DST changes and tweet about it
            $this->checkDST();

            //check for menions and reply
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

        //check if any of the countries are switching to DST (summer time) NOW
        echo "Checking for DST start..\n";
        if ($aGroups = $this->checkDSTStart(time())) {

            if (!$this->postTweetDST('starting', $aGroups, 'now')) {
                return FALSE;
            }
        }

        //check if any of the countries are switching to DST (summer time) in 24 hours
        if ($aGroups = $this->checkDSTStart(time() + 24 * 3600)) {

            if (!$this->postTweetDST('starting', $aGroups, 'in 24 hours')) {
                return FALSE;
            }
        }

        //check if any of the countries are switching to DST (summer time) in 7 days
        if ($aGroups = $this->checkDSTStart(time() + 7 * 24 * 3600)) {

            if (!$this->postTweetDST('starting', $aGroups, 'in 1 week')) {
                return FALSE;
            }
        }

        //check if any of the countries are switching from DST (winter time) NOW
        echo "Checking for DST end..\n";
        if ($aGroups = $this->checkDSTEnd(time())) {

            if (!$this->postTweetDST('ending', $aGroups, 'now')) {
                return FALSE;
            }
        }

        //check if any of the countries are switching from DST (winter time) in 24 hours
        if ($aGroups = $this->checkDSTEnd(time() + 24 * 3600)) {

            if (!$this->postTweetDST('ending', $aGroups, 'in 24 hours')) {
                return FALSE;
            }
        }

        //check if any of the countries are switching from DST (winter time) in 7 days
        if ($aGroups = $this->checkDSTEnd(time() + 7 * 24 * 3600)) {

            if (!$this->postTweetDST('ending', $aGroups, 'in 1 week')) {
                return FALSE;
            }
        }

        return TRUE;
    }

    //check if DST starts (summer time start) for any of the countries
    private function checkDSTStart($iTimestamp) {

        $aGroupsDSTStart = array();
        foreach ($this->aSettings as $sGroup => $aSetting) {

            if ($sGroup != 'no dst') {

                //convert 'last sunday of march 2014' to timestamp
                $iDSTStart = strtotime($aSetting['start'] . ' ' . date('Y'));

                //error margin of 1 minute
                if ($iDSTStart >= $iTimestamp - 60 && $iDSTStart <= $iTimestamp + 60) {

                    //DST will start here
                    $aGroupsDSTStart[] = $sGroup;
                }
            }
        }

        return ($aGroupsDSTStart ? $aGroupsDSTStart : FALSE);
    }

    //check if DST ends (winter time start) for any of the countries
    private function checkDSTEnd($iTimestamp) {

        $aGroupsDSTEnd = array();
        foreach ($this->aSettings as $sGroup => $aSetting) {

            if ($sGroup != 'no dst') {

                //convert 'last sunday of march 2014' to timestamp
                $iDSTEnd = strtotime($aSetting['end'] . ' ' . date('Y'));

                //error margin of 1 minute
                if ($iDSTEnd >= $iTimestamp - 60 && $iDSTEnd <= $iTimestamp + 60) {

                    //DST will end here
                    $aGroupsDSTEnd[] = $sGroup;
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

                    $sTweet = sprintf('Daylight Savings Time %s %s in %s.', $sEvent, $sDelay, $sCountries);
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
        return TRUE;

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
            'count'         => 10, //TODO: increase?
			'since_id'		=> ($aLastMention && !empty($aLastMention['max_id']) ? $aLastMention['max_id'] : 1),
        ));

        if (is_object($aMentions) && !empty($aMentions->errors[0]->message)) {
            $this->logger(2, sprintf('Twitter API call failed: GET statuses/mentions_timeline (%s)', $aMentions->errors[0]->message));
            $this->halt(sprintf('- Failed getting mentions, halting. (%s)', $aMentions->errors[0]->message));
        }

        if (count($aMentions) == 0) {
            echo "- no new mentions.\n\n";
            return FALSE;
        }

        //reply to everyone
        $sMaxId = '0';
        foreach ($aMentions as $oMention) {
            if ($oMention->id_str > $sMaxId) {
                $sMaxId = $oMention->id_str;
            }

            $bRet = $this->parseMention($oMention);
            if (!$bRet) {
                break;
            }
        }
        printf("- replied to %d commands\n\n", count($aMentions));

		//save data for next run
		$aThisCheck = array(
			'max_id'	=> $sMaxId,
			'timestamp'	=> date('Y-m-d H:i:s'),
		);
		file_put_contents(MYPATH . '/' . sprintf($this->sLastMentionFile, $iIndex), json_encode($aThisCheck));

        return TRUE;
    }

    private function parseMention($oMention) {

        //reply to commands from everyon in DMs if possible, mention otherwise
        $sId = $oMention->id_str;
        $sCommand = str_replace('@' . strtolower($this->sUsername) . ' ', '', strtolower($oMention->text));
        printf("Parsing question %s from %s..\n", $sCommand, $oMention->user->screen_name);

        switch ($sCommand) {
            case 'help':
                return $this->replyToQuestion($oMention, 'Commands: help lastrun lastlog ratelimit. Only replies to friends. Lag varies, be patient.');

            default:
                echo "- can't understand question.\n";
                return FALSE;
        }
    }

    private function replyToQuestion($oMention, $sReply) {

        //check friendship between bot and sender
        $oRet = $this->oTwitter->get('friendships/show', array('source_screen_name' => $this->sUsername, 'target_screen_name' => $oMention->user->screen_name));
        if (!empty($oRet->errors)) {
            $this->logger(2, sprintf('Twitter API call failed: GET friendships/show (%s)', $oRet->errors[0]->message));
            $this->halt(sprintf('- Failed to check friendship, halting. (%s)', $oRet->errors[0]->message));
            return FALSE;
        }

        //if we can DM the source of the command, do that
        if ($oRet->relationship->source->can_dm) {

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

    private function halt($sMessage = '') {
        echo $sMessage . "\n\nDone!\n\n";
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
