<?php
require_once('twitteroauth.php');
require_once('towinbot.inc.php');

$o = new ToWinBot(array(
	'sUsername'			=> 'lAlwaysWin',
	'aSearchStrings'	=> array(
		1 => 'RT giveaway OR contest OR "to win"',
		2 => 'retweet giveaway OR contest OR "to win"',
	)
));
$o->run();

class ToWinBot
{
	//stuff we get from twitter
	private $oTwitter;
	private $aBlockedUsers;
	private $aFollowing;

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

	private $iMaxFollowing = 2000; //max number of people to follow (to prevent being blocked)

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
			'mentions'	=> 0.0,
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

					//get all accounts we are following
					if ($this->getFollowing()) {

						//loop through all search strings
						$this->aSearchStrings = (is_array($this->aSearchStrings) ? $this->aSearchStrings : array(1 => $this->aSearchStrings));
						foreach ($this->aSearchStrings as $iIndex => $sSearchString) {

							//perform search for stuff to retweet
							if ($this->doSearch($sSearchString, $iIndex)) {

								//filter and parse
								$this->doActions();
							}
						}

						$this->halt('<br>Performed all searches.');
					}
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
		$oStatus = $this->oTwitter->get('application/rate_limit_status', array('resources' => 'search,blocks,friends'));
		$oRateLimit = $oStatus->resources->search->{'/search/tweets'};
		$oBlockedLimit = $oStatus->resources->blocks->{'/blocks/ids'};
		$oFollowingLimit = $oStatus->resources->friends->{'/friends/ids'};
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
			printf('- Remaining %d/%d calls (blocked users), next reset at %s.<br>',
				$oBlockedLimit->remaining,
				$oBlockedLimit->limit,
				date('Y-m-d H:i:s', $oBlockedLimit->reset)
			);
		}

		//check if remaining calls for following is lower than treshold (after reset: 15)
		if ($oFollowingLimit->remaining < $this->iMinRateLimit) {
			$this->logger(3, sprintf('Rate limit for GET friends/ids hit, waiting until %s', date('Y-m-d H:i:s', $oFollowingLimit->reset)));
			$this->halt(sprintf('- Remaining %d/%d calls for following users! Aborting search until next reset at %s.',
				$oFollowingLimit->remaining,
				$oFollowingLimit->limit,
				date('Y-m-d H:i:s', $oFollowingLimit->reset)
			));
			return FALSE;
		} else {
			printf('- Remaining %d/%d calls (following), next reset at %s.<br>',
				$oFollowingLimit->remaining,
				$oFollowingLimit->limit,
				date('Y-m-d H:i:s', $oFollowingLimit->reset)
			);
		}

		echo '<br>';
		return TRUE;
	}

	private function getBlockedUsers() {

		echo 'Getting blocked users..<br>';
		$oBlockedUsers = $this->oTwitter->get('blocks/ids', array('stringify_ids' => TRUE));
		//note that not providing the 'cursor' param causes pagination in batches of 5000 ids

		if (empty($oBlockedUsers->ids) && !empty($oBlockedUsers->errors)) {
			$this->logger(2, sprintf('Twitter API call failed: GET blocks/ids (%s)', $oBlockedUsers->errors[0]->message));
			$this->halt(sprintf('- Unable to get blocked users, halting. (%s)', $oBlockedUsers->errors[0]->message));
			return FALSE;
		} else {
            printf('- %d on list<br><br>', count($oBlockedUsers->ids));
			$this->aBlockedUsers = $oBlockedUsers->ids;
		}

		return TRUE;
	}

	private function getFollowing() {

		echo 'Getting following..<br>';
		$oFollowing = $this->oTwitter->get('friends/ids', array('screen_name' => $this->sUsername, 'stringify_ids' => TRUE));

		if (empty($oFollowing->ids) && !empty($oFollowing->errors)) {
			$this->logger(2, sprintf('Twitter API call failed: GET friends/ids (%s)', $oFollowing->errors[0]->message));
			$this->halt(sprintf('- Unable to get following users, halting. (%s)', $oFollowing->errors[0]->message));
			return FALSE;
		} else {
			printf('- %d friends<br><br>', count($oFollowing->ids));
			$this->aFollowing = $oFollowing->ids;

			//if we follow too many people, unfollow oldest until it's below limit again
			if (count($this->aFollowing) > $this->iMaxFollowing) {
				printf('Following %d people, unfollowing %d...<br>', count($this->aFollowing), count($this->aFollowing) - $this->iMaxFollowing);
				$this->unfollowOldest(count($this->aFollowing) - $this->iMaxFollowing);
			}
		}

		return TRUE;
	}

	private function unfollowOldest($iCount) {

		if ($this->aFollowing && count($this->aFollowing) > $this->iMaxFollowing && $iCount > 0) {

			for ($i = 0; $i < $iCount; $i++) {
				$iUserId = array_pop($this->aFollowing);
				$this->oTwitter->post('friendships/destroy', array('user_id' => $iUserId));
			}
		}

		return TRUE;
	}

	private function doSearch($sSearchString, $iIndex) {

		if (empty($sSearchString)) {
			$this->logger(2, 'No search string set');
			$this->halt('No search string! Set search string when calling constructor.');
			return FALSE;
		}

		printf('%d: Searching for max %d tweets with: <a href="http://twitter.com/search?f=tweets&q=%s">%s</a>..',
			$iIndex,
			$this->iSearchMax,
			urlencode($sSearchString),
			$sSearchString
		);

		//retrieve data for last search to prevent duplicates
		$aLastSearch = json_decode(@file_get_contents(MYPATH . '/' . sprintf($this->sLastSearchFile, $iIndex)), TRUE);

		$oSearch = $this->oTwitter->get('search/tweets', array(
			'q'				=> $sSearchString,
			'result_type'	=> 'mixed',
			'count'			=> $this->iSearchMax,
			'since_id'		=> ($aLastSearch && !empty($aLastSearch['max_id']) ? $aLastSearch['max_id'] : 1),
		));

		printf('%d results.<br>', count($oSearch->statuses));
		
		if (empty($oSearch->search_metadata)) {
			$this->logger(2, sprintf('Twitter API call failed: GET /search/tweets (%s)', $oSearch->errors[0]->message));
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

	private function doActions() {

		if (empty($this->aTweets)) {
			$this->logger(3, 'Nothing to retweet.');
			$this->halt('Nothing to retweet.');
			return FALSE;
		}

		foreach ($this->aTweets as $oTweet) {

			//replace shortened links
			$oTweet = $this->expandUrls($oTweet);

			//replace retweets with original tweet if possible
			if (strpos($oTweet->text, 'RT @') === 0) {

				if (!empty($oTweet->retweeted_status)) {
					printf('Converting retweet: <a href="http://twitter.com/%s/statuses/%s">@%s</a>: %s<br>',
						$oTweet->user->screen_name,
						$oTweet->id_str,
						$oTweet->user->screen_name,
						str_replace("\n", ' ', $oTweet->text)
					);

					$oTweet = $oTweet->retweeted_status;

				} else {
					printf('Skipping manual retweet: <a href="http://twitter.com/%s/statuses/%s">@%s</a>: %s<br>',
						$oTweet->user->screen_name,
						$oTweet->id_str,
						$oTweet->user->screen_name,
						str_replace("\n", ' ', $oTweet->text)
					);

					continue;
				}
			}

			//perform post-search filters
			if ($this->filterTweet($oTweet) == FALSE) {
				continue;
			}

			//if tweet contains 'fav ' or 'favo(rite)'
			if (preg_match('/fav\s|favorite/i', $oTweet->text)) {

				$this->favoriteTweet($oTweet);
			}

			//if tweet contains 'rt ' or 'retweet'
			if (preg_match('/rt\s|retweet/i', $oTweet->text)) {

				$this->retweetTweet($oTweet);
			}

			//if tweet contains 'follow'
			if (preg_match('/follow\s/i', $oTweet->text)) {

				$this->followAuthor($oTweet);
			}
		}

		$this->aTweets = FALSE;

		return TRUE;
	}

	private function favoriteTweet($oTweet) {

		if ($oTweet->favorited) {
			printf('Skipped favorite <a href="http://twitter.com/%s/statuses/%s">@%s</a> because we already favorited this.<br>',
				$oTweet->user->screen_name,
				$oTweet->id_str,
				$oTweet->user->screen_name
			);
			return TRUE;
		}

		printf('<b>Favoriting:</b> <a href="http://twitter.com/%s/statuses/%s">@%s</a>: %s<br>',
			$oTweet->user->screen_name,
			$oTweet->id_str,
			$oTweet->user->screen_name,
			str_replace("\n", ' ', $oTweet->text)
		);
		$oRet = $this->oTwitter->post('favorites/create', array('id' => $oTweet->id_str, 'include_entities' => FALSE));

		if (!empty($oRet->errors)) {
			$this->logger(2, sprintf('Twitter API call failed: POST favorites/create (%s)', $oRet->errors[0]->message));
			//$this->halt(sprintf('- Favorite failed. (%s)', $oRet->errors[0]->message));
			return FALSE;
		}

		return TRUE;
	}

	private function retweetTweet($oTweet) {

		if ($oTweet->retweeted) {
			printf('Skipped retweet <a href="http://twitter.com/%s/statuses/%s">@%s</a> because we already retweeted this.<br>',
				$oTweet->user->screen_name,
				$oTweet->id_str,
				$oTweet->user->screen_name
			);
			return TRUE;
		}

		printf('<b>Retweeting:</b> <a href="http://twitter.com/%s/statuses/%s">@%s</a>: %s<br>',
			$oTweet->user->screen_name,
			$oTweet->id_str,
			$oTweet->user->screen_name,
			str_replace("\n", ' ', $oTweet->text)
		);
		$oRet = $this->oTwitter->post('statuses/retweet/' . $oTweet->id_str, array('trim_user' => TRUE));

		if (!empty($oRet->errors)) {
			$this->logger(2, sprintf('Twitter API call failed: POST statuses/retweet (%s)', $oRet->errors[0]->message));
			//$this->halt(sprintf('- Retweet failed, halting. (%s)', $oRet->errors[0]->message));
			return FALSE;
		}

		return TRUE;
	}

	private function followAuthor($oTweet) {

		if (in_array($oTweet->user->id_str, $this->aFollowing) || $oTweet->user->following || $oTweet->user->follow_request_sent) {
			printf('Skipped following <a href="http://twitter.com/%s/statuses/%s">@%s</a> because we already follow them.<br>',
				$oTweet->user->screen_name,
				$oTweet->id_str,
				$oTweet->user->screen_name
			);
			return TRUE;
		}

		printf('<b>Following:</b> <a href="http://twitter.com/%s/statuses/%s">@%s</a>: %s<br>',
			$oTweet->user->screen_name,
			$oTweet->id_str,
			$oTweet->user->screen_name,
			str_replace("\n", ' ', $oTweet->text)
		);
		$oRet = $this->oTwitter->post('friendships/create', array('user_id' => $oTweet->user->id_str, 'follow' => FALSE));

		if (!empty($oRet->errors)) {
			$this->logger(2, sprintf('Twitter API call failed: POST friendships/create (%s)', $oRet->errors[0]->message));
			//$this->halt(sprintf('- Follow failed, halting. (%s)', $oRet->errors[0]->message));
			return FALSE;
		}

		return TRUE;
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
				printf('<b>Skipping tweet because it contains "%s"</b>: <a href="http://twitter.com/%s/statuses/%s">%s</a>: %s<br>',
					$sFilter,
					$oTweet->user->screen_name,
					$oTweet->id_str,
					$oTweet->user->screen_name,
					str_replace("\n", ' ', $oTweet->text)
				);
				return FALSE;
			}
		}

		return TRUE;
	}

	//check username
	private function applyUsernameFilters($oTweet) {

		foreach ($this->aUsernameFilters as $sUsername) {
			if (strpos(strtolower($oTweet->user->screen_name), $sUsername) !== FALSE) {
				printf('<b>Skipping tweet because username contains "%s"</b>: <a href="http://twitter.com/%s/statuses/%s">@%s</a>: %s<br>',
					$sUsername,
					$oTweet->user->screen_name,
					$oTweet->id_str,
					$oTweet->user->screen_name,
					str_replace("\n", ' ', $oTweet->text)
				);
				return FALSE;
			}
			if (preg_match('/@\S*' . $sUsername . '/', $oTweet->text)) {
				printf('<b>Skipping tweet because mentioned username contains "%s"</b>: <a href="http://twitter.com/%s/statuses/%s">@%s</a>: %s<br>',
					$sUsername,
					$oTweet->user->screen_name,
					$oTweet->id_str,
					$oTweet->user->screen_name,
					str_replace("\n", ' ', $oTweet->text)
				);
				return FALSE;
			}
		}

		return TRUE;
	}

	//check blocked
	private function isBlocked($oTweet) {

		foreach ($this->aBlockedUsers as $iBlockedId) {
			if ($oTweet->user->id == $iBlockedId) {
				printf('<b>Skipping tweet because user is blocked</b>: <a href="http://twitter.com/%s/statuses/%s">@%s</a>: %s<br>',
					$oTweet->user->screen_name,
					$oTweet->id_str,
					$oTweet->user->screen_name,
					str_replace("\n", ' ', $oTweet->text)
				);
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
			printf('<b>Skipping tweet because the dice said so</b>: <a href="http://twitter.com/%s/statuses/%s">@%s</a>: %s<br>',
				$oTweet->user->screen_name,
				$oTweet->id_str,
				$oTweet->user->screen_name,
				str_replace("\n", ' ', $oTweet->text)
			);
			return FALSE;
		}

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
