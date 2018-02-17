<?php
namespace Twitterbot\Lib;
 
class User extends Base
{
    public function get($sUsername)
    {
        return $this->oTwitter->get('users/show', array('screen_name' => $sUsername));
    }

    private function getById($id)
    {
        return $this->oTwitter->get('users/show', array('user_id' => $id));
    }
}
