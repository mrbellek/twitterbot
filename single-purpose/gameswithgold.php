<?php
require_once('twitteroauth.php');
require_once('gameswithgold.inc.php');

$o = new GamesWithGold(array(
    'sUsername'             => 'XboxPSfreegames',

    'sTweetFormatStartXbox' => '[:platform] Starting today, :game is free for #Xbox Live Gold members - :link',
    'sTweetFormatStopXbox'  => '[:platform] Today is the last day :game is free for #Xbox Live Gold members - :link',
    'sDefaultLinkXbox'      => 'http://www.xbox.com/en-US/live/games-with-gold',

    'sTweetFormatStartPSN'  => '[:platform] Starting today, :game is free for members with #Playstation Plus - :link',
    'sTweetFormatStopXPSN'  => '[:platform] Today is the last day :game is free for members with #Playstation Plus - :link',
    'sDefaultLinkPSN'       => 'http://www.playstation.com/en-us/explore/playstation-plus/',
));
$o->run();

/*
 * TODO:
 * V fetch record from database, tweet it
 * V gameswithgold table: game name, platform (xbone/360), game link on xbox.com, free date start, free date start
 * V tweet when game goes from paid to free
 * V tweet when game starts last free day
 * V add page
 *   V display upcoming free games on page
 *   V click to edit
 *   V delete button in edit dialog to it's password protected
 *
 * V maybe announce free games for PSN Plus too? http://blog.us.playstation.com/tag/playstation-plus/
 * - retweet @majorlenson and @thevowel when tweeting about games with gold?
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

        $this->sUsername                = (!empty($aArgs['sUsername'])              ? $aArgs['sUsername']               : '');
        $this->sLogFile                 = (!empty($aArgs['sLogFile'])               ? $aArgs['sLogFile']                : strtolower(__CLASS__) . '.log');

        $this->aDbVars                  = (!empty($aArgs['aDbVars'])                ? $aArgs['aDbVars']                 : array());
        $this->sTweetFormatStartXbox    = (!empty($aArgs['sTweetFormatStartXbox'])  ? $aArgs['sTweetFormatStartXbox']   : '');
        $this->sTweetFormatStopXbox     = (!empty($aArgs['sTweetFormatStopXbox'])   ? $aArgs['sTweetFormatStopXbox']    : '');
        $this->sDefaultLinkXbox         = (!empty($aArgs['sDefaultLink'])           ? $aArgs['sDefaultLink']            : '');
        $this->sTweetFormatStartPSN     = (!empty($aArgs['sTweetFormatStartPSN'])   ? $aArgs['sTweetFormatStartPSN']    : '');
        $this->sTweetFormatStopPSN      = (!empty($aArgs['sTweetFormatStopPSN'])    ? $aArgs['sTweetFormatStopPSN']     : '');
        $this->sDefaultLinkPSN          = (!empty($aArgs['sDefaultLink'])           ? $aArgs['sDefaultLink']            : '');

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
        if ($this->getIdentity()) {

            //fetch record from database for games starting free period today
            if ($aRecords = $this->fetchStartRecords()) {

                foreach ($aRecords as $aRecord) {
                    $sTweet = '';

                    //determine platform and format start tweet
                    if (stripos($aRecord['platform'], 'xbox') !== FALSE) {
                        $sTweet = $this->formatTweet($aRecord, $this->sTweetFormatStartXbox);
                    } elseif (stripos($aRecord['platform'], 'playstation') !== FALSE) {
                        $sTweet = $this->formatTweet($aRecord, $this->sTweetFormatStartPSN);
                    }

                    //post tweet
                    if ($sTweet) {
                        $this->postTweet($sTweet);
                    } else {
                        $this->logger(2, sprintf('Unknown platform for game: %s (id %d)', $aRecord['platform'], $aRecord['id']));
                    }
                }
            } else {
                echo "No games turn free today.\n";
            }

            //fetch record from database for games ending free period tomorrow
            if ($aRecords = $this->fetchStopRecords()) {

                foreach ($aRecords as $aRecord) {
                    $sTweet = '';

                    //determine platform and format stop tweet
                    if (stripos($aRecord['platform'], 'xbox') !== FALSE) {
                        $sTweet = $this->formatTweet($aRecord, $this->sTweetFormatStopXbox);
                    } elseif (stripos($aRecord['platform'], 'playstation') !== FALSE) {
                        $sTweet = $this->formatTweet($aRecord, $this->sTweetFormatStopPSN);
                    }

                    //post tweet
                    if ($sTweet) {
                        $this->postTweet($sTweet);
                    } else {
                        $this->logger(2, sprintf('Unknown platform for game: %s (id %d)', $aRecord['platform'], $aRecord['id']));
                    }
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

    private function fetchStartRecords() {

        return $this->fetchRecords('
            SELECT *
            FROM gameswithgold
            WHERE startdate = CURDATE()'
        );
    }
    
    private function fetchStopRecords() {

        return $this->fetchRecords('
            SELECT *
            FROM gameswithgold
            WHERE enddate = CURDATE() + INTERVAL 1 DAY'
        );
    }

    private function fetchRecords($sQuery) {

        $sth = $this->oPDO->prepare($sQuery);
		if ($sth->execute() == FALSE) {
			$this->logger(2, sprintf('Select query failed. (%d %s)', $sth->errorCode(), $sth->errorInfo()));
			$this->halt(sprintf('- Select query failed, halting. (%d %s)', $sth->errorCode(), $sth->errorInfo()));
			return FALSE;
		}

        if ($aRecords = $sth->fetchAll(PDO::FETCH_ASSOC)) {

            return $aRecords;
        } else {
            return array();
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
