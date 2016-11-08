<?php
namespace Twitterbot\Lib;
use Twitterbot\Lib\Following;
use Twitterbot\Lib\Tweet;

/**
 * Check mentions and reply to them
 *
 * @param config:only_reply_friends
 */
class Reply extends Base
{
    private $aMentions = array();

    /**
     * Return new mentions since last check
     *
     * return array
     */
    public function getMentions()
    {
        if (!$this->aMentions) {
            $this->fetchMentions();
        }

        return $this->aMentions;
    }

    /**
     * Get new mentions (from followers) since last check
     *
     * return bool
     */
    public function fetchMentions()
    {
        $this->logger->output('Checking mentions since %s for commands..', $this->oConfig->get('last_mentions_timestamp', 'never'));

        //DEBUG
        if (is_file('mentions.dat')) {
            $this->aMentions = unserialize(file_get_contents('mentions.dat'));

            $this->logger->output('- %d new mentions', count($this->aMentions));
            return true;
        }
        //END DEBUG

        //fetch new mentions since last run
        $aMentions = $this->oTwitter->get('statuses/mentions_timeline', array(
            'count'         => 10,
			'since_id'		=> $this->oConfig->get('last_mentions_max_id', 1),
        ));

        if (is_object($aMentions) && !empty($aMentions->errors[0]->message)) {
            $this->logger->write(2, sprintf('Twitter API call failed: GET statuses/mentions_timeline (%s)', $aMentions->errors[0]->message));
            $this->logger->output(sprintf('- Failed getting mentions, halting. (%s)', $aMentions->errors[0]->message));
        }

        $this->oConfig->set('last_mentions_timestamp', date('Y-m-d H:i:s'));

        //if we have mentions, get friends for auth (we will only respond to commands from people that follow us)
        if (count($aMentions) > 0) {
            $oFollowing = new Following($this->oConfig);
            $aFollowing = $oFollowing->getAll();

        } else {
            $this->logger->output('- no new mentions.');
            $this->aMentions = array();

            return false;
        }

        //only reply to friends (people we are following)
        $bOnlyReplyToFriends = $this->oConfig->get('only_reply_friends', true);
        $iMaxId = 1;
        foreach ($aMentions as $i => $oMention) {
            $iMaxId = max($iMaxId, $oMention->id_str);

            if ($bOnlyReplyToFriends) {
                //remove all mentions from non-friends
                if ($oFollowing->isFollowing($oMention)) {
                    unset($aMentions[$i]);
                }
            }
        }

        printf('- %d new mentions %s', count($aMentions), ($bOnlyReplyToFriends ? '(from friends)' : ''));

        $this->aMentions = $aMentions;
        file_put_contents('mentions.dat', serialize($aMentions)); //DEBUG
        $this->oConfig->set('last_mentions_max_id', $iMaxId);
        //$this->oConfig->writeConfig(); //DEBUG

        return true;
    }

    /**
     * Reply to preset commands
     * TODO: finish this
     *
     * @return void
     */
    public function replyToMentions()
    {
        if (!$this->aMentions) {
            $this->getMentions();
        }

        foreach ($this->aMentions as $oMention) {

            $sText = strtolower($oMention->text);
            if (stripos($sText, '@' . $this->oConfig->get('sUsername')) !== 0) {
                //skip tweet because it was not directly to us
                continue;
            }

            $sId = $oMention->id_str;
            $sCommand = str_replace(sprintf('@%s ', strtolower($this->oConfig->get('sUsername'))), '', $sText);
            $this->logger->output('Parsing command \'%s\' from @%s..', $sCommand, $oMention->user->screen_name);

            switch ($sCommand) {
                case 'help':
                    (new Tweet($this->oConfig))
                        ->replyTo($oMention, sprintf('Commands: help lastrun ratelimit. %s', ($this->oConfig->get('only_reply_friends', true) ? 'Only replies to friends.' : '')));
                    break;
                case 'lastrun':
                    $oSearch = $this->oConfig->get('search_strings');
                    if (isset($oSearch->{0}->timestamp)) {
                        (new Tweet($this->oConfig))
                            ->replyTo($oMention, sprintf('Last script run was: %s', $oSearch->{0}->timestamp));
                    }
                    break;
                case 'ratelimit':
                    //TODO
                    break;
            }
        }
    }
}
