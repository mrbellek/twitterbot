<?php
namespace Twitterbot\Lib;

class Block extends Base
{
    private $aBlockedUsers = array();

    public function getAll()
    {
		$this->logger->output('Getting blocked users..');
		$oBlockedUsers = $this->oTwitter->get('blocks/ids', array('stringify_ids' => true));
		//note that not providing the 'cursor' param causes pagination in batches of 5000 ids

		if (!empty($oBlockedUsers->errors)) {
			$this->logger->write(2, sprintf('Twitter API call failed: GET blocks/ids (%s)', $oBlockedUsers->errors[0]->message));
			$this->logger->output(sprintf('- Unable to get blocked users, halting. (%s)', $oBlockedUsers->errors[0]->message));

			return false;
		} else {
            $this->logger->output('- %d on list', count($oBlockedUsers->ids));
			$this->aBlockedUsers = $oBlockedUsers->ids;
		}

		return true;
    }

    public function isUserBlocked($oUser)
    {
        if (!$this->aBlockedUsers) {
            $this->getAll();
        }

		foreach ($this->aBlockedUsers as $iBlockedId) {
			if ($oUser->id == $iBlockedId) {
				$this->logger->output('<b>Skipping tweet because user "%s" is blocked</b>', $oUser->screen_name);
				return true;
			}
		}

		return false;
    }

    public function filterBlocked($aTweets)
    {
        foreach ($aTweets as $key => $oTweet) {
            if ($this->isUserBlocked($oTweet->user)) {
                unset($aTweets[$key]);
            }
        }

        return $aTweets;
    }
}
