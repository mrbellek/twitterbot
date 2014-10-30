# Readme #

Original readme:
```
This is a small php script which can be used to create twitter bots.
I've used two files (twitteroauth.php and OAuth.php) of Abraham's
twitteroauth project to create this app. It works on OAuth, so
no need to enter your twitter password.

Instruction to use this script can be found here:
http://www.prasannasp.net/how-to-create-a-twitter-bot/
```

This repository has several different types of Twitter bots with live examples:
* **retweetbot.php** - a script that searches Twitter for certain terms and retweets them if they meet certain conditions
* **tweetbot.php** - a script that fetches a single record from a database and tweets it, after formatting
* **rssbot.php** - a script that fetches a RSS feed and tweets items from it, after formatting
* **markovbot.php** - a script that parses a body of text and generates tweets from it based on Markov Chains

All scripts assume a basic knowledge of PHP. Every of these bots is used by creating its object and passing arguments in the constructor. None of the scripts should need to be altered to get your bot working.

## Setting up a bot ##

Each of these bots is constructed differently, but there are common steps. They are:

#### Create a Twitter account and app for the bot ####

[Sign up for Twitter](https://twitter.com/signup) with a new account that will be used to post tweets from. Then, go to [apps.twitter.com](https://apps.twitter.com) and **create a new app**. Enter whatever you want for Name/Description/Website and create the app. Now go to **Permissions** and change it to Read and Write. Finally, go to the **Keys and Access Tokens** tab and copy your Consumer Key and Secret. Click the button to generate Access Tokens and copy those too.

#### Create a PHP script ####

Next, start a blank PHP file and define constants for **CONSUMER_KEY, CONSUMER_SECRET, ACCESS_TOKEN and ACCESS_TOKEN_SECRET**. Also, create a **MYPATH** constant with the path the script will run from (especially important on shared hosting).

The final step differs for each type of bot, but the basic idea is to *require()* the bot script of your choice, and create a new object (TweetBot, RetweetBot, RssBot, MarkovBot), passing it any arguments it needs. Call *run()* on the object to make it do its thing. Call your script periodically from a cronjob, between 1 and 4 times per hour.

See any of the scripts in the examples/ folder for details on the bot parameters. (Obviously, the file with the secrets and tokens is not included here.)

## Retweet bot ##

The retweet bot will search Twitter for given terms, and retweet anything that meets the set conditions.

Features:
* native retweets instead of 'RT @username ...' posts
* will not retweet users on 'blocked users' list
* will not retweet tweets matching given keywords
* will not retweet usernames matching given keywords
* keep track of API rate limit status and not exceed it
* multiple possible search queries per bot

Required arguments:
* **sUsername** - the username of the Twitter account the bot will be posting from
* **aSearchStrings** - an array of strings, each is a single search query. You can use boolean logic here to exclude unwanted things early

Optional arguments:
* **iSearchMax** - maximum number of tweets to fetch during each search query (default 5)
* **iMinRateLimit** - the threshold that will stop the bot from tweeting if the rate limit falls below this, and wait until the rate limit reset (default 5)
* **sSettingsFile** - filename of the .json file with settings: keyword filters, username filters, probability values (default blank)
* **sLastSearchFile** - filename of the .json file that stores what tweet was last parsed, to continue from in the next run (default `<username>-last<num>.json`)
* **sLogFile** - filename of the .log file holding and logger messages that are generated (default `<username>.log`)

The search strings array allows for multiple search queries for a single bot, all of which will be executed during a run. For instance, if you want your bot to retweet anything mentioning *apples* OR *oranges*, you could pass it `array(1 => 'apples', 2 => 'oranges')`.

The settings .json file holds several filters:
* Search term filters, listing everything you don't want retweeted even if it satisfies the search query. For instance, if you want your bot to retweet any mention of apples, but not Granny Smith apples, your settings file would contain `{ "filters": [ "granny smith" ] }`.
* Username filters, listing whole or parts of usernames you never want to retweet. For instance, to never retweet *Applebees*, your settings file would contain `{ "userfilters": [ "applebees" ] }`.
* Dice values, listing the probability (0-1) of retweeting tweets with images, urls, mentions or none of these. For instance, if you want to always retweet tweets with pictures but never tweets that are mentions, your settings file would contain `{ "dice": { "media": 1.0, "mentions": 0.0 }}`. The default values for the dice are 1/0.8/0.5/0.8 for tweets with pictures/urls/mentions/none, respectively.

## Tweet bot ##

The tweet bot will fetch a semi-random record from a database, format it, and tweet it.

Features:
* will keep track of how often a database record has been posted, and always pick the record that was posted least often. If multiple records are found, a random one is picked.
* formatting the database record is quite powerful and flexible

Required arguments:
* **sUsername** - the username of the Twitter account the bot will be posting from
* **aDbVars** - an array specifying the table name and column names in the database
* **sTweetFormat** - the format of the tweet to be posted, with placeholders for the data fetched from the database
* **aTweetVars** - an array specifying which placeholder in the tweet format will be filled by which database variable

The *aDbVars* array consists of the keys *sTable, sIdCol, sCounterCol* and *sTimestampCol* indicating what name the database table and database columns have for record id, post count for that record, and timestamp of when that record was last posted. For instance, if your table is named `APPLETYPES` with columns `ID`, `NAME`, `GROWN`, `HITS` and `WHEN`, your array would be `array('sTable' => 'APPLETYPES', 'sIdCol' => 'ID', 'sCounterCol' => 'HITS', 'sTimestampCol' => 'WHEN')`. The column with the actual content that will be tweeted is not set here.

The *sTweetFormat* string contains the generic tweet format with colon placeholders, e.g. if your bot tweets the different types of apples that exist, the tweet format could look like `The best type of apple is <a href="http://en.wikipedia.org/wiki/:apple">:apple</a>, first grown in :grown!`.

Finally, the *aTweetVars* array ties the placeholders in the tweet format to the colum names from your database. In the above examples we've established, you'd want the value of the `NAME` column to fill the `:apple` placeholder, and the `GROWN` column value in the `:grown` placeholder. In this case, the array would look like `array( array('sVar' => ':apple', 'sRecordField' => 'NAME'), array('sVar' => ':grown', 'sRecordField' => 'GROWN'))`.

In the case that the tweet would exceed the 140 character limit, you can also specify which placeholder should be truncated to make it fit with the `bTruncate` setting in `aTweetVars` (only 1 field can be truncated). For instance, if the first grown date of the apple in our example bot would be too long, you could change its variable to `array('sVar' => ':grown', 'sRecordField' => 'GROWN', 'bTruncate' => TRUE)`. If no field is specified to be truncated and the tweet is too long, or the tweet is still too long after truncating, the tweet is not posted. Note that URLs of any length take only 22 (or 23 for https URLs) characters in a tweet.

## RSS bot ##

The RSS bot will grab an RSS feed, and post any new items in it according to a preset format.

Features:
* will keep track of newest item posted, to avoid posting items twice
* powerful and flexible formatting of RSS item into tweet by way of regular expressions
* optionally attach image links to tweet as embedded media

Required arguments:
* **sUsername** - the username of the Twitter account the bot will be posting from
* **sUrl** - the URL of the RSS feed
* **sTweetFormat** - the format of the tweet to be posted, with placeholders for the data fetched from the RSS feed
* **aTweetVars** - an array specifying which placeholder in the tweet format will be filled by which RSS variable

Optional arguments:
* **sLastRunFile** - filename of the .json file that stores what item was last parsed, to continue from in the next run (default `<username>-last.json`)
* **sTimestampXml** - name of the XML field in the RSS feed that specifying when the item was published (default *pubDate*)

The *sTweetFormat* string contains the generic tweet format with colon placeholders, e.g. if your bot tweets the title, link and timestamp from every item in an RSS feed, the format could look like `:title :link (published on :date)`. These names do not have to be the same as the XML fields in the RSS feed.

The *aTweetVars* array ties the placeholders in the tweet format to the XML fields in the RSS feed. Regular expressions can be used to grab specific parts of XML fields and tie them to placeholders, or entire contents of an XML field can be used. If a tweet turns out too long, a single variable can be marked for truncating if need be. For instance, if the RSS feed lists `<item>` nodes that contain `<title>`, `<pubDate>` and `<source>` nodes, your array could look like `array( array( 'sVar' => ':title', 'sValue' => 'title'), array('sVar' => ':link', 'sValue' => 'source'), array('sVar' => ':date', 'sValue' => 'pubDate'))`. (Nested nodes are not supported yet.)

However, if the `<source>` node, for whatever reason, instead of just the link has the text `<a href="http://....'>click here!</a>`, you would want just the link and not the whole node text. To accomplish this, the variable would change to e.g. `array('sVar' => ':link', 'sValue' => 'source', **'sRegex' => '/href="(.+?)">/'**)`.

A special value can be passed to the `sValue` key, to further process another variable into a result that fills the placeholder. Right now, the only value is *special:redditmediatype*, which determines if the value of a variable with a link is an image, a video, a self post or a cross-link. This only applies to Reddit RSS feeds. For example, to add another variable in our example that would fill a placeholder with the link type: `array('sVar' => ':type', 'sValue' => 'special:redditmediatype', 'sSubject' => ':link')`. After this, adding `:type` in the tweet format would fill it with the type of link in the `:link` placeholder.

Finally, the option is available to attach image links in variables directly to tweets as media. This means the picture shows up inline to the tweet on the Twitter website, as a pic.twitter.com link. The option is `bAttachImage`, so the link from our example would be `array('sVar' => ':link', 'sValue' => 'source', 'bAttachImage' => TRUE)`. If the variable does not contain a valid image URL, the option is ignored. Only one image per tweet is allowed.

## Markov bot ##

The Markov bot will grab a body of text, generate Markov Chains from it, and then generate a new sentence from those that fills a tweet, and post it.

[Markov Chain analysis](http://en.wikipedia.org/wiki/Markov_chain) of text creates an array of which word is likely to follow two or more other words. This way, new sentences can be generated that contain snippets from the original body of text that sorta look like it came from the original text. It works best if the source body of text is (very) large and has long sentences (i.e. tweets don't work very well).

Required arguments:
* **sUsername** - the username of the Twitter account the bot will be posting from
* **sInputFile** - the filename of the source body of text
