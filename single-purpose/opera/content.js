/*
 * TODO:
 * v abstract messaging to getOptions for clarity
 * v also hook image search etc, prefix searches with 'image search:' or something
 * v pretty up the options dialog (bootstrap.css?)
 * v popup with twitter logo that stays for 2 seconds, then tweets - and cancels tweet when clicked
 *   - above delay configurable?
 * . figure out how to allow user to auth the app/extension instead of manually entering tokens?
 *   - broken in codebird.js :(
 * . google analytics
 * - package the whole thing, submit to Chrome Web Store as well as Opera Store
 *   v icon
 * - fix so that script doesn't trigger again when going back from link to search results
 */

$(function() {

	//search box is input[type="text"] with id 'lst-ib' for any search type

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

		console.log('showing twitter popup');
		$('body').prepend('<link rel="stylesheet" href="' + chrome.extension.getURL('css/popup.css') + '" />');
		$('body').append('<div id="popupDelay"><img src="' + chrome.extension.getURL('img/twitter.png') + '" width="48" height="48" alt="Click to cancel tweet" title="Click to cancel tweet" /></div>');
		$('#popupDelay').animate({ bottom: 20 }, 'slow');
		iPopupTimer = window.setTimeout(onSearch, 3500);
	}

	$('#popupDelay').click(function() {
		console.log('tweet canceled.');
		window.clearTimeout(iPopupTimer);
		$('#popupDelay').animate({ bottom: -50 }, 'fast');

		//track canceling of tweet
		ga('set', 'checkProtocolTask', function() {});
		ga('require', 'displayfeatures');
		ga('send', 'pageview', '/cancel-tweet.html');
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

		//set handler to catch message reply
		chrome.runtime.onMessage.addListener(function(message) {

			var consumer_key = message.consumer_key;
			var consumer_secret = message.consumer_secret;
			var access_key = message.access_key;
			var access_secret = message.access_secret;

			//basic check on tokens
			if (validateTokens(consumer_key, consumer_secret, access_key, access_secret) == false) {
				alert('Tokens not setup! Check console for errors.');
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

						//track tweet and search type
						ga('set', 'checkProtocolTask', function() {});
						ga('require', 'displayfeatures');
						ga('send', 'pageview', '/tweet.html');
						ga('send', 'pageview', '/search-' + type + '.html');
					}
				}
			);
		});

		//send message to background script to fetch keys + secrets from settings
		chrome.runtime.sendMessage('getOptions');
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
