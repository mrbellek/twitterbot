<?php
require('notesscraper.inc.php');
set_time_limit(0);

/*
 * TODO:
 * - when done, display stats on which filters are hit most often
 *   - merge most hit filters with most hit filters from last time instead of replacing them
 * - use above data to construct better google query
 *
 * for future reference, these are the addresses with probably the funniest public notes:
 * Silkroad Seized Coins    - https://blockchain.info/address/1F1tAaz5x1HUXrCNLbtMDqcw6o5GNn4xqX
 * DPR Seized Coins         - https://blockchain.info/address/1FfmbHfnpaZjKFvyi1okTjJJusN455paPH
 * DPR Seized Coins 2       - https://blockchain.info/address/1i7cZdoE9NcHSdAL5eGjmTJbBVqeQDwgw
 *
 * more named addresses:
 * Bitstamp Hack            - https://blockchain.info/address/1L2JsXHPMYuAa9ugvHGLwkdstCPUDemNCf
 * TouchID hack             - https://blockchain.info/address/1LMvYJx26XMKnnX4R5AhWsxCg6bnSkAk3F
 * XSS hack                 - https://blockchain.info/address/1DnwcSevrYyUCTxbPmL1TtABoaucDTMTYo
 * Inputs.io Hack           - https://blockchain.info/address/1EMztWbGCBBrUAHquVeNjWpJKcB8gBzAFx
 * PeerTech.org Hack Bounty - https://blockchain.info/address/128Dwx6qckYEftrUGmYWfn5PRcKvQtW6bp
 */

//start the scraper, optionally pass an array of urls to spider in constructor
$o = new NotesScraper();

class NotesScraper {

    private $aAddresses = array();
	private $hCurl;

    private $aFilters = array();
    private $aFilterCounts = array();

    private $sSettingsFile = './notesscraper.json';
    private $sCsvExport = './notesscraper.csv';
    private $sSqlExport = './notesscraper.sql';
    private $sFiltersFile = './notesscraper-top.json';

    private $iNoteAgeThreshold;

    //keep track of data for fun
    private $lDataDownloaded = 0;
    private $lNotesFound = 0;
    private $lNotesFiltered = 0;


    public function __construct($aAddresses = array()) {

		//max age of public note (1 month)
        $this->iNoteAgeThreshold = 3600 * 24 * 30;

        $this->aAddresses = $aAddresses;

        //load filters and initialize filter counter
        $this->aSettings = json_decode(file_get_contents($this->sSettingsFile), TRUE);
		if (!$this->aSettings && json_last_error_msg()) {
			printf('failed to read settings file %s.', $this->sSettingsFile);
			die();
		}
        $this->aFilters = $this->aSettings['filters'];
		$this->aBanned = $this->aSettings['banned'];
        foreach ($this->aFilters as $sFilter) {
            $this->aFilterCounts[$sFilter] = 0;
        }

        //if we're running from a browser, make output readable
        if (!empty($_SERVER['DOCUMENT_ROOT'])) {
            echo '<pre>';
        }

		//start cURL
		$this->hCurl = curl_init();

        //get public notes from blockchain.info in the past 30 days (week?)
        $this->searchGoogle();

        if ($this->aAddresses) {

            //reset export files
            $this->resetFiles();

            //parse the blockchain urls of addresses
            $this->parseAddresses();

            //truncate last comma of sql export
            $this->finalizeFiles();

            echo "done!";
            printf("\n- downloaded %d MB of HTML\n- found %d notes\n- filtered %d notes (%d%%)\n",
                number_format($this->lDataDownloaded / (1024 ^ 2), 2),
                $this->lNotesFound,
                $this->lNotesFiltered,
                (100 * $this->lNotesFiltered / $this->lNotesFound)
            );

            echo "\nsaving 10 most hit filters for next run:\n";
			arsort($this->aFilterCounts);
            foreach (array_slice($this->aFilterCounts, 0, 10) as $sFilter => $iCount) {
                printf("- %d: %s\n", $iCount, $sFilter);
            }
            $aLastFilters = json_decode(file_get_contents($this->sFiltersFile), TRUE);
			$aTopFilters = array_merge($aLastFilters, $this->aFilterCounts);
			arsort($aTopFilters);
            $aTopFilters = array_slice($aTopFilters, 0, 10, TRUE);
            file_put_contents($this->sFiltersFile, json_encode($aTopFilters, JSON_PRETTY_PRINT));

        } else {
            echo "searchGoogle() failed! bot throttling?";
        }

		curl_close($this->hCurl);
    }

