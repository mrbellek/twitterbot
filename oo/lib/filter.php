<?php
namespace Twitterbot\Lib;
use Twitterbot\Lib\Blocks;

/**
 * Filter class, use several settings and rules to filter unwanted tweets
 *
 * @param config:filters
 * @param config:dice_values
 */
class Filter extends Base
{
    private $aSearchFilters = array();
    private $aUsernameFilters = array();
    private $aDiceValues = array();

    //hardcoded search filters
    private $aDefaultFilters = array(
        '"@',           //quote (instead of retweet)
        'ô@',           //smart quote  (chr(147)
        '@',
        'â@',         //mangled smart quote
        '“@',           //more smart quote “
    );

    //default probability values
    private $aDefaultDiceValues = array(
        'media'     => 1.0,
        'urls'      => 0.8,
        'mentions'  => 0.5,
        'base'      => 0.7,
    );

    /**
     * Get filters from config and split out
     *
     * @return $this
     */
    public function setFilters()
    {
        if ($aFilters = $this->oConfig->get('filters')) {
            $this->aSearchFilters   = array_merge($this->aDefaultFilters, (!empty($aFilters->tweet) ? $aFilters->tweet : array()));
            $this->aUsernameFilters = array_merge(array('@' . $this->oConfig->get('sUsername')), (!empty($aFilters->username) ? $aFilters->username : array()));
        }
        if ($aDiceValues = $this->oConfig->get('dice_values')) {
            $this->aDiceValues      = array_merge($this->aDefaultDiceValues, (array) $aDiceValues);
        }

        return $this;
    }

    /**
     * Apply filters to tweets, return remaining tweets
     *
     * @param array $aTweets
     *
     * @return array
     */
    public function filter($aTweets)
    {
        foreach ($aTweets as $i => $oTweet) {

			//replace shortened links
            $oTweet = $this->expandUrls($oTweet);

            if (!$this->applyFilters($oTweet) ||
                !$this->applyUsernameFilters($oTweet) ||
                !$this->rollDie($oTweet)) {

                unset($aTweets[$i]);
            }
        }

        return $aTweets;
    }

    /**
     * Apply textual filters to tweet
     *
     * @param object $oTweet
     *
     * @return bool
     */
    private function applyFilters($oTweet)
    {
		foreach ($this->aSearchFilters as $sFilter) {
            if (is_object($oTweet)) {
                if (strpos(strtolower($oTweet->text), $sFilter) !== false) {
                    $this->logger->output('<b>Skipping tweet because it contains "%s"</b>: %s', $sFilter, str_replace("\n", ' ', $oTweet->text));

                    return false;
                }
            } else {
                if (strpos(strtolower($oTweet), $sFilter) !== false) {
                    $this->logger->output('<b>Skipping tweet because it contains "%s"</b>: %s', $sFilter, str_replace("\n", ' ', $oTweet));

                    return false;
                }
            }
		}

		return true;
    }

    /**
     * Apply username filters to tweet
     *
     * @param object $oTweet
     *
     * @return bool
     */
    private function applyUsernameFilters($oTweet)
    {
        if (is_object($oTweet)) {
            foreach ($this->aUsernameFilters as $sUsername) {
                if (strpos(strtolower($oTweet->user->screen_name), $sUsername) !== false) {
                    $this->logger->output('<b>Skipping tweet because username contains "%s"</b>: %s', $sUsername, $oTweet->user->screen_name);
                    return false;
                }
                if (preg_match('/@\S*' . $sUsername . '/', $oTweet->text)) {
                    $this->logger->output('<b>Skipping tweet because mentioned username contains "%s"</b>: %s', $sUsername, $oTweet->text);
                    return false;
                }
            }
        }

		return true;
    }

    /**
     * Apply probability values to tweet (more interesting tweets have more chance of passing
     *
     * @param object $oTweet
     *
     * @return bool
     */
    private function rollDie($oTweet)
    {
        if (!is_object($oTweet)) {
            return true;
        }

		//regular tweets are better than mentions - medium probability
		$lProbability = $this->aDiceValues['base'];

		if (!empty($oTweet->entities->media) && count($oTweet->entities->media) > 0 
			|| strpos('instagram.com/p/', $oTweet->text) !== false
			|| strpos('vine.co/v/', $oTweet->text) !== false) {

			//photos/videos are usually funny - certain
			$lProbability = $this->aDiceValues['media'];

		} elseif (!empty($oTweet->entities->urls) && count($oTweet->entities->urls) > 0) {
			//links are ok but can be porn - high probability
			$lProbability = $this->aDiceValues['urls'];

		} elseif (strpos('@', $oTweet->text) === 0) {
			//mentions tend to be 'remember that time' stories or insults - low probability
			$lProbability = $this->aDiceValues['mentions'];
		}

		//compare probability (0.0 to 1.0) against random number
		$random = mt_rand() / mt_getrandmax();
		if (mt_rand() / mt_getrandmax() > $lProbability) {
			$this->logger->output('<b>Skipping tweet because the dice said so</b>: %s', str_replace("\n", ' ', $oTweet->text));
			return false;
		}

		return true;
    }

    /**
     * Replace shortened t.co urls in tweet with expanded urls
     *
     * @param object $oTweet
     *
     * @return object
     */
    private function expandUrls($oTweet)
    {
		//check for links/photos
		if (is_object($oTweet) && strpos($oTweet->text, 'http://t.co') !== false) {
            foreach($oTweet->entities->urls as $oUrl) {
                $oTweet->text = str_replace($oUrl->url, $oUrl->expanded_url, $oTweet->text);
            }
		}

		return $oTweet;
    }
}
