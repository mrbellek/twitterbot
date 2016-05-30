<?php
require_once('logger.inc.php');

/*
 * TODO:
 * - pagination for view/search
 * - remember search when paging
 * - source (foldout when clicked?)
 */

class TwitterLogger {

	private static $oPDO;

	private static function connect() {

		try {
			self::$oPDO = new PDO('mysql:host=' . DB_LOGGER_HOST . ';dbname=' . DB_LOGGER_NAME, DB_LOGGER_USER, DB_LOGGER_PASS);
		} catch (Exception $e) {
			self::$oPDO = FALSE;
		}
	}

	public static function write($sUsername, $sLevel = 'warning', $sError, $sFile, $iLine, $aSource = array()) {

		if (!self::$oPDO) {
			self::connect();
		}

		if (self::$oPDO) {
			$oStm = self::$oPDO->prepare('
				INSERT INTO twitterlog
				(botname, error, level, line, file, source)
				VALUES
				(:name, :error, :level, :line, :file, :source)'
			);

			return $oStm->execute(
				array(
					'name'	=> $sUsername,
					'error'	=> $sError,
					'level'	=> $sLevel,
					'line'	=> $iLine,
					'file'	=> $sFile,
					'source' => ($aSource ? serialize($aSource) : NULL),
				)
			);
		}

		return FALSE;
	}

	public static function view($iPage = 1) {

		if (!self::$oPDO) {
			/*return array(
				array(
					'id' => 2,
					'botname' => 'rbimbofetish',
					'error' => 'Twitter API call failed: statuses/update (Status is over 140 characters.)',
					'level' => 'ERROR',
					'line' => 172,
					'file' => 'rssbot.php',
					'timestamp' => '2016-05-30 14:00:26',
					'source' => serialize(array(
						'object' => serialize('adsfjkdfhjksdlhfkjdhfkdjfhaksldjhfaskdjfhasldkjfhasdkjflsfkajsdhfkasdhfkasdhfaksdhfaksdfhjasdlfhksdfj'),
						'tweet' => 'something incredibly long and very tall so that its more than 140 chars http://reddit.com/r/bimbofetish/32awgrh/hotchickwithbigtits',
					)),
				),
				array(
					'id' => 2,
					'botname' => 'username',
					'error' => 'no error',
					'level' => 'warning',
					'line' => 24,
					'file' => 'twitterbot.php',
					'timestamp' => '2016-05-30 13:00:26',
					'source' => NULL,
				),
			);*/
			self::connect();
		}

		if (self::$oPDO) {
			$oStm = self::$oPDO->prepare('
				SELECT *
				FROM twitterlog
				ORDER BY timestamp DESC'
			);

			if ($oStm->execute()) {
				return $oStm->fetchAll(PDO::FETCH_ASSOC);
			}
		}

		return array();
	}


	public static function search($sSearch, $iPage = 1) {
		if (!self::$oPDO) {
			self::connect();
		}

		if (self::$oPDO) {
			$oStm = self::$oPDO->prepare('
				SELECT *
				FROm twitterlog
				WHERE botname LIKE :search
				OR error LIKE :search
				OR level LIKE :search'
			);

			if ($oStm->execute(array('search' => '%' . $sSearch . '%'))) {
				return $oStm->fetchAll(PDO::FETCH_ASSOC);
			}
		}

		return array();
	}

}
