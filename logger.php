<?php
require_once('logger.inc.php');

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

		die(var_dump(func_get_args()));
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
}

//TwitterLogger::write('username', 'warning', 'no error', __FILE__, 24);
