<?php
require_once('autoload.php');
require_once('mrbellekplays.inc.php');

use Twitterbot\Lib\Logger;
use Twitterbot\Lib\Config;
use Twitterbot\Lib\Auth;
use Twitterbot\Lib\Ratelimit;
use Twitterbot\Lib\Format;
use Twitterbot\Lib\Tweet;

(new MrbellekPlays)->run();

class MrbellekPlays
{
    private $bPlayingOnXbox = false;
    private $bPlayingOnSteam = false;

    public function __construct()
    {
        $this->sUsername = 'mrbellekplays';
        $this->logger = new Logger;
    }

    public function run()
    {
        $this->oConfig = new Config;
        if ($this->oConfig->load($this->sUsername)) {

            if ((new Ratelimit($this->oConfig))->check()) {

                if ((new Auth($this->oConfig))->isUserAuthed($this->sUsername)) {

                    //TODO: only post when things change or we start playing a game
                    /*
                     * TODO:
                     * - run every 15 mins
                     * - save last xbox status and last steam status
                     * - if either status changes to playing a game, post that
                     * - post offline status every 6 hours?
                     */

                    $this->logger->output('Fetching current status on Steam..');
                    $aSteamStatus = $this->getSteamStatus();

                    $this->logger->output('Fetching current status on Xbox Live..');
                    $aXboxStatus = $this->getXboxStatus();

                    $this->logger->output('- Xbox: %s', $aXboxStatus['status']);
                    $this->logger->output('- Steam: %s', $aSteamStatus['status']);

                    $sLastStatus = $this->oConfig->get('laststatus');

                    //only post if status is different from last time (and it's not 'offline')
                    if ($aXboxStatus['playing_now'] && $aXboxStatus['game'] != $sLastStatus) {

                        //if actively playing on xbox, post that
                        $this->logger->output('- Actively playing on Xbox, posting that!');
                        (new Tweet($this->oConfig))->post($aXboxStatus['status']);

                        $this->oConfig->set('laststatus', $aXboxStatus['game']);

                    } elseif ($aSteamStatus['playing_now'] && $aSteamStatus['game'] != $sLastStatus) {

                        //if actively playing on steam and not xbox, post that
                        $this->logger->output('- Actively playing on Steam, posting that!');
                        (new Tweet($this->oConfig))->post($aSteamStatus['status']);

                        $this->oConfig->set('laststatus', $aSteamStatus['game']);

                    } else {

                        $this->oConfig->set('laststatus', 'offline');
                        $this->logger->output('- Not playing right now.');

                        //if not playing, post last seen info
                        /*$this->logger->output('- Not playing anything, posting random last seen info.');
                        if (mt_rand(1, 2) == 1) {
                            (new Tweet($this->oConfig))->post($aXboxStatus['status']);
                        } else {
                            (new Tweet($this->oConfig))->post($aSteamStatus['status']);
                        }*/
                    }

                    $this->oConfig->writeConfig();
                    $this->logger->output('done!');
                }
            }
        }
    }

    private function getSteamStatus()
    {
        $sApiUrl = str_replace([':steamapikey', ':steamid'], [STEAM_API_KEY, $this->oConfig->get('steam_id')], $this->oConfig->get('steam_api_url'));
        $oResponse = json_decode(file_get_contents($sApiUrl));

        if (isset($oResponse->response->players[0])) {
            $oPlayer = $oResponse->response->players[0];

            if (isset($oPlayer->gameextrainfo)) {

                return [
                    'status' => sprintf('%s is playing %s on Steam.', $oPlayer->personaname, $oPlayer->gameextrainfo),
                    'playing_now' => true,
                    'game' => $oPlayer->gameextrainfo,
                ];

            } else {
                $sStatus = $this->getSteamStatusName($oPlayer->personastate);

                if ($sStatus == 'offline' && !empty($oPlayer->lastlogoff)) {

                    $iLastOnline = time() - $oPlayer->lastlogoff;
                    if ($iLastOnline < 60) {
                        //last online less than 1 minute ago, show in seconds
                        $sAgo = sprintf('Last online %d seconds ago.', $iLastOnline);
                    } elseif ($iLastOnline < 3600) {
                        //last online less than 1 hour ago, show in minutes
                        $sAgo = sprintf('Last online %d minutes ago.', ($iLastOnline / 60));
                    } elseif ($iLastOnline < 24 * 3600) {
                        //last online less than 1 day ago, show in hours
                        $sAgo = sprintf('Last online %d hours ago.', ($iLastOnline / 3600));
                    } else {
                        //show in days
                        $sAgo = sprintf('Last online %d days ago.', ($iLastOnline / (24 * 3600)));
                    }

                    return [
                        'status' => sprintf('%s is currently %s on Steam. %s', $oPlayer->personaname, $sStatus, $sAgo),
                        'playing_now' => false,
                        'game' => 'offline',
                    ];

                } else {

                    //technically the short status isn't 'offline' but who cares about away/busy/etc status changes
                    return [
                        'status' => sprintf('%s is currently %s on Steam.', $oPlayer->personaname, $sStatus),
                        'playing_now' => false,
                        'game' => 'offline',
                    ];
                }
            }
        }

        return false;
    }

    private function getSteamStatusName($iStatus)
    {
        $aStatuses = [
            0 => 'offline',
            1 => 'online',
            2 => 'busy',
            3 => 'away',
            4 => 'snooze',
            5 => 'looking to trade',
            6 => 'looking to play',
        ];

        return (isset($aStatuses[$iStatus]) ? $aStatuses[$iStatus] : 'offline');
    }

