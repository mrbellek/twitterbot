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

	public static function write($sUsername, $sLevel = 'warning', $sError, $sFile, $iLine) {

		if (!self::$oPDO) {
			self::connect();
		}

		if (self::$oPDO) {
			$oStm = self::$oPDO->prepare('
				INSERT INTO twitterlog
				(botname, error, level, line, file)
				VALUES
				(:name, :error, :level, :line, :file)'
			);

			return $oStm->execute(
				array(
					'name'	=> $sUsername,
					'error'	=> $sError,
					'level'	=> $sLevel,
					'line'	=> $iLine,
					'file'	=> $sFile,
				)
			);
		}

		return FALSE;
	}

	public static function view($iPage = 1) {

		if (!self::$oPDO) {
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
