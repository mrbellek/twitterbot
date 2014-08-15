<?php
/*
 * TODO:
 * - display number of blocked users when getting list
 * - only get rate limit status of relevant calls
 * - rename object to RetweetBot or something
 */
require_once('twitteroauth.php');

class TwitterBot
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

		$this->sSettingsFile 	= (!empty($aArgs['sSettingsFile'])	? $aArgs['sSettingsFile']		: strtolower($this->sUsername) . '.json');
		$this->sLastSearchFile 	= (!empty($aArgs['sLastSearchFile']) ? $aArgs['sLastSearchFile']	: strtolower($this->sUsername) . '-last%d.json');
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
			$this->halt('- No username! Set username when calling constructor.');
			return FALSE;
		}

		$oCurrentUser = $this->oTwitter->get('account/verify_credentials');

		if (is_object($oCurrentUser)) {
			if ($oCurrentUser->screen_name = $this->sUsername) {
				printf('- Allowed: @%s, continuing.<br><br>', $oCurrentUser->screen_name);
			} else {
				$this->halt(sprintf('- Not allowed: @%s (expected: %s), halting.', $oCurrentUser->screen_name, $this->sUsername));
				return FALSE;
			}
		} else {
			$this->halt(sprintf('- Call failed, halting. (%s)', $oCurrentUser->errors[0]->message));
			return FALSE;
		}

		return TRUE;
	}

	private function getRateLimitStatus() {

		echo 'Fetching rate limit status..<br>';
		$oStatus = $this->oTwitter->get('application/rate_limit_status');
		$oRateLimit = $oStatus->resources->search->{'/search/tweets'};
		$oBlockedLimit = $oStatus->resources->blocks->{'/blocks/ids'};

		//check if remaining calls for search is lower than threshold (after reset: 180)
		if ($oRateLimit->remaining < $this->iMinRateLimit) {
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
		$oBlockedUsers = $this->oTwitter->get('blocks/ids');

		if (empty($oBlockedUsers->ids)) {
			$this->halt(sprintf('- Unable to get blocked users, halting. (%s)', $oBlockedUsers->errors[0]->message));
			return FALSE;
		} else {
			$this->aBlockedUsers = $oBlockedUsers->ids;
		}

		return TRUE;
	}

	private function doSearch($sSearchString, $iIndex) {

		if (empty($sSearchString)) {
			$this->halt('No search string! Set search string when calling constructor.');
			return FALSE;
		}

		printf('Searching for max %d tweets with: %s..<br>', $this->iSearchMax, $sSearchString);

		//retrieve data for last search to prevent duplicates
		$aLastSearch = json_decode(@file_get_contents(MYPATH . '/' . sprintf($this->sLastSearchFile, $iIndex)), TRUE);

		$oSearch = $this->oTwitter->get('search/tweets', array(
			'q'				=> $sSearchString,
			'result_type'	=> 'mixed',
			'count'			=> $this->iSearchMax,
			'since_id'		=> ($aLastSearch && !empty($aLastSearch['max_id']) ? $aLastSearch['max_id'] : FALSE),
		));

		if (empty($oSearch->search_metadata)) {
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
			$this->aTweets = $oSearch->statuses;
			return TRUE;
		}
	}

	private function doRetweets() {

		if (empty($this->aTweets)) {
			$this->halt('Nothing to retweet.');
			return FALSE;
		}

		foreach ($this->aTweets as $oTweet) {

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
			$oRet = $this->oTwitter->post('statuses/retweet/' . $oTweet->id_str);

			if (!empty($oRet->error)) {
				$this->halt(sprintf('- Retweet failed, halting. %s', $oRet->error));
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
			|| strpos('instagram.com/p/', $oTweet->text) !== FALSE) {

			//photos are usually funny - certain
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
}
