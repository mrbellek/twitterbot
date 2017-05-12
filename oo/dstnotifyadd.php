<?php
/**
 * TODO:
 * . browse/manage groups, countries, includes, excludes, aliases
 * - refactor into classes
 */

require('autoload.php');
require('dstnotify.inc.php');

use Twitterbot\Lib\Config;
use Twitterbot\Lib\Database;

$oConfig = new Config;
$oConfig->load('DSTnotify');
$db = new Database($oConfig);

$argv = [1 => 'test'];
require('dstnotify.php');
$oBot = new DSTnotify();
$oBot->runTest();

$preview = '';

//GROUPS
if (($show = filter_input(INPUT_GET, 'show', FILTER_SANITIZE_STRING)) == 'group') {
    $groups = getGroups();
    if ($id = filter_input(INPUT_GET, 'id', FILTER_SANITIZE_NUMBER_INT)) {
        //show group
        $action = 'edit group';
        $group = getGroup($id);
        $countries = getGroupCountries($id);
        $excludes = getGroupExcludes($id);
        $error = $group ? '' : 'Group not found.';
        $preview = $oBot->formatTweetDST('start', [$group['shortname'] => ['name' => $group['name']]], 'tomorrow');
    } else {
        //show all groups
        $action = 'view groups';
        $countries = [];
        $excludes = [];

        if ($deleted = filter_input(INPUT_GET, 'deleted', FILTER_SANITIZE_NUMBER_INT)) {
            $success = sprintf('Group %d deleted.', $deleted);
        }
    }

//COUNTRIES
} elseif (($show = filter_input(INPUT_GET, 'show', FILTER_SANITIZE_STRING)) == 'country') {
    $countries = getCountries();
    $groups = getGroups();
    if ($id = filter_input(INPUT_GET, 'id', FILTER_SANITIZE_NUMBER_INT)) {
        //show country
        $action = 'edit country';
        $country = getCountry($id);
        $error = $country ? '' : 'Country not found.';
        $preview = $oBot->formatTweetDST('start', [$country['group'] => ['name' => $country['name']]], 'tomorrow');
    } else {
        //show all countries
        $action = 'view countries';

        if ($deleted = filter_input(INPUT_GET, 'deleted', FILTER_SANITIZE_NUMBER_INT)) {
            $success = sprintf('Country %d deleted.', $deleted);
        }
    }

//COUNTRY ALIASES
} elseif (($show = filter_input(INPUT_GET, 'show', FILTER_SANITIZE_STRING)) == 'alias') {
    $aliases = getAliases();
    $countries = getCountries();
    if ($id = filter_input(INPUT_GET, 'id', FILTER_SANITIZE_NUMBER_INT)) {
        //show alias 
        $action = 'edit alias';
        $alias = getAlias($id);
        $error = $alias ? '' : 'Country alias not found.';
    } else {
        //show all aliases
        $action = 'view aliases';

        if ($deleted = filter_input(INPUT_GET, 'deleted', FILTER_SANITIZE_NUMBER_INT)) {
            $success = sprintf('Country alias %d deleted.', $deleted);
        }
    }

//GROUP EXCLUDES
} elseif (($show = filter_input(INPUT_GET, 'show', FILTER_SANITIZE_STRING)) == 'exclude') {
    $excludes = getExcludes();
    $groups = getGroups();
    if ($id = filter_input(INPUT_GET, 'id', FILTER_SANITIZE_NUMBER_INT)) {
        //show exclude
        $action = 'edit exclude';
        $exclude = getExclude($id);
        $error = $exclude ? '' : 'Exclude not found.';
    } else {
        //show all excludes
        $action = 'view excludes';

        if ($deleted = filter_input(INPUT_GET, 'deleted', FILTER_SANITIZE_NUMBER_INT)) {
            $success = sprintf('Exclude %d deleted.', $deleted);
        }
    }

//DEFAULT
} else {
    //show groups
    $show = 'group';
    $action = 'view groups';
    $groups = getGroups();
    $countries = [];
    $excludes = [];
}

$preview = (!empty($preview[0]) ? $preview[0] : '');

