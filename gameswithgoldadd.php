<?php
require_once('gameswithgold.inc.php');

if ($_POST) {

    $sError = '';
    $sSuccess = '';

    if (empty($_POST['game']) || empty($_POST['platform']) || empty($_POST['startdate']) || empty($_POST['enddate'])) {

        $sError = 'Missing fields.';

    } elseif ($_POST['password'] != FORM_PASS) {

        $sError = 'Wrong password.';

    } else {

        //connect to database
        try {
            $oPDO = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME, DB_USER, DB_PASS);
        } catch (Exception $e) {
            $sError = 'Database connection failed.';
        }

        if (!$sError) {

            //insert query
            $sth = $oPDO->prepare('
                INSERT INTO gameswithgold (game, platform, startdate, enddate, link)
                VALUES (:game, :platform, :startdate, :enddate, :link)'
            );
            $sth->bindValue(':game'     , $_POST['game']);
            $sth->bindValue(':platform' , $_POST['platform']);
            $sth->bindValue(':startdate', date('Y-m-d', strtotime($_POST['startdate'])));
            $sth->bindValue(':enddate'  , date('Y-m-d', strtotime($_POST['enddate'])));
            $sth->bindValue(':link'     , $_POST['link']);

            if ($sth->execute()) {
                $sSuccess = 'Game added to database.';
                $_POST = array();
            } else {
                $sError = 'Failed to add game to database.';
            }
        }
    }
}

?>
<html>
    <head>
        <title>@GamesWithGold - add game</title>
        <link href="//maxcdn.bootstrapcdn.com/bootstrap/3.3.2/css/bootstrap.min.css" rel="stylesheet" type="text/css" />
    </head>
    <body>
        <div class="container">
            <h1 class="col-md-offset-2"><a href="https://twitter.com/GamesWithGold" target="_blank">@GamesWithGold</a> - add game</h1>
            <?php if (!empty($sSuccess)) { ?><div role="alert" class="alert alert-success"><?= $sSuccess ?></div><?php } ?>
            <?php if (!empty($sError)) { ?><div role="alert" class="alert alert-danger"><?= $sError ?></div><?php } ?>
            <form method="post" class="form-horizontal" role="form">
                <div class="form-group">
                    <label for="game" class="col-sm-2 control-label">Game</label>
                    <div class="col-sm-10">
                        <input type="text" id="game" name="game" class="form-control" value="<?= @$_POST['game'] ?>" required />
                    </div>
                </div>
                <div class="form-group">

                    <label for="platform" class="col-sm-2 control-label">Platform</label>
                    <div class="col-sm-10">
                        <select name="platform" id="platform" class="form-control">
                            <option value="Xbox 360" <?php (@$_POST['platform'] == 'Xbox 360' ? 'selected' : '') ?>>Xbox 360</option>
                            <option value="Xbox One" <?php (@$_POST['platform'] == 'Xbox One' ? 'selected' : '') ?>>Xbox One</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">

                    <label for="startdate" class="col-sm-2 control-label">Start date</label>
                    <div class="col-sm-10">
                        <input type="date" name="startdate" id="startdate" class="form-control" value="<?= (!empty($_POST['startdate']) ? $_POST['startdate'] : date('Y-m-d')) ?>" required/>
                    </div>
                </div>
                <div class="form-group">

                    <label for="enddate" class="col-sm-2 control-label">End date</label>
                    <div class="col-sm-10">
                        <input type="date" name="enddate" id="enddate" class="form-control" value="<?= (!empty($_POST['enddate']) ? $_POST['enddate'] : date('Y-m-d', strtotime('+2 week'))) ?>" required />
                    </div>
                </div>
                <div class="form-group">

                    <label for="link" class="col-sm-2 control-label">Link</label>
                    <div class="col-sm-10">
                        <input type="text" name="link" id="link" class="form-control" value="<?= (!empty($_POST['link']) ? $_POST['link'] : '') ?>" />
                    </div>
                </div>
                <div class="form-group">

                    <label for="password" class="col-sm-2 control-label">Password</label>
                    <div class="col-sm-10">
                        <input type="password" name="password" id="password" class="form-control" required />
                    </div>
                </div>
                <div class="form-group">

                    <label class="col-sm-2 control-label">Wikipedia</label>
                    <div class="col-sm-10">
                        <p class="form-control-static">
                            <a href="http://en.wikipedia.org/wiki/List_of_Games_with_Gold_games" target="_blank">http://en.wikipedia.org/wiki/List_of_Games_with_Gold_games</a>
                        </p>
                    </div>

                    <label class="col-sm-2 control-label">Majornelson</label>
                    <div class="col-sm-10">
                        <p class="form-control-static">
                            <a href="http://majornelson.com/category/games-with-gold/" target="_blank">http://majornelson.com/category/games-with-gold/</a>
                        </p>
                    </div>
                </div>
                <div class="form-group">

                    <label for="link" class="col-sm-2 control-label">&nbsp;</label>
                    <div class="col-sm-10">
                        <input type="submit" value="Save" class="btn btn-primary " />
                    </div>
                </div>
            </form>
        </div>
    </body>
</html>
