<?php
namespace Twitterbot\Core;

/**
 * TODO:
 * - sanitize tweets:
 *   v escape mentions
 *   v include images, links
 *   x unescape html entities
 *   - fix foreign chars
 *   - expand truncated tweets
 *   x include videos
 *   - post original tweets with media t.co links and all... seems to work!
 *
 * - run bot every 15 minutes, post everything that was posted within 2 years + 15 minutes ago
 */
require_once('autoload.php');
require_once('obamareplay.inc.php');

use Twitterbot\Lib\Logger;
use Twitterbot\Lib\Config;
use Twitterbot\Lib\Auth;
use Twitterbot\Lib\Tweet;
use Twitterbot\Lib\Database;
//use Twitterbot\Lib\Media;

(new Obamareplay)->run();

class Obamareplay {

    public function __construct()
    {
        $this->sUsername = 'ObamaReplay';
        $this->logger = new Logger;
    }

    public function run()
    {
        $this->oConfig = new Config;
        if ($this->oConfig->load($this->sUsername)) {

            if ((new Auth($this->oConfig))->isUserAuthed($this->sUsername)) {

                $this->db = (new Database($this->oConfig));

                $this->logger->output('Fetching tweets from 2 years ago +/- 15 minutes..');
                if ($aTweets = $this->getTweets(15 * 60)) {
                    $this->logger->output('Replaying %d tweets..', count($aTweets));
                    $oTweet = new Tweet($this->oConfig);
                    foreach ($aTweets as $aTweet) {
                        $sMediaId = false;
                        $sTweet = $aTweet['text'];
                        /*if ($aTweet['attachment']) {
                            $sAttachment = $sTweet['attachment'] . '.' . $sTweet['type'];
                            if (is_file('potus_images/' . $sAttachments)) {
                                $this->logger->output(sprintf('- uploading %s..', $sAttachment));
                                $sMediaId = (new Media($this->oConfiog))->upload('potus_images/' . $sAttachment);
                                if ($sMediaId) {
                                    $oTweet->setMedia($sMediaId);
                                }
                            }
                        }*/

                        $this->logger->output(sprintf('- posting: %s', $sTweet));
                        if ($oTweet->post($sTweet)) {
                            $this->markPosted($aTweet['id']);
                        }
                    }
                } else {
                    $this->logger->output('- nothing to do.');
                }
            }
        }
    }

    private function getTweets($iSeconds)
    {
        $aTweets = $this->db->query('
            SELECT *
            FROM obamareplay
            WHERE created_at BETWEEN DATE_SUB(DATE_SUB(NOW(), INTERVAL 2 YEAR), INTERVAL :seconds SECOND) AND DATE_SUB(NOW(), INTERVAL 2 YEAR)
            AND posted IS NULL',
            [':seconds' => $iSeconds]
        );

        return $aTweets;
    }

    private function markPosted($id)
    {
        $this->db->query('
            UPDATE obamareplay
            SET posted = NOW()
            WHERE id = :id
            LIMIT 1',
            [':id' => $id]
        );
    }
}
