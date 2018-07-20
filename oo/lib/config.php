<?php
namespace Twitterbot\Lib;

use Twitterbot\Lib\Logger;
use \stdClass;

/**
 * Config class - store and retrieve persistent settings
 */
class Config
{
    public function __construct()
    {
        $this->logger = new Logger;
    }

    /**
     * Load settings for given twitter username from .json file
     *
     * @param string $sUsername
     *
     * @return bool
     */
    public function load($sUsername)
    {
        $this->sUsername = $sUsername;

        //load default settings
        if (is_file(DOCROOT . 'default.json')) {
            $this->oDefaultSettings = @json_decode(file_get_contents(DOCROOT . 'default.json'));
        }

        //load bot settings and merge
        if (is_file(DOCROOT . strtolower($sUsername) . '.json')) {
            $this->oSettings = @json_decode(file_get_contents(DOCROOT . strtolower($sUsername) . '.json'));
        } else {
            $this->logger->output('Config json file not found for username %s! Halting.', $sUsername);
            $this->logger->write(1, sprintf('Config json file not found for username %s!', $sUsername));
            exit();
        }

        if (json_last_error()) {
            $this->jsonError();
        }

        return !is_null($this->oSettings);
    }

    /**
     * Get value of config setting
     *
     * @param string $sName
     * @param mixed $mDefault
     *
     * @return mixed
     */
    public function get($sName, $mDefault = false)
    {
        if ($sName == 'sUsername') {

            return $this->sUsername;

        } elseif (isset($this->oSettings->$sName)) {

            return $this->oSettings->$sName;

        } elseif (isset($this->oDefaultSettings->$sName)) {

            return $this->oDefaultSettings->$sName;

        } else {

            return $mDefault;
        }
    }

    /**
     * Set config setting (recursive, can be multiple levels deep)
     * @TODO: PHP7 syntax for function arguments
     *
     * @param string(s) keys
     * @param string value
     *
     * @return void 
     */
    public function set()
    {
        //get all func arguments
        $aArgs = func_get_args();

        //take out value
        $mValue = array_pop($aArgs);

        //take out property we want to set to above value
        $oProp = array_pop($aArgs);

        //recursively get node to set property of
        //NB: no & operator needed since these are objects
        $oNode = $this->oSettings;
        foreach ($aArgs as $sSubnode) {
            if (!isset($oNode->$sSubnode)) {
                $oNode->$sSubnode = new stdClass;
            }

            $oNode = $oNode->$sSubnode;
        }

        //set property to new value
        $oNode->$oProp = $mValue;

        //$this->writeConfig();
    }

    /**
     * Handle json error
     *
     * @return void
     */
    private function jsonError()
    {
        $iJsonError = json_last_error();
        $sJsonError = json_last_error_msg();

        $this->logger->write(1, sprintf('Error reading JSON file for %s: %s (%s)', $this->sUsername, $iJsonError, $sJsonError));
        $this->logger->output('Error reading JSON file for %s: %s (%s)', $this->sUsername, $iJsonError, $sJsonError);
    }

    /**
     * Write current config to disk
     *
     * @return void
     */
    public function writeConfig()
    {
        file_put_contents(DOCROOT . strtolower($this->sUsername) . '.json', json_encode($this->oSettings, JSON_PRETTY_PRINT));
    }

    public function __destruct()
    {
        $this->writeConfig();
    }
}
