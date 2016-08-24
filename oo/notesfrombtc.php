<?php
require_once('autoload.php');
require_once('notesfrombtc.inc.php');

use Twitterbot\Lib\Logger;
use Twitterbot\Lib\Config;
use Twitterbot\Lib\Auth;
use Twitterbot\Lib\Ratelimit;
use Twitterbot\Lib\Database;
use Twitterbot\Lib\Format;
use Twitterbot\Lib\Tweet;

class NotesFromBtc {

    public function __construct()
    {
        $this->sUsername = 'NotesFromBTC';
        $this->logger = new Logger;
    }

    public function run()
    {
        $oConfig = new Config;
        if ($oConfig->load($this->sUsername)) {

            if ((new Ratelimit)->check($oConfig->get('min_rate_limit'))) {

                if ((new Auth)->isUserAuthed($this->sUsername)) {

                    $aRecord = (new Database)
                        ->set('oConfig', $oConfig)
                        ->getRecord();

                    if ($aRecord) {
                        $sTweet = (new Format)
                            ->set('oConfig', $oConfig)
                            ->format($aRecord);

                        if ($sTweet) {
                            (new Tweet)
                                ->set('oConfig', $oConfig)
                                ->post($sTweet);

                            $this->logger->output('done!');
                        }
                    }
                }
            }
        }
    }
}

//(new NotesFromBtc)->run();

class NotesScraper {

    public function __construct() 
    {
        $this->sOutputFile = 'notesscraper';
        $this->iNoteAgeThreshold = 3600 * 24 * 30;

        $this->oConfig = new Config;
        $this->oConfig->load('notesfrombtc');

        $this->oSettings = $this->oConfig->get('scraper');

        $this->aFilterCounts = array();
        foreach ($this->oSettings->filters as $sFilter) {
            $this->aFilterCounts[$sFilter] = 0;
        }

        $this->hCurl = curl_init();
        $this->logger = new Logger;
    }

    public function run()
    {
        if ($this->searchGoogle()) {

            $this->resetFiles();

            $this->parseAddresses();

            $this->finalizeFiles();

            $this->logger->output('done!');
            $this->logger->output('- downloaded %d MB of HTML', number_format($this->lDataDownloaded / (1024 ^ 2), 2));
            $this->logger->output('- found %d notes', $this->lNotesFound);
            $this->logger->output('- filtered %d notes (%d%%)', $this->lNotesFiltered, (100 * $this->lNotesFiltered / $this->lNotesFound));

            $this->logger->output('saving 10 most hit filters for next run:');
			arsort($this->aFilterCounts);
            foreach (array_slice($this->aFilterCounts, 0, 10) as $sFilter => $iCount) {
                $this->logger->output('- %d: %s', $iCount, $sFilter);
            }
            $aLastFilters = $this->oSettings->top;
			$aTopFilters = array_merge($aLastFilters, $this->aFilterCounts);
			arsort($aTopFilters);
            $aTopFilters = array_slice($aTopFilters, 0, 10, true);
            $this->oConfig->set('scraper', 'top', $aTopFilters);

        } else {
            $this->logger->output('searchGoogle() failed! bot throttling?');
        }
    }

    private function searchGoogle()
    {
        //TODO: use DOMDOcument and Xpath here instead of strpos and regex because fuck that

        $this->logger->output('searching google for recent transactions with public notes');

        //basic search query
        $sQuery = 'site:blockchain.info "public note"';

        //put in top hit filters from last run as boolean operators from the start to save traffic+time
        if (!empty($this->oSettings->top)) {

            $aTopFilters = array_keys((array) $this->oSettings->top);

			//don't put regex filters in google query string, this confuses google
			foreach ($aTopFilters as $key => $sFilter) {
				if (preg_match('/^\/.+\/i?$/', $sFilter)) {
					unset($aTopFilters[$key]);
				}
			}

            //shuffle to prevent google from blocking us for automated searches
            shuffle($aTopFilters);

            //truncate at 512 chars
            $sQuery .= substr(' -"' . implode('" -"', $aTopFilters) . '"', 0, 512);

        } else {

            //if those don't exist, use random filters and truncate at 512 chars
            $aFilters = $this->aFilters;
            shuffle($aFilters);
            $sQuery .= substr(' -"' . implode('" -"', $aFilters) . '"', 0, 512);
        }

        //prepare the whole url
        $sUrl = 'https://google.com/search?q=' . urlencode($sQuery) . '&safe=off&tbs=qdr:m';

        //fetch the first page
        if (!is_file('last.html')) {
            $sResults = $this->getAddress($sUrl, false);
            file_put_contents('last.html', $sResults);
        } else {
            $sResults = file_get_contents('last.html');
        }

		if (strlen($sResults) == 0) {
            $this->logger->output('- google returned 0 bytes for %s', $sUrl);

            return false;
		}

        $iOffset = 10;
        $aAddresses = array();
        libxml_use_internal_errors(true);

        //keep going until the 'next page' link is no longer present
        //while (strpos($sResults, $sNextLink) !== FALSE || strpos($sResults, $sNextLink2) !== FALSE) {

            $oDom = new DOMDocument;
            $oDom->loadHTML($sResults);
            $oXpath = new DOMXPath($oDom);
            $aNodes = $oXpath->query('//h3/a');
            foreach ($aNodes as $oNode) {
                $aAddresses[] = $oNode->getAttribute('href');
            }

            $aNode = $oXpath->query('//a[@class="pn"]');
            die(var_dump($aNode));


            //this isn't perfect (urls get truncated) but it'll do
            if (preg_match_all('/(https:\/\/blockchain.info\/address\/[a-zA-Z0-9]+)/', $sResults, $aMatches)) {

                $aAddresses = array_merge($aAddresses, $aMatches[1]);
            }

			$sResults = $this->getAddress($sUrl . '&start=' . $iOffset, FALSE);
            $iOffset += 10;
            echo '.';
        //}
		echo '/last';

        //merge into global array of addresses
        $this->aAddresses = array_values(array_unique(array_merge($this->aAddresses, $aAddresses)));
        echo "\r\n";
    }