//handle form POST action
if (empty($error) && filter_input(INPUT_SERVER, 'REQUEST_METHOD') == 'POST' && ($show = filter_input(INPUT_POST, 'show'))) {
    $id = filter_input(INPUT_POST, 'id');
    $action = strtolower(filter_input(INPUT_POST, 'action'));
    $data = filter_input(INPUT_POST, 'post', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY);
    if ($id) {
        $data['id'] = $id;
    }
    switch ($action) {
        case 'delete':
            switch ($show) {
            case 'group':
                if (deleteGroup($id)) {
                    $success = sprintf('Group %s deleted.', $id);
                    redirect(sprintf('?show=group&deleted=%d', $id));
                } else {
                    $error = sprintf('Error deleting group %d!', $id);
                }
                break;
            case 'country':
                if (deleteCountry($id)) {
                    $success = sprintf('Country %d deleted.', $id);
                    redirect(sprintf('?show=country&deleted=%d', $id));
                } else {
                    $error = sprintf('Error deleting country %d!', $id);
                }
                break;
            case 'alias':
                if (deleteAlias($id)) {
                    $success = sprintf('Alias %d deleted.', $id);
                    redirect(sprintf('?show=alias&deleted=%d', $id));
                } else {
                    $error = sprintf('Error deleting alias %d!', $id);
                }
                break;
            case 'exclude':
                if (deleteExclude($id)) {
                    $success = sprintf('Exclude %d deleted.', $id);
                    redirect(sprintf('?show=exclude&deleted=%d', $id));
                } else {
                    $error = sprintf('Error deleting alias %d!', $id);
                }
                break;
            }
            break;
        default:
        case 'save':
            switch ($show) {
            case 'group':
                if (saveGroup($id, $data)) {
                    $success = sprintf('Group "%s" saved.', $data['name']);
                    $groups = getGroups();
                } else {
                    $error = sprintf('Error creating group "%s"!', $data['name']);
                    $group = $data;
                }
                break;
            case 'country':
                //some optional fields
                if (empty($data['group_id'])) {
                    $data['group_id'] = null;
                }
                if (empty($data['start'])) {
                    $data['start'] = null;
                }
                if (empty($data['end'])) {
                    $data['end'] = null;
                }
                if (empty($data['permanent'])) {
                    $data['permanent'] = null;
                }
                if (saveCountry($id, $data)) {
                    $success = sprintf('Country "%s" saved.', $data['name']);
                    $countries = getCountries();
                } else {
                    $error = sprintf('Error creating country "%s"!', $data['name']);
                    $country = $data;
                }
                break;
            case 'alias':
                if (saveAlias($id, $data)) {
                    $success = sprintf('Alias "%s" saved.', $data['alias']);
                    $aliases = getAliases();
                } else {
                    $error = sprintf('Error creating alias "%s"!', $data['alias']);
                    $alias = $data;
                }
                break;
            case 'exclude':
                if (saveExclude($id, $data)) {
                    $success = sprintf('Exclude "%s" saved.', $data['exclude']);
                    $excludes = getExcludes();
                } else {
                    $error = sprintf('Error creating exclude "%s"!', $data['exclude']);
                    $exclude = $data;
                }
                break;
            }
            break;
    }
}

