<?php
namespace Twitterbot\Lib;

/**
 * Block class - retrieve blocked users, check if user is blocked
 */
class Block extends Base
{
    private $aBlockedUsers = false;

    /**
     * Get all blocked users
     *
     * @return bool
     */
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

    /**
     * Check if a user is blocked
     *
     * @param object $oUser
     *
     * @return bool
     */
    public function isUserBlocked($oUser)
    {
        if (!is_array($this->aBlockedUsers)) {
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

    /**
     * Given a list of tweets, return those by users that are not blocked
     *
     * @param array $aTweets
     *
     * @return array
     */
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
