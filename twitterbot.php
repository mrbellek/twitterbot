<?php
require_once('twitteroauth.php');
require_once('config.inc.php');
$twitter = new TwitterOAuth(CONSUMER_KEY, CONSUMER_SECRET, ACCESS_TOKEN, ACCESS_TOKEN_SECRET);
$twitter->host = "https://api.twitter.com/1.1/";

/*
 * TODO:
 * V extra zoektermen die bij RoundTeam in de config zaten ("@, [office quote]@, @ebay, ask.fm, people demand rubber dicks)
 * V links expanden en filteren op zoektermen
 * V username filteren op 'dildo'
 * V ratelimit ophalen? GET application/rate_limit_status
 * V in config.json opslaan vanaf welke id laatst gezocht is, zodat niet dingen 2x geretweet worden
 * V cronjob mss aanpassen zodat deze 1x of 2x per uur draait
 * - retweet aanzetten (read+write in app setting)
 */

//////////////
// SETTINGS //
//////////////
$allowedUser = 'FoundDildo';
$minimumRateLimit = 10;
$searchString = 'found dildo -RT -retweet -retweeted -"people demand rubber dicks" -"ask.fm" -tumblr -tmblr';
$searchMax = 10;
$searchFilters = array(
	'"@',				//quote (instead of retweet)
	chr(8220) . '@',	//smart quote
	'@ebay',			//don't retweet stupid ebay links
	'@founddildo',		//don't retweet mentions
);

///////////////////////////////////////
echo '<pre>Fetching identity..<br>'; //
///////////////////////////////////////
$currentUser = $twitter->get('account/verify_credentials');
if (is_object($currentUser)) {
	if ($currentUser->screen_name == $allowedUser) {
		printf('- Allowed user: @%s, continuing.<br><br>', $currentUser->screen_name);
	} else {
		printf('- Not allowed user: @%s (allowed: @%s), halting.<br><br>', $currentUser->screen_name, $allowedUser);
		die('Done!');
	}
} else {
	printf('- Return value is not object! ABANDON SHIP WOOP WOOP<br><br>%s', var_export($currentUser, TRUE));
	die();
}

///////////////////////////////////////////
echo 'Fetching rate limit status..<br>'; //
///////////////////////////////////////////
$rateLimit = $twitter->get('application/rate_limit_status');
$rateLimit = $rateLimit->resources->search->{'/search/tweets'};
if ($rateLimit->remaining < $minimumRateLimit) {
	printf('- remaining %d/%d calls! aborting search until reset at %s<br><br>', $rateLimit->remaining, $rateLimit->limit, date('Y-m-d H:i:s', $rateLimit->reset));
	die('Done!');
} else {
	printf('- remaining %d/%d calls, reset at %s<br><br>', $rateLimit->remaining, $rateLimit->limit, date('Y-m-d H:i:s', $rateLimit->reset));
}

//////////////////////////////////////////////////////////////////////////////
printf('Searching for "%s".. (%d max)<br>', $searchString, $searchMax); //
//////////////////////////////////////////////////////////////////////////////
$lastSearch = @json_decode(file_get_contents('lastsearch.json'));
$search = $twitter->get('search/tweets', array(
	'q' 			=> $searchString,
	'result_type' 	=> 'mixed',
	'count' 		=> $searchMax,
	'since_id'		=> ($lastSearch && !empty($lastSearch->max_id) ? $lastSearch->max_id : FALSE),
));
$data = array(
	'max_id' => $search->search_metadata->max_id_str,
	'timestamp' => date('Y-m-d H:i:s'),
);
file_put_contents('lastsearch.json', json_encode($data));
if (empty($search->statuses) || count($search->statuses) == 0) {
	printf('- no results since last search (done at %s).<br><br>', $lastSearch->timestamp);
	die('Done!');
}

//////////////////////////////////////////////////////////////////////////////
echo 'Filtering through tweets and retweeting where appropriate..<br><br>'; //
//////////////////////////////////////////////////////////////////////////////
foreach($search->statuses as $status) {
	//replace shortened links
	$tweet = expandUrls($status);

	//perform post-search filters
	$skipTweet = FALSE;
	foreach ($searchFilters as $filter) {
		if (strpos(strtolower($tweet->text), $filter) !== FALSE || strpos($tweet->user->screen_name, $filter) !== FALSE) {
			printf('<b>Skipping tweet because it contains "%s"</b>: %s<br>', $filter, $tweet->text);
			$skipTweet = TRUE;
			break;
		}
		if(strpos(strtolower($tweet->user->screen_name), 'dildo') !== FALSE) {
			printf('<b>Skipping tweet because username contains "dildo"</b> (%s)<br>', $tweet->user->screen_name);
			$skipTweet = TRUE;
			break;
		}
	}

	if (!$skipTweet) {
		printf('Retweeting: <a href="http://twitter.com/%s/statuses/%s">@%s</a>: %s<br>',
			$tweet->user->screen_name,
			$tweet->id_str,
			$tweet->user->screen_name,
			str_replace("\n", ' ', $tweet->text)
		);
		//$twitter->post('statuses/retweet/' . $tweet->id_str);
	}
}

echo '<br>Done!';

/*
 * shortened urls are listed in their expanded form in 'entities' node, under entities/url/ur
 * - urls - expanded_url
 * - media (embedded photos) - display_url
 */
function expandUrls($tweet, $urls = TRUE, $photos = FALSE) {
	//check for links/photos
	if (strpos($tweet->text, 'http://t.co') !== FALSE) {
		if ($urls) {
			foreach($tweet->entities->urls as $url) {
				$tweet->text = str_replace($url->url, $url->expanded_url, $tweet->text);
			}
		}
		if ($photos) {
			foreach($tweet->entities->media as $photo) {
				$tweet->text = str_replace($photo->url, $photo->display_url, $tweet->text);
			}
		}
	}
	return $tweet;
}
