<?php
namespace Twitterbot\Lib;

/**
 * Tweet class, post tweet from whatever source, optionally adding media
 */
class Tweet extends Base
{
    /**
     * Post tweets to Twitter, add media if present (set by setMedia())
     *
     * @param array $aTweets
     *
     * @return bool
     */
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
                //TODO: why array_shift for just the first one? twitter API supports up to 4 attachments
                $sMediaId = array_shift($this->aMediaIds);
                $this->logger->output('Tweeting: [%dch] %s (with attachment)', strlen($sTweet), utf8_decode($sTweet));
                $oRet = $this->oTwitter->post('statuses/update', array('status' => $sTweet, 'trim_users' => true, 'media_ids' => $sMediaId));
            } else {
                $this->logger->output('Tweeting: [%dch] %s', strlen($sTweet), utf8_decode($sTweet));
                $oRet = $this->oTwitter->post('statuses/update', array('status' => $sTweet, 'trim_users' => true));
            }
            if (isset($oRet->errors)) {
                $this->logger->write(2, sprintf('Twitter API call failed: statuses/update (%s)', $oRet->errors[0]->message), array('tweet' => $sTweet));
                $this->logger->output('- Error: %s (code %s)', $oRet->errors[0]->message, $oRet->errors[0]->code);

                return false;
            }
        }

        return true;
    }

    public function replyTo($oTweet, $sMessage)
    {
        if (!$oTweet || empty($oTweet->id_str || !trim($sMessage))) {
            $this->logger->write(3, 'Nothing to tweet.');
            $this->logger->output('Nothing to tweet.');

            return false;
        }

        $this->logger->output('Replying: [%dch] %s', strlen($sMessage), utf8_decode($sMessage));
        $oRet = $this->oTwitter->post('statuses/update', array(
            'status' => sprintf('@%s %s',
                $oTweet->user->screen_name,
                substr($sMessage, 0, 140 - 2 - strlen($oTweet->user->screen_name))
            ),
            'trim_users' => true,
            'in_reply_to_status_id' => $oTweet->id_str,
        ));
        if (isset($oRet->errors)) {
            $this->logger->write(2, sprintf('Twitter API call failed: statuses/update (reply: %s)', $oRet->errors[0]->message), array('tweet' => $sMessage));
            $this->logger->output('- Error replying: %s (code %s)', $oRet->errors[0]->message, $oRet->errors[0]->code);

            return false;
        }

        return true;
    }

    /**
     * Set media ids to be posted with next tweet
     *
     * @param array $aMediaIds
     *
     * @return $this
     */
    public function setMedia($aMediaIds)
    {
        $this->aMediaIds = $aMediaIds;

        return $this;
    }
}
