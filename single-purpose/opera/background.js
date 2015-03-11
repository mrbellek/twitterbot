console.log('test');
chrome.runtime.onMessage.addListener(

	function (request, sender) {
		console.log(request);
		if (request.line == 'getTokens') {
			var params = [];
			params.consumer_key = localStorage.consumer_key;
			params.consumer_secret = localStorage.consumer_secret;
			params.access_key = localStorage.access_key;
			params.access_secret = localStorage.access_secret;

			chrome.runtime.sendMessage(params);
		}
	}
);
