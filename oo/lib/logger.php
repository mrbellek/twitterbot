<?php
namespace Twitterbot\Lib;

/**
 * Logger class, write messages to database or to screen
 */
class Logger
{
    private $bInBrowser;

    /**
     * Determine if we're in browser or CLI at start
     *
     * @return void
     */
    public function __construct()
    {
        $this->bInBrowser = !empty($_SERVER['DOCUMENT_ROOT']) ? true : false;
    }

    public function write()
    {
        //write to database
    }

    /**
     * Output message to screen
     *
     * @param ..string
     *
     * @return void
     */
    public function output()
    {
        $aArgs = func_get_args();

        if ($this->bInBrowser) {
            $aArgs[0] .= '<br>' . PHP_EOL;
        } else {
            $aArgs[0] = strip_tags($aArgs[0]) . PHP_EOL;
        }

        call_user_func_array('printf', $aArgs);
    }

    public function getAll()
    {
        //fetch from database
    }
}
