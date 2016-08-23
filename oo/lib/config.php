<?php
namespace Twitterbot\Lib;

class Config
{
    public function load($sUsername)
    {
        $this->sUsername = $sUsername;

        //load default settings
        if (is_file(MYPATH . 'default.json')) {
            $this->oDefaultSettings = @json_decode(file_get_contents(MYPATH . 'default.json'));
        }

        //load bot settings and merge
        if (is_file(MYPATH . strtolower($sUsername) . '.json')) {
            $this->oSettings = @json_decode(file_get_contents(MYPATH . strtolower($sUsername) . '.json'));
        }

        return !is_null($this->oSettings);
    }

    public function get($sName, $mDefault = false)
    {
        if (isset($this->oSettings->$sName)) {

            return $this->oSettings->$sName;

        } elseif (isset($this->oDefaultSettings->$sName)) {

            return $this->oDefaultSettings->$sName;

        } else {

            return $mDefault;
        }
    }

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

    public function writeConfig()
    {
        file_put_contents(MYPATH . strtolower($this->sUsername) . '.json', json_encode($this->oSettings, JSON_PRETTY_PRINT));
    }

    public function __destruct()
    {
        //$this->writeConfig();
    }
}
