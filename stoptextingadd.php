<?php
if ($_POST && !empty($_POST['input'])) {
    require_once('stoptextingall.inc.php');

    try {
        $oPDO = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME, DB_USER, DB_PASS);
    } catch(Exception $e) {
        die('db connection failed');
    }

    //lines are separated by return, groups of lines are separated by double return
    $aTweets = explode("\r\n\r\n", $_POST['input']);
    $iSubmitCount = count($aTweets);
    $iDupeCount = 0;
    foreach ($aTweets as $iKey => $sTweet) {

        if (trim($sTweet) == '') {
            continue;
        }

        $sQuery = '
            SELECT id
            FROM stoptexting
            WHERE tweet = :tweets
            LIMIT 1';
        $sth = $oPDO->prepare($sQuery);
        $sth->bindParam(':tweets', $sTweet, PDO::PARAM_STR);

        if ($sth->execute() == FALSE) {
            die(sprintf('query failed: %s', $sQuery));
        }

        if ($sth->fetch()) {
            unset($aTweets[$iKey]);
            $iDupeCount++;
        } else {
            $sQuery = 'INSERT INTO stoptexting (tweet) VALUES (:tweet)';
            $sth = $oPDO->prepare($sQuery);
            $sth->bindParam(':tweet', $sTweet, PDO::PARAM_STR);

            if ($sth->execute() == FALSE) {
                die(sprintf('query failed: %s', $sQuery));
            }
        }
    }

    $sQuery = 'SELECT COUNT(*) AS `count` FROM stoptexting WHERE postcount = 0';
    $sth = $oPDO->prepare($sQuery);
    if ($sth->execute() == FALSE) {
        die(sprintf('query failed: %s', $sQuery));
    }
    $aRecord = $sth->fetch(PDO::FETCH_ASSOC);
    $iPostCount = $aRecord['count'];

    $sMsg = sprintf('Done, inserted %d tweets into database out of %d submitted. %d total unposted tweets.', ($iSubmitCount - $iDupeCount), $iSubmitCount, $iPostCount);
}
?>
<html>
    <body>
        <h1>StopTextingAll tweet adder</h1>
        <p>Group tweets with a return, separate groups with double return</p>
        <?= (!empty($sMsg) ? '<p style="font-weight: bold;">' . $sMsg . '</p>' : '') ?>
        <form method="post">
            <textarea id="input" name="input" cols="70" rows="50" ondrop="window.setTimeout('clearSelection()', 100);"><?= (!empty($_POST['input']) ? $_POST['input'] : '') ?></textarea>
            <input type="submit">
        </form>
        <script type="text/javascript">
            function clearSelection() {
                el = document.getElementById('input');
                el.value += '\n';
                el.selectionStart = el.value.length;
            } 
        </script>
    </body>
</html>