?><!DOCTYPE html>
<html lang="en">
    <head>
        <title>@DSTnotify admin page</title>
        <link href="//maxcdn.bootstrapcdn.com/bootstrap/3.3.2/css/bootstrap.min.css" rel="stylesheet" type="text/css" />
        <script src="https://ajax.googleapis.com/ajax/libs/jquery/2.1.3/jquery.min.js"></script>

        <style type="text/css">
            .inherited { color: lightgrey; }
        </style>

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

            <h1 class="col-md-offset-2"><a href="https://twitter.com/DSTnotify" target="_blank">@DSTnotify</a> admin page - <?= $action ?></h1>
            <?php if (!empty($success)) { ?><div role="alert" class="alert alert-success"><?= $success ?></div><?php } ?>
            <?php if (!empty($error)) { ?><div role="alert" class="alert alert-danger"><?= $error?></div><?php } ?>

            <form method="post" action="?show=<?= $show ?>" class="form-horizontal" role="form">
            <div class="form-group bg-warning">
                <label class="col-sm-2 control-label">Go to:</label>
                <div class="col-sm-10">
                <a href="?show=group" class="btn btn-<?= $show == 'group' ? 'primary' : 'default' ?>">Groups</a>
                    <a href="?show=country" class="btn btn-<?= $show == 'country' ? 'primary' : 'default' ?>">Countries</a>
                    <a href="?show=alias" class="btn btn-<?= $show == 'alias' ? 'primary' : 'default' ?>">Country aliases</a>
                    <a href="?show=exclude" class="btn btn-<?= $show == 'exclude' ? 'primary' : 'default' ?>">Excluded countries</a>
                </div>
            </div>
            <div class="form-group"><hr /></div>

            <?php if ($show == 'group') { ?>

                <div class="form-group">
                    <label for="name" class="col-sm-2 control-label">Preview</label>
                    <div class="col-sm-10">
                    <p class="form-control-static"><i><?= $preview ?></i><?= $preview ? sprintf(' [%dch]', strlen($preview)) : '' ?></p>
                    </div>
                </div>
                <div class="form-group">
                    <label for="name" class="col-sm-2 control-label">Name</label>
                    <div class="col-sm-10">
                        <input type="text" id="name" name="post[name]" class="form-control" value="<?= @utf8_encode($group['name']) ?>" required />
                    </div>
                </div>
                <div class="form-group">
                    <label for="shortname" class="col-sm-2 control-label">Short name</label>
                    <div class="col-sm-10">
                        <input type="text" id="shortname" name="post[shortname]" class="form-control" value="<?= @utf8_encode($group['shortname']) ?>" />
                    </div>
                </div>
                <div class="form-group">
                    <label for="start" class="col-sm-2 control-label">Start</label>
                    <div class="col-sm-10">
                        <input type="text" id="start" name="post[start]" class="form-control" value="<?= @$group['start'] ?>" required />
                    </div>
                </div>
                <div class="form-group">
                    <label for="end" class="col-sm-2 control-label">End</label>
                    <div class="col-sm-10">
                        <input type="text" id="end" name="post[end]" class="form-control" value="<?= @$group['end'] ?>" required />
                    </div>
                </div>
                <div class="form-group">
                    <label for="countries" class="col-sm-2 control-label">Countries</label>
                    <div class="col-sm-10">
                    <?php if ($countries) { ?>
                        <ul>
                        <?php foreach ($countries as $country) { ?>
                            <li><a href="?show=country&id=<?= $country['id'] ?>"><?= $country['name'] ?></a></li>
                        <?php } ?>
                        </ul>
                    <?php } else { ?>
                        <p class="form-control-static">(empty)</p>
                    <?php } ?>
                    </div>
                </div>
                <div class="form-group">
                    <label for="excludes" class="col-sm-2 control-label">Excluded countries</label>
                    <div class="col-sm-10">
                    <?php if ($excludes) { ?>
                        <ul>
                        <?php foreach ($excludes as $exclude) { ?>
                            <li><a href="?show=exclude&id=<?= $exclude['id'] ?>"><?= $exclude['exclude'] ?></a></li>
                        <?php } ?>
                        </ul>
                    <?php } else { ?>
                        <p class="form-control-static">(empty)</p>
                    <?php } ?>
                    </div>
                </div>

            <?php } elseif ($show == 'country') { ?>

                <div class="form-group">
                    <label for="name" class="col-sm-2 control-label">Preview</label>
                    <div class="col-sm-10">
                    <p class="form-control-static"><i><?= $preview ?></i><?= $preview ? sprintf(' [%dch]', strlen($preview)) : '' ?></p>
                    </div>
                </div>
                <div class="form-group">
                    <?php if (@$country['group_id']) { ?>
                        <label for="group" class="col-sm-2 control-label"><a href="?show=group&id=<?= $country['group_id'] ?>">Group</a></label>
                    <?php } else { ?>
                        <label for="group" class="col-sm-2 control-label">Group</label>
                    <?php } ?>
                    <div class="col-sm-10">
                        <select id="group" name="post[group_id]" class="form-control">
                            <option></option>
                            <?php foreach ($groups as $group) { ?>
                                <option value="<?= $group['id'] ?>" <?= $group['id'] == @$country['group_id'] ? 'selected' : ''?>><?= $group['shortname'] ?></option>
                            <?php } ?>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label for="name" class="col-sm-2 control-label">Name</label>
                    <div class="col-sm-10">
                        <input type="text" id="name" name="post[name]" class="form-control" value="<?= @utf8_encode($country['name']) ?>" required />
                    </div>
                </div>
                <div class="form-group">
                    <label for="start" class="col-sm-2 control-label">Start</label>
                    <div class="col-sm-10">
                        <?php if (@$country['group_id']) { ?>
                            <p class="form-control-static"><span class="inherited"><?= $country['groupstart'] ?></span></p>
                        <?php } else { ?>
                            <input type="text" id="start" name="post[start]" class="form-control" value="<?= @$country['start'] ?>" />
                        <?php } ?>
                    </div>
                </div>
                <div class="form-group">
                    <label for="end" class="col-sm-2 control-label">End</label>
                    <div class="col-sm-10">
                        <?php if (@$country['group_id']) { ?>
                            <p class="form-control-static"><span class="inherited"><?= $country['groupend'] ?></span></p>
                        <?php } else { ?>
                            <input type="text" id="end" name="post[end]" class="form-control" value="<?= @$country['end'] ?>" />
                        <?php } ?>
                    </div>
                </div>
                <div class="form-group">
                    <label for="since" class="col-sm-2 control-label">Since</label>
                    <div class="col-sm-10">
                        <input type="text" id="since" name="post[since]" class="form-control" value="<?= @$country['since'] > 0 ? @$country['since'] : '' ?>" />
                    </div>
                </div>
                <div class="form-group">
                    <label for="timezone" class="col-sm-2 control-label">Timezone</label>
                    <div class="col-sm-10">
                        <input type="text" id="timezone" name="post[timezone]" class="form-control" value="<?= @$country['timezone'] ?>" />
                    </div>
                </div>
                <div class="form-group">
                    <label for="note" class="col-sm-2 control-label">Note</label>
                    <div class="col-sm-10">
                        <input type="text" id="note" name="post[note]" class="form-control" value="<?= @$country['note'] ?>" />
                    </div>
                </div>
                <div class="form-group">
                    <label for="info" class="col-sm-2 control-label">Info URL</label>
                    <div class="col-sm-10">
                        <input type="text" id="info" name="post[info]" class="form-control" value="<?= @$country['info'] ?>" />
                    </div>
                </div>
                <div class="form-group">
                    <label for="permanent" class="col-sm-2 control-label">Permanent</label>
                    <div class="col-sm-10">
                        <div class="radio">
                            <label>
                                <input type="radio" name="post[permanent]" id="permanent" value="" <?= @is_null($country['permanent']) ? 'checked' : '' ?>> N/A
                            </label>
                        </div>
                        <div class="radio">
                            <label>
                                <input type="radio" name="post[permanent]" id="permanent" value="1" <?= @$country['permanent'] === '1' ? 'checked' : '' ?>> Active
                            </label>
                        </div>
                        <div class="radio">
                            <label>
                                <input type="radio" name="post[permanent]" id="permanent" value="0" <?= @$country['permanent'] === '0' ? 'checked' : '' ?>> Inactive
                            </label>
                        </div>
                    </div>
                </div>

            <?php } elseif ($show == 'alias') { ?>

                <div class="form-group">
                    <label for="country" class="col-sm-2 control-label"><a href="?show=country&id=<?= $alias['country_id'] ?>">Country</a></label>
                    <div class="col-sm-10">
                        <select id="country" name="post[country_id]" class="form-control">
                            <?php foreach ($countries as $country) { ?>
                                <option value="<?= $country['id'] ?>" <?= $country['id'] == @$alias['country_id'] ? 'selected' : ''?>><?= $country['name'] ?></option>
                            <?php } ?>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label for="alias" class="col-sm-2 control-label">Name</label>
                    <div class="col-sm-10">
                        <input type="text" id="alias" name="post[alias]" class="form-control" value="<?= @utf8_encode($alias['alias']) ?>" required />
                    </div>
                </div>

            <?php } elseif ($show == 'exclude') { ?>

                <div class="form-group">
                    <label for="group" class="col-sm-2 control-label"><a href="?show=group&id=<?= $exclude['group_id'] ?>">Group</a></label>
                    <div class="col-sm-10">
                        <select id="group" name="post[group_id]" class="form-control">
                            <?php foreach ($groups as $group) { ?>
                                <option value="<?= $group['id'] ?>" <?= $group['id'] == @$exclude['group_id'] ? 'selected' : ''?>><?= $group['name'] ?></option>
                            <?php } ?>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label for="exclude" class="col-sm-2 control-label">Name</label>
                    <div class="col-sm-10">
                        <input type="text" id="exclude" name="post[exclude]" class="form-control" value="<?= @utf8_encode($exclude['exclude']) ?>" required />
                    </div>
                </div>

            <?php } ?>
                <div class="form-group">
                    <label class="col-sm-2 control-label">&nbsp;</label>
                    <input type="submit" name="action" value="Save" class="btn btn-primary" />
                    <input type="hidden" name="show" value="<?= $show ?>" />
                    <?php if (!empty($id)) { ?>
                        <a href="?show=<?= $show ?>" class="btn btn-default">Cancel</a>

                        <input type="hidden" name="id" value="<?= $id ?>" />
                        <input type="submit" name="action" value="Delete" class="confirm btn btn-danger" />
                    <?php } ?>
                </div>
            </form>

            <table class="table tale-condensed table-hover">
            <?php if ($show == 'group') { ?>
                <tr>
                    <th>Name</th>
                    <th>Short name</th>
                    <th>Start</th>
                    <th>End</th>
                    <th></th>
                </tr>
                <?php foreach ($groups as $group) { ?>
                    <tr class="table-striped">
                        <td><?= utf8_encode($group['name']) ?></td>
                        <td><?= $group['shortname'] ?></td>
                        <td><?= $group['start'] ?></td>
                        <td><?= $group['end'] ?></td>
                        <td><a href="?show=group&id=<?= $group['id'] ?>"><span class="glyphicon glyphicon-pencil"></span></a></td>
                    </tr>
                <?php } ?>

            <?php } elseif ($show == 'country') { ?>
                <tr>
                    <th>Group</th>
                    <th>Name</th>
                    <th>Start</th>
                    <th>End</th>
                    <th>Note</th>
                    <th>Info URL</th>
                    <th>Permanent</th>
                    <th></th>
                </tr>
                <?php foreach ($countries as $country) { ?>
                    <tr class="table-striped">
                        <td>
                            <?php if ($country['group_id']) { ?>
                                <a href="?show=group&id=<?= $country['group_id'] ?>"><?= $country['group'] ?></a>
                            <?php } else { ?>
                                <?= $country['group'] ?>
                            <?php } ?>
                        </td>
                        <td><?= utf8_encode($country['name']) ?></td>
                        <td>
                            <?php if ($country['group_id']) { ?>
                                <span class="inherited"><?= $country['groupstart'] ?></span>
                            <?php } else { ?>
                                <?= $country['start'] ?>
                            <?php } ?>
                        </td>
                        <td>
                            <?php if ($country['group_id']) { ?>
                                <span class="inherited"><?= $country['groupend'] ?></span>
                            <?php } else { ?>
                                <?= $country['end'] ?>
                            <?php } ?>
                        </td>
                        <td><?= $country['note'] ?></td>
                        <td><?= $country['info'] ? '<a href="' . $country['info'] . '" target="_blank">link</a>' : '' ?></td>
                        <td><?= !is_null($country['permanent']) ? ($country['permanent'] == 1 ? 'active' : 'inactive') : '' ?></td>
                        <td><a href="?show=country&id=<?= $country['id'] ?>"><span class="glyphicon glyphicon-pencil"></span></a></td>
                    </tr>
                <?php } ?>

            <?php } elseif ($show == 'alias') { ?>
                <tr>
                    <th>Country</th>
                    <th>Alias</th>
                    <th></th>
                </tr>
                <?php foreach ($aliases as $alias) { ?>
                    <tr class="table-striped">
                        <td><a href="?show=country&id=<?= $alias['country_id'] ?>"><?= $alias['country'] ?></a></td>
                        <td><?= utf8_encode($alias['alias']) ?></td>
                        <td><a href="?show=alias&id=<?= $alias['id'] ?>"><span class="glyphicon glyphicon-pencil"></span></a></td>
                    </tr>
                <?php } ?>

            <?php } elseif ($show == 'exclude') { ?>
                <tr>
                    <th>Group</th>
                    <th>Exclude</th>
                    <th></th>
                </tr>
                <?php foreach ($excludes as $exclude) { ?>
                    <tr class="table-striped">
                        <td><a href="?show=group&id=<?= $exclude['group_id'] ?>"><?= $exclude['group'] ?></a></td>
                        <td><?= utf8_encode($exclude['exclude']) ?></td>
                        <td><a href="?show=exclude&id=<?= $exclude['id'] ?>"><span class="glyphicon glyphicon-pencil"></span></a></td>
                    </tr>
                <?php } ?>

            <?php } ?>
            </table>
        </div>
    </body>
