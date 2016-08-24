<?php
namespace Twitterbot\Lib;

class Tweet extends Base
{
    public function post($aTweets = array())
    {
        if (!$aTweets) {
			$this->logger->write(3, 'Nothing to tweet.');
			$this->logger->output('Nothing to tweet.');

			return false;
		}

        $aTweets = (is_array($aTweets) ? $aTweets : array($aTweets));
        if (!empty($this->aMediaIds)) {
            $this->aMediaIds = (is_array($this->aMediaIds) ? $this->aMediaIds : array($this->aMediaIds));
        }

        foreach ($aTweets as $sTweet) {
            if (!empty($this->aMediaIds)) {
                $sMediaId = array_shift($this->aMediaIds);
                $this->logger->output('Tweeting: %s (with attachment)', $sTweet);
                $oRet = $this->oTwitter->post('statuses/update', array('status' => $sTweet, 'trim_users' => true, 'media_ids' => $sMediaId));
            } else {
                $this->logger->output('Tweeting: %s', $sTweet);
                $oRet = $this->oTwitter->post('statuses/update', array('status' => $sTweet, 'trim_users' => true));
            }
            if (isset($oRet->errors)) {
                $this->logger->write(2, sprintf('Twitter API call failed: statuses/update (%s)', $oRet->errors[0]->message), array('tweet' => $sTweet));
                $this->logger->output('- Error: %s (code %s)', $oRet->errors[0]->message, $oRet->errors[0]->code);

                return false;
            } else {
                $this->logger->output("- %s", utf8_decode($sTweet));
            }
        }

        return true;
    }
}
