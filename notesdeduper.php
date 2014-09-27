<?php
if ($_POST) {
    //require_once('./notesfrombtc.inc.php');
    require_once('./bitcoinnotes.inc.php');

    echo '<pre>';
    try {
        $oPDO = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME, DB_USER, DB_PASS);
    } catch(Exception $e) {
        die('db connection failed');
    }

    $aNotes = explode("\r\n", $_POST['notes']);
    printf('checking %d notes for duplicates in database..' . "\r\n", count($aNotes));
    $i = 0;
    foreach ($aNotes as $key => $sNote) {
        $sTransaction = substr(substr($sNote, strpos($sNote, '","') + 3), 0, -1);
        $sNoteText = substr($sNote, 1, strpos($sNote, '","') - 1);

        //check by transaction id and note text
        $sth = $oPDO->prepare('
            SELECT id
            FROM notes
            WHERE tx = :tx
            OR note = :note
            LIMIT 1'
        );
        $sth->bindParam(':tx', $sTransaction, PDO::PARAM_STR);
        $sth->bindParam(':note', $sNoteText, PDO::PARAM_STR);

        if ($sth->execute() == FALSE) {
            die(sprintf('query failed: %s', $sQuery));
        }

        if ($sth->fetch()) {
            printf('note already in database: %s' . "\r\n", $sNote);
            unset($aNotes[$key]);
            $i++;
        }
    }
    printf('done, %d out of %d notes already in database</pre>' . "\r\n", $i, count($aNotes));

}
?>
<html>
    <head><title>NotesFromBTC dupecheck</title></head>
    <body>
        <form method="post">
        <textarea cols="200" rows="40" name="notes"><?= (!empty($aNotes) ? implode("\r\n", $aNotes) : '') ?></textarea>
            <br />
            <input type="submit" value="CHECK" />
        </form>
    </body>
</html>
