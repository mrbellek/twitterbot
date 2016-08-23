<?php
namespace Twitterbot\Lib;

class Reply extends Base
{
    public $sUsername;
    public $bOnlyReplyToFriends = true;

    public function __construct()
    {
        parent::__construct();

        $this->sLastReplyFile = MYPATH . sprintf('/%s.json', $sUsername);
    }

    public function getNew()
    {
		$aLastSearch = @json_decode($this->sLastSearchFile, TRUE);
        if (!$aLastSearch || empty($aLastSearch['timestamp'])) {
            $aLastSearch = array(
                'timestamp' => 'never',
                'max_id' => 1,
            );
        }

        $this->logger->output('Checking mentions since %s for commands..', $aLastSearch['timestamp']);

        //fetch new mentions since last run
        $aMentions = $this->oTwitter->get('statuses/mentions_timeline', array(
            'count'         => 10,
			'since_id'		=> $aLastSearch['max_id'],
        ));

        if (is_object($aMentions) && !empty($aMentions->errors[0]->message)) {
            $this->logger->write(2, sprintf('Twitter API call failed: GET statuses/mentions_timeline (%s)', $aMentions->errors[0]->message));
            $this->logger->output(sprintf('- Failed getting mentions, halting. (%s)', $aMentions->errors[0]->message));
        }

        //if we have mentions, get friends for auth (we will only respond to commands from people we follow)
        if (count($aMentions) > 0) {
            $oRet = $this->oTwitter->get('friends/ids', array('screen_name' => $this->sUsername, 'stringify_ids' => TRUE));
            if (!empty($oRet->errors[0]->message)) {
                $this->logger->write(2, sprintf('Twitter API call failed: GET friends/ids (%s)', $aMentions->errors[0]->message));
                $this->logger->output(sprintf('- Failed getting friends, halting. (%s)', $aMentions->errors[0]->message));
            }
            $aFollowing = $oRet->ids;

        } else {
            $this->logger->output('- no new mentions.');
            return array();
        }

        if ($this->bOnlyReplyToFriends) {
            foreach ($aMentions as $i => $oMention) {

                //only reply to friends (people we are following)
                if (!in_array($oMention->user->id_str, $aFollowing)) {
                    unset($aMentions[$i]);
                }
            }
            printf('- %d new mentions (from friends)', count($aMentions));
        } else {
            printf('- %d new mentions', count($aMentions));
        }

        return $aMentions;
    }
}
