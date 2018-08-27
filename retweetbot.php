<?php
require_once('twitteroauth.php');
require_once('logger.php');

class RetweetBot
{
	//stuff we get from twitter
	private $oTwitter;
	private $aBlockedUsers;

	//stuff from settings
	private $aSearchFilters = array();
	private $aUsernameFilters = array();
	private $aDiceValues = array();

	//stuff passed in constructor 
	private $sUsername;			//username we will be tweeting from
	private $aSearchStrings;	//search query
	private $iSearchMax;		//max search results to get at once

	private $iMinRateLimit;		//rate limit threshold, halt if rate limit is below this

	private $sSettingsFile;		//where to get settings from
	private $sLastSearchFile;	//where to save data from last search
	private $sLogFile;			//where to log stuff
    private $iLogLevel = 3;     //increase for debugging

    private $aDontLogTheseErrors = [
        327, //you have already retweeted this tweet
        //TODO: 'you have been blocked from retweeting this user's tweets at their request'

    ];

	public function __construct($aArgs) {

		$this->oTwitter = new TwitterOAuth(CONSUMER_KEY, CONSUMER_SECRET, ACCESS_TOKEN, ACCESS_TOKEN_SECRET);
		$this->oTwitter->host = "https://api.twitter.com/1.1/";

		//hardcoded search filters
		$this->aSearchFilters = array(
			'"@',				//quote (instead of retweet)
			chr(147) . '@',		//smart quote 
			'@',
			'â@',				//mangled smart quote
			'“@',				//more smart quote “
		);

		//default probability values
		$this->aDiceValues = array(
			'media' 	=> 1.0,
			'urls' 		=> 0.8,
			'mentions'	=> 0.5,
			'base' 		=> 0.7,
		);

		//parse arguments and merge with defaults
		$this->parseArgs($aArgs);

		//load settings file and merge with defaults
		$this->loadSettings();
	}

	private function parseArgs($aArgs) {

		//parse arguments, set values or defaults
		$this->sUsername 		= (!empty($aArgs['sUsername']) 		? $aArgs['sUsername']		: '');
		$this->aSearchStrings	= (!empty($aArgs['aSearchStrings']) ? $aArgs['aSearchStrings']	: '');
		$this->iSearchMax		= (!empty($aArgs['iSearchMax'])		? $aArgs['iSearchMax']		: 5);
		$this->iMinRateLimit	= (!empty($aArgs['iMinRateLimit'])	? $aArgs['iMinRateLimit']	: 5);
        $this->bReplyToCmds     = (!empty($aArgs['bReplyToCmds'])   ? $aArgs['bReplyToCmds']    : FALSE);

		$this->sSettingsFile 	= (!empty($aArgs['sSettingsFile'])	? $aArgs['sSettingsFile']		: strtolower($this->sUsername) . '.json');
		$this->sLastSearchFile 	= (!empty($aArgs['sLastSearchFile']) ? $aArgs['sLastSearchFile']	: strtolower($this->sUsername) . '-last%d.json');
		$this->sLogFile			= (!empty($aArgs['sLogFile'])		? $aArgs['sLogFile']			: strtolower($this->sUsername) . '.log');

		if ($this->sLogFile == '.log') {
			$this->sLogFile = pathinfo($_SERVER['SCRIPT_FILENAME'], PATHINFO_FILENAME) . '.log';
		}
	}

	private function loadSettings() {

		//load settings
		$this->aSettings = json_decode(@file_get_contents(MYPATH . '/' . $this->sSettingsFile), TRUE);

		//merge tweet text filters
		if (!empty($this->aSettings['filters'])) {
			$this->aSearchFilters = array_merge($this->aSearchFilters, $this->aSettings['filters']);
		}

		//get username filters
		if (!empty($this->aSettings['userfilters'])) {
			$this->aUsernameFilters = $this->aSettings['userfilters'];
		}

		//get probability values
		if (!empty($this->aSettings['dice'])) {
			$this->aDiceValues = $this->aSettings['dice'];
		}
	}

	public function run() {

		//get current user and verify it's the right one
		if ($this->getIdentity()) {

			//check rate limit status
			if ($this->getRateLimitStatus()) {

				//retrieve list of all blocked users
				if ($this->getBlockedUsers()) {

                    //check messages & reply if needed
                    if ($this->bReplyToCmds) {
                        $this->checkMentions();
                    }

					//loop through all search strings
					$this->aSearchStrings = (is_array($this->aSearchStrings) ? $this->aSearchStrings : array(1 => $this->aSearchStrings));
					foreach ($this->aSearchStrings as $iIndex => $sSearchString) {

						//perform search for stuff to retweet
						if ($this->doSearch($sSearchString, $iIndex)) {

							//filter and retweet
							$this->doRetweets();
						}
					}

					$this->halt('<br>Performed all searches.');
				}
			}
		}
		//end
	}

