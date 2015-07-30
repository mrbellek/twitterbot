<?php
require_once('gameswithgold.inc.php');

$sError = '';
$sSuccess = '';

//array with platform possibilities
$aPlatforms = array(
    'Xbox 360' => 'xbox',
    'Xbox One' => 'xbox',
    'Playstation 3' => 'playstation',
    'Playstation 4' => 'playstation',
    'Playstation Vita' => 'playstation',
    'Playstation (cross-buy)' => 'playstation',
);

//connect to database
try {
    $oPDO = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME, DB_USER, DB_PASS);
} catch (Exception $e) {
    $sError = 'Database connection failed.';
}

if ($_POST && !$sError) {

    //check form password
    if ($_POST['password'] != FORM_PASS) {

        $sError = 'Wrong password.';

    } else {

        //delete record
        if (!empty($_POST['action']) && strtolower($_POST['action']) == 'delete') {

            if (!empty($_POST['id']) && is_numeric($_POST['id'])) {

                $sth = $oPDO->prepare('
                    DELETE
                    FROM gameswithgold
                    WHERE id = :id
                    LIMIT 1'
                );
                $sth->bindValue(':id', (int)$_POST['id']);

                if ($sth->execute()) {
                    $sSuccess = 'Game deleted from database.';
                    $aData = array();
                } else {
                    $sError = 'Failed to delete game from database.';
                    $aData = $_POST;
                }
            }

        //insert/update record
        } elseif (!empty($_POST['action']) && strtolower($_POST['action']) == 'save') {

            //validate stuff
            if (empty($_POST['game']) || empty($_POST['platform']) || empty($_POST['startdate']) || empty($_POST['enddate'])) {

                $sError = 'Missing fields.';

            } else {

                //everything ok
                if (!empty($_POST['id']) && is_numeric($_POST['id'])) {

                    //update query
                    $sth = $oPDO->prepare('
                        UPDATE gameswithgold
                        SET game = :game,
                            platform = :platform,
                            startdate = :startdate,
                            enddate = :enddate,
                            link = :link
                        WHERE id = :id
                        LIMIT 1'
                    );
                    $sth->bindValue(':game'     , $_POST['game']);
                    $sth->bindValue(':platform' , $_POST['platform']);
                    $sth->bindValue(':startdate', date('Y-m-d', strtotime($_POST['startdate'])));
                    $sth->bindValue(':enddate'  , date('Y-m-d', strtotime($_POST['enddate'])));
                    $sth->bindValue(':link'     , $_POST['link']);
                    $sth->bindValue(':id'       , (int)$_POST['id']);

                    if ($sth->execute()) {
                        $sSuccess = 'Game updated in database.';
                        $aData = array();
                    } else {
                        $sError = 'Failed to update game in database.';
                        $aData = $_POST;
                    }

                } else {

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
                        $aData = array();
                    } else {
                        $sError = 'Failed to add game to database.';
                        $aData = $_POST;
                    }
                }
            }
        }
    }

} elseif (!empty($_GET['id']) && is_numeric($_GET['id']) && !$sError) {
    
    //fetch record to edit
    $sth = $oPDO->prepare('
        SELECT *
        FROM gameswithgold
        WHERE id = :id
        LIMIT 1'
    );
    $sth->bindValue(':id', (int)$_GET['id']);
    if ($sth->execute()) {
        $aData = $sth->fetch(PDO::FETCH_ASSOC);
    }
}

//no form submit: list upcoming games
$aUpcomingGames = array();
$sth = $oPDO->prepare('
    SELECT *
    FROM gameswithgold
    WHERE enddate >= CURDATE()
    ORDER BY startdate, enddate, platform, game'
);
if ($sth->execute()) {
    $aUpcomingGames = $sth->fetchAll(PDO::FETCH_ASSOC);

    //make xbox games green color and playstation games blue color
    foreach ($aUpcomingGames as $key => $aGame) {
        $sPlatform = (!empty($aPlatforms[$aGame['platform']]) ? $aPlatforms[$aGame['platform']] : '');
        if ($sPlatform == 'xbox') {
            $aUpcomingGames[$key]['platformclass'] = 'success';
        } elseif ($sPlatform == 'playstation') {
            $aUpcomingGames[$key]['platformclass'] = 'info';
        }
    }
}

