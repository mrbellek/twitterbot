<?php
set_time_limit(0);

//start the scraper, optionally pass an array of urls to spider in constructor
$o = new NotesScraper();

class NotesScraper {

    private $aAddresses = array();

    //filter out some spam
    private $aFilters = array(
        '48hourbtc.com',
        '9bitz.eu',
        'abitback.com',
        'adbit.co',
        'bitads.net',
        'bitbucks',
        'bitcoinforest',
        'bitcoinposter.com',
        'bitcoins4.me',
        'bitcoinsand', 'daily simple interest',
        'bitcoinworld.me',
        'bitonplay',
        'bitsleep.com',
        'btc-dice',
        'btclove.net',
        'coinad.com',
        'cryptory.com',
        'doublebitcoins.com',
        'elbitcoingratis.es',
        'eu5.com',
        'exchanging.ir', 'exchchanging.ir',
        'fishbitfish.com',
        'freebitco.in',
        'freebitcoins.es',
        'freebitcoinz.com',
        'gbbg|bitbillions',
        'invoice #',
        'kitiwa.com',
        'leancy.com',
        'lucky bit',
        'peerluck',
        'ptcircle.com',
        'simplecoin.cz',
        'win88.me',
        'winbtc',
        'withdraw to',
    );

    private $sCsvExport = './notesscraper.csv';
    private $sSqlExport = './notesscraper.sql';

    public function __construct($aAddresses = array()) {

        $this->aAddresses = $aAddresses;

        //get public notes from blockchain.info in the past 30 days (week?)
        $this->searchGoogle();

        //if we're running from a browser, make output readable
        if (!empty($_SERVER['DOCUMENT_ROOT'])) {
            echo '<pre>';
        }

        //reset export files
        $this->resetFiles();

        //parse the blockchain urls of addresses
        $this->parseAddresses();

        //truncate last comma of sql export
        $this->finalizeFiles();

        echo "done!";
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

        //loop through addresses
        foreach ($this->aAddresses as $iKey => $sAddress) {

            //get first page
            printf("fetching address %d/%d..\r\n", $iKey + 1, count($this->aAddresses));
            try {
                $sHTML = file_get_contents($sAddress);
                if (empty($sHTML)) {
                    die('file_get_contents failed!');
                }
                
                echo 'checking pages for public notes: ';
                $this->parseNotes($sHTML);

                $iOffset = 50;
                //get next pages, as long as next link is present AND it is not disabled
                while (preg_match('/<li class="next ?">/', $sHTML) && !preg_match('/<li class="next disabled/', $sHTML)) {

                    $sHTML = file_get_contents($sAddress . '?offset=' . $iOffset . '&filter=0');
                    $this->parseNotes($sHTML);

                    $iOffset += 50;
                }
            } catch (Exception $e) {
                //got disconnected, just move on
                echo 'x';
            }
            echo "\r\n\r\n";
        }
    }

    private function parseNotes($sHTML) {
                
        //look for public notes
        if (preg_match_all('/<div class="alert note"><b>Public Note:<\/b> (.*?)<\/div>.*?href="(\/tx\/[a-zA-Z0-9]{64})"/', $sHTML, $aMatches)) {
            echo count($aMatches[1]) . ' ';

            //loop through public notes (+ transaction ids)
            foreach ($aMatches[1] as $iKey2 => $sNote) {

                //apply filters
                $bFiltered = FALSE;
                foreach ($this->aFilters as $sFilter) {
                    if (stripos($sNote, $sFilter) !== FALSE) {
                        //keyword match, don't save
                        $bFiltered = TRUE;
                    }
                }

                //write to file
                if (!$bFiltered) {
                    //save some space, tweets are short
                    $sNote = preg_replace('/[a-z0-9]{64}/i', '[transaction]', $sNote);
                    $sNote = preg_replace('/[a-z0-9]{26,34}/i', '[address]', $sNote);

                    //append line to csv and sql
                    file_put_contents($this->sCsvExport, '"' . str_replace('"', '\"', $sNote) . '","' . $aMatches[2][$iKey2] . "\"\r\n", FILE_APPEND);
                    file_put_contents($this->sSqlExport, '("' . str_replace('"', '\"', $sNote) . '","' . $aMatches[2][$iKey2] . "\"),\r\n", FILE_APPEND);
                }
            }
        } else {
            echo '. ';
        }
    }

    private function searchGoogle() {

        echo 'searching google for recent transactions with public notes';

        //basic search query
        $sQuery = 'site:blockchain.info "public note"';

        //put in filters as boolean operators from the start, to save traffic and time
        $sQuery .= ' -"' . implode('" -"', $this->aFilters) . '"';

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
