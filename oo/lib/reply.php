<?php
namespace Twitterbot\Lib;
use Twitterbot\Lib\Following;
use Twitterbot\Lib\Tweet;

class Reply extends Base
{
    public function getMentions()
    {
        $this->logger->output('Checking mentions since %s for commands..', $this->oConfig->get('last_mentions_timestamp', 'never'));

        //fetch new mentions since last run
        $aMentions = $this->oTwitter->get('statuses/mentions_timeline', array(
            'count'         => 10,
			'since_id'		=> $this->oConfig->get('last_mentions_check', 1),
        ));

        if (is_object($aMentions) && !empty($aMentions->errors[0]->message)) {
            $this->logger->write(2, sprintf('Twitter API call failed: GET statuses/mentions_timeline (%s)', $aMentions->errors[0]->message));
            $this->logger->output(sprintf('- Failed getting mentions, halting. (%s)', $aMentions->errors[0]->message));
        }

        //if we have mentions, get friends for auth (we will only respond to commands from people we follow)
        if (count($aMentions) > 0) {
            $aFollowing = (new Following)
                ->set('oConfig', $this->oConfig)
                ->getAll();

        } else {
            $this->logger->output('- no new mentions.');

            return true;
        }

        //only reply to friends (people we are following)
        if ($this->oConfig->get('only_reply_friends', true)) {
            foreach ($aMentions as $i => $oMention) {

                //remove all mentions from non-friends
                if (!in_array($oMention->user->id_str, $aFollowing)) {
                    unset($aMentions[$i]);
                }
            }
            printf('- %d new mentions (from friends)', count($aMentions));
        } else {
            printf('- %d new mentions', count($aMentions));
        }

        $this->aMentions = $aMentions;

        return true;
    }

    public function replyToMentions()
    {
        if (!isset($this->aMentions)) {
            $this->getMentions();
        }

        foreach ($this->aMentions as $oMention) {

            $sId = $oMention->id_str;
            $sCommand = str_replace(sprintf('@%s ', strtolower($this->oConfig->get('sUsername'))), '', strtolower($oMention->text));
            $this->logger->output('Parsing command \'%s\' from @%s..', $sCommand, $oMention->user->screen_name);

            switch ($sCommand) {
                case 'help':
                    (new Tweet)
                        ->set('oConfig', $this->oConfig)
                        ->replyTo($oMention, sprintf('Commands: help lastrun ratelimit. %s', ($this->oConfig->get('only_reply_friends', true) ? 'Only replies to friends.' : '')));
                    break;
                case 'lastrun':
                    $oSearch = $this->oConfig->get('search_strings');
                    if (isset($oSearch->{0}->timestamp)) {
                        (new Tweet)
                            ->set('oConfig', $this->oConfig)
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
