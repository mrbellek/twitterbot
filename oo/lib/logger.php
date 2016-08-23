<?php
namespace Twitterbot\Lib;

class Logger
{
    private $bInBrowser;

    public function write()
    {
        //write to database
    }

    public function output()
    {
        $aArgs = func_get_args();
        if (!isset($this->bInBrowser)) {
            $this->bInBrowser = !empty($_SERVER['DOCUMENT_ROOT']) ? true : false;
        }

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