    private function parseAddresses()
    {
        //sort by newest first
        $sArgs = '?sort=0';

        //loop through addresses
        foreach ($this->aAddresses as $iKey => $sAddress) {

			//skip banned addresses that never have interesting notes
			foreach ($this->oSettings->banned as $sBanned) {
				if (strpos($sAddress, $sBanned) !== FALSE) {
					
					$this->logger->output('skipping address %s because it is banned', $sBanned);
					continue 2;
				}
			}

            //get first page
            $this->logger->output('fetching address %d/%d..', $iKey + 1, count($this->aAddresses));
            try {
				$sHTML = $this->getAddress($sAddress . $sArgs);
                $this->lDataDownloaded += strlen($sHTML);
                if (empty($sHTML)) {
                    //got disconnected, just move on
                    echo '/dc';

                } else {
                    echo 'checking pages for public notes: ';
                    $bRet = $this->parseNotes($sHTML);
                    if (!$bRet) {
                        //note found that is older than treshold, skip to next address
                        echo "/age\r\n\r\n";
                        continue;
                    }

                    $iOffset = 50;
                    //get next pages, as long as next link is present AND it is not disabled (max 100 pages)
                    while (preg_match('/<li class="next ?">/', $sHTML) && !preg_match('/<li class="next disabled/', $sHTML) && $iOffset <= 5000) {

                        $sHTML = $this->getAddress($sAddress . '?offset=' . $iOffset . '&filter=0');
                        $this->lDataDownloaded += strlen($sHTML);
                        if (empty($sHTML)) {
                            //disconnected
                            echo '/dc';
                        } else {
                            $bRet = $this->parseNotes($sHTML);
                            if (!$bRet) {
                                //note found that is older than treshold, break out of loop and go to next address
                                echo '/age';
                                break;
                            }
                        }

                        $iOffset += 50;
                    }
                    if ($bRet) {
                        if ($iOffset > 5000) {
                            echo '/max'; //too many pages
                        } else {
                            echo '/end'; //no more pages
                        }
                    }
                }

            } catch (Exception $e) {
                //got disconnected, just move on
                echo '/dc';
            }
            echo "\r\n\r\n";
        }
    }

    private function getAddress($sUrl, $bUseApiCode = true)
    {
		curl_setopt($this->hCurl, CURLOPT_URL, $sUrl . ($bUseApiCode ? '&api_code=' . API_CODE : ''));
		curl_setopt($this->hCurl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($this->hCurl, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($this->hCurl, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($this->hCurl, CURLOPT_AUTOREFERER, true);
		curl_setopt($this->hCurl, CURLOPT_HTTPHEADER, array('Accept-Language: en-US;q=0.6,en;q=0.4'));
		curl_setopt($this->hCurl, CURLOPT_CONNECTTIMEOUT, 5);
		curl_setopt($this->hCurl, CURLOPT_TIMEOUT, 5);
		curl_setopt($this->hCurl, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/43.0.2357.130 Safari/537.36');

		$sResponse = curl_exec($this->hCurl);
		$sHttpCode = curl_getinfo($this->hCurl, CURLINFO_HTTP_CODE);
		if ($sHttpCode != '200') {
			echo 'HTTP ' . $sHttpCode . "\n";
			return false;
		}

		return $sResponse;
    }

    private function parseNotes($sHTML)
    {
        //TODO: use simplexml and xpath here and not regex because goddamn
    }

    private function resetFiles()
    {
        @unlink($this->sOutputFile . '.csv');
        @unlink($this->sOutputFile . '.sql');
        file_put_contents($this->sOutputFile . '.sql', 'INSERT INTO notes(note, tx) VALUES' . "\r\n");
    }

    private function finalizeFiles()
    {
        if ($oHandle = fopen($this->sOutputFile . '.sql', 'r+')) {

            $aStat = fstat($oHandle);
            ftruncate($oHandle, $aStat['size'] - 3);
            fclose($oHandle);
        }
    }

    public function __destruct()
    {
        curl_close($this->hCurl);
    }
}

(new NotesScraper)->run();
