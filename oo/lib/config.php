<?php
namespace Twitterbot\Lib;

class Config
{
    public function load($sUsername)
    {
        $this->sUsername = $sUsername;

        //load default settings
        if (is_file(MYPATH . 'default.json')) {
            $this->oSettings = @json_decode(file_get_contents(MYPATH . 'default.json'));
        }

        //load bot settings and merge
        if (is_file(MYPATH . strtolower($sUsername) . '.json')) {
            $oBotSettings = @json_decode(file_get_contents(MYPATH . strtolower($sUsername) . '.json'));
            if ($oBotSettings) {
                $this->oSettings = (object) array_merge((array) $this->oSettings, (array) $oBotSettings);
            }
        }

        return !is_null($this->oSettings);
    }

    public function get($sName, $mDefault = false)
    {
        return isset($this->oSettings->$sName) ? $this->oSettings->$sName : $mDefault;
    }

    public function set()
    {
        $aArgs = func_get_args();

        //TODO

        //$this->writeConfig();
    }

    public function writeConfig()
    {
        //TODO: fix this so it doesn't include default stuff

        file_put_contents(MYPATH . strtolower($this->sUsername) . '.json', json_encode($this->oSettings, JSON_PRETTY_PRINT));
    }
}
