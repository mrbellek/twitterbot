$(function() {

	$('#save').click(function() { 

		localStorage.consumer_key = $('#consumer_key').val();
		localStorage.consumer_secret = $('#consumer_secret').val();
		localStorage.access_key = $('#access_key').val();
		localStorage.access_secret = $('#access_secret').val();
		console.log('saved');
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
