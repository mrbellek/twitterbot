<?php
define('DOCROOT', __DIR__ . '\\');
define('NAMESPACE_PREFIX', 'Twitterbot\\');

spl_autoload_register(function($sClass)
{
    //echo($sClass . PHP_EOL);
    $sClassFile = DOCROOT . str_replace(NAMESPACE_PREFIX, '', $sClass) . '.php';
    if (is_file($sClassFile)) {
        require_once($sClassFile);
    } elseif(preg_match('/^OAuth.+/', $sClass)) {
        require_once(MYPATH . 'twitteroauth.php');
        require_once(MYPATH . 'OAuth.php');
    } else {
        $aBacktrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
        die(sprintf('Could not find class %s called from %s:%d', $sClass, $aBacktrace[1]['file'], $aBacktrace[1]['line']));
    }
});
