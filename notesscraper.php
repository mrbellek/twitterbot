<?php
set_time_limit(0);

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

$aFilters = array(
    'freebitco.in',
    'peerluck',
    'btc-dice',
    'bitcoinsand',
    'daily simple interest',
);

if (!empty($_SERVER['DOCUMENT_ROOT'])) {
    echo '<pre>';
}

foreach ($aAddresses as $iKey => $sAddress) {

    //get first page
    printf("fetching address %d/%d..\r\n", $iKey + 1, count($aAddresses));
    $sHTML = file_get_contents($sAddress);
    
    //look for public notes
    echo 'checking address for public notes: ';
    if (preg_match_all('/<div class="alert note"><b>Public Note:<\/b> (.*?)<\/div>.*?href="(\/tx\/[a-zA-Z0-9]{64})"/', $sHTML, $aMatches)) {
        echo count($aMatches[1]) . ' ';

        foreach ($aMatches[1] as $iKey2 => $sNote) {

            $bFiltered = FALSE;
            foreach ($aFilters as $sFilter) {
                if (stripos($sNote, $sFilter) !== FALSE) {
                    //keyword match, don't save
                    $bFiltered = TRUE;
                }
            }

            if (!$bFiltered) {
                $sNote = preg_replace('/[a-z0-9]{64}/i', '[transaction]', $sNote);
                $sNote = preg_replace('/[a-z0-9]{26,34}/i', '[address]', $sNote);
                file_put_contents('notesscraper.csv', '"' . str_replace('"', '\"', $sNote) . '","' . $aMatches[2][$iKey2] . "\"\r\n", FILE_APPEND);
            }
        }
    } else {
        echo '. ';
    }

    //get other pages, if any
    $iOffset = 50;
    while (preg_match('/<li class="next ?">/', $sHTML) && !preg_match('/<li class="next disabled/', $sHTML)) {

        $sHTML = file_get_contents($sAddress . '?offset=' . $iOffset . '&filter=0');

        if (preg_match_all('/<div class="alert note"><b>Public Note:<\/b> (.*?)<\/div>.*?href="(\/tx\/[a-zA-Z0-9]{64})"/', $sHTML, $aMatches)) {
            echo count($aMatches[1]) . ' ';

            foreach ($aMatches[1] as $iKey2 => $sNote) {

                $bFiltered = FALSE;
                foreach ($aFilters as $sFilter) {
                    if (stripos($sNote, $sFilter) !== FALSE) {
                        //keyword match, don't save
                        $bFiltered = TRUE;
                    }
                }

                if (!$bFiltered) {
                    $sNote = preg_replace('/[a-z0-9]{64}/i', '[transaction]', $sNote);
                    $sNote = preg_replace('/[a-z0-9]{26,34}/i', '[address]', $sNote);
                    file_put_contents('notesscraper.csv', '"' . str_replace('"', '\"', $sNote) . '","' . $aMatches[2][$iKey2] . "\"\r\n", FILE_APPEND);
                }
            }
        } else {
            echo '. ';
        }

        $iOffset += 50;
    }
    echo "\r\n\r\n";
}
echo "done!";
