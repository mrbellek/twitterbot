<?php
/*
 * TODO:
 * - show dynamic holidays in 'today's/tomorrow's holidays' overviews
 * v pagination
 * v don't hardcode filename, replace with __FILE__
 * v check all holidays for unicode fuckups (search for ?, &)
 */
require_once('autoload.php');
require_once('holidaysbot.inc.php');

use Twitterbot\Lib\Config;
use Twitterbot\Lib\Database;

$oConfig = new Config;
$oConfig->load('HolidaysBot');
$oDatabase = new Database($oConfig);

$sThisFile = pathinfo(__FILE__, PATHINFO_BASENAME);

$sError = '';
$sSuccess = '';
$aHolidays = array();

$iPage = (!empty(filter_input(INPUT_GET, 'page')) ? filter_input(INPUT_GET, 'page', FILTER_SANITIZE_NUMBER_INT) : 1);
$iPerPage = 100;
$iOffset = ($iPage - 1) * $iPerPage;

$aParams = array();
if ($_POST && !$sError) {
	//add/edit holiday

	if (filter_input(INPUT_POST, 'password', FILTER_SANITIZE_STRING) != FORM_PASSWORD) {

		$sError = 'Wrong password.';
		$aData = $_POST;

	} else {

		if (!empty(filter_input(INPUT_POST, 'action')) && strtolower(filter_input(INPUT_POST, 'action', FILTER_SANITIZE_STRING)) == 'delete') {
			//delete record
			if ($oDatabase->query('
				DELETE FROM holidays
				WHERE id = :id
				LIMIT 1',
				array('id' => filter_input(INPUT_POST, 'id', FILTER_SANITIZE_NUMBER_INT))
			)) {
				$sSuccess = 'Holiday deleted.';
			} else {
				$sError = 'Holiday deleting failed.';
				$aData = $_POST;
			}
			unset($sth);

		} elseif (!empty(filter_input(INPUT_POST, 'action')) && strtolower(filter_input(INPUT_POST, 'action', FILTER_SANITIZE_STRING)) == 'save') {

			if (!empty(filter_input(INPUT_POST, 'id'))) {
				//edit holiday
				if ($oDatabase->query('
					UPDATE holidays
					SET name = :name,
                        year = NULLIF(:year, 0),
						month = :month,
						day = :day,
						region = :region,
						country = :country,
						note = :note,
						dynamic = :dynamic,
						important = :important,
						url = :url
					WHERE id = :id
					LIMIT 1',
					array(
						'id'		=> filter_input(INPUT_POST, 'id', FILTER_SANITIZE_NUMBER_INT),
						'name'		=> utf8_decode(filter_input(INPUT_POST, 'name', FILTER_SANITIZE_STRING)),
						'year'		=> filter_input(INPUT_POST, 'year', FILTER_SANITIZE_NUMBER_INT),
						'month'		=> filter_input(INPUT_POST, 'month', FILTER_SANITIZE_NUMBER_INT),
						'day'		=> filter_input(INPUT_POST, 'day', FILTER_SANITIZE_NUMBER_INT),
						'region'	=> utf8_decode(filter_input(INPUT_POST, 'region', FILTER_SANITIZE_STRING)),
						'country'	=> utf8_decode(filter_input(INPUT_POST, 'country', FILTER_SANITIZE_STRING)),
						'note'		=> utf8_decode(filter_input(INPUT_POST, 'note', FILTER_SANITIZE_STRING)),
						'dynamic'	=> filter_input(INPUT_POST, 'dynamic', FILTER_SANITIZE_STRING),
						'important'	=> filter_input(INPUT_POST, 'important', FILTER_SANITIZE_NUMBER_INT),
						'url'		=> filter_input(INPUT_POST, 'url', FILTER_SANITIZE_URL),
					)
				)) {
					$sSuccess = 'Holiday edited.';
				} else {
					$sError = 'Holiday edit failed.';
					$aData = $_POST;
				}
			} else {
				//add holiday
				if ($oDatabase->query('
					INSERT INTO holidays (name, year, month, day, region, country, note, dynamic, important, url)
					VALUES (:name, NULLIF(:year, 0), :month, :day, :region, :country, :note, :dynamic, :important, :url)',
					array(
						'name'		=> utf8_decode(filter_input(INPUT_POST, 'name', FILTER_SANITIZE_STRING)),
						'year'		=> filter_input(INPUT_POST, 'year', FILTER_SANITIZE_NUMBER_INT),
						'month'		=> filter_input(INPUT_POST, 'month', FILTER_SANITIZE_NUMBER_INT),
						'day'		=> filter_input(INPUT_POST, 'day', FILTER_SANITIZE_NUMBER_INT),
						'region'	=> utf8_decode(filter_input(INPUT_POST, 'region', FILTER_SANITIZE_STRING)),
						'country'	=> utf8_decode(filter_input(INPUT_POST, 'country', FILTER_SANITIZE_STRING)),
						'note'		=> utf8_decode(filter_input(INPUT_POST, 'note', FILTER_SANITIZE_STRING)),
						'dynamic'	=> filter_input(INPUT_POST, 'dynamic', FILTER_SANITIZE_STRING),
						'important'	=> filter_input(INPUT_POST, 'important', FILTER_SANITIZE_NUMBER_INT),
						'url'		=> filter_input(INPUT_POST, 'url', FILTER_SANITIZE_URL),
					)
				)) {
					$sSuccess = 'Holiday added.';
				} else {
					$sError = 'Holiday add failed.';
					$aData = $_POST;
				}
			}
		}
		unset($sth);
	}

} elseif (!empty(filter_input(INPUT_GET, 'id')) && is_numeric(filter_input(INPUT_GET, 'id')) && !$sError) {

	//display single holiday
	$aData = $oDatabase->query('
		SELECT SQL_CALC_FOUND_ROWS *
		FROM holidays
		WHERE id = :id
		LIMIT 1',
		array('id' => filter_input(INPUT_GET, 'id', FILTER_SANITIZE_NUMBER_INT))
	);
    $aData = $aData[0];

} elseif (!empty(filter_input(INPUT_GET, 'search')) && !$sError) {
	//display holidays matching search queries
	$aHolidays = $oDatabase->query('
		SELECT SQL_CALC_FOUND_ROWS *
		FROM holidays
		WHERE name LIKE :search
        OR year LIKE :search
		OR region LIKE :search
		OR country LIKE :search
		OR note LIKE :search
		OR dynamic LIKE :search
		ORDER BY month, day, name
		LIMIT :offset, :perpage',
		array(
			'search' => '%' . filter_input(INPUT_GET, 'search', FILTER_SANITIZE_STRING) . '%',
			'offset' => $iOffset,
			'perpage' => $iPerPage,
		)
	);

	$sSearch = filter_input(INPUT_GET, 'search', FILTER_SANITIZE_STRING);
	
} elseif (filter_input(INPUT_GET, 'today') && !$sError) {
	//display today's holidays
	$aHolidays = $oDatabase->query('
		SELECT SQL_CALC_FOUND_ROWS * FROM holidays
		WHERE month = :month
		AND day = :day
        AND (year = YEAR(CURDATE()) OR year IS NULL OR year = 0)
		ORDER BY name
		LIMIT :offset, :perpage',
		array(
			'month' => date('m'),
			'day' => date('d'),
			'offset' => $iOffset,
			'perpage' => $iPerPage,
		)
	);
} elseif (filter_input(INPUT_GET, 'tomorrow') && !$sError) {
	//display tomorrow's holidays
	$aHolidays = $oDatabase->query('
		SELECT SQL_CALC_FOUND_ROWS * FROM holidays
		WHERE month = :month
		AND day = :day
        AND (year = YEAR(CURDATE()) OR year IS NULL OR year = 0)
		ORDER BY name
		LIMIT :offset, :perpage',
		array(
			'month' => date('m', time() + 24 * 3600),
			'day' => date('d', time() + 24 * 3600),
			'offset' => $iOffset,
			'perpage' => $iPerPage,
		)
	);
}

