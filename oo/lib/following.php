<?php
namespasce Twitterbot\Lib;

class Following extends Base
{
    public $sUsername;
    public $aFollowing = array();

    public function getAll()
    {
        $oRet = $this->oTwitter->get('friends/ids', array('screen_name' => $this->sUsername, 'stringify_ids' => TRUE));
        if (!empty($oRet->errors[0]->message)) {
            $this->logger->write(2, sprintf('Twitter API call failed: GET friends/ids (%s)', $aMentions->errors[0]->message));
            $this->logger->output(sprintf('- Failed getting friends, halting. (%s)', $aMentions->errors[0]->message));

            return false;
        }

        $this->aFollowing = $oRet->ids;

        return true;
    }

    public function isFollowing($oUser)
    {
        if (!$this->aFollowing) {
            $this->getAll();
        }

        return (in_array($oUser->id_str, $this->aFollowing));
    }
}
