chrome.runtime.onMessage.addListener(

	function (request, sender) {
		if (request == 'getTokens') {

			var params = {
				consumer_key: localStorage.consumer_key,
				consumer_secret: localStorage.consumer_secret,
				access_key: localStorage.access_key,
				access_secret: localStorage.access_secret
			};

			chrome.tabs.query({ active: true, currentWindow: true }, function(tabs) {
				chrome.tabs.sendMessage(tabs[0].id, params);
			});
		}
	}
);
