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
    public function search($aQuery)
    {
		if (empty($aQuery)) {
			$this->logger->write(2, 'No search strings set');
			$this->logger->output('No search strings!');
            
			return false;
		}

		$aTweets = array();

        foreach ($aQuery as $i => $oSearch) {
            $sSearchString = $oSearch->search;

            $this->logger->output('Searching for max %d tweets with: %s..', $this->oConfig->get('search_max'), $sSearchString);

            $aArgs = array(
                'q'				=> $sSearchString,
                'result_type'	=> 'mixed',
                'count'			=> $this->oConfig->get('search_max'),
                'since_id'		=> $this->oConfig->get('max_id', 1),
            );
            $oSearch = $this->oTwitter->get('search/tweets', $aArgs);

            if (empty($oSearch->search_metadata)) {
                $this->logger->write(2, sprintf('Twitter API call failed: GET /search/tweets (%s)', $oSearch->errors[0]->message), $aArgs);
                $this->logger->output(sprintf('- Unable to get search results, halting. (%s)', $oSearch->errors[0]->message));

                return false;
            }

            //save data for next run
            $this->oConfig->set('search_strings', $i, 'max_id', $oSearch->search_metadata->max_id_str);
            $this->oConfig->set('search_strings', $i, 'timestamp', date('Y-m-d H:i:s'));

            if (empty($oSearch->statuses) || count($oSearch->statuses) == 0) {
                $this->logger->output('- No results since last search at %s.', $oSearch->timestamp);
            } else {
                //make sure we parse oldest tweets first
                $aTweets = array_merge($aTweets, array_reverse($oSearch->statuses));
            }
        }

        return $aTweets;
    }
}
