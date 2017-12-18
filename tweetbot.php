<?php
require_once('twitteroauth.php');
require_once('logger.php');

/*
 * TODO:
 * - for bPostOnlyOnce=TRUE, notification when no more tweets available
 * - commands through mentions, replies through mentions/DMs like retweetbot
 */

class TweetBot {

	private $sUsername;			//username we will be tweeting from
    private $oPDO;

	private $sLogFile;			//where to log stuff
    private $iLogLevel = 3;     //increase for debugging

	private $aDbSettings;		//database query settings
	private $aTweetSettings;	//tweet format settings

	public function __construct($aArgs) {

		//connect to twitter
		$this->oTwitter = new TwitterOAuth(CONSUMER_KEY, CONSUMER_SECRET, ACCESS_TOKEN, ACCESS_TOKEN_SECRET);
		$this->oTwitter->host = "https://api.twitter.com/1.1/";

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

		//load args
		$this->parseArgs($aArgs);
	}

	private function parseArgs($aArgs) {

		$this->sUsername = (!empty($aArgs['sUsername']) ? $aArgs['sUsername'] : '');
        $this->bReplyToCmds = (!empty($aArgs['bReplyToCmds']) ? $aArgs['bReplyToCmds'] : FALSE);

		//stuff to determine what to get from database
		$this->aDbSettings = (!empty($aArgs['aDbVars']) ? $aArgs['aDbVars'] : array());

		//stuff to determine what we're tweeting
		$this->aTweetSettings = array(
			'sFormat'		=> (isset($aArgs['sTweetFormat']) ? $aArgs['sTweetFormat'] : ''),
			'aTweetVars'	=> (isset($aArgs['aTweetVars']) ? $aArgs['aTweetVars'] : array()),
            'bPostOnlyOnce' => (isset($aArgs['bPostOnlyOnce']) ? $aArgs['bPostOnlyOnce'] : FALSE),
		);

		$this->sLogFile			= (!empty($aArgs['sLogFile'])		? $aArgs['sLogFile']			: strtolower($this->sUsername) . '.log');

		if ($this->sLogFile == '.log') {
			$this->sLogFile = pathinfo($_SERVER['SCRIPT_FILENAME'], PATHINFO_FILENAME) . '.log';
		}
	}

	public function run() {

		//verify current twitter user is correct
		if ($this->getIdentity()) {

            //check messages & reply if needed
            if ($this->bReplyToCmds) {
                $this->checkMentions();
            }

			//fetch database record
			if ($aRecord = $this->getRecord()) {

				//format and post message
				if ($this->postMessage($aRecord)) {

					$this->halt('Done.');
				}
			}
		}
	}

	private function getIdentity() {

		echo "Fetching identify..\n";

		if (!$this->sUsername) {
			$this->logger(2, 'No username');
			$this->halt('- No username! Set username when calling constructor.');
			return FALSE;
		}

		$oUser = $this->oTwitter->get('account/verify_credentials', array('include_entities' => FALSE, 'skip_status' => TRUE));

		if (is_object($oUser) && !empty($oUser->screen_name)) {
			if ($oUser->screen_name == $this->sUsername) {
				printf("- Allowed: @%s, continuing.\n\n", $oUser->screen_name);
			} else {
				$this->logger(2, sprintf('Authenticated username was unexpected: %s (expected: %s)', $oUser->screen_name, $this->sUsername));
				$this->halt(sprintf('- Not alowed: @%s (expected: %s), halting.', $oUser->screen_name, $this->sUsername));
				return FALSE;
			}
		} else {
			$this->logger(2, sprintf('Twitter API call failed: GET account/verify_credentials (%s)', $oUser->errors[0]->message));
			$this->halt(sprintf('- Call failed, halting. (%s)', $oUser->errors[0]->message));
			return FALSE;
		}

		return TRUE;
	}

