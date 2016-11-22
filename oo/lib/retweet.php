<?php
namespace Twitterbot\Lib;

/**
 * Retweet tweets
 */
class Retweet extends Base
{
    /**
     * Retweet given tweets
     *
     * @param array $aTweets
     *
     * @return bool
     */
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

    /**
     * Quote given tweets with comment
     *
     * @param object $oTweet
     * @param string $sComment
     *
     * @return bool
     */
    public function quote($oTweet, $sComment)
    {
        if (!$oTweet || !$sComment) {
			$this->logger->write(3, 'Nothing to quote.');
			$this->logger->output('Nothing to quote.');

			return false;
		}

        //don't parse stuff we already retweeted
        if ($oTweet->retweeted) {
            //NOTE: this currently isn't supported in 1.1 API lol
            $this->logger->output('Skipping because already quoted: %s', $oTweet->text);
            return true;
        }

        $sTweetUrl = sprintf('https://twitter.com/%s/statuses/%s',
            $oTweet->user->screen_name,
            $oTweet->id_str
        );
        $this->logger->output('Quoting: @%s: %s with comment "%s"',
            $oTweet->user->screen_name,
            str_replace("\n", ' ', $oTweet->text),
            $sComment
        );
        $oRet = $this->oTwitter->post('statuses/update', array('status' => $sComment . ' ' . $sTweetUrl, 'trim_user' => true));

        if (!empty($oRet->errors)) {
            $this->logger->write(2, sprintf('Twitter API call failed: POST statuses/update(%s)', $oRet->errors[0]->message), array('tweet' => $oTweet));
            $this->logger->output(sprintf('- Quote failed, halting. (%s)', $oRet->errors[0]->message));

            return false;
        }

        return true;
    }
}
