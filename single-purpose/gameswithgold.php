<?php
require_once('twitteroauth.php');
require_once('logger.php');
require_once('gameswithgold.inc.php');

/*
 * TODO:
 * ? [ERROR] Unknown platform for game: Playstation 3 (id 150)
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
 * V tweets with really really long game titles aren't truncated (e.g. Guacamelee! Super Turbo Championship Edition)
 * V default links aren't used
 * x retweet @majorlenson and @thevowel when tweeting about games with gold?
 */

$o = new GamesWithGold(array(
    'sUsername'             => 'XboxPSfreegames',

    'sTweetFormatStartXbox'     => '[:platform] Starting today, :game is free with #Xbox Live Gold - :link',
    'sTweetFormatStopXbox'      => '[:platform] Today is the last day :game is free with #Xbox Live Gold - :link',
    'sDefaultLinkXbox'          => 'http://www.xbox.com/en-US/live/games-with-gold',
	'sTruncateFieldXbox'	    => 'game',

    'sTweetFormatStartPSN'      => '[:platform] Starting today, :game is free with #Playstation Plus - :link',
    'sTweetFormatStopPSN'	    => '[:platform] Today is the last day :game is free with #Playstation Plus - :link',
    'sDefaultLinkPSN'           => 'http://www.playstation.com/en-us/explore/playstation-plus/',
	'sTruncateFieldPSN'		    => 'game',

    'sTweetFormatStartGamepass' => '[Xbox GamePass] Starting today, :game is available for free with #Xbox GamePass - :link',
    'sTweetFormatStopGamepass'  => '[Xbox GamePass] Today is the last day :game is available for free with #Xbox GamePass - :link',
    'sDefaultLinkGamepass'      => 'https://www.microsoft.com/store/p/Xbox-Game-Pass/CFQ7TTC0K6L8',
    'sTruncateFieldGamepass'    => 'game',
));
$o->run();

class GamesWithGold {

    private $sUsername;
    private $oPDO;

    private $sLogFile;
    private $iLogLevel = 3; //increase for debugging
    private $aSettings;

	private $iTweetMaxLength = 280;
	private $iTweetLinkLength = 23;

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
        $this->sDefaultLinkXbox         = (!empty($aArgs['sDefaultLinkXbox'])       ? $aArgs['sDefaultLinkXbox']        : '');
		$this->sTruncateFieldXbox       = (!empty($aArgs['sTruncateFieldXbox'])     ? $aArgs['sTruncateFieldXbox']      : '');

        $this->sTweetFormatStartPSN     = (!empty($aArgs['sTweetFormatStartPSN'])   ? $aArgs['sTweetFormatStartPSN']    : '');
        $this->sTweetFormatStopPSN      = (!empty($aArgs['sTweetFormatStopPSN'])    ? $aArgs['sTweetFormatStopPSN']     : '');
        $this->sDefaultLinkPSN          = (!empty($aArgs['sDefaultLinkPSN'])        ? $aArgs['sDefaultLinkPSN']         : '');
		$this->sTruncateFieldPSN        = (!empty($aArgs['sTruncateFieldPSN'])      ? $aArgs['sTruncateFieldPSN']       : '');

