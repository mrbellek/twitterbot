//listen for message from extension in tabs
chrome.runtime.onMessage.addListener(

	function (request, sender) {
		//retrieve settings
		if (request.line == 'getOptions') {

			var params = {
				consumer_key: localStorage.consumer_key,
				consumer_secret: localStorage.consumer_secret,
				access_key: localStorage.access_key,
				access_secret: localStorage.access_secret
			};

			//reply
			chrome.tabs.query({ active: true, currentWindow: true }, function(tabs) {
				chrome.tabs.sendMessage(tabs[0].id, params);
			});

		//save last few searches to prevent duplicate tweets
		} else if (request.line == 'saveSearch') {

			//TODO: trim search history
			if (!localStorage.savedSearches || localStorage.savedSearches.constructor != Array) {
				localStorage.savedSearches = new Array();
			}
			localStorage.savedSearches.push(request.search);

		//retrieve last searches
		} else if (request.line == 'getSearches') {

			var searches = localStorage.savedSearches || [];

			chrome.tabs.query({ active: true, currentWindow: true }, function(tabs) {
				chrome.tabs.sendMessage(tabs[0].id, { 'line': 'sendSearches', 'history': searches });
			});
		}
	}
);