//no form submit: list some past games
$aPastGames = array();
$sth = $oPDO->prepare('
	SELECT *, PERIOD_DIFF(DATE_FORMAT(NOW(), "%Y%m"), DATE_FORMAT(enddate, "%Y%m")) AS months_ago
	FROM gameswithgold
	WHERE enddate < CURDATE()
	ORDER BY startdate, enddate, platform, game'
);
if ($sth->execute()) {
	$aPastGames = $sth->fetchAll(PDO::FETCH_ASSOC);

    //make xbox games green color and playstation games blue color
    foreach ($aPastGames as $key => $aGame) {
        $sPlatform = (!empty($aPlatforms[$aGame['platform']]) ? $aPlatforms[$aGame['platform']] : '');
        if ($sPlatform == 'xbox') {
            $aPastGames[$key]['platformclass'] = 'success';
        } elseif ($sPlatform == 'playstation') {
            $aPastGames[$key]['platformclass'] = 'info';
        }
    }
}
?>
<html>
    <head>
        <title>@XboxPSfreegames - add game</title>
        <link href="//maxcdn.bootstrapcdn.com/bootstrap/3.3.2/css/bootstrap.min.css" rel="stylesheet" type="text/css" />
        <script src="https://ajax.googleapis.com/ajax/libs/jquery/2.1.3/jquery.min.js"></script>

        <script type="text/javascript">
            $(function() {
                $('.confirm').on('click', function(e) {

                    if (!confirm('Are you sure you want to delete this record?')) {
                        e.preventDefault();
                        return false;
                    }
                });

				$('#search').on('input', function() {
					$('tr:not(.headers)', '#pastgames').each(function(k, row) {
						if ($('#search').val().trim().length > 0) {
							var game = $('.game', row);
							var platform = $('.platform', row);
							if (game.text().toLowerCase().indexOf($('#search').val()) > -1 || platform.text().toLowerCase().indexOf($('#search').val()) > -1) {
								$(row).removeClass('hidden');
							} else {
								$(row).addClass('hidden');
							}
						}
					});
				});
            });
        </script>
    </head>
    <body>
        <div class="container">
            <h1 class="col-md-offset-2"><a href="https://twitter.com/XboxPSfreegames" target="_blank">@XboxPSfreegames</a> - <?= (empty($_GET['id']) ? 'add' : 'edit') ?> game</h1>
            <?php if (!empty($sSuccess)) { ?><div role="alert" class="alert alert-success"><?= $sSuccess ?></div><?php } ?>
            <?php if (!empty($sError)) { ?><div role="alert" class="alert alert-danger"><?= $sError ?></div><?php } ?>
            <form method="post" action="gameswithgoldadd.php" class="form-horizontal" role="form">

                <?php if (!empty($_GET['id'])) { ?>
                    <input type="hidden" name="id" value="<?= (int)$_GET['id'] ?>" />
                <?php } ?>
                <div class="form-group">
                    <label for="game" class="col-sm-2 control-label">Game</label>
                    <div class="col-sm-10">
                        <input type="text" id="game" name="game" class="form-control" value="<?= @$aData['game'] ?>" required />
                    </div>
                </div>
                <div class="form-group">

                    <label for="platform" class="col-sm-2 control-label">Platform</label>
                    <div class="col-sm-10">
                        <select name="platform" id="platform" class="form-control">
                        <?php foreach (array_keys($aPlatforms) as $sPlatform) { ?>
                            <option value="<?= $sPlatform ?>" <?= (@$aData['platform'] == $sPlatform ? 'selected' : '') ?>><?= $sPlatform ?></option>
                        <?php } ?>
                        </select>
                    </div>
                </div>
                <div class="form-group">

                    <label for="startdate" class="col-sm-2 control-label">Start date</label>
                    <div class="col-sm-10">
                        <input type="date" name="startdate" id="startdate" class="form-control" value="<?= (!empty($aData['startdate']) ? $aData['startdate'] : date('Y-m-d')) ?>" required/>
                    </div>
                </div>
                <div class="form-group">

                    <label for="enddate" class="col-sm-2 control-label">End date</label>
                    <div class="col-sm-10">
                        <input type="date" name="enddate" id="enddate" class="form-control" value="<?= (!empty($aData['enddate']) ? $aData['enddate'] : date('Y-m-d', strtotime('+2 week'))) ?>" required />
                    </div>
                </div>
                <div class="form-group">

                    <label for="link" class="col-sm-2 control-label">Link</label>
                    <div class="col-sm-10">
                        <input type="text" name="link" id="link" class="form-control" value="<?= (!empty($aData['link']) ? $aData['link'] : '') ?>" />
                    </div>
                </div>
                <div class="form-group">

                    <label class="col-sm-2 control-label">Majornelson</label>
                    <div class="col-sm-10">
                        <p class="form-control-static">
                            <a href="http://majornelson.com/category/games-with-gold/" target="_blank">http://majornelson.com/category/games-with-gold/</a>
                        </p>
                    </div>

                    <label class="col-sm-2 control-label">Wikipedia Xbox</label>
                    <div class="col-sm-10">
                        <p class="form-control-static">
                            <a href="http://en.wikipedia.org/wiki/List_of_Games_with_Gold_games" target="_blank">http://en.wikipedia.org/wiki/List_of_Games_with_Gold_games</a>
                        </p>
                    </div>
                </div>
                <div class="form-group">

                    <label class="col-sm-2 control-label">Playstation Plus blog</label>
                    <div class="col-sm-10">
                        <p class="form-control-static">
                            <a href="http://blog.us.playstation.com/tag/playstation-plus/" target="_blank">http://blog.us.playstation.com/tag/playstation-plus/</a>
                        </p>
                    </div>

                    <label class="col-sm-2 control-label">Wikipedia PS</label>
                    <div class="col-sm-10">
                        <p class="form-control-static">
                            <a href="http://en.wikipedia.org/wiki/List_of_Instant_Game_Collection_games_(North_America)" target="_blank">http://en.wikipedia.org/wiki/List_of_Instant_Game_Collection_games_(North_America)</a><br/>
                            <a href="http://en.wikipedia.org/wiki/List_of_Instant_Game_Collection_games_(PAL_region)" target="_blank">http://en.wikipedia.org/wiki/List_of_Instant_Game_Collection_games_(PAL_region)</a>
                        </p>
                    </div>
                </div>
                <div class="form-group">

                    <label for="password" class="col-sm-2 control-label">Password</label>
                    <div class="col-sm-10">
                        <input type="password" name="password" id="password" class="form-control" required />
                    </div>
                </div>
                <div class="form-group">

                    <label for="link" class="col-sm-2 control-label">&nbsp;</label>
                    <div class="col-sm-10">
                        <input type="submit" name="action" value="Save" class="btn btn-primary" />
                        <?php if (!empty($_GET['id'])) { ?>
                            <a href="gameswithgoldadd.php" class="btn btn-default">Cancel</a>
                            <input type="submit" name="action" value="Delete" class="btn btn-danger confirm" />
                        <?php } ?>
                    </div>
                </div>
            </form>

            <?php if ($aUpcomingGames) { ?>
                <table class="table table-condensed table-hover">
                    <caption>Upcoming free games</caption>
                    <tr>
                        <th>Game</th><th>Platform</th><th>Start date</th><th>End date</th><th></th>
                    </tr>
                    <?php foreach ($aUpcomingGames as $aGame) { ?>
                        <tr class="table-striped <?= $aGame['platformclass'] ?>">
							<td>
								<?php if ($aGame['link']) { ?>
									<a href="<?= $aGame['link'] ?>" target="_blank"><?= $aGame['game'] ?></a>
								<?php } else { ?>
									<?= $aGame['game'] ?>
								<?php } ?>
							</td>
                            <td><?= $aGame['platform'] ?></td>
							<td>
								<?= ($aGame['startdate'] < date('Y-m-d') ? '<small><i class="glyphicon glyphicon-play"></i></small>' : '') ?>
								<?= $aGame['startdate'] ?>
							</td>
                            <td><?= $aGame['enddate'] ?></td>
                            <td><a href="?id=<?= $aGame['id']?>"><span class="glyphicon glyphicon-pencil"></span></a></td>
                        </tr>
                    <?php } ?>
                </table>
            <?php } ?>

            <?php if ($aPastGames) { ?>
                <table class="table table-condensed table-hover" id="pastgames">
                    <caption>Past free games</caption>
                    <tr class="headers">
						<th>
							<input type="text" id="search" placeholder="Search.." />
						</th>
						<th>Platform</th><th>End date</th><th></th>
                    </tr>
                    <?php foreach ($aPastGames as $aGame) { ?>
                        <tr class="table-striped hidden <?= $aGame['platformclass'] ?>">
							<td class="game">
								<?php if ($aGame['link']) { ?>
									<a href="<?= $aGame['link'] ?>" target="_blank"><?= $aGame['game'] ?></a>
								<?php } else { ?>
									<?= $aGame['game'] ?>
								<?php } ?>
							</td>
                            <td class="platform"><?= $aGame['platform'] ?></td>
                            <td><?= $aGame['enddate'] ?></td>
							<td><?= $aGame['months_ago'] ?> months ago</td>
                        </tr>
                    <?php } ?>
                </table>
            <?php } ?>
        </div>
    </body>
</html>
