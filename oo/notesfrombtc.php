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

(new NotesFromBtc)->run();
//(new NotesScraper)->run();

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

            if ((new Ratelimit($oConfig))->check()) {

                if ((new Auth($oConfig))->isUserAuthed($this->sUsername)) {

                    $aRecord = (new Database($oConfig))
                        ->getRecord();

                    if ($aRecord) {
                        $sTweet = (new Format($oConfig))
                            ->format($aRecord);

                        if ($sTweet) {
                            (new Tweet($oConfig))
                                ->post($sTweet);

                            $this->logger->output('done!');
                        }
                    }
                }
            }
        }
    }
}

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
		curl_setopt($this->hCurl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($this->hCurl, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($this->hCurl, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($this->hCurl, CURLOPT_AUTOREFERER, true);
		curl_setopt($this->hCurl, CURLOPT_HTTPHEADER, array('Accept-Language: en-US;q=0.6,en;q=0.4'));
		curl_setopt($this->hCurl, CURLOPT_CONNECTTIMEOUT, 5);
		curl_setopt($this->hCurl, CURLOPT_TIMEOUT, 5);
		curl_setopt($this->hCurl, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/43.0.2357.130 Safari/537.36');

        $this->logger = new Logger;

        $this->aAddresses = array();
        $this->lDataDownloaded = 0;
        $this->lNotesFound = 0;
        $this->lNotesFiltered = 0;
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
            $aLastFilters = (array) $this->oSettings->top;
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
        $sBaseUrl = 'https://google.com';
        $sUrl = $sBaseUrl . '/search?q=' . urlencode($sQuery) . '&safe=off&tbs=qdr:m';

        //fetch the first page
        $sResults = $this->getAddress($sUrl, false);
		if (strlen($sResults) == 0) {
            $this->logger->output('- google returned 0 bytes for %s', $sUrl);

            return false;
		}

        $aAddresses = array();
        libxml_use_internal_errors(true);

        $oDom = new DOMDocument;
        $oDom->loadHTML($sResults);
        $oXpath = new DOMXPath($oDom);

        //keep going until the 'next page' link is no longer present
        while ($sUrl) {

            $oDom = new DOMDocument;
            $oDom->loadHTML($sResults);
            $oXpath = new DOMXPath($oDom);
            $aNodes = $oXpath->query('//h3/a');
            foreach ($aNodes as $oNode) {
                $aAddresses[] = $oNode->getAttribute('href');
            }

            //check for 'next' link
            $aNextLinkNode = $oXpath->query('//a[@class="pn"]');
            $sUrl = false;
            $sResults = false;
            foreach ($aNextLinkNode as $oNode) {
                $sUrl = $sBaseUrl . $oNode->getAttribute('href');
                $sResults = $this->getAddress($sUrl, false);
                break;
            }
            echo '.';
        }
		echo '/last';

        //merge into global array of addresses
        $this->aAddresses = array_values(array_unique(array_merge($this->aAddresses, $aAddresses)));
        echo "\r\n";

        return (count($this->aAddresses) > 0);
    }

    private function parseAddresses()
    {
        //sort by newest first
        $sArgs = '?sort=0';

        $oDom = new DOMDocument;

        //loop through addresses
        foreach ($this->aAddresses as $iKey => $sAddress) {

			//skip banned addresses that never have interesting notes
			foreach ($this->oSettings->banned as $sBanned) {
				if (strpos($sAddress, $sBanned) !== FALSE) {
					
					$this->logger->output('skipping address %s because it is banned', $sBanned);
					continue 2;
				}
			}

            //get pages, as long as next link is present AND it is not disabled (max 100 pages)
            $this->logger->output('fetching address %d/%d..', $iKey + 1, count($this->aAddresses));
            try {
                $sUrl = $sAddress . $sArgs;
                $iPage = 1;
                while ($sUrl && $iPage <= 100) {

                    $sHTML = $this->getAddress($sUrl);
                    $this->lDataDownloaded += strlen($sHTML);
                    if (empty($sHTML)) {
                        //disconnected
                        echo '/dc';
                        continue;
                    }

                    $oDom->loadHTML($sHTML);
                    $oXpath = new DOMXPath($oDom);

                    $bRet = $this->parseNotes($oXpath);
                    if (!$bRet) {
                        //note found that is older than treshold, break out of loop and go to next address
                        echo '/age';
                        break;
                    }

                    //fetch next page url
                    $aNextLinkNode = $oXpath->query('//li[@class="next "]/a');
                    $sUrl = false;
                    foreach ($aNextLinkNode as $oNode) {
                        $sUrl = $sAddress . $oNode->getAttribute('href');
                    }
                    $iPage++;
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

		$sResponse = curl_exec($this->hCurl);
		$sHttpCode = curl_getinfo($this->hCurl, CURLINFO_HTTP_CODE);
		if ($sHttpCode != '200') {
			echo 'HTTP ' . $sHttpCode . "\n";
			return false;
		}

		return $sResponse;
    }

    private function parseNotes($oXpath)
    {
        //look for nodes with public notes in them
        $aNodes = $oXpath->query('//div[@class="alert note"]');
        $this->lNotesFound += $aNodes->length;
        echo ($aNodes->length ? $aNodes->length : '.') . ' ';

        foreach ($aNodes as $oNode) {

            //get the note
            $sNote = trim(str_replace('Public Note:', '', $oNode->nodeValue));

            //get the timestamp on the transaction
            $aDateNode = $oXpath->query('//span[@class="pull-right"]', $oNode->parentNode);
            $sTimestamp = false;
            foreach ($aDateNode as $oSubnode) {
                $sTimestamp = $oSubnode->nodeValue;
                break;
            }
            if (!$sTimestamp || strtotime($sTimestamp) + $this->iNoteAgeThreshold < time()) {
                //note is too old, stop
                return false;
            }

            //get the transaction id
            $aTxNode = $oXpath->query('//a[@class="hash-link"]', $oNode->parentNode);
            $sTransactionId = false;
            foreach ($aTxNode as $oSubnode) {
                $sTransactionId = $oSubnode->getAttribute('href');
                break;
            }

            //apply filters
            $bFiltered = false;
            foreach ($this->oSettings->filters as $sFilter) {
                //check if filter is keyword match or regex
                if (preg_match('/^\/.+\/i?$/', $sFilter)) {
                    //regex match
                    if (preg_match($sFilter, $sNote)) {
                        $bFiltered = true;
                        $this->lNotesFiltered++;
                        $this->aFilterCounts[$sFilter]++;
                    }
                } else {
                    //keyword match
                    if (stripos($sNote, $sFilter) !== false) {
                        $bFiltered = true;
                        $this->lNotesFiltered++;
                        $this->aFilterCounts[$sFilter]++;
                    }
                }
            }

            //prevent mentioning users
            if (strpos($sNote, '@') !== false) {
                $sNote = str_replace('@', '@\\', $sNote);
            }

            //write to file
            if (!$bFiltered) {
                //save some space, tweets are short
                $sNote = preg_replace('/[a-f0-9]{64}/i', '[transaction]', $sNote);
                $sNote = preg_replace('/1[a-z0-9]{25,33}/i', '[address]', $sNote);

                //append line to csv and sql
                file_put_contents($this->sOutputFile . '.csv', '"' . str_replace('"', '\"', $sNote) . '","' . $sTransactionId . '"' . PHP_EOL, FILE_APPEND);
                file_put_contents($this->sOutputFile . '.sql', '("' . str_replace('"', '\"', $sNote) . '","' . $sTransactionId . '"),' . PHP_EOL, FILE_APPEND);
            }
        }

        return true;
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
