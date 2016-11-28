<?php
namespace Twitterbot\Lib;

/**
 * Search class, search Twitter for given terms
 *
 * @param config:search_max
 * @param config:search_strings
 */
class Search extends Base
{
    /**
     * Search Twitter with given search terms, return tweets
     *
     * @param array $aQuery
     *
     * @return array|false
     */
    public function search()
    {
        $oQuery = $this->oConfig->get('search_strings');
		if (empty($oQuery)) {
			$this->logger->write(2, 'No search strings set');
			$this->logger->output('No search strings!');
            
			return false;
		}

		$aTweets = array();

        foreach ($oQuery as $i => $oSearch) {
            $sSearchString = $oSearch->search;
            $sMaxId = (!empty($oSearch->max_id) ? $oSearch->max_id : 1);

            $this->logger->output('Searching for max %d tweets with: %s..', $this->oConfig->get('search_max'), $sSearchString);

            $aArgs = array(
                'q'				=> $sSearchString,
                'result_type'	=> 'mixed',
                'count'			=> $this->oConfig->get('search_max'),
                'since_id'		=> $sMaxId,
            );
            $oTweets = $this->oTwitter->get('search/tweets', $aArgs);

            if (empty($oTweets->search_metadata)) {
                $this->logger->write(2, sprintf('Twitter API call failed: GET /search/tweets (%s)', $oTweets->errors[0]->message), $aArgs);
                $this->logger->output(sprintf('- Unable to get search results, halting. (%s)', $oTweets->errors[0]->message));

                return false;
            }

            if (empty($oTweets->statuses) || count($oTweets->statuses) == 0) {
                $this->logger->output('- No results since last search at %s.', $oSearch->timestamp);
            } else {
                //make sure we parse oldest tweets first
                $aTweets = array_merge($aTweets, array_reverse($oTweets->statuses));
            }

            //save data for next run
            $this->oConfig->set('search_strings', $i, 'max_id', $oTweets->search_metadata->max_id_str);
            $this->oConfig->set('search_strings', $i, 'timestamp', date('Y-m-d H:i:s'));
        }

        $this->oConfig->writeConfig();

        return $aTweets;
    }
}
