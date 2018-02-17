<?php
require_once('autoload.php');
require_once('xboxpsfreegames.inc.php');

use Twitterbot\Lib\Logger;
use Twitterbot\Lib\Config;
use Twitterbot\Lib\Auth;
use Twitterbot\Lib\Database;
use Twitterbot\Lib\Format;
use Twitterbot\Lib\Tweet;

(new GamesWithGold)->run();

class GamesWithGold
{
    public function __construct()
    {
        $this->sUsername = 'XboxPSfreegames';
        $this->logger = new Logger;
    }

    public function run()
    {
        $this->oConfig = new Config;
        if ($this->oConfig->load($this->sUsername)) {

            $this->db = new Database($this->oConfig);

            if ((new Auth($this->oConfig))->isUserAuthed($this->sUsername)) {

                $oFormat = new Format($this->oConfig);
                $oTweet = new Tweet($this->oConfig);

                $this->logger->output('Fetching games that start being free today..');
                $aGames = $this->getStartingGames();

                if ($aGames) {
                    $this->logger->output('- %d found!', count($aGames));
                    foreach ($aGames as $oGame) {
                        $oRecord = $this->prepareFormatStart($oGame);
                        $sTweet = $oFormat->format($oRecord);

                        if ($sTweet) {
                            $oTweet->post($sTweet);
                        }
                    }
                } else {
                    $this->logger->output('- none for today.');
                }

                $this->logger->output('Fetching games that stop being free tomorrow..');
                $aGames = $this->getEndingGames();

                if ($aGames) {
                    $this->logger->output('- %d found!', count($aGames));
                    foreach ($aGames as $oGame) {
                        $oRecord = $this->prepareFormatStop($oGame);
                        $sTweet = $oFormat->format($oRecord);

                        if ($sTweet) {
                            $oTweet->post($sTweet);
                        }
                    }
                } else {
                    $this->logger->output('- none for tomorrow.');
                }

                $this->logger->output('done!');
            }
        }
    }

    private function getStartingGames()
    {
        $aGames = $this->db->query('
            SELECT *,
                IF (platform LIKE "%xbox%", 1, 0) AS xbox,
                IF (platform LIKE "%playstation%", 1, 0) AS playstation
            FROM gameswithgold
            WHERE startdate = CURDATE()'
        );

        $aGamesObj = [];
        foreach ($aGames as $aGame) {
            $aGamesObj[] = (object)$aGame;
        }

        return $aGamesObj;
    }

    private function getEndingGames()
    {
        $aGames = $this->db->query('
            SELECT *,
                IF (platform LIKE "%xbox%", 1, 0) AS xbox,
                IF (platform LIKE "%playstation%", 1, 0) AS playstation
            FROM gameswithgold
            WHERE enddate = CURDATE()'
        );

        $aGamesObj = [];
        foreach ($aGames as $aGame) {
            $aGamesObj[] = (object)$aGame;
        }

        return $aGamesObj;
    }

    private function prepareFormatStart($oGame)
    {
        return $this->prepareFormat($oGame, 'start');
    }

    private function prepareFormatStop($oGame)
    {
        return $this->prepareFormat($oGame, 'stop');
    }
    
    private function prepareFormat($oGame, $sAction)
    {
        $oFormats = $this->oConfig->get('formats');

        if ($oGame->xbox) {
            //format as xbox tweet
            $this->oConfig->set('format', $oFormats->xbox->{$sAction});
            $sDefaultLink = $oFormats->xbox->default_link;
        } elseif ($oGame->playstation) {
            //format as psn tweet
            $this->oConfig->set('format', $oFormats->psn->{$sAction});
            $sDefaultLink = $oFormats->psn->default_link;
        }
        $oRecord = (object)[
            'platform' => $oGame->platform,
            'game' => $oGame->game,
            'link' => ($oGame->link ? $oGame->link : $sDefaultLink),
        ];

        return $oRecord;
    }
}
