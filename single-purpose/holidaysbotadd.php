<?php
/*
 * TODO:
 * - show dynamic holidays in 'today's/tomorrow's holidays' overviews
 * - pagination
 * - don't hardcode filename, replace with __FILE__
 * v check all holidays for unicode fuckups (search for ?, &)
 */
require_once('holidaysbot.inc.php');

$sError = '';
$sSuccess = '';

try {
	$oPDO = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME, DB_USER, DB_PASS);
} catch (Exception $e) {
	$sError = 'Database connection failed.';
}

$aParams = array();
if ($_POST && !$sError) {
	//add/edit holiday

	if ($_POST['password'] != FORM_PASSWORD) {

		$sError = 'Wrong password.';
		$aData = $_POST;

	} else {

		if (!empty($_POST['action']) && strtolower($_POST['action']) == 'delete') {
			//delete record
			$sth = $oPDO->prepare('
				DELETE FROM holidays
				WHERE id = :id
				LIMIT 1'
			);
			if ($sth->execute(array(
				'id' => (int)$_POST['id'],
			))) {
				$sSuccess = 'Holiday deleted.';
			} else {
				$sError = 'Holiday deleting failed.';
				$aData = $_POST;
			}
			unset($sth);

		} elseif (!empty($_POST['action']) && strtolower($_POST['action']) == 'save') {

			if (!empty($_POST['id'])) {
				//edit holiday
				$sth = $oPDO->prepare('
					UPDATE holidays
					SET name = :name,
						month = :month,
						day = :day,
						region = :region,
						country = :country,
						note = :note,
						dynamic = :dynamic,
						important = :important,
						url = :url
					WHERE id = :id
					LIMIT 1'
				);
				if ($sth->execute(array(
					'id'		=> $_POST['id'],
					'name'		=> $_POST['name'],
					'month'		=> $_POST['month'],
					'day'		=> $_POST['day'],
					'region'	=> $_POST['region'],
					'country'	=> $_POST['country'],
					'note'		=> $_POST['note'],
					'dynamic'	=> $_POST['dynamic'],
					'important'	=> (isset($_POST['important']) ? 1 : 0),
					'url'		=> $_POST['url'],
				))) {
					$sSuccess = 'Holiday edited.';
				} else {
					$sError = 'Holiday edit failed.';
					$aData = $_POST;
				}
			} else {
				//add holiday
				$sth = $oPDO->prepare('
					INSERT INTO holidays (name, month, day, region, country, note, dynamic, important, url)
					VALUES (:name, :month, :day, :region, :country, :note, :dynamic, :important, :url)'
				);
				if ($sth->execute(array(
					'name'		=> $_POST['name'],
					'month'		=> $_POST['month'],
					'day'		=> $_POST['day'],
					'region'	=> $_POST['region'],
					'country'	=> $_POST['country'],
					'note'		=> $_POST['note'],
					'dynamic'	=> $_POST['dynamic'],
					'important'	=> (isset($_POST['important']) ? 1 : 0),
					'url'		=> $_POST['url'],
				))) {
					$sSuccess = 'Holiday added.';
				} else {
					$sError = 'Holiday add failed.';
					$aData = $_POST;
				}
			}
		}
		unset($sth);
	}

} elseif (!empty($_GET['id']) && is_numeric($_GET['id']) && !$sError) {

	//display single holiday
	$sth = $oPDO->prepare('SELECT * FROM holidays WHERE id = :id LIMIT 1');
	$sth->execute(array(
		'id' => (int)$_GET['id']
	));
	$aData = $sth->fetch(PDO::FETCH_ASSOC);
	unset($sth);

} elseif (!empty($_GET['search']) && !$sError) {
	//display holidays matching search queries
	$sth = $oPDO->prepare('
		SELECT *
		FROM holidays
		WHERE name LIKE :search
		OR region LIKE :search
		OR country LIKE :search
		OR note LIKE :search
		OR dynamic LIKE :search'
	);
	$aParams = array(
		'search' => '%' . $_GET['search'] . '%',
	);
	$sSearch = $_GET['search'];
	
} elseif (isset($_GET['today']) && !$sError) {
	//display today's holidays
	$sth = $oPDO->prepare('
		SELECT * FROM holidays
		WHERE month = :month
		AND day = :day'
	);
	$aParams = array(
		'month' => date('m'),
		'day' => date('d'),
	);
} elseif (isset($_GET['tomorrow']) && !$sError) {
	//display tomorrow's holidays
	$sth = $oPDO->prepare('
		SELECT * FROM holidays
		WHERE month = :month
		AND day = :day'
	);
	$aParams = array(
		'month' => date('m', time() + 24 * 3600),
		'day' => date('d', time() + 24 * 3600),
	);
}