    private function getXboxStatus()
    {
        $sApiUrl = str_replace(':xbox_xuid', $this->oConfig->get('xbox_xuid'), $this->oConfig->get('xbox_api_url'));
        $sGamerTag = 'mrbellek'; //we could looks this up by xuid but it's hella slow

        $oCurl = curl_init();
        curl_setopt_array($oCurl, array(
            CURLOPT_URL => $sApiUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            //CURLOPT_HEADER => true,
            CURLOPT_HTTPHEADER => ['X-AUTH: ' . XBOX_API_KEY],
        ));

        //run curl request and strip any stupid escaped unicode from response (like copyright signs)
        $sResult = preg_replace('/\\\u\S+/', '', curl_exec($oCurl));

        //convert to object
        $oResult = json_decode($sResult);
        curl_close($oCurl);

        //var_dump($sResult, $oResult);

        //$oResult = json_decode(preg_replace('/\\\u\S+/', '', '{"xuid":2533274827047006,"state":"Offline","lastSeen":{"deviceType":"Xbox360","titleId":1464993871,"titleName":"LEGO\u00ae Marvel\'s Avengers","timestamp":"2017-03-12T21:21:24.0313969Z"}}'));
        //$oResult = json_decode(preg_replace('/\\\u\S+/', '', '{"xuid":2533274929739371,"state":"Online","devices":[{"type":"XboxOne","titles":[{"id":714681658,"name":"Home","placement":"Background","state":"Active","lastModified":"2017-03-14T09:53:09.6990805Z"},{"id":345903534,"activity":{"richPresence":"Idle"},"name":"Minecraft: Xbox One Edition","placement":"Full","state":"Active","lastModified":"2017-03-14T09:53:09.6990805Z"}]}]}'));
        //
        //$oResult = json_decode('{"xuid":2533274930951356,"state":"Online","devices":[{"type":"XboxOne","titles":[{"id":714681658,"name":"Home","placement":"Full","state":"Active","lastModified":"2017-03-14T10:23:48.2638109Z"}]},{"type":"WindowsOneCore","titles":[{"id":328178078,"name":"Xbox App","placement":"Full","state":"Active","lastModified":"2017-03-14T10:21:20.1596501Z"}]}]}');

        //so far this seems to only be 'Online' or 'Offline'
        $sStatus = strtolower($oResult->state);

        //if we are offline, show 'x is offline' and add 'last seen ..' if possible
        if ($sStatus == 'offline') {

            //is there 'last seen' data?
            if (!empty($oResult->lastSeen)) {

                //add a space between 'XboxOne' or 'Xbox360'
                $sDevice = str_replace('Xbox', 'Xbox ', $oResult->lastSeen->deviceType);

                $sPlayed = $oResult->lastSeen->titleName;

                $iLastOnline = time() - strtotime($oResult->lastSeen->timestamp);
                if ($iLastOnline < 60) {
                    //last online less than 1 minute ago, show in seconds
                    $sAgo = sprintf('Last online %d seconds ago playing %s on %s.', $iLastOnline, $sPlayed, $sDevice);
                } elseif ($iLastOnline < 3600) {
                    //last online less than 1 hour ago, show in minutes
                    $sAgo = sprintf('Last online %d minutes ago playing %s on %s.', ($iLastOnline / 60), $sPlayed, $sDevice);
                } elseif ($iLastOnline < 24 * 3600) {
                    //last online less than 1 day ago, show in hours
                    $sAgo = sprintf('Last online %d hours ago playing %s on %s.', ($iLastOnline / 3600), $sPlayed, $sDevice);
                } else {
                    //show in days
                    $sAgo = sprintf('Last online %d days ago playing %s on %s.', ($iLastOnline / (24 * 3600)), $sPlayed, $sDevice);
                }
            } else {

                //if not, don't show that info
                $sAgo = false;
            }

            return [
                'status' => trim(sprintf('%s is currently %s on Xbox Live. %s', $sGamerTag, $sStatus, $sAgo)),
                'playing_now' => false,
                'game' => 'offline',
            ];

        } else if ($sStatus == 'online') {

            //for online status, try to show currently played game
            $sPlaying = false;
            $sActitivy = false;

            //for some reason you can be online on multiple devices at the same time (like win10 and xb1?)
            foreach ($oResult->devices as $oDevice) {

                //just show xbox status, not win10 (?)
                if (strtolower($oDevice->type) != 'windowsonecore') {
                    $sDevice = str_replace('Xbox', 'Xbox ', $oDevice->type);
                }

                //search for whatever game is currently in the foreground
                foreach ($oDevice->titles as $oTitle) {
                    if (strtolower($oTitle->placement) == 'full') {

                        //add rich activity (in-game status)
                        if (!empty($oTitle->activity)) {
                            $sActivity = '(' . $oTitle->activity->richPresence . ')';
                        }

                        //if current app is not the home screen or the xbox app on win10,
                        //assume it's the currently played game and stop
                        if (!in_array(strtolower($oTitle->name), ['home', 'xbox app'])) {
                            $sPlaying = $oTitle->name;
                            break 2;
                        }
                    }
                }
            }

            //if we have currently played game show that, otherwise just show 'online'
            if ($sPlaying) {

                return [
                    'status' => sprintf('%s is currently playing %s %s on %s', $sGamerTag, $sPlaying, $sActivity, $sDevice),
                    'playing_now' => true,
                    'game' => $sPlaying,
                ];

            } else {

                return [
                    'status' => sprintf('%s is currently %s on %s', $sGamerTag, $sStatus, $sDevice),
                    'playing_now' => false,
                    'game' => 'online',
                ];
            }
        } else {
            $this->logger->output('Unexpected Xbox status: %s, halting', $sStatus);
        }

        return false;
    }
}