</html>
<?php

function getGroups()
{
    global $db;
    return $db->query('
        SELECT *
        FROM dst_group
        ORDER BY name'
    );
}

function getGroup($id)
{
    global $db;
    return $db->query_single('
        SELECT *
        FROM dst_group
        WHERE id = :id
        LIMIT 1',
        [':id' => $id]
    );
}

function getGroupCountries($id)
{
    global $db;
    return $db->query('
        SELECT *
        FROM dst_country
        WHERE group_id = :id',
        [':id' => $id]
    );
}

function getGroupExcludes($id)
{
    global $db;
    return $db->query('
        SELECT *
        FROM dst_exclude
        WHERE group_id = :id',
        [':id' => $id]
    );
}

function getCountries()
{
    global $db;
    return $db->query('
        SELECT c.*, g.shortname AS `group`, g.start AS groupstart, g.end AS groupend
        FROM dst_country c
        LEFT JOIN dst_group g ON g.id = c.group_id
        ORDER BY c.name'
    );
}

function getCountry($id)
{
    global $db;
    return $db->query_single('
        SELECT c.*, g.name AS `group`, g.start AS groupstart, g.end AS groupend
        FROM dst_country c
        LEFT JOIN dst_group g ON g.id = c.group_id
        WHERE c.id = :id
        LIMIT 1',
        [':id' => $id]
    );
}

