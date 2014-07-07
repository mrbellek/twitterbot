<?php
require_once('twitteroauth.php');
require_once('config.inc.php');
$twitter = new TwitterOAuth(CONSUMER_KEY, CONSUMER_SECRET, ACCESS_TOKEN, ACCESS_TOKEN_SECRET);
$twitter->host = "https://api.twitter.com/1.1/";

//////////////
// SETTINGS //
//////////////
$allowedUser = 'FoundDildo';
$minimumRateLimit = 10;
$searchString = 'found vibrator -RT -retweet -retweeted -"ask.fm" -tumblr -tmblr -"cat hissing"';
$searchMax = 5;

//hardcoded filters
$searchFilters = array(
	'"@',				//quote (instead of retweet)
	chr(147) . '@',		//smart quote “
	'â€œ@',				//mangled smart quote
);
if ($extraFilters = @json_decode(file_get_contents(MYPATH . '/filters2.json'))) {
	$searchFilters = array_merge($searchFilters, $extraFilters);
}
die(var_dump($searchFilters));

$userFilters = array(
	'dildo',			//don't retweet anyone with dildo in their handle
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

//////////////////////////////////////
echo 'Getting blocked users..<br>'; //
//////////////////////////////////////
$blockedUsers = $twitter->get('blocks/ids');
if (!isset($blockedUsers->ids)) {
	die('- Unable to get blocked users, stopping<br><br>');
} else {
	echo '<br>';
}

//////////////////////////////////////////////////////////////////////////
printf('Searching for "%s".. (%d max)<br>', $searchString, $searchMax); //
//////////////////////////////////////////////////////////////////////////
$lastSearch = @json_decode(file_get_contents(MYPATH . '/lastsearch2.json'));
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
file_put_contents(MYPATH . '/lastsearch2.json', json_encode($data));
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
		if (strpos(strtolower($tweet->text), $filter) !== FALSE) {
			printf('<b>Skipping tweet because it contains "%s"</b>: %s<br>', $filter, str_replace("\n", ' ', $tweet->text));
			$skipTweet = TRUE;
			break;
		}
	}

	//check username
	foreach ($userFilters as $filter) {
		if(strpos(strtolower($tweet->user->screen_name), $filter) !== FALSE) {
			printf('<b>Skipping tweet because username contains "%s"</b> (%s)<br>', $filter, $tweet->user->screen_name);
			$skipTweet = TRUE;
			break;
		}
	}

	//check blocked
	foreach ($blockedUsers->ids as $blockedId) {
		if ($tweet->user->id == $blockedId) {
			printf('<b>Skipping tweet because user "%s" is blocked</b><br>', $tweet->user->screen_name);
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
		$twitter->post('statuses/retweet/' . $tweet->id_str);
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
