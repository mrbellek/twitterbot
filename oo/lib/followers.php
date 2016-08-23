<?php
namespace Twitterbot\Lib;

class Followers
{
    public function getAll()
    {
        $oRet = $this->oTwitter->get('followers/ids', array('screen_name' => $this->oConfig->get('sUsername'), 'stringify_ids' => true));
        if (!empty($oRet->errors[0]->message)) {
            $this->logger->write(2, sprintf('Twitter API call failed: GET followers/ids (%s)', $aMentions->errors[0]->message));
            $this->logger->output(sprintf('- Failed getting followers, halting. (%s)', $aMentions->errors[0]->message));

            return false;
        }

        $this->aFollowers = $oRet->ids;

        return true;
    }

    public function isFollower()
    {
        if (!$this->aFollowers) {
            $this->getAll();
        }

        return (in_array($oUser->id_str, $this->aFollowers));
    }
}
