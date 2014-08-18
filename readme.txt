This is a small php script which can be used to create twitter bots. I’ve used two files (twitteroauth.php and OAuth.php) of Abraham’s twitteroauth project to create this app. It works on OAuth, so no need to enter your twitter password.

Instruction to use this script can be found here - http://www.prasannasp.net/how-to-create-a-twitter-bot/
---

Above is the original readme.

I forked this script because I wanted to use it as a base for a Twitter bot that retweets certain terms. It has been *heavily*  modified to include the following features:
- use native retweets instead of 'RT @username ...' posts
- modular design, allowing for simple setup while leaving the base bot.php script untouched
- will not retweet blocked users
- will not retweet messages matching keywords
- will not retweet users or mentions matching keywords
- keeps track of API rate limit status and will not exceed set threshold
- keeps track of last message retweeted and will not retweet messages older than that
- some randomization to tweak probability of retweeting tweets with photos/links/mentions
- allow for multiple search queries per bot

Creating a new *retweet* bot:

Set up a Twitter account to retweet from, then go to dev.twitter.com to setup your app and get the API and consumer keys+secrets. The link in the original readme is great for help with this. Don't forget to change access permissions for the account to read/write (modifying the permissions will generate new access token+secret).

Now create a new PHP file and define CONSUMER_KEY, CONSUMER_SECRET, ACCESS_TOKEN and ACCESS_TOKEN_SECRET with your values. Define MYPATH as the path the bot will run from (I created this constant because getting DOCUMENT_ROOT on shared hosting was broken on my provider).

Finally, include/require the bot.php file and create a new TwitterBot object, passing values in the constructor that set the username, settings filename, last search filename and search string(s). Use the json file for quickly changing keyword/username filters and die values. See the files for my bot for examples. 
End the file with calling run() on the TwitterBot object. Call the resulting PHP file every half hour (not too often, 15min is pushing it) or less to run your bot.

Creating a new *tweeting* bot:

The bot2.php file is a bot that posts tweets from a database with messages. It's far less complicated than the retweet bot above, since it doesn't need to filter messages before posting them.
Same steps to setup an account and an app as above.

Setup MySQL database with a table to hold the tweets. Columns are needed to hold unique id, the message, a counter, a timestamp.
The counter keeps track of how many times that message has been posted (tweeted), the timestamp column keeps track when
it was last posted. 

Finally, include/require the bot2.php file and create a TweetBot object, passing values in the constructor that set the username, database table settings, and tweet format/settings. See the files for the bots for examples.
End the file with calling run() on the TweetBot object. Call the resulting PHP file to run your bot. Note that bots that only use POST API calls aren't rate limited.