function getAliases()
{
    global $db;
    return $db->query('
        SELECT a.*, c.name AS country
        FROM dst_country_alias a
        JOIN dst_country c ON c.id = a.country_id
        ORDER BY country'
    );
}

function getAlias($id)
{
    global $db;
    return $db->query_single('
        SELECT *
        FROM dst_country_alias
        WHERE id = :id
        LIMIT 1',
        [':id' => $id]
    );
}

function getExcludes()
{
    global $db;
    return $db->query('
        SELECT e.*, g.shortname AS `group`
        FROM dst_exclude e
        JOIN dst_group g ON g.id = e.group_id
        ORDER BY e.exclude'
    );
}

function getExclude($id)
{
    global $db;
    return $db->query_single('
        SELECT *
        FROM dst_exclude
        WHERE id = :id
        LIMIT 1',
        [':id' => $id]
    );
}

function saveGroup($id = false, $data)
{
    global $db;
    if ($id) {
        return $db->query('
            UPDATE dst_group
            SET name = :name,
                shortname = :shortname,
                start = :start,
                end = :end
            WHERE id = :id
            LIMIT 1',
            $data
        );
    } else {
        return $db->query('
            INSERT INTO dst_group
            SET name = :name,
                shortname = :shortname,
                start = :start,
                end = :end',
            $data
        );
    }
}