	private function getRecord() {

		echo "Getting random record from database..\n";

		if (!defined('DB_HOST') || !defined('DB_NAME') ||
			!defined('DB_USER') || !defined('DB_PASS')) {

			$this->logger(2, 'MySQL database credentials missing.');
			$this->halt('- One or more of the MySQL database credentials are missing, halting.');
			return FALSE;
		}

		if (empty($this->aDbSettings) || empty($this->aDbSettings['sTable']) || empty($this->aDbSettings['sIdCol']) || 
			empty($this->aDbSettings['sCounterCol']) || empty($this->aDbSettings['sTimestampCol'])) {

			$this->logger(2, 'Database table settings missing.');
			$this->halt('- One or more of the database table settings are missing, halting.');
			return FALSE;
		}

        if ($this->aTweetSettings['bPostOnlyOnce'] == TRUE) {

            //fetch random record out of those that haven't been posted yet
            $sth = $this->oPDO->prepare(sprintf('
                SELECT *
                FROM %1$s AS r1
                JOIN (
                    SELECT (RAND() * (
                        SELECT MAX(id) FROM %1$s
                    )) AS random_id
                ) AS r2
                WHERE r1.id >= r2.random_id
                AND %2$s = 0
                ORDER BY r1.id ASC
                LIMIT 1',
                $this->aDbSettings['sTable'],
                $this->aDbSettings['sCounterCol']
            ));
        } else {

            //fetch random record out of those with the lowest counter value
            $sth = $this->oPDO->prepare(sprintf('
                SELECT *
                FROM %1$s AS r1
                JOIN (
                    SELECT (RAND() * (
                        SELECT MAX(id) FROM %1$s
                    )) AS random_id
                ) AS r2
                WHERE r1.id >= r2.random_id
                AND %2$s = (
                    SELECT MIN(%2$s)
                    FROM %1$s
                )
                ORDER BY r1.id ASC
                LIMIT 1',
                $this->aDbSettings['sTable'],
                $this->aDbSettings['sCounterCol']
            ));
        }

		if ($sth->execute() == FALSE) {
			$this->logger(2, sprintf('Select query failed. (%d %s)', $sth->errorCode(), $sth->errorInfo()));
			$this->halt(sprintf('- Select query failed, halting. (%d %s)', $sth->errorCode(), $sth->errorInfo()));
			return FALSE;
		}

        if ($aRecord = $sth->fetch(PDO::FETCH_ASSOC)) {
            printf("\n- Found record that has been posted %d times before.\n", $aRecord['postcount']);

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

	private function postMessage($aRecord) {

		echo "Posting tweet..\n";

		//construct tweet
		$sTweet = $this->formatTweet($aRecord);
		if (!$sTweet) {
			return FALSE;
		}

        //check if message is tweet url
        if (preg_match('/https:\/\/twitter\.com\/[^\/]+\/status\/\d+/', $sTweet)) {

            //retweet
            $aTweetUrls = explode("\n", $sTweet);
            foreach ($aTweetUrls as $sTweetUrl) {
                if (preg_match('/https:\/\/twitter\.com\/[^\/]+\/status\/(\d+)/', $sTweetUrl, $m)) {

                    //do retweet
                    $oRet = $this->oTwitter->post('statuses/retweet/' . $m[1], array('trim_user' => TRUE));

                    if (!empty($oRet->errors)) {
                        $this->logger(2, sprintf('Twitter API call failed: POST statuses/retweet (%s)', $oRet->errors[0]->message), array('tweet' => $m[1]));
                        $this->halt(sprintf('- Retweet failed, halting. (*%s)', $oRet->errors[0]->message));
                        return FALSE;
                    } else {
                        printf("- Retweeted: %s\n", $sTweetUrl);
                    }
                }
            }

        } else {

            //tweet
            $oRet = $this->oTwitter->post('statuses/update', array('status' => $sTweet, 'trim_users' => TRUE));
            if (isset($oRet->errors)) {
                $this->logger(2, sprintf('Twitter API call failed: statuses/update (%s)', $oRet->errors[0]->message), array('tweet' => $sTweet));
                $this->halt('- Error: ' . $oRet->errors[0]->message . ' (code ' . $oRet->errors[0]->code . ')');
                return FALSE;
            } else {
                printf("- %s\n", utf8_decode($sTweet));
            }
        }

		return TRUE;
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

    private function checkMentions() {

		$aLastSearch = json_decode(@file_get_contents(MYPATH . '/' . sprintf($this->sLastSearchFile, 1)), TRUE);
        printf("Checking mentions since %s for commands..\n", $aLastSearch['timestamp']);

        //fetch new mentions since last run
        $aMentions = $this->oTwitter->get('statuses/mentions_timeline', array(
            'count'         => 10,
			'since_id'		=> ($aLastSearch && !empty($aLastSearch['max_id']) ? $aLastSearch['max_id'] : 1),
        ));

        if (is_object($aMentions) && !empty($aMentions->errors[0]->message)) {
            $this->logger(2, sprintf('Twitter API call failed: GET statuses/mentions_timeline (%s)', $aMentions->errors[0]->message));
            $this->halt(sprintf('- Failed getting mentions, halting. (%s)', $aMentions->errors[0]->message));
        }

        //if we have mentions, get friends for auth (we will only respond to commands from people we follow)
        if (count($aMentions) > 0) {
            $oRet = $this->oTwitter->get('friends/ids', array('screen_name' => $this->sUsername, 'stringify_ids' => TRUE));
            if (!empty($oRet->errors[0]->message)) {
                $this->logger(2, sprintf('Twitter API call failed: GET friends/ids (%s)', $aMentions->errors[0]->message));
                $this->halt(sprintf('- Failed getting friends, halting. (%s)', $aMentions->errors[0]->message));
            }
            $aFollowing = $oRet->ids;

        } else {
            echo "- no new mentions.\n\n";
            return FALSE;
        }

        foreach ($aMentions as $oMention) {

            //only reply to friends (people we are following)
            if (in_array($oMention->user->id_str, $aFollowing)) {

                $bRet = $this->parseCommand($oMention);
                if (!$bRet) {
                    break;
                }
            }
        }
        printf("- replied to %d commands\n\n", count($aMentions));

        return TRUE;
    }

    private function parseCommand($oMention) {

        //reply to commands from friends (people we follow) in DMs
        $sId = $oMention->id_str;
        $sCommand = str_replace('@' . strtolower($this->sUsername) . ' ', '', strtolower($oMention->text));
        printf("Parsing command %s from %s..\n", $sCommand, $oMention->user->screen_name);

        switch ($sCommand) {
            case 'help':
                return $this->replyToCommand($oMention, 'Commands: help lastrun lastlog. Only replies to friends. Lag varies, be patient.');

            case 'lastrun':
                $aLastSearch = json_decode(@file_get_contents(MYPATH . '/' . sprintf($this->sLastSearchFile, 1)), TRUE);

                return $this->replyToCommand($oMention, sprintf('Last script run was: %s', (!empty($aLastSearch['timestamp']) ? $aLastSearch['timestamp'] : 'never')));

            case 'lastlog':
                $aLogFile = @file($this->sLogFile, FILE_IGNORE_NEW_LINES);

                return $this->replyToCommand($oMention, ($aLogFile ? $aLogFile[count($aLogFile) - 1] : 'Log file is empty'));

            case 'stats':

                $aStats = $this->getStats();

                return $this->replyToCommand($oMention, sprintf('Total records: %d, %d aren\'t posted yet.', $aStats['total'], $aStats['unposted']));

            default:
                echo "- command unknown.\n";
                return FALSE;
        }
    }

    private function replyToCommand($oMention, $sReply) {

        //check friendship between bot and command sender
        $oRet = $this->oTwitter->get('friendships/show', array('source_screen_name' => $this->sUsername, 'target_screen_name' => $oMention->user->screen_name));
        if (!empty($oRet->errors)) {
            $this->logger(2, sprintf('Twitter API call failed: GET friendships/show (%s)', $oRet->errors[0]->message));
            $this->halt(sprintf('- Failed to check friendship, halting. (%s)', $oRet->errors[0]->message));
            return FALSE;
        }

        //if we can DM the source of the command, do that
        if ($oRet->relationship->source->can_dm) {

            $oRet = $this->oTwitter->post('direct_messages/new', array('user_id' => $oMention->user->id_str, 'text' => $sReply));

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
                    substr($sReply, 0, 280 - 2 - strlen($oMention->user->screen_name))
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

    private function getStats() {

        $sth = $this->oPDO->prepare(sprintf('
            SELECT (
             SELECT COUNT(*) FROM %1$s
            ) AS total, (
             SELECT COUNT(*) FROM %1$s WHERE %2$s = 0
            ) AS unposted',
            $this->aDbSettings['sTable'],
            $this->aDbSettings['sCounterCol']
        ));
        if ($sth->execute() == FALSE) {
            $this->logger(2, sprintf('Stats query failed. (%d %s)', $stf->errorCode(), $sth->errorInfo()));
            $this->halt(sprintf('- Stats query failed, halting. (%d %s)', $sth->errorCode(), $sth->errorInfo()));
            return FALSE;
        }

        //get total number of records, as well as records with postcount 0
        if ($aStats = $sth->fetch(PDO::FETCH_ASSOC)) {
            return $aStats;
        } else {
            return array('total' => 0, 'unposted' => 0);
        }
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