	private function getIdentity() {

		echo 'Fetching identity..<br>';

		if (!$this->sUsername) {
			$this->logger(2, 'No username');
			$this->halt('- No username! Set username when calling constructor.');
			return FALSE;
		}

		$oCurrentUser = $this->oTwitter->get('account/verify_credentials', array('include_entities' => FALSE, 'skip_status' => TRUE));

		if (is_object($oCurrentUser) && !empty($oCurrentUser->screen_name)) {
			if ($oCurrentUser->screen_name == $this->sUsername) {
				printf('- Allowed: @%s, continuing.<br><br>', $oCurrentUser->screen_name);
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

	private function getRateLimitStatus() {

		echo 'Fetching rate limit status..<br>';
		$oStatus = $this->oTwitter->get('application/rate_limit_status', array('resources' => 'search,blocks'));
		$oRateLimit = $oStatus->resources->search->{'/search/tweets'};
		$oBlockedLimit = $oStatus->resources->blocks->{'/blocks/ids'};
        $this->oRateLimitStatus = $oStatus;

		//check if remaining calls for search is lower than threshold (after reset: 180)
		if ($oRateLimit->remaining < $this->iMinRateLimit) {
			$this->logger(3, sprintf('Rate limit for GET search/tweets hit, waiting until %s', date('Y-m-d H:i:s', $oRateLimit->reset)));
			$this->halt(sprintf('- Remaining %d/%d calls! Aborting search until next reset at %s.',
				$oRateLimit->remaining,
				$oRateLimit->limit,
				date('Y-m-d H:i:s', $oRateLimit->reset)
			));
			return FALSE;
		} else {
			printf('- Remaining %d/%d calls (search), next reset at %s.<br>', $oRateLimit->remaining, $oRateLimit->limit, date('Y-m-d H:i:s', $oRateLimit->reset));
		}

		//check if remaining calls for blocked users is lower than treshold (after reset: 15)
		if ($oBlockedLimit->remaining < $this->iMinRateLimit) {
			$this->logger(3, sprintf('Rate limit for GET blocks/ids hit, waiting until %s', date('Y-m-d H:i:s', $oBlockedLimit->reset)));
			$this->halt(sprintf('- Remaining %d/%d calls for blocked users! Aborting search until next reset at %s.',
				$oBlockedLimit->remaining,
				$oBlockedLimit->limit,
				date('Y-m-d H:i:s', $oBlockedLimit->reset)
			));
			return FALSE;
		} else {
			printf('- Remaining %d/%d calls (blocked users), next reset at %s.<br><br>',
				$oBlockedLimit->remaining,
				$oBlockedLimit->limit,
				date('Y-m-d H:i:s', $oBlockedLimit->reset)
			);
		}

		return TRUE;
	}

	private function getBlockedUsers() {

		echo 'Getting blocked users..<br>';
		$oBlockedUsers = $this->oTwitter->get('blocks/ids', array('stringify_ids' => TRUE));
		//note that not providing the 'cursor' param causes pagination in batches of 5000 ids

		if (!empty($oBlockedUsers->errors)) {
			$this->logger(2, sprintf('Twitter API call failed: GET blocks/ids (%s)', $oBlockedUsers->errors[0]->message));
			$this->halt(sprintf('- Unable to get blocked users, halting. (%s)', $oBlockedUsers->errors[0]->message));
			return FALSE;
		} else {
            printf('- %d on list<br><br>', count($oBlockedUsers->ids));
			$this->aBlockedUsers = $oBlockedUsers->ids;
		}

		return TRUE;
	}

	private function doSearch($sSearchString, $iIndex) {

		if (empty($sSearchString)) {
			$this->logger(2, 'No search string set');
			$this->halt('No search string! Set search string when calling constructor.');
			return FALSE;
		}

		printf('Searching for max %d tweets with: %s..<br>', $this->iSearchMax, $sSearchString);

		//retrieve data for last search to prevent duplicates
		$aLastSearch = json_decode(@file_get_contents(MYPATH . '/' . sprintf($this->sLastSearchFile, $iIndex)), TRUE);

		$aArgs = array(
			'q'				=> $sSearchString,
			'result_type'	=> 'mixed',
			'count'			=> $this->iSearchMax,
			'since_id'		=> ($aLastSearch && !empty($aLastSearch['max_id']) ? $aLastSearch['max_id'] : 1),
		);
		$oSearch = $this->oTwitter->get('search/tweets', $aArgs);

		if (empty($oSearch->search_metadata)) {
			$this->logger(2, sprintf('Twitter API call failed: GET /search/tweets (%s)', $oSearch->errors[0]->message), $aArgs);
			$this->halt(sprintf('- Unable to get search results, halting. (%s)', $oSearch->errors[0]->message));
			return FALSE;
		}

		//save data for next run
		$aThisSearch = array(
			'max_id'	=> $oSearch->search_metadata->max_id_str,
			'timestamp'	=> date('Y-m-d H:i:s'),
		);
		file_put_contents(MYPATH . '/' . sprintf($this->sLastSearchFile, $iIndex), json_encode($aThisSearch));

		$this->aTweets = FALSE;
		if (empty($oSearch->statuses) || count($oSearch->statuses) == 0) {
			printf('- No results since last search at %s.<br><br>', $aLastSearch['timestamp']);
			return FALSE;

		} else {
            //make sure we parse oldest tweets first
			$this->aTweets = array_reverse($oSearch->statuses);
			return TRUE;
		}
	}

	private function doRetweets() {

		if (empty($this->aTweets)) {
			$this->logger(3, 'Nothing to retweet.');
			$this->halt('Nothing to retweet.');
			return FALSE;
		}

		foreach ($this->aTweets as $oTweet) {

			//don't parse stuff we already retweeted
			if ($oTweet->retweeted) {
				//NOTE: this currently isn't supported in 1.1 API lol
				printf('Skipping because already retweeted: %s<br>', $oTweet->text);
				continue;
			}

			//replace shortened links
			$oTweet = $this->expandUrls($oTweet);

			//perform post-search filters
			if ($this->filterTweet($oTweet) == FALSE) {
				continue;
			}

			printf('Retweeting: <a href="http://twitter.com/%s/statuses/%s">@%s</a>: %s<br>',
				$oTweet->user->screen_name,
				$oTweet->id_str,
				$oTweet->user->screen_name,
				str_replace("\n", ' ', $oTweet->text)
			);
			$oRet = $this->oTwitter->post('statuses/retweet/' . $oTweet->id_str, array('trim_user' => TRUE));

			if (!empty($oRet->errors) && !in_array($oRet->errors[0]->code, $this->aDontLogTheseErrors)) {
                $this->logger(2, sprintf('Twitter API call failed: POST statuses/retweet (%s, %s)',
                    $oRet->errors[0]->code,
                    $oRet->errors[0]->message),
                    array('tweet' => $oTweet)
                );
				$this->halt(sprintf('- Retweet failed, halting. (%s, %s)', $oRet->errors[0]->code, $oRet->errors[0]->message));
				return FALSE;
			}
		}

		$this->aTweets = FALSE;
	}

	//apply all filters to a tweet to determine if we're retweeting it
	private function filterTweet($oTweet) {

		//text filters
		if (!$this->applyFilters($oTweet)) {
			return FALSE;
		}

		//username filters
		if (!$this->applyUsernameFilters($oTweet)) {
			return FALSE;
		}

		//check blocked list
		if (!$this->isBlocked($oTweet)) {
			return FALSE;
		}
		
		//random chance
		if (!$this->rollDie($oTweet)) {
			return FALSE;
		}

		return TRUE;
	}

	//check tweet for filtered terms
	private function applyFilters($oTweet) {

		foreach ($this->aSearchFilters as $sFilter) {
			if (strpos(strtolower($oTweet->text), $sFilter) !== FALSE) {
				printf('<b>Skipping tweet because it contains "%s"</b>: %s<br>', $sFilter, str_replace("\n", ' ', $oTweet->text));
				return FALSE;
			}
		}

		return TRUE;
	}

	//check username
	private function applyUsernameFilters($oTweet) {

		foreach ($this->aUsernameFilters as $sUsername) {
			if (strpos(strtolower($oTweet->user->screen_name), $sUsername) !== FALSE) {
				printf('<b>Skipping tweet because username contains "%s"</b>: %s<br>', $sUsername, $oTweet->user->screen_name);
				return FALSE;
			}
			if (preg_match('/@\S*' . $sUsername . '/', $oTweet->text)) {
				printf('<b>Skipping tweet because mentioned username contains "%s"</b>: %s<br>', $sUsername, $oTweet->text);
				return FALSE;
			}
		}

		return TRUE;
	}

	//check blocked
	private function isBlocked($oTweet) {

		foreach ($this->aBlockedUsers as $iBlockedId) {
			if ($oTweet->user->id == $iBlockedId) {
				printf('<b>Skipping tweet because user "%s" is blocked</b><br>', $oTweet->user->screen_name);
				return FALSE;
			}
		}

		return TRUE;
	}

	//calculate probability we should tweet this, based on some properties
	private function rollDie($oTweet) {

		//regular tweets are better than mentions - medium probability
		$lProbability = $this->aDiceValues['base'];

		if (!empty($oTweet->entities->media) && count($oTweet->entities->media) > 0 
			|| strpos('instagram.com/p/', $oTweet->text) !== FALSE
			|| strpos('vine.co/v/', $oTweet->text) !== FALSE) {

			//photos/videos are usually funny - certain
			$lProbability = $this->aDiceValues['media'];

		} elseif (!empty($oTweet->entities->urls) && count($oTweet->entities->urls) > 0) {
			//links are ok but can be porn - high probability
			$lProbability = $this->aDiceValues['urls'];

		} elseif (strpos('@', $oTweet->text) === 0) {
			//mentions tend to be 'remember that time' stories or insults - low probability
			$lProbability = $this->aDiceValues['mentions'];
		}

		//compare probability (0.0 to 1.0) against random number
		$random = mt_rand() / mt_getrandmax();
		if (mt_rand() / mt_getrandmax() > $lProbability) {
			printf('<b>Skipping tweet because the dice said so</b>: %s<br>', str_replace("\n", ' ', $oTweet->text));
			return FALSE;
		}

		return TRUE;
	}

    private function checkMentions() {

		$aLastSearch = json_decode(@file_get_contents(MYPATH . '/' . sprintf($this->sLastSearchFile, 1)), TRUE);
        printf('Checking mentions since %s for commands..<br>', $aLastSearch['timestamp']);

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
            echo '- no new mentions.<br><br>';
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
        printf('- replied to %d commands<br><br>', count($aMentions));

        return TRUE;
    }

    private function parseCommand($oMention) {

        //reply to commands from friends (people we follow) in DMs
        $sId = $oMention->id_str;
        $sCommand = str_replace('@' . strtolower($this->sUsername) . ' ', '', strtolower($oMention->text));
        printf('Parsing command %s from %s..<br>', $sCommand, $oMention->user->screen_name);

        switch ($sCommand) {
            case 'help':
                return $this->replyToCommand($oMention, 'Commands: help lastrun lastlog ratelimit. Only replies to friends. Lag varies, be patient.');

            case 'lastrun':
                $aLastSearch = json_decode(@file_get_contents(MYPATH . '/' . sprintf($this->sLastSearchFile, 1)), TRUE);

                return $this->replyToCommand($oMention, sprintf('Last script run was: %s', (!empty($aLastSearch['timestamp']) ? $aLastSearch['timestamp'] : 'never')));

            case 'lastlog':
                $aLogFile = @file($this->sLogFile, FILE_IGNORE_NEW_LINES);

                return $this->replyToCommand($oMention, ($aLogFile ? $aLogFile[count($aLogFile) - 1] : 'Log file is empty'));

            case 'ratelimit':
                if (!empty($this->oRateLimitStatus)) {
                    $oRateLimit = $this->oRateLimitStatus->resources->search->{'/search/tweets'};
                    $oBlockedLimit = $this->oRateLimitStatus->resources->blocks->{'/blocks/ids'};

                    return $this->replyToCommand($oMention, sprintf('Rate limit status: search %d/%d (next reset at %s), blocks %d/%d (next reset at %s)',
                        $oRateLimit->remaining, $oRateLimit->limit, date('H:i:s', $oRateLimit->reset),
                        $oBlockedLimit->remaining, $oBlockedLimit->limit, date('H:i:s', $oBlockedLimit->reset)
                    ));
                } else {

                    return $this->replyToCommand($oMention, 'Rate limit status: not available (API call failed?)');
                }

            default:
                echo '- command unknown.<br>';
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

        printf('- Replied: %s<br>', $sReply);
        return TRUE;
    }

	/*
	 * shortened urls are listed in their expanded form in 'entities' node, under entities/urls and entities/media
	 * - urls - expanded_url
	 * - media (embedded photos) - display_url (default FALSE because text search is kinda useless here)
	 */
	private function expandUrls($oTweet, $bUrls = TRUE, $bPhotos = FALSE) {

		//check for links/photos
		if (strpos($oTweet->text, 'http://t.co') !== FALSE) {
			if ($bUrls) {
				foreach($oTweet->entities->urls as $oUrl) {
					$oTweet->text = str_replace($oUrl->url, $oUrl->expanded_url, $oTweet->text);
				}
			}
			if ($bPhotos) {
				foreach($oTweet->entities->media as $oPhoto) {
					$oTweet->text = str_replace($oPhoto->url, $oPhoto->display_url, $oTweet->text);
				}
			}
		}
		return $oTweet;
	}

	private function halt($sMessage = '') {
		echo $sMessage . '<br><br>Done!<br><br>';
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
