<?php
namespace Twitterbot\Lib;

/**
 * Favorite class, mark tweets as favorite
 */
class Favorite extends Base
{
    /**
     * Add tweets to favorites
     *
     * @return bool
     */
    public function add($aTweets = array())
    {
        if (!$aTweets) {
            $this->logger->write(3, 'Nothing to favorite.');
            $this->logger->output('Nothing to favorite.');

            return false;
        }

        $aTweets = (is_array($aTweets) ? $aTweets : array($aTweets));

        foreach ($aTweets as $oTweet) {

            //don't parse stuff we already favorited
			if ($oTweet->favorited) {
				//NOTE: this currently isn't supported in 1.1 API lol
				$this->logger->output('Skipping because already favorited: %s', $oTweet->text);
				continue;
			}

			$this->logger->output('Favoriting: <a href="http://twitter.com/%s/statuses/%s">@%s</a>: %s',
				$oTweet->user->screen_name,
				$oTweet->id_str,
				$oTweet->user->screen_name,
				str_replace("\n", ' ', $oTweet->text)
            );
			$oRet = $this->oTwitter->post('favorites/create/' . $oTweet->id_str, array('include_entities' => false));

			if (!empty($oRet->errors)) {
				$this->logger->write(2, sprintf('Twitter API call failed: POST favorites/create (%s)', $oRet->errors[0]->message), array('tweet' => $oTweet));
				$this->logger->output(sprintf('- Favoriting failed, halting. (%s)', $oRet->errors[0]->message));

				return false;
			}
        }

        return true;
    }
}
