<?php
require_once('twitteroauth.php');

/*
 * TODO:
 * - input large body of text
 * - analyze, create markov chains
 * - construct tweets
 * - profit
 *
 * TODO:
 * - find a way to cache markov chains that loads faster than re-generating the markov chains every run
 */

class MarkovBot {

    private $oTwitter;
    private $sUsername;
    private $sLogFile;
    private $sInputFile;

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

        //parse arguments, set values or defaults
        $this->sUsername        = (!empty($aArgs['sUsername'])       ? $aArgs['sUsername']      : '');
        $this->sInputFile       = (!empty($aArgs['sInputFile'])      ? $aArgs['sInputFile']     : '');
        $this->sLogFile         = (!empty($aArgs['sLogFile'])        ? $aArgs['sLogFile']       : strtolower($this->sUsername) . '.log');

        if ($this->sLogFile == '.log') {
            $this->sLogFile = pathinfo($_SERVER['SCRIPT_FILENAME'], PATHINFO_FILENAME) . '.log';
        }
    }

    public function run() {

        //get current user and verify it's the right one
        if ($this->getIdentity()) {

            //analyze text into markov chain
            if ($this->generateMarkovChain()) {

                //create a tweet!
                if ($sTweet = $this->generateTweet()) {

                    //tweet it
                    if ($this->postMessage($sTweet)) {

                        $this->halt('Done!');
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

    private function generateMarkovChain() {

        echo "Generating Markov chains\n";

        if (!$this->sInputFile || filesize($this->sInputFile) == 0) {
            $this->logger(2, 'No input file specified.');
            $this->halt('- No input file specified, halting.');
            return FALSE;
        }

        $aMarkovChains = array();
        $sInput = implode(' ', file($this->sInputFile, FILE_IGNORE_NEW_LINES));

        printf("- Read input file %s (%d bytes)..\n", $this->sInputFile, filesize($this->sInputFile));

        $lStart = microtime(TRUE);
        $aWords = explode(' ', $sInput);
        unset($sInput);
        foreach ($aWords as $i => $sWord) {
            if (!empty($aWords[$i + 2])) {
                $aMarkovChains[$sWord . ' ' . $aWords[$i + 1]][] = $aWords[$i + 2];
            }
        }
        unset($aWords);
        unset($aMarkovChains[' ']);
        $this->aMarkovChains = $aMarkovChains;

        printf("- done, generated %d chains in %d seconds\n\n", count($aMarkovChains), microtime(TRUE) - $lStart);

        return TRUE;
    }

    private function generateTweet() {

        echo "Generating tweet..\n";

        //TODO: pick key with capital letter first?
        srand();
        mt_srand();
        $sKey = array_rand($this->aMarkovChains); //get random start key
        $sNewTweet = $sKey; //new sentence starts with this key

        while (array_key_exists($sKey, $this->aMarkovChains)) {
            //get next word to add to sentence (random, based on key)
            $sNextWord = $this->aMarkovChains[$sKey][array_rand($this->aMarkovChains[$sKey])];

            //remove first word from key, add new word to it to create next key
            $aKey = explode(' ', $sKey);
            array_shift($aKey);
            $aKey[] = $sNextWord;
            $sKey = implode(' ', $aKey);

            //add next word to tweet
            if (strlen($sNewTweet . ' ' . $sNextWord) <= 140) {
                $sNewTweet .= ' ' . $sNextWord;
            } else {
                break;
            }
        }

        return html_entity_decode($sNewTweet);
    }

    private function postMessage($sTweet) {

        //tweet
        $oRet = TRUE;
        //$oRet = $this->oTwitter->post('statuses/update', array('status' => $sTweet, 'trim_users' => TRUE));
        if (isset($oRet->errors)) {
            $this->logger(2, sprintf('Twitter API call failed: statuses/update (%s)', $oRet->errors[0]->message));
            $this->halt('- Error: ' . $oRet->errors[0]->message . ' (code ' . $oRet->errors[0]->code . ')');
            return FALSE;
        } else {
            printf("- %s\n", $sTweet);
        }

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
?>
