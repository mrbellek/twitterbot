<?php
namespace Twitterbot;

require_once('autoload.php');
require_once('realmgrinder.inc.php');

use Twitterbot\Lib\Logger;
use Twitterbot\Lib\Config;
use Twitterbot\Lib\Auth;
use Twitterbot\Lib\Tweet;
use Twitterbot\Lib\Database;

(new RealmGrinder)->run();

class RealmGrinder
{
    public function __construct()
    {
        $this->sUsername = 'RealmGrinder';
        $this->logger = new Logger;
    }

    public function run()
    {
        $this->oConfig = new Config;
        if ($this->oConfig->load($this->sUsername)) {
            if ((new Auth($this->oConfig))->isUserAuthed($this->sUsername)) {

                $this->db = new Database($this->oConfig);

                $this->logger->output('Fetching tweet from database...');
                if ($oTweet = $this->getTweet()) {

                    if ($sTweet = $this->formatTweet($oTweet)) {

                        $this->logger->output('- posting: %s', $sTweet);
                        if ((new Tweet($this->oConfig))->post($sTweet)) {

                            $this->markPosted($oTweet);
                        } else {
                            $this->logger->output('Failed to post tweet.');
                        }
                    } else {
                        $this->logger->output('Failed to format tweet.');
                    }
                } else {
                    $this->logger->output('Failed to fetch tweet.');
                }
            }
        }
        $this->logger->output('Done!');
    }

    private function getTweet()
    {
        $aTweet = $this->db->query_single('
            SELECT *
            FROM realmgrinder
            WHERE postcount = (
                SELECT MIN(postcount)
                FROM realmgrinder
                WHERE postcount >= 0
            )
            ORDER BY RAND()
            LIMIT 1'
        );

        return (object) $aTweet;
    }

    private function formatTweet($oTweet)
    {
        //replace all placeholders (%0, %1, %2 etc) with a random number
        //between 10 and 1000
        $sTweet = $oTweet->text;
        for ($i = 0; $i < 10; $i++) {
            $sTweet = str_replace('%' . $i, mt_rand(10, 1000), $sTweet);
        }

        return $sTweet;
    }

    private function markPosted($oTweet)
    {
        return $this->db->query('
            UPDATE realmgrinder
            SET postcount = postcount + 1
            WHERE id = :id
            LIMIT 1',
            [':id' => $oTweet->id]
        );
    }
}
