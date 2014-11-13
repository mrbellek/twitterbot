<?php
set_time_limit(0);

/*
 * TODO:
 * - indication for cause of halt of spidering address (out of pages, too old, too many pages, disconnect etc)
 */

//start the scraper, optionally pass an array of urls to spider in constructor
$o = new NotesScraper();

class NotesScraper {

    private $aAddresses = array();

    private $aFilters = array();

    private $sSettingsFile = './notesscraper.json';
    private $sCsvExport = './notesscraper.csv';
    private $sSqlExport = './notesscraper.sql';

    private $iNoteAgeThreshold;

    public function __construct($aAddresses = array()) {

        $this->iNoteAgeThreshold = 3600 * 24 * 30 * 6;

        $this->aAddresses = $aAddresses;

        $this->aFilters = json_decode(file_get_contents($this->sSettingsFile), TRUE);
        $this->aFilters = $this->aFilters['filters'];

        //if we're running from a browser, make output readable
        if (!empty($_SERVER['DOCUMENT_ROOT'])) {
            echo '<pre>';
        }

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
        } else {
            echo "searchGoogle() failed! bot throttling?";
        }
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

            //get first page
            printf("fetching address %d/%d..\r\n", $iKey + 1, count($this->aAddresses));
            try {
                $sHTML = file_get_contents($sAddress . $sArgs);
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

                        $sHTML = @file_get_contents($sAddress . '?offset=' . $iOffset . '&filter=0');
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

    private function parseNotes($sHTML) {

        //look for public notes
        if (preg_match_all('/<div class="alert note"><b>Public Note:<\/b> (.*?)<\/div>.*?href="(\/tx\/[a-zA-Z0-9]{64})">.+?"pull-right">(.+?)<\/span>/', $sHTML, $aMatches)) {
            echo count($aMatches[1]) . ' ';

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
                    if (stripos($sNote, $sFilter) !== FALSE) {
                        //keyword match, don't save
                        $bFiltered = TRUE;
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

        //basic search query
        $sQuery = 'site:blockchain.info "public note"';

        //put in filters as boolean operators from the start, to save traffic and time
        $aFilters = $this->aFilters;
        shuffle($aFilters);
        $sQuery .= substr(' -"' . implode('" -"', $aFilters) . '"', 0, 512);

        //prepare the whole url
        $sUrl = 'http://google.com/search?q=' . urlencode($sQuery) . '&safe=off&tbs=qdr:m';

        //fetch the first page
        $sResults = file_get_contents($sUrl);
        $iOffset = 10;
        $aAddresses = array();

        //keep going until the 'next page' link is no longer present
        while (strpos($sResults, '>Next</span>') !== FALSE) {

            //this isn't perfect (urls get truncated) but it'll do
            if (preg_match_all('/(https:\/\/blockchain.info\/address\/[a-zA-Z0-9]+)/', $sResults, $aMatches)) {

                $aAddresses = array_merge($aAddresses, $aMatches[1]);
            }

            $sResults = file_get_contents($sUrl . '&start=' . $iOffset);
            $iOffset += 10;
            echo '.';
        }

        //merge into global array of addresses
        $this->aAddresses = array_values(array_unique(array_merge($this->aAddresses, $aAddresses)));
        echo "\r\n";
    }
}
