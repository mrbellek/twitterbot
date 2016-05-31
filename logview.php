<?php
require_once('logger.php');

if (!empty($_POST['search'])) {
	$sSearch = $_POST['search'];
	$aItems = TwitterLogger::search($sSearch);
} else {
	$aItems = TwitterLogger::view();
}

?><!DOCTYPE html>
<html>
	<head>
		<title>Twitter bots log</title>
        <link href="//maxcdn.bootstrapcdn.com/bootstrap/3.3.2/css/bootstrap.min.css" rel="stylesheet" type="text/css" />
        <script src="https://ajax.googleapis.com/ajax/libs/jquery/2.1.3/jquery.min.js"></script>
		<script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.2/js/bootstrap.min.js"></script>
	</head>
	<body>
		<div class="container">
			<h1 style="margin-bottom: 1em;">Twitter bots log</h1>
			<form method="post" action="logview.php" class="form-horizontal" role="form">
				<?php if (!empty($sSearch)) { ?>
					<input type="hidden" name="search" value="<?= htmlentities($sSearch) ?>" />
				<?php } ?>
				<label for="search" class="col-sm-1 control-label">Search</label>
				<div class="col-sm-10" style="margin-bottom: 2em;">
					<input type="text" id="search" name="search" class="form-control" value="<?= @$sSearch ?>" />
				</div>
				<div class="col-sm-1">
					<input type="submit" class="btn btn-info" value="Search" />
				</div>
			</form>

			<table class="table table-condensed table-hover">
				<tr>
					<th>Bot</th>
					<th>Error</th>
					<th>Source</th>
					<th>Line</th>
					<th>File</th>
					<th>Timestamp</th>
				</tr>
				<?php foreach ($aItems as $aRow) { ?>
					<tr class="table-striped">
						<td><a href="https://twitter.com/<?= $aRow['botname'] ?>" target="_blank"><?= $aRow['botname'] ?></a></td>
						<td><?= $aRow['error'] ?></td>
						<td>
						<?php if (!empty($aRow['source'])) { ?>
							<a data-toggle="collapse" href="#source<?= $aRow['id'] ?>">show</a>
						<?php } ?>
						</td>
						<td><?= $aRow['line'] ?></td>
						<td><?= $aRow['file'] ?></td>
						<td><?= $aRow['timestamp'] ?></td>
					</tr>
					<?php if (!empty($aRow['source'])) { ?>
						<tr class="collapse" id="source<?= $aRow['id'] ?>">
							<td colspan="6"><?= htmlentities($aRow['source']) ?></td>
						</tr>
					<?php } ?>
				<?php } ?>
			</table>
		</div>
	</body>
</html>
