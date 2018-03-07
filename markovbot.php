<?php
require_once('twitteroauth.php');
require_once('logger.php');

/*
 * TODO:
 * - options to:
 *   V generate markov chains on every run
 *   v read pregenerated markov chains on every run
 *   V read pregenerated messages from database
 *   - seed a database with [x] generated tweets en masse
 */

class MarkovBot {

    private $oTwitter;
    private $sUsername;
    private $sLogFile;
    private $iLogLevel = 3; //increase for debugging
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
        $this->sInputType       = (!empty($aArgs['sInputType'])      ? $aArgs['sInputType']     : 'database');
        $this->sLogFile         = (!empty($aArgs['sLogFile'])        ? $aArgs['sLogFile']       : strtolower($this->sUsername) . '.log');

        switch ($this->sInputType) {
            case 'generate':
                ini_set('memory_limit', '256M');
                $this->sInputFile       = (!empty($aArgs['sInputFile'])      ? $aArgs['sInputFile']     : strtolower($this->sUsername) . '.csv');
                break;

            case 'generatesql':
                ini_set('memory_limit', '256M');
                $this->sInputFile       = (!empty($aArgs['sInputFile'])      ? $aArgs['sInputFile']     : strtolower($this->sUsername) . '.csv');
                $this->iTweetCount      = (!empty($aArgs['iTweetCount'])     ? $aArgs['iTweetCount']    : 1000);
                $this->sOutputFile      = (!empty($aArgs['sOutputFile'])     ? $aArgs['sOutputFile']    : strtolower($this->sUsername) . '.sql');
                break;

            case 'pregenerated':
                $this->sInputFile       = (!empty($aArgs['sInputFile'])      ? $aArgs['sInputFile']     : strtolower($this->sUsername) . '.json');
                break;

            case 'database':
            default:
                $this->aDbSettings      = (!empty($aArgs['aDbSettings'])     ? $aArgs['aDbSettings']        : array());
                $this->aTweetSettings = array(
                    'sFormat'       => (!empty($aArgs['sTweetFormat'])    ? $aArgs['sTweetFormat']   : ''),
                    'aTweetVars'    => (!empty($aArgs['aTweetVars'])      ? $aArgs['aTweetVars']     : array()),
                );
                break;
        }