function saveCountry($id = false, $data)
{
    global $db;
    if ($id) {
        return $db->query('
            UPDATE dst_country
            SET group_id = :group_id,
                name = :name,
                start = :start,
                end = :end,
                since = :since,
                timezone = :timezone,
                note = :note,
                info = :info,
                permanent = :permanent
            WHERE id = :id
            LIMIT 1',
            $data
        );
    } else {
        return $db->query('
            INSERT INTO dst_country
            SET group_id = :group_id,
                name = :name,
                start = :start,
                end = :end,
                since = :since,
                timezone = :timezone,
                note = :note,
                info = :info,
                permanent = :permanent',
            $data
        );
    }
}

function saveAlias($id = false, $data)
{
    global $db;
    if ($id) {
        return $db->query('
            UPDATE dst_country_alias
            SET country_id = :country_id,
                alias = :alias
            WHERE id = :id
            LIMIT 1',
            $data
        );
    } else {
        return $db->query('
            INSERT INTO dst_country_alias
            SET country_id = :country_id,
                alias = :alias',
            $data
        );
    }
}

function saveExclude($id = false, $data)
{
    global $db;
    if ($id) {
        return $db->query('
            UPDATE dst_exclude
            SET group_id = :group_id,
                exclude = :exclude
            WHERE id = :id
            LIMIT 1',
            $data
        );
    } else {
        return $db->query('
            INSERT INTO dst_exclude
            SET group_id = :group_id,
                exclude = :exclude',
            $data
        );
    }
}

function deleteGroup($id)
{
    global $db;
    return $db->query('
        DELETE
        FROM dst_group
        WHERE id = :id
        LIMIT 1',
        [':id' => $id]
    );
}

function deleteCountry($id)
{
    global $db;
    return $db->query('
        DELETE
        FROM dst_country
        WHERE id = :id
        LIMIT 1',
        [':id' => $id]
    );
}

function deleteAlias($id)
{
    global $db;
    return $db->query('
        DELETE
        FROM dst_country_alias
        WHERE id = :id
        LIMIT 1',
        [':id' => $id]
    );
}

function deleteExclude($id)
{
    global $db;
    return $db->query('
        DELETE
        FROM dst_exclude
        WHERE id = :id
        LIMIT 1',
        [':id' => $id]
    );
}

function redirect($url)
{
    header('Location: ' . $url);
    exit();
}
