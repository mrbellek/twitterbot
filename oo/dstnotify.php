<?php
require_once('autoload.php');
require_once('dstnotify.inc.php');

use Twitterbot\Lib\Logger;
use Twitterbot\Lib\Config;
use Twitterbot\Lib\Auth;
use Twitterbot\Lib\Ratelimit;
use Twitterbot\Lib\Format;
use Twitterbot\Lib\Tweet;

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

                    //TODO: use Format lib here instead of own function
                    $aTweets = $this->checkDST();
                    die(var_dumP($aTweets));

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

                    $aMentions = $this->checkMentions();

                    foreach ($aMentions as $oMention) {
                        $this->replyToMention($oMention);
                    }

                    $this->logger->output('done!');
                }
            }
        }
    }

    private function checkDST()
    {
        $this->logger->output('Checking for DST start..');
        $aTweets = array();

        //$sToday = strtotime(date('Y-m-d UTC'));
        $sToday = strtotime('tomorrow UTC');

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
}

//RUN SCRIPT WITH CLI ARGUMENT 'mentions' TO PARSE & REPLY TO MENTIONS
if (!empty($argv[1]) && $argv1[1] == 'mentions') {
    //(new DSTNotify)->runMentions();
} else {
    (new DSTNotify)->run();
}
