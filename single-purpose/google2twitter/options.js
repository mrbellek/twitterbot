$(function() {

	$('#save').click(function() { 

		localStorage.consumer_key = $('#consumer_key').val();
		localStorage.consumer_secret = $('#consumer_secret').val();
		localStorage.access_key = $('#access_key').val();
		localStorage.access_secret = $('#access_secret').val();
		console.log('saved');
	});

	$('#auth').click(function(e) {
		e.preventDefault();

		$('#pinfield_container').show();
		var cb = new Codebird;
		cb.setConsumerKey(localStorage.consumer_key, localStorage.consumer_secret);
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

	$('#verifypin').click(function(e) {
		e.preventDefault();

		var cb = new Codebird;
		cb.setConsumerKey(localStorage.consumer_key, localStorage.consumer_secret);
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
				localStorage.access_token = reply.oauth_token;
				localStorage.access_secret = reply.oauth_secret;
				alert(localStorage.access_token);
			}
		);
	});

	function loadSettings() {
		$('#consumer_key').val(localStorage.consumer_key);
		$('#consumer_secret').val(localStorage.consumer_secret);
		$('#access_key').val(localStorage.access_key);
		$('#access_secret').val(localStorage.access_secret);
		console.log('loaded');
	}

	document.addEventListener('DOMContentLoaded', loadSettings());
});