if (!isset($sth)) {

	//no form submit or arg: list raw holidays
	$sth = $oPDO->prepare('
		SELECT * FROM holidays'
	);
}

if ($sth->execute($aParams)) {
	$aHolidays = $sth->fetchAll(PDO::FETCH_ASSOC);
}
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
			<h1 class="col-md->offset-2"><a href="https://twitter.com/HolidaysBot" target="_blank">@HolidaysBot</a> - <?= (empty($_GET['id']) ? 'add' : 'edit') ?> holiday</h1>
            <?php if (!empty($sSuccess)) { ?><div role="alert" class="alert alert-success"><?= $sSuccess ?></div><?php } ?>
            <?php if (!empty($sError)) { ?><div role="alert" class="alert alert-danger"><?= $sError ?></div><?php } ?>
            <form method="post" action="holidaysbotadd.php" class="form-horizontal" role="form">

                <?php if (!empty($_GET['id'])) { ?>
                    <input type="hidden" name="id" value="<?= (int)$_GET['id'] ?>" />
                <?php } ?>

				<div class="form-group">
					<label for="name" class="col-sm-2 control-label">Name</label>
					<div class="col-sm-10">
						<input type="text" id="name" name="name" class="form-control" value="<?= @$aData['name'] ?>" required />
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
						<?php for ($i = 1; $i <= 31; $i++) { ?>
							<option value="<?= $i ?>" <?= ($i == @$aData['day'] ? "selected" : "") ?>><?= $i ?></option>
						<?php } ?>
						</select>
					</div>
				</div>

				<div class="form-group">
					<label for="region" class="col-sm-2 control-label">Region</label>
					<div class="col-sm-10">
						<input type="text" id="region" name="region" class="form-control" value="<?= @$aData['region'] ?>" />
					</div>
				</div>

				<div class="form-group">
					<label for="country" class="col-sm-2 control-label">Country</label>
					<div class="col-sm-10">
						<input type="text" id="country" name="country" class="form-control" value="<?= @$aData['country'] ?>" />
					</div>
				</div>

				<div class="form-group">
					<label for="note" class="col-sm-2 control-label">Note</label>
					<div class="col-sm-10">
						<input type="text" id="note" name="note" class="form-control" value="<?= @$aData['note'] ?>" />
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
						<?php if (!empty($_GET['id'])) { ?>
							<a href="holidaysbotadd.php" class="btn btn-default">Cancel</a>
							<input type="submit" name="action" value="Delete" class="btn btn-danger confirm" />
						<?php } ?>
					</div>
				</div>
			</form>

            <form method="get" action="holidaysbotadd.php" class="form-horizontal" role="form">

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
						<?php if (@$sSearch || isset($_GET['today']) || isset($_GET['tomorrow'])) { ?>
							<a href="holidaysbotadd.php" class="btn btn-danger">Reset</a>
						<?php } ?>
						<a href="holidaysbotadd.php?today" class="btn btn-default">Today's holidays</a>
						<a href="holidaysbotadd.php?tomorrow" class="btn btn-default">Tomorrow's holidays</a>
					</div>
				</div>
			</form>

			<table class="table table-condensed table-hover">
				<tr>
					<th>Holiday (<?= count($aHolidays) ?>)</th>
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
								<a href="<?= $aHoliday['url'] ?>" target="_blank"><?= $aHoliday['name'] ?></a>
							<?php } else { ?>
								<?= $aHoliday['name'] ?>
							<?php } ?>
						</td>
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
		</div>
	</body>
</html>
