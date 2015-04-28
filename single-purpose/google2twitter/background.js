chrome.runtime.onInstalled.addListener(function(details) {

	//track installs/updates via google analytics
	ga('create', 'UA-17061491-2', 'auto');
	ga('set', 'checkProtocolTask', function() {});
	ga('require', 'displayfeatures');
	if (details.reason == 'update') {
		ga('send', 'pageview', '/' + details.reason + '/' + details.previousVersion + '/' + chrome.runtime.getManifest().version);
	} else {
		ga('send', 'pageview', '/' + details.reason);
	}
});
