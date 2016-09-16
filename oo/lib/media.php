<?php
namespace Twitterbot\Lib;

class Media extends Base
{
    public function upload($sFilePath)
    {
        $sImageBinary = base64_encode(file_get_contents($sFilePath));
        if ($sImageBinary && strlen($sImageBinary) < 5 * pow(1024, 2)) { //max size is 3MB

            $oRet = $this->oTwitter->upload('media/upload', array('media' => $sImageBinary));
            if (isset($oRet->errors)) {
                $this->logger->write(2, sprintf('Twitter API call failed: media/upload (%s)', $oRet->errors[0]->message), array('file' => $sFilePath, 'length' => strlen($sFilePath)));
                $this->logger->output('- Error: ' . $oRet->errors[0]->message . ' (code ' . $oRet->errors[0]->code . ')');

                return false;
            } else {
                $this->logger->output("- Uploaded %s to attach to next tweet", $sFilePath);

                return $oRet->media_id_string;
            }
        }
    }
}
