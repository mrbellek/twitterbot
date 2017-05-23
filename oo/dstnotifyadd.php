<?php
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

$error = '';
$success = '';
$preview = '';

//GROUPS
if (($show = filter_input(INPUT_GET, 'show', FILTER_SANITIZE_STRING)) == 'group') {
    $groups = Group::all($db);
    if ($id = filter_input(INPUT_GET, 'id', FILTER_SANITIZE_NUMBER_INT)) {
        //show group
        $action = 'edit group';
        $group = new Group($id, $db);
        if (!empty($group->id)) {
            $countries = $group->getCountries();
            $excludes = $group->getExcludes();
            $preview = $oBot->formatTweetDST('start', [$group->shortname => ['name' => $group->name]], 'tomorrow');
        } else {
            $error = 'Group not found.';
            unset($group);
            $countries = false;
            $excludes = false;
        }
    } else {
        //show all groups
        $action = 'view groups';
        $countries = [];
        $excludes = [];

        if ($saved = filter_input(INPUT_GET, 'saved', FILTER_SANITIZE_STRING)) {
            $success = sprintf('Group "%s" saved.', $saved);
        } elseif ($deleted = filter_input(INPUT_GET, 'deleted', FILTER_SANITIZE_NUMBER_INT)) {
            $success = sprintf('Group %d deleted.', $deleted);
        }
    }

//COUNTRIES
} elseif (($show = filter_input(INPUT_GET, 'show', FILTER_SANITIZE_STRING)) == 'country') {
    $countries = Country::all($db);
    $groups = Group::all($db);
    if ($id = filter_input(INPUT_GET, 'id', FILTER_SANITIZE_NUMBER_INT)) {
        //show country
        $action = 'edit country';
        $country = new Country($id, $db);
        if (!empty($country->id)) {
            if (!empty($country->group)) {
                $preview = $oBot->formatTweetDST('start', [$country->group->name => ['name' => $country->name]], 'tomorrow');
            } else {
                $preview = $oBot->formatTweetDST('start', [$country->name => ['name' => $country->name]], 'tomorrow');
            }
        } else {
            $error = 'Country not found.';
        }
    } else {
        //show all countries
        $action = 'view countries';

        if ($saved = filter_input(INPUT_GET, 'saved', FILTER_SANITIZE_STRING)) {
            $success = sprintf('Country "%s" saved.', $saved);
        } elseif ($deleted = filter_input(INPUT_GET, 'deleted', FILTER_SANITIZE_NUMBER_INT)) {
            $success = sprintf('Country %d deleted.', $deleted);
        }
    }

//COUNTRY ALIASES
} elseif (($show = filter_input(INPUT_GET, 'show', FILTER_SANITIZE_STRING)) == 'alias') {
    $aliases = Alias::all($db);
    $countries = Country::all($db);
    if ($id = filter_input(INPUT_GET, 'id', FILTER_SANITIZE_NUMBER_INT)) {
        //show alias 
        $action = 'edit alias';
        $alias = new Alias($id, $db);
        $error = $alias ? '' : 'Country alias not found.';
    } else {
        //show all aliases
        $action = 'view aliases';

        if ($saved = filter_input(INPUT_GET, 'saved', FILTER_SANITIZE_STRING)) {
            $success = sprintf('Country alias "%s" saved.', $saved);
        } elseif ($deleted = filter_input(INPUT_GET, 'deleted', FILTER_SANITIZE_NUMBER_INT)) {
            $success = sprintf('Country alias %d deleted.', $deleted);
        }
    }

//GROUP EXCLUDES
} elseif (($show = filter_input(INPUT_GET, 'show', FILTER_SANITIZE_STRING)) == 'exclude') {
    $excludes = Exclude::all($db);
    $groups = Group::all($db);
    if ($id = filter_input(INPUT_GET, 'id', FILTER_SANITIZE_NUMBER_INT)) {
        //show exclude
        $action = 'edit exclude';
        $exclude = new Exclude($id, $db);
        $error = $exclude ? '' : 'Exclude not found.';
    } else {
        //show all excludes
        $action = 'view excludes';

        if ($saved = filter_input(INPUT_GET, 'saved', FILTER_SANITIZE_STRING)) {
            $success = sprintf('Exclude "%s" saved.', $saved);
        } elseif ($deleted = filter_input(INPUT_GET, 'deleted', FILTER_SANITIZE_NUMBER_INT)) {
            $success = sprintf('Exclude %d deleted.', $deleted);
        }
    }

//DEFAULT
} else {
    //show groups
    $show = 'group';
    $action = 'view groups';
    $groups = Group::all($db);
    $countries = [];
    $excludes = [];
}

