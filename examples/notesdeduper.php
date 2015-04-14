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
	$iNotesCount = count($aNotes);
    printf('checking %d notes for duplicates in database..' . "\r\n", count($aNotes));
    $i = 0;
    foreach ($aNotes as $key => $sNote) {
        $sTransaction = substr(substr($sNote, strpos($sNote, '","') + 3), 0, -1);
        $sNoteText = substr($sNote, 1, strpos($sNote, '","') - 1);
        if (substr($sNoteText, 0, 1) == '@') {
            $sNoteText = '.' . $sNoteText;
        }

        //check by transaction id and note text
        $sQuery = '
            SELECT id
            FROM notes
            WHERE tx = :tx
            OR note = :note
            LIMIT 1';
        $sth = $oPDO->prepare($sQuery);
        $sth->bindParam(':tx', $sTransaction, PDO::PARAM_STR);
        $sth->bindParam(':note', $sNoteText, PDO::PARAM_STR);

        if ($sth->execute() == FALSE) {
            die(sprintf('query failed: %s', $sQuery));
        }

        if ($sth->fetch()) {
            //printf('note already in database: %s' . "\r\n", $sNote);
            unset($aNotes[$key]);
            $i++;
        }
    }
    printf('done, %d out of %d notes already in database</pre>' . "\r\n", $i, $iNotesCount);

    //convert the whole thing into a query for inserting into database
    $aQuery = array('insert into notes(note, tx) values');
    foreach ($aNotes as $sNote) {
        if ($sNote) {
            if (substr($sNote, 1, 1) == '@') {
                $sNote = '".' . substr($sNote, 1);
            }

            $aQuery[] = '(' . $sNote . '),';
        }
    }
    $aQuery[count($aQuery) - 1] = rtrim($aQuery[count($aQuery) - 1], ',') . ';';
}
?>
<html>
    <head><title>NotesFromBTC dupecheck</title></head>
    <body>
        <form method="post">
        <textarea cols="200" rows="40" name="notes"><?= (!empty($aNotes) ? htmlentities(implode("\r\n", $aNotes)) : '') ?></textarea>
            <br />
            <input type="submit" value="CHECK" />
        </form>
        <pre><?= (!empty($aQuery) ? htmlentities(implode("\r\n", $aQuery)) : '') ?></pre>
    </body>
</html>
