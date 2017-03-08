<?php
define('DS', DIRECTORY_SEPARATOR);
define('DOCROOT', __DIR__ . DS);
define('NAMESPACE_PREFIX', 'Twitterbot\\');

//stop xdebug from being an ass
ini_set('xdebug.var_display_max_data', 1024 * 100);
ini_set('xdebug.var_display_max_depth', 1024);
ini_set('xdebug.var_display_max_children', 1024 * 100);

spl_autoload_register(function($sClass)
{
    $sClassFile = DOCROOT . str_replace('\\', DS, strtolower(str_replace(NAMESPACE_PREFIX, '', $sClass) . '.php'));
    if (is_file($sClassFile)) {
        require_once($sClassFile);
    } elseif(preg_match('/^oauth.+/i', $sClass)) {
        require_once(DOCROOT . 'twitteroauth.php');
        require_once(DOCROOT . 'OAuth.php');
    } else {
        //var_dump($sClass, $sClassFile);
        $aBacktrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
        die(sprintf('Could not find class %s called from %s:%d', $sClass, $aBacktrace[1]['file'], $aBacktrace[1]['line']));
    }
});
