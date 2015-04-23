$(function() {

	$('#save').click(function() { 

		chrome.storage.local.set({
			'consumer_key': $('#consumer_key').val(),
			'consumer_secret': $('#consumer_secret').val(),
			'access_key': $('#access_key').val(),
			'access_secret': $('#access_secret').val(),
		});
		console.log('saved');
	});

	$('#auth').click(function(e) {
		e.preventDefault();

		chrome.storage.local.get([ 'consumer_key', 'consumer_secret' ], function(storage) {

			var cb = new Codebird;
			cb.setConsumerKey(storage.consumer_key, storage.consumer_secret);
			cb.__call(
				"oauth_requestToken",
				{ oauth_callback: "oob" },
				function (reply) {
					// store it
					cb.setToken(reply.oauth_token, reply.oauth_token_secret);

					// gets the authorize screen URL
					cb.__call(
						"oauth_authorize",
						{},
						function (auth_url) {
							window.codebird_auth = window.open(auth_url);
						}
					);
				}
			);
		});
	});

	$('#verifypin').click(function(e) {
		e.preventDefault();

		chrome.storage.local.get([ 'consumer_key', 'consumer_secret' ], function(storage) {

			var cb = new Codebird;
			cb.setConsumerKey(storage.consumer_key, storage.consumer_secret);
			alert($('#pinfield').val());
			cb.__call(
				"oauth_accessToken",
				{ oauth_verifier: $('#pinfield').val() },
				function(reply) {
					alert('test');
					//store the authenticated token, which may be different from the request token (!)
					alert(reply.oauth_token, reply.oauth_token_secret);
					cb.setToken(reply.oauth_token, reply.oauth_token_secret);

					//persist access tokens
					chrome.storage.local.set({
						'access_token': reply.oauth_token,
						'access_secret': reply.oauth_secret
					});
				}
			);
		});
	});

	function loadSettings() {

		chrome.storage.local.get([ 'consumer_key', 'consumer_secret', 'access_key', 'access_secret' ], function(storage) {

			$('#consumer_key').val(storage.consumer_key);
			$('#consumer_secret').val(storage.consumer_secret);
			$('#access_key').val(storage.access_key);
			$('#access_secret').val(storage.access_secret);

			console.log('loaded');
		});
	}

	document.addEventListener('DOMContentLoaded', loadSettings());
});
