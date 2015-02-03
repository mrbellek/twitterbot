<?php
require_once('twitteroauth.php');
require_once('gameswithgold.inc.php');

$o = new GamesWithGold(array(
    'sUsername'         => 'GamesWGold',

    'sTweetFormatStart' => 'Starting today, :game [:platform] is free for members with Xbox Live Gold - :link',
    'sTweetFormatStop'  => 'Today is the last day :game [:platform] is free for members with Xbox Live Gold - :link',
    'sDefaultLink'      => 'http://www.xbox.com/en-US/live/games-with-gold',
));
$o->run();

/*
 * TODO:
 * V fetch record from database, tweet it
 * V gameswithgold table: game name, platform (xbone/360), game link on xbox.com, free date start, free date start
 * V tweet when game goes from paid to free
 * V tweet when game starts last free day
 * ? retweet @majorlenson and @thevowel when tweeting about games with gold
 */

class GamesWithGold {

    private $sUsername;
    private $oPDO;

    private $sLogFile;
    private $iLogLevel = 3; //increase for debugging
    private $aSettings;

    public function __construct($aArgs) {

        //connect to twitter
        $this->oTwitter = new TwitterOAuth(CONSUMER_KEY, CONSUMER_SECRET, ACCESS_TOKEN, ACCESS_TOKEN_SECRET);
        $this->oTwitter->host = "https://api.twitter.com/1.1/";

        //load args
        $this->parseArgs($aArgs);

        //connect to database
		try {
			$this->oPDO = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME, DB_USER, DB_PASS);
		} catch(Exception $e) {
			$this->logger(2, sprintf('Database connection failed. (%s)', $e->getMessage()));
			$this->halt(sprintf('- Database connection failed. (%s)', $e->getMessage()));
			return FALSE;
		}

        //make output visible in browser
        if (!empty($_SERVER['HTTP_HOST'])) {
            echo '<pre>';
        }
    }

    private function parseArgs($aArgs) {

        $this->sUsername        = (!empty($aArgs['sUsername'])          ? $aArgs['sUsername']           : '');
        $this->sLogFile         = (!empty($aArgs['sLogFile'])           ? $aArgs['sLogFile']            : strtolower(__CLASS__) . '.log');

        $this->aDbVars          = (!empty($aArgs['aDbVars'])            ? $aArgs['aDbVars']             : array());
        $this->sTweetFormatStart= (!empty($aArgs['sTweetFormatStart'])  ? $aArgs['sTweetFormatStart']   : '');
        $this->sTweetFormatStop = (!empty($aArgs['sTweetFormatStop'])   ? $aArgs['sTweetFormatStop']   : '');
        $this->sDefaultLink     = (!empty($aArgs['sDefaultLink'])       ? $aArgs['sDefaultLink']        : '');

        if ($this->sLogFile == '.log') {
            $this->sLogFile = pathinfo($_SERVER['SCRIPT_FILENAME'], PATHINFO_FILENAME) . '.log';
        }
    }

    public function run() {

        if (!$this->oPDO) {
            $this->halt('No database connection.');
            return FALSE;
        }

        //check if auth is ok
        if (1||$this->getIdentity()) { //DEBUG

            //fetch record from database for games starting free period today
            if ($aRecord = $this->fetchStartRecord()) {

                //format start tweet
                if ($sTweet = $this->formatTweet($aRecord, $this->sTweetFormatStart)) {

                    //post tweet
                    $this->postTweet($sTweet);
                }
            } else {
                echo "No games turn free today.\n";
            }

            //fetch record from database for games ending free period tomorrow
            if ($aRecord = $this->fetchStopRecord()) {

                //format stop tweet
                if ($sTweet = $this->formatTweet($aRecord, $this->sTweetFormatStop)) {

                    //post tweet
                    $this->postTweet($sTweet);
                }
            } else {
                echo "No games stop being free tomorrow.\n";
            }

            $this->halt('Done.');
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

    private function fetchStartRecord() {

        return $this->fetchRecord('
            SELECT *
            FROM gameswithgold
            WHERE startdate = CURDATE()'
        );
    }
    
    private function fetchStopRecord() {

        return $this->fetchRecord('
            SELECT *
            FROM gameswithgold
            WHERE enddate = CURDATE() + INTERVAL 1 DAY'
        );
    }

    private function fetchRecord($sQuery) {

        $sth = $this->oPDO->prepare($sQuery);
		if ($sth->execute() == FALSE) {
			$this->logger(2, sprintf('Select query failed. (%d %s)', $sth->errorCode(), $sth->errorInfo()));
			$this->halt(sprintf('- Select query failed, halting. (%d %s)', $sth->errorCode(), $sth->errorInfo()));
			return FALSE;
		}

        if ($aRecord = $sth->fetch(PDO::FETCH_ASSOC)) {

            return $aRecord;
        } else {
            return FALSE;
        }
    }

    private function formatTweet($aRecord, $sFormat) {

        $sTweet = $sFormat;
        foreach ($aRecord as $sField => $sValue) {
            $sTweet = str_replace(':' . $sField, $sValue, $sTweet);
        }

        return $sTweet;
    }

    private function postTweet($sTweet) {

		echo 'Posting tweet..<br>';
        die(var_dump($sTweet));

        //tweet
        $oRet = $this->oTwitter->post('statuses/update', array('status' => $sTweet, 'trim_users' => TRUE));
        if (isset($oRet->errors)) {
            $this->logger(2, sprintf('Twitter API call failed: statuses/update (%s)', $oRet->errors[0]->message));
            $this->halt('- Error: ' . $oRet->errors[0]->message . ' (code ' . $oRet->errors[0]->code . ')');
            return FALSE;
        } else {
            printf('- <b>%s</b><br>', utf8_decode($sTweet));
        }

        return TRUE;
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
