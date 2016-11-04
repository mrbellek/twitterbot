<?php
//TODO: use Config class, not parameter
namespace Twitterbot\Lib;

/**
 * Check ratelimit of our Twitter API account
 */
class Ratelimit extends Base
{
    /**
     * Check rate limit, return false if too close
     *
     * @param int $iMinRateLimit
     *
     * @return bool
     */
    public function check()    {
        return TRUE; //DEBUG

        $iMinRateLimit = $this->oConfig->get('min_rate_limit');

		$this->logger->output('Fetching rate limit status..');
		$oStatus = $this->oTwitter->get('application/rate_limit_status', array('resources' => 'search,blocks'));
		$oRateLimit = $oStatus->resources->search->{'/search/tweets'};
		$oBlockedLimit = $oStatus->resources->blocks->{'/blocks/ids'};
        $this->oRateLimitStatus = $oStatus;

		//check if remaining calls for search is lower than threshold (after reset: 180)
		if ($oRateLimit->remaining < $iMinRateLimit) {
			$this->logger->write(3, sprintf('Rate limit for GET search/tweets hit, waiting until %s', date('Y-m-d H:i:s', $oRateLimit->reset)));
			$this->logger->output(sprintf('- Remaining %d/%d calls! Aborting search until next reset at %s.',
				$oRateLimit->remaining,
				$oRateLimit->limit,
				date('Y-m-d H:i:s', $oRateLimit->reset)
			));

			return false;
		} else {
			$this->logger->output('- Remaining %d/%d calls (search), next reset at %s.', $oRateLimit->remaining, $oRateLimit->limit, date('Y-m-d H:i:s', $oRateLimit->reset));
		}

		//check if remaining calls for blocked users is lower than treshold (after reset: 15)
		if ($oBlockedLimit->remaining < $iMinRateLimit) {
			$this->logger->write(3, sprintf('Rate limit for GET blocks/ids hit, waiting until %s', date('Y-m-d H:i:s', $oBlockedLimit->reset)));
			$this->logger->output(sprintf('- Remaining %d/%d calls for blocked users! Aborting search until next reset at %s.',
				$oBlockedLimit->remaining,
				$oBlockedLimit->limit,
				date('Y-m-d H:i:s', $oBlockedLimit->reset)
			));

			return false;
		} else {
			$this->logger->output('- Remaining %d/%d calls (blocked users), next reset at %s.',
				$oBlockedLimit->remaining,
				$oBlockedLimit->limit,
				date('Y-m-d H:i:s', $oBlockedLimit->reset)
			);
		}

		return true;
    }
}
