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
