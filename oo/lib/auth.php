<?php
namespace Twitterbot\Lib;

/**
 * Auth class - verify current twitter user is the correct user
 *
 * @param string $sUsername
 *
 * @return bool
 */
class Auth extends Base
{
    public function isUserAuthed($sUsername)
    {
        $this->logger->output('Fetching identity..');

        if (!$sUsername) {
            $this->logger->write(2, 'No username');
            $this->logger->output('- No username provided!');

            return false;
        }

        $oCurrentUser = $this->oTwitter->get('account/verify_credentials', array('include_entities' => false, 'skip_status' => true));
        if (is_object($oCurrentUser) && !empty($oCurrentUser->screen_name)) {
			if ($oCurrentUser->screen_name == $sUsername) {
				$this->logger->output('- Allowed: @%s, continuing.', $oCurrentUser->screen_name);
			} else {
				$this->logger->write(2, sprintf('Authenticated username was unexpected: %s (expected: %s)', $oCurrentUser->screen_name, $sUsername));
				$this->logger->output(sprintf('- Not allowed: @%s (expected: %s), halting.', $oCurrentUser->screen_name, $sUsername));

				return false;
			}
		} else {
			$this->logger->write(2, sprintf('Twitter API call failed: GET account/verify_credentials (%s)', $oCurrentUser->errors[0]->message));
			$this->logger->output(sprintf('- Call failed, halting. (%s)', $oCurrentUser->errors[0]->message));

			return false;
        }

        return true;
    }
}