$preview = (!empty($preview[0]) ? $preview[0] : '');

//handle form POST action
if (empty($error) && filter_input(INPUT_SERVER, 'REQUEST_METHOD', FILTER_SANITIZE_STRING) == 'POST' && ($show = filter_input(INPUT_POST, 'show', FILTER_SANITIZE_STRING))) {
    $id = filter_input(INPUT_POST, 'id', FILTER_SANITIZE_NUMBER_INT);
    $action = strtolower(filter_input(INPUT_POST, 'action', FILTER_SANITIZE_STRING));
    $password = filter_input(INPUT_POST, 'password', FILTER_SANITIZE_STRING);
    $data = filter_input(INPUT_POST, 'post', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY);

    if ($password != FORM_PASSWORD) {
        $action = '';
        $error = 'Password is wrong.';
    }

    if ($id) {
        $data['id'] = $id;
    }

    switch ($action) {
        case 'delete':
            switch ($show) {
                case 'group':
                    if ((new Group($id, $db))->delete()) {
                        redirect(sprintf('?show=group&deleted=%d', $id));
                    } else {
                        $error = sprintf('Error deleting group %d!', $id);
                    }
                    break;
                case 'country':
                    if ((new Country($id, $db))->delete()) {
                        redirect(sprintf('?show=country&deleted=%d', $id));
                    } else {
                        $error = sprintf('Error deleting country %d!', $id);
                    }
                    break;
                case 'alias':
                    if ((new Alias($id, $db))->delete()) {
                        redirect(sprintf('?show=alias&deleted=%d', $id));
                    } else {
                        $error = sprintf('Error deleting alias %d!', $id);
                    }
                    break;
                case 'exclude':
                    if ((new Exclude($id, $db))->delete()) {
                        redirect(sprintf('?show=exclude&deleted=%d', $id));
                    } else {
                        $error = sprintf('Error deleting alias %d!', $id);
                    }
                    break;
            }
            break;
        case 'save':
            switch ($show) {
            case 'group':
                if (empty($id)) {
                    $saved = (Group::create($data, $db));
                } else {
                    $saved = (new Group($id, $db))->save($data);
                }
                if ($saved) {
                    redirect(sprintf('?show=group&saved=%s', $data['name']));
                } else {
                    $error = sprintf('Error saving group "%s"!', $data['name']);
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
                } else {
                    $data['permanent'] = ($data['permanent'] == 'active' ? 1 : 0);
                }
                if (empty($id)) {
                    $saved = (Country::create($data, $db));
                } else {
                    $saved = (new Country($id, $db))->save($data);
                }
                if ($saved) {
                    redirect(sprintf('?show=country&saved=%s', $data['name']));
                } else {
                    $error = sprintf('Error creating country "%s"!', $data['name']);
                    $country = $data;
                }
                break;
            case 'alias':
                if (empty($id)) {
                    $saved = (Alias::create($data, $db));
                } else {
                    $saved = (new Alias($id, $db))->save($data);
                }
                if ($saved) {
                    redirect(sprintf('?show=alias&saved=%s', $data['alias']));
                } else {
                    $error = sprintf('Error creating alias "%s"!', $data['alias']);
                    $alias = $data;
                }
                break;
            case 'exclude':
                if (empty($id)) {
                    $saved = (Exclude::create($data, $db));
                } else {
                    $saved = (new Exclude($id, $db))->save($data);
                }
                if ($saved) {
                    redirect(sprintf('?show=exclude&saved=%s', $data['exclude']));
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
                        <input type="text" id="name" name="post[name]" class="form-control" value="<?= !empty($group) ? utf8_encode($group->name) : '' ?>" required />
                    </div>
                </div>
                <div class="form-group">
                    <label for="shortname" class="col-sm-2 control-label">Short name</label>
                    <div class="col-sm-10">
                        <input type="text" id="shortname" name="post[shortname]" class="form-control" value="<?= !empty($group) ? utf8_encode($group->shortname) : '' ?>" />
                    </div>
                </div>
                <div class="form-group">
                    <label for="start" class="col-sm-2 control-label">Start</label>
                    <div class="col-sm-10">
                        <input type="text" id="start" name="post[start]" class="form-control" value="<?= !empty($group) ? $group->start : '' ?>" required />
                    </div>
                </div>
                <div class="form-group">
                    <label for="end" class="col-sm-2 control-label">End</label>
                    <div class="col-sm-10">
                        <input type="text" id="end" name="post[end]" class="form-control" value="<?= !empty($group) ? $group->end : '' ?>" required />
                    </div>
                </div>
                <div class="form-group">
                    <label for="countries" class="col-sm-2 control-label">Countries</label>
                    <div class="col-sm-10">
                    <?php if ($countries) { ?>
                        <ul>
                        <?php foreach ($countries as $country) { ?>
                            <li><a href="?show=country&id=<?= $country->id ?>"><?= utf8_encode($country->name) ?></a></li>
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
                            <li><a href="?show=exclude&id=<?= $exclude->id ?>"><?= $exclude->exclude ?></a></li>
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
                    <?php if (!empty($country->group_id)) { ?>
                        <label for="group" class="col-sm-2 control-label"><a href="?show=group&id=<?= $country->group_id ?>">Group</a></label>
                    <?php } else { ?>
                        <label for="group" class="col-sm-2 control-label">Group</label>
                    <?php } ?>
                    <div class="col-sm-10">
                        <select id="group" name="post[group_id]" class="form-control">
                            <option></option>
                            <?php foreach ($groups as $group) { ?>
                                <option value="<?= $group->id ?>" <?= !empty($country) && $group->id == $country->group_id ? 'selected' : ''?>><?= $group->shortname ?></option>
                            <?php } ?>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label for="name" class="col-sm-2 control-label">Name</label>
                    <div class="col-sm-10">
                        <input type="text" id="name" name="post[name]" class="form-control" value="<?= !empty($country) ? utf8_encode($country->name) : '' ?>" required />
                    </div>
                </div>
                <div class="form-group">
                    <label for="start" class="col-sm-2 control-label">Start</label>
                    <div class="col-sm-10">
                        <?php if (!empty($country) && $country->hasGroup()) { ?>
                                <p class="form-control-static"><span class="inherited"><?= $country->getGroup()->start ?></span></p>
                        <?php } else { ?>
                            <input type="text" id="start" name="post[start]" class="form-control" value="<?= !empty($country) ? $country->start : '' ?>" />
                        <?php } ?>
                    </div>
                </div>
                <div class="form-group">
                    <label for="end" class="col-sm-2 control-label">End</label>
                    <div class="col-sm-10">
                        <?php if (!empty($country) && $country->hasGroup()) { ?>
                                <p class="form-control-static"><span class="inherited"><?= $country->getGroup()->end ?></span></p>
                        <?php } else { ?>
                            <input type="text" id="end" name="post[end]" class="form-control" value="<?= !empty($country) ? $country->end : '' ?>" />
                        <?php } ?>
                    </div>
                </div>
                <div class="form-group">
                    <label for="since" class="col-sm-2 control-label">Since</label>
                    <div class="col-sm-10">
                        <input type="text" id="since" name="post[since]" class="form-control" value="<?= !empty($country) ? ($country->since > 0 ? $country->since : '') : '' ?>" />
                    </div>
                </div>
                <div class="form-group">
                    <label for="timezone" class="col-sm-2 control-label">Timezone</label>
                    <div class="col-sm-10">
                        <input type="text" id="timezone" name="post[timezone]" class="form-control" value="<?= !empty($country) ? $country->timezone : '' ?>" />
                    </div>
                </div>
                <div class="form-group">
                    <label for="note" class="col-sm-2 control-label">Note</label>
                    <div class="col-sm-10">
                        <input type="text" id="note" name="post[note]" class="form-control" value="<?= !empty($country) ? $country->note : '' ?>" />
                    </div>
                </div>
                <div class="form-group">
                    <label for="info" class="col-sm-2 control-label">Info URL</label>
                    <div class="col-sm-10">
                        <input type="text" id="info" name="post[info]" class="form-control" value="<?= !empty($country) ? $country->info : '' ?>" />
                    </div>
                </div>
                <div class="form-group">
                    <label for="permanent" class="col-sm-2 control-label">Permanent</label>
                    <div class="col-sm-10">
                        <div class="radio">
                            <label>
                                <input type="radio" name="post[permanent]" id="permanent" value="" <?= !empty($country) && is_null($country->permanent) || empty($country) ? 'checked' : '' ?>> N/A
                            </label>
                        </div>
                        <div class="radio">
                            <label>
                                <input type="radio" name="post[permanent]" id="permanent" value="active" <?= !empty($country) && $country->permanent === '1' ? 'checked' : '' ?>> Active
                            </label>
                        </div>
                        <div class="radio">
                            <label>
                                <input type="radio" name="post[permanent]" id="permanent" value="inactive" <?= !empty($country) && $country->permanent === '0' ? 'checked' : '' ?>> Inactive
                            </label>
                        </div>
                    </div>
                </div>

            <?php } elseif ($show == 'alias') { ?>

                <div class="form-group">
                    <label for="country" class="col-sm-2 control-label"><a href="?show=country&id=<?= !empty($alias) ? $alias->country_id : '' ?>">Country</a></label>
                    <div class="col-sm-10">
                        <select id="country" name="post[country_id]" class="form-control">
                            <?php foreach ($countries as $country) { ?>
                                <option value="<?= $country->id ?>" <?= !empty($alias) && $country->id == $alias->country_id ? 'selected' : ''?>><?= utf8_encode($country->name) ?></option>
                            <?php } ?>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label for="alias" class="col-sm-2 control-label">Name</label>
                    <div class="col-sm-10">
                        <input type="text" id="alias" name="post[alias]" class="form-control" value="<?= !empty($alias) ? utf8_encode($alias->alias) : '' ?>" required />
                    </div>
                </div>

            <?php } elseif ($show == 'exclude') { ?>

                <div class="form-group">
                    <label for="group" class="col-sm-2 control-label"><a href="?show=group&id=<?= !empty($exclude) ? $exclude->group_id : '' ?>">Group</a></label>
                    <div class="col-sm-10">
                        <select id="group" name="post[group_id]" class="form-control">
                            <?php foreach ($groups as $group) { ?>
                                <option value="<?= $group->id ?>" <?= !empty($exclude) && $group->id == $exclude->group_id ? 'selected' : ''?>><?= utf8_encode($group->name) ?></option>
                            <?php } ?>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label for="exclude" class="col-sm-2 control-label">Name</label>
                    <div class="col-sm-10">
                        <input type="text" id="exclude" name="post[exclude]" class="form-control" value="<?= !empty($exclude) ? utf8_encode($exclude->exclude) : '' ?>" required />
                    </div>
                </div>

            <?php } ?>
                <div class="form-group">
                    <label for="password" class="col-sm-2 control-label">Password</label>
                    <div class="col-sm-10">
                        <input type="password" id="password" name="password" class="form-control" required />
                    </div>
                </div>
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
                        <td><?= utf8_encode($group->name) ?></td>
                        <td><?= $group->shortname ?></td>
                        <td><?= $group->start ?></td>
                        <td><?= $group->end ?></td>
                        <td><a href="?show=group&id=<?= $group->id ?>"><span class="glyphicon glyphicon-pencil"></span></a></td>
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
                            <?php if ($country->hasGroup()) { ?>
                                <a href="?show=group&id=<?= $country->group_id ?>"><?= $country->getGroup()->shortname ?></a>
                            <?php } ?>
                        </td>
                        <td><?= utf8_encode($country->name) ?></td>
                        <td>
                            <?php if ($country->hasGroup()) { ?>
                                <span class="inherited"><?= $country->getGroup()->start ?></span>
                            <?php } else { ?>
                                <?= $country->start ?>
                            <?php } ?>
                        </td>
                        <td>
                            <?php if ($country->hasGroup()) { ?>
                                <span class="inherited"><?= $country->getGroup()->end ?></span>
                            <?php } else { ?>
                                <?= $country->end ?>
                            <?php } ?>
                        </td>
                        <td><?= $country->note ?></td>
                        <td><?= $country->info ? '<a href="' . $country->info . '" target="_blank">link</a>' : '' ?></td>
                        <td><?= !is_null($country->permanent) ? ($country->permanent == 1 ? 'active' : 'inactive') : '' ?></td>
                        <td><a href="?show=country&id=<?= $country->id ?>"><span class="glyphicon glyphicon-pencil"></span></a></td>
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
                        <td>
                            <?php if ($alias->hasCountry()) { ?>
                                <a href="?show=country&id=<?= $alias->country_id ?>"><?= utf8_encode($alias->getCountry()->name) ?></a>
                            <?php } ?>
                        </td>
                        <td><?= utf8_encode($alias->alias) ?></td>
                        <td><a href="?show=alias&id=<?= $alias->id ?>"><span class="glyphicon glyphicon-pencil"></span></a></td>
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
                        <td>
                            <?php if ($exclude->hasGroup()) { ?>
                                <a href="?show=group&id=<?= $exclude->group_id ?>"><?= utf8_encode($exclude->getGroup()->name) ?></a>
                            <?php } ?>
                        </td>
                        <td><?= utf8_encode($exclude->exclude) ?></td>
                        <td><a href="?show=exclude&id=<?= $exclude->id ?>"><span class="glyphicon glyphicon-pencil"></span></a></td>
                    </tr>
                <?php } ?>

            <?php } ?>
            </table>
        </div>
    </body>
</html>
<?php

function redirect($url)
{
    header('Location: ' . $url);
    exit();
}

abstract class Base
{
    public function __construct($id, $db)
    {
        $this->db = $db;
        $this->id = $id;

        if ($object = $this->get()) {
            //set properties
            foreach ($object as $prop => $value) {
                $this->{$prop} = $value;
            }
        } else {
            //not found, clean up
            unset($this->db);
            unset($this->id);
        }
    }

    protected function get()
    {
        return $this->db->query_single(sprintf('
            SELECT *
            FROM %s
            WHERE id = :id
            LIMIT 1',
            static::TABLE_NAME),
            ['id' => $this->id]
        );
    }

    static public function all($db)
    {
        $arrays = $db->query(sprintf('
            SELECT *
            FROM %s
            ORDER BY 2',
            static::TABLE_NAME)
        );

        foreach ($arrays as $array) {
            $objects[$array['id']] = new static($array['id'], $db);
        }

        return $objects;
    }

    abstract public function save($data);
    abstract static public function create($data, $db);

    public function delete()
    {
        return $this->db->query(sprintf('
            DELETE FROM %s
            WHERE id = :id
            LIMIT 1',
            static::TABLE_NAME),
            ['id' => $this->id]
        );
    }
}

class Group extends Base
{
    const TABLE_NAME = 'dst_group';

    public function getCountries()
    {
        if (empty($this->countries)) {

            $countries = $this->db->query('
                SELECT id
                FROM dst_country
                WHERE group_id = :id',
                ['id' => $this->id]
            );

            $this->countries = [];
            foreach ($countries as $country) {
                $this->countries[$country['id']] = new Country($country['id'], $this->db);
            }
        }

        return $this->countries;
    }

    public function getExcludes()
    {
        if (empty($this->excludes)) {
            $excludes = $this->db->query('
                SELECT id
                FROM dst_exclude
                WHERE group_id = :id',
                ['id' => $this->id]
            );

            $this->excludes = [];
            foreach ($excludes as $exclude) {
                $this->excludes[$exclude['id']] = new Exclude($exclude['id'], $this->db);
            }
        }

        return $this->excludes;
    }

    public function save($data)
    {
        $data['id'] = $this->id;

        return $this->db->query('
            UPDATE dst_group
            SET name = :name,
                shortname = :shortname,
                start = :start,
                end = :end
            WHERE id = :id
            LIMIT 1',
            $data
        );
    }

    static public function create($data, $db)
    {
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

class Country extends Base
{
    const TABLE_NAME = 'dst_country';

    public function getGroup()
    {
        if (empty($this->group)) {

            $this->group = new Group($this->group_id, $this->db);
        }

        return $this->group;
    }

    static public function all($db)
    {
        $rows = $db->query('
            SELECT *
            FROM dst_country
            ORDER BY name'
        );

        $countries = [];
        foreach ($rows as $row) {
            $countries[$row['id']] = new Country($row['id'], $db);
        }

        return $countries;
    }

    public function hasGroup()
    {
        return !empty($this->getGroup()->id);
    }

    public function getAliases()
    {
        if (empty($this->aliases)) {

            $aliases = $this->db->query('
                SELECT id
                FROM dst_country_alias
                WHERE country_id = :id',
                ['id' => $this->id]
            );

            $this->aliases = [];
            foreach ($aliases as $alias) {
                $this->aliases[$alias['id']] = new Alias($this->alias_id, $this->db);
            }
        }

        return $this->aliases;
    }

    public function save($data)
    {
        $data['id'] = $this->id;

        return $this->db->query('
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
    
    }

    static public function create($data, $db)
    {
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

class Alias extends Base
{
    const TABLE_NAME = 'dst_country_alias';

    public function getCountry()
    {
        if (empty($this->country)) {
            $this->country = new Country($this->country_id, $this->db);
        }

        return $this->country;
    }

    public function hasCountry()
    {
        return !empty($this->getCountry()->id);
    }

    public function save($data)
    {
        $data['id'] = $this->id;

        return $this->db->query('
            UPDATE dst_country_alias
            SET country_id = :country_id,
                alias = :alias
            WHERE id = :id
            LIMIT 1',
            $data
        );
    }

    public static function create($data, $db)
    {
        return $db->query('
            INSERT INTO dst_country_alias
            SET country_id = :country_id,
                alias = :alias',
            $data
        );
    }
}

class Exclude extends Base
{
    const TABLE_NAME = 'dst_exclude';

    public function getGroup()
    {
        if (empty($this->group)) {
            $this->group = new Group($this->group_id, $this->db);
        }

        return $this->group;
    }

    public function hasGroup()
    {
        return !empty($this->getGroup()->id);
    }

    public function save($data)
    {
        $data['id'] = $this->id;

        return $this->db->query('
            UPDATE dst_exclude
            SET group_id = :group_id,
                exclude = :exclude
            WHERE id = :id
            LIMIT 1',
            $data
        );
    }

    public static function create($data, $db)
    {
        return $db->query('
            INSERT INTO dst_exclude
            SET group_id = :group_id,
                exclude = :exclude',
            $data
        );
    }
}