        if ($this->sLogFile == '.log') {
            $this->sLogFile = pathinfo($_SERVER['SCRIPT_FILENAME'], PATHINFO_FILENAME) . '.log';
        }
    }

    public function run() {

        //get current user and verify it's the right one
        if ($this->getIdentity()) {

            switch ($this->sInputType) {

                //input text body, generate markov chains, generate tweet (least efficient)
                case 'generate':

                    //analyze text into markov chain
                    if ($this->generateMarkovChain()) {

                        //create a tweet!
                        echo "Generating tweet..\n";
                        if ($sTweet = $this->generateTweet()) {

                            //tweet it
                            if ($this->postMessage($sTweet)) {

                                $this->halt();
                            }
                        }
                    }
                break;

                case 'generatesql':

                    //analyze text into markov chain
                    if ($this->generateMarkovChain()) {

                        //generate body of tweets
                        $hFile = fopen(MYPATH . DIRECTORY_SEPARATOR . $this->sOutputFile, 'w');

                        printf("Generating %d tweets..\n", $this->iTweetCount);
                        for ($i = 0; $i < $this->iTweetCount; $i++) {
                            $sTweet = $this->generateTweet();
                            fwrite($hFile, sprintf('"%s",', str_replace('"', '\\"', $sTweet)) . PHP_EOL);
                            if ($i % 100 == 0) {
                                echo '.';
                            }
                        }
                        echo PHP_EOL;

                        fclose($hFile);

                        $this->halt();
                    }
                break;

                //input markov chains, generate tweet
                case 'pregenerated':

                    //load previously generated markov chains
                    if ($this->loadMarkovChain()) {

                        //create a tweet!
                        if ($sTweet = $this->generateTweet()) {

                            //tweet it
                            if ($this->postMessage($sTweet)) {

                                $this->halt();
                            }
                        }
                    }
                break;

                //read pregenerated tweet from database
                case 'database':
                default:

                    //get a tweet!
                    if ($sTweet = $this->getTweet()) {

                        //tweet it
                        if ($this->postMessage($sTweet)) {

                            $this->halt();
                        }
                    }
                break;
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

        if (!$this->sInputFile || filesize(MYPATH . DIRECTORY_SEPARATOR . $this->sInputFile) == 0) {
            $this->logger(2, 'No input file specified.');
            $this->halt('- No input file specified, halting.');
            return FALSE;
        }

        $aMarkovChains = array();
        $lStart = microtime(TRUE);
        $sInput = implode(' ', file(MYPATH . DIRECTORY_SEPARATOR . $this->sInputFile, FILE_IGNORE_NEW_LINES));
        printf("- Read input file %s (%d bytes in %.3fs)..\n", $this->sInputFile, filesize(MYPATH . DIRECTORY_SEPARATOR . $this->sInputFile), microtime(TRUE) - $lStart);
        $this->aMarkovChains = $this->generateMarkovChainsWords($sInput);

        return TRUE;
    }

    private function generateMarkovChainsWords($sInput) {

        $lStart = microtime(TRUE);
        $aWords = str_word_count($sInput, 1, '\'"-,.;:0123456789%?!');

        foreach ($aWords as $i => $sWord) {
            if (!empty($aWords[$i + 2])) {
                $aMarkovChains[$sWord . ' ' . $aWords[$i + 1]][] = $aWords[$i + 2];
            }
        }
        printf("- done, generated %d chains in %.3f seconds\n\n", count($aMarkovChains), microtime(TRUE) - $lStart);

        return $aMarkovChains;
    }

    private function loadMarkovChains() {

        echo "Loading Markov chains\n";

        if (is_file(MYPATH . DIRECTORY_SEPARATOR . $this->sInputFile)) {

            $lStart = microtime(TRUE);
            $this->aMarkovChains = @json_decode(file_get_contents(MYPATH . DIRECTORY_SEPARATOR . $this->sInputFile));

            if ($iJsonErr = json_last_error()) {

                printf("- error loading from %s: json_decode error %d\n\n", $this->sInputFile, $iJsonErr);
                return FALSE;
            } else {

                printf("- done, loaded %d chains in %d seconds\n\n", count($this->aMarkovChains), microtime(TRUE) - $lStart);
                return TRUE;
            }
        }

        printf("- error loading from %s: file does not exist\n\n", MYPATH . DIRECTORY_SEPARATOR . $this->sInputFile);
        return FALSE;
    }

    private function getTweet() {

        if ($aRecord = $this->getRecord()) {

            return $sTweet = $this->formatTweet($aRecord);
        }
    }

    private function formatTweet($aRecord) {

		//should get this by API (GET /help/configuration ->short_url_length) but it rarely changes
		$iMaxTweetLength = 280;
		$iShortUrlLength = 23;

		if (empty($this->aTweetSettings['sFormat']) || empty($this->aTweetSettings['aTweetVars'])) {
			$this->logger(2, 'Tweet format settings missing.');
			$this->halt('- One or more of the tweet format settings are missing, halting.');
			return FALSE;
		}

		//construct tweet
		$sTweet = $this->aTweetSettings['sFormat'];

		//replace all non-truncated fields
		foreach ($this->aTweetSettings['aTweetVars'] as $aVar) {
			if (empty($aVar['bTruncate']) || $aVar['bTruncate'] == FALSE) {
				$sTweet = str_replace($aVar['sVar'], $aRecord[$aVar['sRecordField']], $sTweet);
			}
		}

		//determine maximum length left over for truncated field (links are shortened to t.co format of max 22 chars)
		$sTempTweet = preg_replace('/http:\/\/\S+/', str_repeat('x', $iShortUrlLength), $sTweet);
		$sTempTweet = preg_replace('/https:\/\/\S+/', str_repeat('x', $iShortUrlLength + 1), $sTempTweet);
		$iTruncateLimit = $iMaxTweetLength - strlen($sTempTweet);

		//replace truncated field
		foreach ($this->aTweetSettings['aTweetVars'] as $aVar) {
			if (!empty($aVar['bTruncate']) && $aVar['bTruncate'] == TRUE) {

				//placeholder will get replaced, so add that to char limit
				$iTruncateLimit += strlen($aVar['sVar']);

				//get text to replace placeholder with
				$sText = html_entity_decode($aRecord[$aVar['sRecordField']], ENT_QUOTES, 'UTF-8');

				//get length of text with url shortening
				$sTempText = preg_replace('/http:\/\/\S+/', str_repeat('x', $iShortUrlLength), $sText);
				$sTempText = preg_replace('/https:\/\/\S+/', str_repeat('x', $iShortUrlLength + 1), $sTempText);
				$iTextLength = strlen($sTempText);

				//if text with url shortening falls under limit, keep it - otherwise truncate
				if ($iTextLength <= $iTruncateLimit) {
					$sTweet = str_replace($aVar['sVar'], $sText, $sTweet);
				} else {
					$sTweet = str_replace($aVar['sVar'], substr($sText, 0, $iTruncateLimit), $sTweet);
				}

				//only 1 truncated field allowed
				break;
			}
		}

		return $sTweet;
    }

    private function getRecord() {

		echo "Getting random record from database..\n";

		if (!defined('DB_HOST') || !defined('DB_NAME') ||
			!defined('DB_USER') || !defined('DB_PASS')) {

			$this->logger(2, 'MySQL database credentials missing.');
			$this->halt('- One or more of the MySQL database credentials are missing, halting.');
			return FALSE;
		}

        //connect to database
		try {
			$this->oPDO = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME, DB_USER, DB_PASS);
		} catch(Exception $e) {
			$this->logger(2, sprintf('Database connection failed. (%s)', $e->getMessage()));
			$this->halt(sprintf('- Database connection failed. (%s)', $e->getMessage()));
			return FALSE;
		}

		if (empty($this->aDbSettings) || empty($this->aDbSettings['sTable']) || empty($this->aDbSettings['sIdCol']) || 
			empty($this->aDbSettings['sCounterCol']) || empty($this->aDbSettings['sTimestampCol'])) {

			$this->logger(2, 'Database table settings missing.');
			$this->halt('- One or more of the database table settings are missing, halting.');
			return FALSE;
		}

        //fetch random record out of those with the lowest counter value
        $sth = $this->oPDO->prepare(sprintf('
            SELECT *
            FROM %1$s
            WHERE %2$s = (
                SELECT MIN(%2$s)
                FROM %1$s
            )
            ORDER BY RAND()
            LIMIT 1',
            $this->aDbSettings['sTable'],
            $this->aDbSettings['sCounterCol']
        ));

		if ($sth->execute() == FALSE) {
			$this->logger(2, sprintf('Select query failed. (%d %s)', $sth->errorCode(), $sth->errorInfo()));
			$this->halt(sprintf('- Select query failed, halting. (%d %s)', $sth->errorCode(), $sth->errorInfo()));
			return FALSE;
		}

        if ($aRecord = $sth->fetch(PDO::FETCH_ASSOC)) {
            printf("- Found record that has been posted %d times before.\n", $aRecord['postcount']);

            //update record with postcount and timestamp of last post
            $sth = $this->oPDO->prepare(sprintf('
                UPDATE %1$s
                SET %3$s = %3$s + 1,
                    %4$s = NOW()
                WHERE %2$s = :id
                LIMIT 1',
                $this->aDbSettings['sTable'],
                $this->aDbSettings['sIdCol'],
                $this->aDbSettings['sCounterCol'],
                $this->aDbSettings['sTimestampCol']
            ));
            $sth->bindValue(':id', $aRecord[$this->aDbSettings['sIdCol']], PDO::PARAM_INT);
            if ($sth->execute() == FALSE) {
                $this->logger(2, sprintf('Update query failed. (%d %s)', $stf->errorCode(), $sth->errorInfo()));
                $this->halt(sprintf('- Update query failed, halting. (%d %s)', $sth->errorCode(), $sth->errorInfo()));
                return FALSE;
            }

            return $aRecord;

        } else {
            $this->logger(3, 'Query yielded no results. (%d %s)', $sth->errorCode(), $sth->errorInfo());
            $this->halt(sprintf('- Select query yielded no records, halting. (%d %s)', $sth->errorCode, $sth->errorInfo()));
            return FALSE;
        }
    }

    private function generateTweet() {

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
            if (strlen($sNewTweet . ' ' . $sNextWord) <= 280) {
                $sNewTweet .= ' ' . $sNextWord;

                if (substr($sNewTweet, -1) == '.' && rand(1, 2) == 1) {
                    //sometimes stop on a period
                    break;
                }
            } else {
                break;
            }
        }

        return html_entity_decode($sNewTweet);
    }

    private function postMessage($sTweet) {

        //tweet
        $oRet = $this->oTwitter->post('statuses/update', array('status' => $sTweet, 'trim_users' => TRUE));
        if (isset($oRet->errors)) {
            $this->logger(2, sprintf('Twitter API call failed: statuses/update (%s)', $oRet->errors[0]->message), array('tweet' => $sTweet));
            $this->halt('- Error: ' . $oRet->errors[0]->message . ' (code ' . $oRet->errors[0]->code . ')');
            return FALSE;
        } else {
            printf("- Posted: %s\n", $sTweet);
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
?>
