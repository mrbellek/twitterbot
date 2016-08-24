<?php
namespace Twitterbot\Lib;

class File extends Base
{
    public function rebuildIndex()
    {
        //do not recreate index if filelist exists and is younger than the max age
        if ($this->oConfig->get('filelist') && (strtotime($this->oConfig->get('filelist_mtime')) + $this->oConfig->get('max_index_age') > time())) {

            $this->logger->output('- Using cached filelist');
            $this->aFileList = $this->oConfig->get('filelist');

            return;
        }

        $sFolder = DOCROOT . $this->oConfig->get('folder');
        $this->logger->output('- Scanning %s', $sFolder);

        $aFileList = $this->recursiveScan($sFolder);
        if ($aFileList) {
            natcasesort($aFileList);

            //convert list into keys of array with postcount
            $this->aFileList = array();
            foreach ($aFileList as $sFile) {
                $this->aFileList[utf8_encode($sFile)] = 0;
            }
            unset($this->aFileList['.']);
            unset($this->aFileList['..']);

            if ($aOldFileList = $this->oConfig->get('filelist')) {
                foreach ($aOldFileList as $sFile => $iPostCount) {

                    //carry over postcount from existing files
                    if (isset($this->aFileList[$sFile])) {
                        $this->aFileList[$sFile] = $iPostCount;
                    }
                }
            }

            $this->logger->output('- Writing filelist with %d entries to cache', count($this->aFileList));
            $this->writeFileList();

            return true;
        }
    }

    private function recursiveScan($sFolder)
    {
		$aFiles = scandir($sFolder);

		foreach ($aFiles as $key => $sFile) {

			if (is_dir($sFolder . DS . $sFile) && !in_array($sFile, array('.', '..'))) {
				unset($aFiles[$key]);
				$aSubFiles = $this->recursiveScan($sFolder . DS . $sFile);
				foreach ($aSubFiles as $sSubFile) {
					if (!in_array($sSubFile, array('.', '..'))) {
						$aFiles[] = $sFile . DS . $sSubFile;
					}
				}
			}
		}

		return $aFiles;
    }

    public function get()
    {
        $this->logger->output('Getting file..');

        //rebuild index, if needed
        $this->rebuildIndex();

        //get random file (lowest postcount) or random unposted file
        if ($this->oConfig->get('post_only_once', false) == true) {
            $sFilename = $this->getRandomUnposted();
        } else {
            $sFilename = $this->getRandom();
        }

        //get file info
        $sFilePath = DOCROOT . $this->oConfig->get('folder') . DS . utf8_decode($sFilename);
        $aImageInfo = getimagesize($sFilePath);

        //construct array
		$aFile = array(
			'filepath'  => $sFilePath,
			'dirname'   => pathinfo($sFilename, PATHINFO_DIRNAME),
			'filename'  => $sFilename,
			'basename'  => pathinfo($sFilePath, PATHINFO_FILENAME),
			'extension' => pathinfo($sFilePath, PATHINFO_EXTENSION),
			'size'      => number_format(filesize($sFilePath) / 1024, 0) . 'k',
			'width'     => $aImageInfo[0],
			'height'    => $aImageInfo[1],
			'created'   => date('Y-m-d', filectime($sFilePath)),
			'modified'  => date('Y-m-d', filemtime($sFilePath)),
		);

        //increase postcount for this file by 1 and write filelist to disk
        $this->increment($aFile);

        $this->logger->output('- File: %s', $sFilePath);

		return $aFile;
    }

    private function getRandom()
    {
        $this->logger->output('- Getting random file');

        //get lowest postcount in index
        global $iLowestCount;
        $iLowestCount = false;
        foreach ($this->aFileList as $sFilename => $iCount) {
            if ($iLowestCount === false || $iCount < $iLowestCount) {
                $iLowestCount = $iCount;
            }
        }

        //create temp array of files with lowest postcount
        $aTempIndex = array_filter((array) $this->aFileList, function($i) {
            global $iLowestCount;
            return ($i == $iLowestCount ? true : false);
        });

        //pick random file
        $sFilename = array_rand($aTempIndex);

        return $sFilename;
    }

    private  function getRandomUnposted()
    {
        $this->logger->output('- Getting unposted random file');

        //create temp array of all files that have postcount = 0 
        $aTempIndex = array_filter($this->aFileList, function($i) { return ($i == 0 ? true : false); });

        //pick random file
        $sFilename = array_rand($aTempIndex);

        return $sFilename;
    }

    public function increment($aFile)
    {
        $this->aFileList[$aFile['filename']]++;

        $this->writeFileList();
    }

    private function writeFileList()
    {
        $this->oConfig->set('filelist', $this->aFileList);
        $this->oConfig->set('filelist_mtime', date('Y-m-d H:i:s'));
        $this->oConfig->writeConfig();
    }
}