    private function resetFiles() {

        //reset
        @unlink($this->sCsvExport);
        @unlink($this->sSqlExport);
        file_put_contents($this->sSqlExport, 'INSERT INTO notes(note, tx) VALUES' . "\r\n");
    }

    private function finalizeFiles() {

        //trim last comma (and return carriage) from sql file, if it exists
        if($oHandle = fopen($this->sSqlExport, 'r+')) {

            $aStat = fstat($oHandle);
            ftruncate($oHandle, $aStat['size'] - 3);
            fclose($oHandle);
        }
    }

    private function parseAddresses() {

        //sort by newest first
        $sArgs = '?sort=0';

        //loop through addresses
        foreach ($this->aAddresses as $iKey => $sAddress) {

			//skip banned addresses that never have interesting notes
			foreach ($this->aBanned as $sBanned) {
				if (strpos($sAddress, $sBanned) !== FALSE) {
					
					printf("skipping address %s because it is banned\n", $sBanned);
					continue 2;
				}
			}

            //get first page
            printf("fetching address %d/%d..\r\n", $iKey + 1, count($this->aAddresses));
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

	private function getAddress($sUrl, $bUseApiCode = TRUE) {

		curl_setopt($this->hCurl, CURLOPT_URL, $sUrl . ($bUseApiCode ? '&api_code=' . API_CODE : ''));
		curl_setopt($this->hCurl, CURLOPT_RETURNTRANSFER, TRUE);
		curl_setopt($this->hCurl, CURLOPT_SSL_VERIFYPEER, FALSE);
		curl_setopt($this->hCurl, CURLOPT_FOLLOWLOCATION, TRUE);
		curl_setopt($this->hCurl, CURLOPT_AUTOREFERER, TRUE);
		curl_setopt($this->hCurl, CURLOPT_HTTPHEADER, array('Accept-Language: en-US;q=0.6,en;q=0.4'));
		curl_setopt($this->hCurl, CURLOPT_CONNECTTIMEOUT, 5);
		curl_setopt($this->hCurl, CURLOPT_TIMEOUT, 5);
		curl_setopt($this->hCurl, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/43.0.2357.130 Safari/537.36');

		$sResponse = curl_exec($this->hCurl);
		$sHttpCode = curl_getinfo($this->hCurl, CURLINFO_HTTP_CODE);
		if ($sHttpCode != '200') {
			echo 'HTTP ' . $sHttpCode . "\n";
			return FALSE;
		}

		return $sResponse;
	}

    private function parseNotes($sHTML) {

        //look for public notes
        if (preg_match_all('/<div class="alert note"><b>Public Note:<\/b> (.*?)<\/div>.*?href="(\/tx\/[a-zA-Z0-9]{64})">.+?"pull-right">(.+?)<\/span>/', $sHTML, $aMatches)) {
            echo count($aMatches[1]) . ' ';
            $this->lNotesFound += count($aMatches[1]);

            //loop through public notes (+ transaction ids)
            foreach ($aMatches[1] as $iKey2 => $sNote) {

                //check note age
                if ($aMatches[3][$iKey2] && strtotime($aMatches[3][$iKey2])) {
                    if (strtotime($aMatches[3][$iKey2]) < time() - $this->iNoteAgeThreshold) {
                        //note is too old, stop
                        return FALSE;
                    }
                }

                //apply filters
                $bFiltered = FALSE;
                foreach ($this->aFilters as $sFilter) {
                    //check if filter is a keyword match or regex
                    if (preg_match('/^\/.+\/i?$/', $sFilter)) {
                        //regex
                        if (preg_match($sFilter, $sNote)) {
                            //regex match, don't save
                            $bFiltered = TRUE;
                            $this->lNotesFiltered++;
                            $this->aFilterCounts[$sFilter]++;
                        }
                    } else {
                        //keyword match
                        if (stripos($sNote, $sFilter) !== FALSE) {
                            //keyword match, don't save
                            $bFiltered = TRUE;
                            $this->lNotesFiltered++;
                            $this->aFilterCounts[$sFilter]++;
                        }
                    }
                }

                //TODO: check database for duplicates here

                //write to file
                if (!$bFiltered) {
                    //save some space, tweets are short
                    $sNote = preg_replace('/[a-f0-9]{64}/i', '[transaction]', $sNote);
                    $sNote = preg_replace('/1[a-z0-9]{25,33}/i', '[address]', $sNote);

                    //append line to csv and sql
                    file_put_contents($this->sCsvExport, '"' . str_replace('"', '\"', $sNote) . '","' . $aMatches[2][$iKey2] . "\"\r\n", FILE_APPEND);
                    file_put_contents($this->sSqlExport, '("' . str_replace('"', '\"', $sNote) . '","' . $aMatches[2][$iKey2] . "\"),\r\n", FILE_APPEND);
                }
            }
        } else {
            echo '. ';
        }

        return TRUE;
    }

    private function searchGoogle() {

        echo 'searching google for recent transactions with public notes';

		//next link whenever google feels like giving it to me in the wrong language
		$sNextLink = '>Volgende</span>';
		$sNextLink2 = '>Next</span>';

        //basic search query
        $sQuery = 'site:blockchain.info "public note"';

        //put in top hit filters from last run as boolean operators from the start to save traffic+time
        if (is_file($this->sFiltersFile)) {

            $aLastFilters = json_decode(file_get_contents($this->sFiltersFile), TRUE);
            $aTopFilters = array_keys($aLastFilters);

			//don't put regex filters in google query string, this confuses google
			foreach ($aTopFilters as $key => $sFilter) {
				if (preg_match('/^\/.+\/i?$/', $sFilter)) {
					unset($aTopFilters[$key]);
				}
			}
            shuffle($aTopFilters);
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
		$sResults = $this->getAddress($sUrl, FALSE);
		file_put_contents('./lastwebpage.html', $sResults);

		if (strlen($sResults) == 0) {
			die("\ngoogle returned 0 bytes\n$sUrl");
		} elseif (strpos($sResults, $sNextLink) === FALSE && strpos($sResults, $sNextLink2) === FALSE) {
			die("\nfirst page of results ok, but can't find \"Next\" link!");
		}

        $iOffset = 10;
        $aAddresses = array();

        //keep going until the 'next page' link is no longer present
        while (strpos($sResults, $sNextLink) !== FALSE || strpos($sResults, $sNextLink2) !== FALSE) {

            //this isn't perfect (urls get truncated) but it'll do
            if (preg_match_all('/(https:\/\/blockchain.info\/address\/[a-zA-Z0-9]+)/', $sResults, $aMatches)) {

                $aAddresses = array_merge($aAddresses, $aMatches[1]);
            }

			$sResults = $this->getAddress($sUrl . '&start=' . $iOffset, FALSE);
			file_put_contents('./lastwebpage.html', $sResults);
            $iOffset += 10;
            echo '.';
        }
		echo '/last';

        //merge into global array of addresses
        $this->aAddresses = array_values(array_unique(array_merge($this->aAddresses, $aAddresses)));
        echo "\r\n";
    }
}