        $this->sTweetFormatStartGamepass    = (!empty($aArgs['sTweetFormatStartGamepass'])  ? $aArgs['sTweetFormatStartGamepass']   : '');
        $this->sTweetFormatStopGamepass     = (!empty($aArgs['sTweetFormatStopGamepass'])   ? $aArgs['sTweetFormatStopGamepass']    : '');
        $this->sDefaultLinkGamepass         = (!empty($aArgs['sDefaultLinkGamepass'])       ? $aArgs['sDefaultLinkGamepass']        : '');
        $this->sTruncateFieldGamepass       = (!empty($aArgs['sTruncateFieldGamepass'])     ? $aArgs['sTruncateFieldGamepass']      : '');

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
            if (php_sapi_name() == 'cli') {
                global $argv;
                $sDate = isset($argv[1]) ? $argv[1] : '';
            } else {
                $sDate = filter_input(INPUT_GET, 'date');
            }
            if ($aRecords = $this->fetchStartRecords($sDate)) {

                foreach ($aRecords as $aRecord) {
                    $sTweet = '';

                    //determine platform and format start tweet
                    if (stripos($aRecord['platform'], 'xbox') !== FALSE) {
                        if ($aRecord['gamepass']) {
                            $sTweet = $this->formatTweet($aRecord, $this->sTweetFormatStartGamepass, 'xbox', true);
                        } else {
                            $sTweet = $this->formatTweet($aRecord, $this->sTweetFormatStartXbox, 'xbox');
                        }
                    } elseif (stripos($aRecord['platform'], 'playstation') !== FALSE) {
                        $sTweet = $this->formatTweet($aRecord, $this->sTweetFormatStartPSN, 'ps');
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
            if ($aRecords = $this->fetchStopRecords($sDate)) {

                foreach ($aRecords as $aRecord) {
                    $sTweet = '';

                    //determine platform and format stop tweet
                    if (stripos($aRecord['platform'], 'xbox') !== FALSE) {
                        if ($aRecord['gamepass']) {
                            $sTweet = $this->formatTweet($aRecord, $this->sTweetFormatStopGamepass, 'xbox', true);
                        } else {
                            $sTweet = $this->formatTweet($aRecord, $this->sTweetFormatStopXbox, 'xbox');
                        }
                    } elseif (stripos($aRecord['platform'], 'playstation') !== FALSE) {
                        $sTweet = $this->formatTweet($aRecord, $this->sTweetFormatStopPSN, 'ps');
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

    private function fetchStartRecords($sDate = '') {

        $sDate = $sDate ? $sDate : date('Y-m-d');
        return $this->fetchRecords('
            SELECT *
            FROM gameswithgold
            WHERE startdate = :date',
            [':date' => $sDate]
		);
    }
    
    private function fetchStopRecords($sDate = '') {

        $sDate = $sDate ? $sDate : date('Y-m-d');
        return $this->fetchRecords('
            SELECT *
            FROM gameswithgold
            WHERE enddate = :date',
            [':date' => $sDate]
        );
    }

    private function fetchRecords($sQuery, $aParams = []) {

        $sth = $this->oPDO->prepare($sQuery);
		if ($sth->execute($aParams) == FALSE) {
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

    private function formatTweet($aRecord, $sFormat, $sPlatform, $bGamepass = false) {

		//put in default link if game link is missing
		if (!$aRecord['link']) {
			if ($sPlatform == 'xbox') {
                if ($bGamepass) {
                    $aRecord['link'] = $this->sDefaultLinkGamepass;
                } else {
                    $aRecord['link'] = $this->sDefaultLinkXbox;
                }
			} elseif ($sPlatform == 'ps') {
				$aRecord['link'] = $this->sDefaultLinkPSN;
			}
		}

		//replace placeholder fields with values
        $sTweet = $sFormat;
        foreach ($aRecord as $sField => $sValue) {
            $sTweet = str_replace(':' . $sField, $sValue, $sTweet);
        }

		//check if tweet (with shortened links) is not too long
		$iTweetLength = strlen(preg_replace('/https?:\/\/\S+/', str_repeat('x', 22), $sTweet));
		if ($iTweetLength > $this->iTweetMaxLength) {

			if ($sPlatform == 'xbox') {
                if ($bGamepass) {
                    $sOriginalFieldValue = $aRecord[$this->sTruncateFieldGamepass];
                } else {
                    $sOriginalFieldValue = $aRecord[$this->sTruncateFieldXbox];
                }
			} elseif ($sPlatform == 'ps') {
				$sOriginalFieldValue = $aRecord[$this->sTruncateFieldPSN];
			}
			//truncate field with ellipsis
			$sTruncatedFieldValue = substr($sOriginalFieldValue, 0, strlen($sOriginalFieldValue) - ($iTweetLength - $this->iTweetMaxLength + 2)) . '..';
			$sTweet = str_replace($sOriginalFieldValue, $sTruncatedFieldValue, $sTweet);
		}

        return $sTweet;
    }

    private function postTweet($sTweet) {

		echo 'Posting tweet..<br>';
		$sTweet = rtrim($sTweet, '- ');

        //tweet
        $oRet = $this->oTwitter->post('statuses/update', array('status' => $sTweet, 'trim_users' => TRUE));
        if (isset($oRet->errors)) {
            $this->logger(2, sprintf('Twitter API call failed: statuses/update (%s)', $oRet->errors[0]->message), array('tweet' => $sTweet));
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
}
