<?php
set_time_limit(0);

//put yer addresses to spider here
//TODO: google for site:blockchain.info "public note" hits in last 30 days?
$aAddresses = array(
    'https://blockchain.info/address/1JvXNLXUJdBnbSye6LH1RsE51ySDH2qQw5',
    'https://blockchain.info/address/1XeH1kKXWYbzZcFHokGiZ4d3MWW5WyXzU',
    'https://blockchain.info/address/1uJw4z4UHcGBdpJcwPxWqm9nyUgtsUmPu',
    'https://blockchain.info/address/1QAHVyRzkmD4j1pU5W89htZ3c6D6E7iWDs',
    'https://blockchain.info/address/1LBregs3t4goLpvBghbJbFLoyt1rPL3jJh',
    'https://blockchain.info/address/1K6e5BTPVbzMuyXsysodsubiZPhdubUGFG',
    'https://blockchain.info/address/1Bc29jnuUKNt1gf1vgLtnFBPy754QcVKhh',
    'https://blockchain.info/address/1F8tjT7zfXfR5vpwSz9W5FkJuKNBHVRMnS',
    'https://blockchain.info/address/1nPfxnncZqWvVP4UHT6XLfNzfaik7akQS',
    'https://blockchain.info/address/1EiNgZmQsFN4rJLZJWt93quMEz3X82FJd2',
    'https://blockchain.info/address/1EkKs6UgNx2t715ALXdiHfKS1rdrFk1PHA',
    'https://blockchain.info/address/1FfCDbgmPmbdjrzUyp9s4vnrc5nvLxR7Yh',
    'https://blockchain.info/address/31oSGBBNrpCiENH3XMZpiP6GTC4tad4bMy',
    'https://blockchain.info/address/15u8aAPK4jJ5N8wpWJ5gutAyyeHtKX5i18',
    'https://blockchain.info/address/15Wtrm7ou3p9WaH8YXLtWGXoUnUssppsb9',
    'https://blockchain.info/address/1EhxrTcRHyFd2LFwbVwHqvhsEx8Fz9EaTA',
    'https://blockchain.info/address/1M87hiTAa49enJKVeT9gzLjYmJoYh9V98',
    'https://blockchain.info/address/149tkc36EfESvdhAtkwTsaMjCpP5nd3p8t',
    'https://blockchain.info/address/1McqPj92jvWfFg5F24dwyDSUptjTosH2EY',
    'https://blockchain.info/address/134dV6U7gQ6wCFbfHUz2CMh6Dth72oGpgH',
    'https://blockchain.info/address/1L6Xzog5krZ4KZF344NvGZMRpx2bND7ogE',
);

$o = new NotesScraper($aAddresses);

class NotesScraper {

    private $aAddresses = array();

    //filter out some spam
    private $aFilters = array(
        'freebitco.in',
        'peerluck',
        'btc-dice',
        'bitcoinsand', 'daily simple interest',
    );

    private $sCsvExport = './notesscraper.csv';
    private $sSqlExport = './notesscraper.sql';

    public function __construct($aAddresses) {

        $this->aAddresses = $aAddresses;

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
}
