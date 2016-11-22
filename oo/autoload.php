<?php
define('DS', DIRECTORY_SEPARATOR);
define('DOCROOT', __DIR__ . DS);
define('NAMESPACE_PREFIX', 'Twitterbot\\');

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
