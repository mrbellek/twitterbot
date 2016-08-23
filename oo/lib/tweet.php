<?php
namespace Twitterbot\Lib;

class Tweet
{
    public function post($aTweets = array())
    {
        if (!$aTweets) {
			$this->logger->write(3, 'Nothing to tweet.');
			$this->logger->output('Nothing to tweet.');

			return false;
		}

        $aTweets = (is_array($aTweets) ? $aTweets : array($aTweets));

        foreach ($aTweets as $sTweet) {
            $this->logger->output('Tweeting: %s', $sTweet);
            $oRet = $this->oTwitter->post('statuses/update', array('status' => $sTweet, 'trim_users' => true));
            if (isset($oRet->errors)) {
                $this->logger->write(2, sprintf('Twitter API call failed: statuses/update (%s)', $oRet->errors[0]->message), array('tweet' => $sTweet));
                $this->logger->output('- Error: %s (code %s)', $oRet->errors[0]->message, $oRet->errors[0]->code);

                return false;
            } else {
                printf("- %s\n", utf8_decode($sTweet));
            }
        }

        return true;
    }
}
