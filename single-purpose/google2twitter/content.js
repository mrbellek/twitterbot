/*
 * TODO:
 * . fix so that script doesn't trigger again when going back from link to search results
 * - use google analytics with chrome.runtime.onInstalled event
 */

$(function() {

	var iPopupTimer;

	//trigger on page load (opera search keywords)
	if ($('#lst-ib').length > 0) {
		onBeforeSearch();
	}

	//catch when someone hits ENTER in search box
	$('#lst-ib').keydown(function(e) {
		if (e.which == 13) {
			onBeforeSearch();
		}
	});

	//catch when someone clicks the search button
	$('button[name="btnG"]').click(function() {
		onBeforeSearch();
	});

	function onBeforeSearch() {

		//get search query
		var tweet = $.trim($('#lst-ib').val());
		if (tweet.length == 0) {
			return;
		}

		//we're prefixing the search type if it's not a regular web search
		type = getSearchType();
		if (type != 'web') {
			tweet = type + ' search: ' + tweet;
		}

		//fetch previous searches
		chrome.storage.local.get({'savedSearches': []}, function(result) {

			//check if this search hasn't been tweeted before
			if ($.inArray(tweet, result.savedSearches) == -1) {

				console.log('showing twitter popup');
				$('body').prepend('<link rel="stylesheet" href="' + chrome.extension.getURL('css/popup.css') + '" />');
				$('body').append('<div id="popupDelay"><img src="' + chrome.extension.getURL('img/twitter.png') + '" width="48" height="48" alt="Click to cancel tweet" title="Click to cancel tweet" /></div>');
				$('#popupDelay').animate({ bottom: 20 }, 'slow');
				iPopupTimer = window.setTimeout(onSearch, 3500);

			} else {
				console.log('skipping duplicate search');
			}
		});
	}

	//handle clicking on twitter icon to cancel tweet
	$('body').on('click', '#popupDelay', function() {
		console.log('tweet canceled.');
		window.clearTimeout(iPopupTimer);
		$('#popupDelay').animate({ bottom: -50 }, 'fast');
	});

	//main function for when a search is done
	function onSearch() {
		var tweet = $.trim($('#lst-ib').val());
		if (tweet.length == 0) {
			return;
		}
		$('#popupDelay').animate({ bottom: -50 }, 'fast');

		//we're prefixing the search type if it's not a regular web search
		type = getSearchType();
		if (type != 'web') {
			tweet = type + ' search: ' + tweet;
		}

		//fetch tokens from storage
		chrome.storage.local.get([ 'consumer_key', 'consumer_secret', 'access_key', 'access_secret' ], function(storage) {

			var consumer_key = storage.consumer_key;
			var consumer_secret = storage.consumer_secret;
			var access_key = storage.access_key;
			var access_secret = storage.access_secret;

			//basic check on tokens
			if (validateTokens(consumer_key, consumer_secret, access_key, access_secret) == false) {
				alert('Tokens not setup! Check options page for instructions.');
				return;
			}

			console.log('tweetin: ' + tweet);
			//return;

			//tweet using CodeBird js library
			cb = new Codebird;
			cb.setConsumerKey(consumer_key, consumer_secret);
			cb.setToken(access_key, access_secret);
			cb.__call(
				"statuses_update", { "status": tweet },
				function(reply) {
					if (typeof reply.errors != "undefined") {
						console.log('twitter error: ' + reply.errors[0].message);
					} else {
						console.log('tweet posted.');

						//if successful, save search to prevent duplicates
						chrome.storage.local.get({'savedSearches': []}, function(result) {
							result.savedSearches.push(tweet);
							chrome.storage.local.set({'savedSearches': result.savedSearches });
						});
					}
				}
			);
		});
	}

	//fetch search type from 'tbm' query param
	function getSearchType() {

		//check hash first
		var hashes = $(location).attr('hash').slice($(location).attr('hash').indexOf('?') + 1).split('&');
		for (var i = 0; i < hashes.length; i++) {
			hash = hashes[i].split('=');
			if (hash[0] == 'tbm') {
				switch(hash[1]) {
					case 'isch':	return 'image';
					case 'nws':		return 'news';
					case 'app':		return 'app';
					case 'vid':		return 'video';
					case 'shop':	return 'shopping';
					case 'bks':		return 'book';
				}
			}
		}

		//after that, check location
		var hashes = $(location).attr('href').slice($(location).attr('href').indexOf('?') + 1).split('&');
		for (var i = 0; i < hashes.length; i++) {
			hash = hashes[i].split('=');
			if (hash[0] == 'tbm') {
				switch(hash[1]) {
					case 'isch':	return 'image';
					case 'nws':		return 'news';
					case 'app':		return 'app';
					case 'vid':		return 'video';
					case 'shop':	return 'shopping';
					case 'bks':		return 'book';
				}
			}
		}

		//if nothing, it's regular web search
		return 'web';
	}

	//basic token validation
	function validateTokens(consumer_key, consumer_secret, access_key, access_secret) {

		var valid = true;
		if (typeof consumer_key == 'undefined' || consumer_key.length == 0) {
			console.log('consumer_key is blank.');
			valid = false;
		}
		if (typeof consumer_secret == 'undefined' || consumer_secret.length == 0) {
			console.log('consumer_secret is blank.');
			valid = false;
		}
		if (typeof access_key == 'undefined' || access_key.length == 0) {
			console.log('access_key is blank.');
			valid = false;
		}
		if (typeof access_secret == 'undefined' || access_secret.length == 0) {
			console.log('access_secret is blank.');
			valid = false;
		}

		return valid;
	}
});