if (!$aHolidays && empty($sSearch)) {
	$aHolidays = $oDatabase->query('
		SELECT SQL_CALC_FOUND_ROWS *
		FROM holidays
		ORDER BY month, day, name
		LIMIT :offset, :perpage',
		array(
			'offset' => $iOffset,
			'perpage' => $iPerPage,
		)
	);
}

$aCount = $oDatabase->query('SELECT FOUND_ROWS()', array());
$iCount = $aCount[0]['FOUND_ROWS()'];

?><!DOCTYPE html>
<html>
	<head>
		<title>@HolidaysBot - manage holidays</title>
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
			});
		</script>
	</head>
	<body>
		<div class="container">
			<h1 class="col-md->offset-2"><a href="https://twitter.com/HolidaysBot" target="_blank">@HolidaysBot</a> - <?= (empty(filter_input(INPUT_GET, 'id', FILTER_SANITIZE_NUMBER_INT)) ? 'add' : 'edit') ?> holiday</h1>
            <?php if (!empty($sSuccess)) { ?><div role="alert" class="alert alert-success"><?= $sSuccess ?></div><?php } ?>
            <?php if (!empty($sError)) { ?><div role="alert" class="alert alert-danger"><?= $sError ?></div><?php } ?>
			<form method="post" action="<?= $sThisFile ?>" class="form-horizontal" role="form">

                <?php if (!empty(filter_input(INPUT_GET, 'id'))) { ?>
                    <input type="hidden" name="id" value="<?= filter_input(INPUT_GET, 'id', FILTER_INPUT_SANITIZE_NUMBER_INT) ?>" />
                <?php } ?>

				<div class="form-group">
					<label for="name" class="col-sm-2 control-label">Name</label>
					<div class="col-sm-10">
						<input type="text" id="name" name="name" class="form-control" value="<?= @utf8_encode($aData['name']) ?>" required />
					</div>
				</div>

				<div class="form-group">
					<label for="year" class="col-sm-2 control-label">Year</label>
					<div class="col-sm-10">
                        <input type="text" id="year" name="year" class="form-control" value="<?= (@$aData['year'] > 0 ? $aData['year'] : '') ?>" />
					</div>
				</div>

				<div class="form-group">
					<label for="month" class="col-sm-2 control-label">Month</label>
					<div class="col-sm-10">
						<select id="month" name="month" class="form-control">
						<?php for($i = 1; $i <= 12; $i++) { ?>
							<option value="<?= $i ?>" <?= ($i == @$aData['month'] ? "selected" : "") ?>><?= date('F', mktime(0, 0, 0, $i)) ?></option>
						<?php } ?>
						</select>
					</div>
				</div>

				<div class="form-group">
					<label for="" class="col-sm-2 control-label">Day</label>
					<div class="col-sm-10">
						<select id="day" name="day" class="form-control">
						<?php for ($i = 0; $i <= 31; $i++) { ?>
							<option value="<?= $i ?>" <?= ($i == @$aData['day'] ? "selected" : "") ?>><?= $i ?></option>
						<?php } ?>
						</select>
					</div>
				</div>

				<div class="form-group">
					<label for="region" class="col-sm-2 control-label">Region</label>
					<div class="col-sm-10">
						<input type="text" id="region" name="region" class="form-control" value="<?= @utf8_encode($aData['region']) ?>" />
					</div>
				</div>

				<div class="form-group">
					<label for="country" class="col-sm-2 control-label">Country</label>
					<div class="col-sm-10">
						<input type="text" id="country" name="country" class="form-control" value="<?= @utf8_encode($aData['country']) ?>" />
					</div>
				</div>

				<div class="form-group">
					<label for="note" class="col-sm-2 control-label">Note/by</label>
					<div class="col-sm-10">
						<input type="text" id="note" name="note" class="form-control" value="<?= @utf8_encode($aData['note']) ?>" />
					</div>
				</div>

				<div class="form-group">
					<label for="dynamic" class="col-sm-2 control-label">Dynamic</label>
					<div class="col-sm-10">
						<input type="text" id="dynamic" name="dynamic" class="form-control" value="<?= @$aData['dynamic'] ?>" />
					</div>
				</div>

				<div class="form-group">
					<div class="col-sm-offset-2 col-sm-10">
						<div class="checkbox">
							<label for="important">
								<input type="checkbox" id="important" name="important" <?= (@$aData['important'] ? "checked" : "") ?>> Important
							</label>
						</div>
					</div>
				</div>

				<div class="form-group">
					<label for="url" class="col-sm-2 control-label">
						<?php if (@$aData['url']) { ?>
							<a href="<?= @$aData['url'] ?>" target="_blank">URL</a>
						<?php } else { ?>
							URL
						<?php } ?>
					</label>
					<div class="col-sm-10">
						<input type="text" id="url" name="url" class="form-control" value="<?= @$aData['url'] ?>" />
					</div>
				</div>

				<div class="form-group">
					<label for="password" class="col-sm-2 control-label">Password</label>
					<div class="col-sm-10">
						<input type="password" id="password" name="password" class="form-control" />
					</div>
				</div>

				<div class="form-group">
					<label class="col-sm-2 control-label">&nbsp;</label>
					<div class="col-sm-10">
						<input type="submit" name="action" value="Save" class="btn btn-primary" />
						<?php if (!empty(filter_input(INPUT_GET, 'id'))) { ?>
							<a href="<?= $sThisFile ?>" class="btn btn-default">Cancel</a>
							<input type="submit" name="action" value="Delete" class="btn btn-danger confirm" />
						<?php } ?>
					</div>
				</div>
			</form>

            <form method="get" action="<?= $sThisFile ?>" class="form-horizontal" role="form">

				<div class="form-group">
					<label for="search" class="col-sm-2 control-label">Search</label>
					<div class="col-sm-10">
						<input type="text" id="search" name="search" class="form-control" value="<?= @$sSearch ?>" />
					</div>
				</div>

				<div class="form-group">
					<label class="col-sm-2 control-label">&nbsp;</label>
					<div class="col-sm-10">
						<input type="submit" name="action" value="Search" class="btn btn-primary" />
						<?php if (@$sSearch || filter_input(INPUT_GET, 'today') || filter_input(INPUT_GET, 'tomorrow')) { ?>
							<a href="<?= $sThisFile ?>" class="btn btn-danger">Reset</a>
						<?php } ?>
						<a href="<?= $sThisFile ?>?today=1" class="btn btn-default">Today's holidays</a>
						<a href="<?= $sThisFile ?>?tomorrow=1" class="btn btn-default">Tomorrow's holidays</a>
					</div>
				</div>
			</form>

			<table class="table table-condensed table-hover">
				<tr>
					<th>Holiday (<?= $iCount ?>)</th>
                    <th>Year</th>
					<th>Month</th>
					<th>Day</th>
					<th>Region</th>
					<th>Country</th>
					<th>Note</th>
					<th>Dynamic</th>
					<th>Prio</th>
					<th></th>
				</tr>
				<?php foreach ($aHolidays as $aHoliday) { ?>
				<tr class="table-striped <?= ($aHoliday['important'] ? "bg-info" : "") ?> <?= ($aHoliday['dynamic'] ? "bg-warning" : "") ?>">
						<td>
							<?php if ($aHoliday['url']) { ?>
								<a href="<?= $aHoliday['url'] ?>" target="_blank"><?= utf8_encode($aHoliday['name']) ?></a>
							<?php } else { ?>
								<?= utf8_encode($aHoliday['name']) ?>
							<?php } ?>
						</td>
						<td><?= $aHoliday['year'] ?></td>
						<td><?= $aHoliday['month'] ?></td>
						<td><?= $aHoliday['day'] ?></td>
						<td><?= $aHoliday['region'] ?></td>
						<td><?= $aHoliday['country'] ?></td>
						<td><?= $aHoliday['note'] ?></td>
						<td><?= $aHoliday['dynamic'] ?></td>
						<td><?= ($aHoliday['important'] ? 'yes' : '') ?></td>
						<td><a href="?id=<?= $aHoliday['id'] ?>"><span class="glyphicon glyphicon-pencil"></span></a></td>
					</tr>
				<?php } ?>
			</table>

			<?php if ($iCount > $iPerPage) { ?>
			<nav style="text-align: center;">
				<ul class="pagination">
				<?php for ($i = 1; $i <= ceil($iCount / $iPerPage); $i++) { ?>
					<li <?= ($i == $iPage ? 'class="active"' : '') ?>>
						<a href="<?= $sThisFile ?>?page=<?= $i ?><?= (@$sSearch ? "&search=" . $sSearch : "") ?>"><?= $i ?></a>
					</li>
				<?php } ?>
				</ul>
			</nav>
			<?php } ?>
		</div>
	</body>
</html>
