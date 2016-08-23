<?php
namespace Twitterbot\Lib;

class Retweet extends Base
{
    public function post($aTweets = array())
    {
        if (!$aTweets) {
			$this->logger->write(3, 'Nothing to retweet.');
			$this->logger->output('Nothing to retweet.');

			return false;
		}

        $aTweets = (is_array($aTweets) ? $aTweets : array($aTweets));

		foreach ($aTweets as $oTweet) {

			//don't parse stuff we already retweeted
			if ($oTweet->retweeted) {
				//NOTE: this currently isn't supported in 1.1 API lol
				$this->logger->output('Skipping because already retweeted: %s', $oTweet->text);
				continue;
			}

			$this->logger->output('Retweeting: <a href="http://twitter.com/%s/statuses/%s">@%s</a>: %s',
				$oTweet->user->screen_name,
				$oTweet->id_str,
				$oTweet->user->screen_name,
				str_replace("\n", ' ', $oTweet->text)
            );
			$oRet = $this->oTwitter->post('statuses/retweet/' . $oTweet->id_str, array('trim_user' => true));

			if (!empty($oRet->errors)) {
				$this->logger->write(2, sprintf('Twitter API call failed: POST statuses/retweet (%s)', $oRet->errors[0]->message), array('tweet' => $oTweet));
				$this->logger->output(sprintf('- Retweet failed, halting. (%s)', $oRet->errors[0]->message));

				return false;
			}
		}

        return true;
    }
}
