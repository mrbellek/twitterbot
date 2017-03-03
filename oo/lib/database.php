<?php
namespace Twitterbot\Lib;
use \PDO;
use \Exception;

/**
 * Database class, connect to database and run queries
 */
class Database extends Base
{
    /**
     * Connect to database (PDO)
     *
     * @return bool
     */
    public function connect()
    {
		try {
            //basic dns check to prevent warnings
            if (gethostbyname(DB_HOST) == DB_HOST) {
                throw new Exception ('database hostname not found');
            }

            //connect to database
            $this->oPDO = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
		} catch(Exception $e) {
			$this->logger->write(2, sprintf('Database connection failed. (%s)', $e->getMessage()));
			$this->logger->output(sprintf('- Database connection failed. (%s)', $e->getMessage()));

            die();
		}

        return true;
    }

    /**
     * Generic query function wrapper
     *
     * @param string $sQuery - mysql query
     * @param array $aData - data for placeholders
     *
     * @return mixed
     */
    public function query($sQuery, $aData = [])
    {
        if (empty($this->oPDO)) {
            $this->connect();
        }

        try {
            //create statement
            if($sth = $this->oPDO->prepare($sQuery)) {

                foreach ($aData as $key => $value) {
                    //prefix colon
                    $key = (substr($key, 0, 1) == ':' ? $key : ':' . $key);

                    //bind as int if it looks like a number
                    if (is_numeric($value)) {
                        $sth->bindValue($key, $value, PDO::PARAM_INT);
                    } else {
                        $sth->bindValue($key, $value, PDO::PARAM_STR);
                    }
                }

                //run query
                $sth->execute();
            }
        } catch(Exception $e) {
            $this->logger->write(sprintf('FATAL: %s (query: %s with data %s', $e->getMessage(), $sQuery, var_export($aData, true)));
            $this->logger->output('FATAL: %s (query: %s with data %s', $e->getMessage(), $sQuery, var_export($aData, true));

            return false;
        }

        //what to return?
        $sQueryTemp = preg_replace('/^\s+/', '', strtolower($sQuery));
        if (strpos($sQueryTemp, 'insert') === 0) {
            //insert, return insert id
            return $this->oPDO->lastInsertId();

        } elseif (strpos($sQueryTemp, 'delete') === 0) {
            //delete, return bool
            return true;

        } elseif (strpos($sQueryTemp, 'update') === 0) {
            //update, return bool
            return true;

        } elseif (strpos($sQueryTemp, 'replace') === 0) {
            //replace into, return bool
            return true;

        } else {
            //assume select, return rows
            return $sth->fetchAll();
        }
    }

    public function query_single($sQuery, $aData = [])
    {
        $aResult = $this->query($sQuery, $aData);

        return $aResult[0];
    }

    public function query_value($sQuery, $aData = [])
    {
        $aResult = $this->query($sQuery, $aData);

        return reset($aResult[0]);
    }

    /**
     * Wrapper for fetching record and updating postcount
     * TODO: refactor this using above generic query() function
     *
     * @param config:db_settings
     * @param config:post_only_once
     *
     * @return array|false
     */
    public function getRecord()
    {
        if (empty($this->oPDO)) {
            $this->connect();
        }

		$this->logger->output('Getting random record from database..');

		if (!defined('DB_HOST') || !defined('DB_NAME') ||
			!defined('DB_USER') || !defined('DB_PASS')) {

			$this->logger->write(2, 'MySQL database credentials missing.');
			$this->logger->output('- One or more of the MySQL database credentials are missing, halting.');

			return false;
		}

        $this->oDbConf = $this->oConfig->get('db_settings');
        if (empty($this->oDbConf->table) ||
            empty($this->oDbConf->idcol) ||
            empty($this->oDbConf->countercol) ||
            empty($this->oDbConf->timestampcol)) {

			$this->logger->write(2, 'Database table settings missing.');
			$this->logger->output('- One or more of the database table settings are missing, halting.');

			return false;
		}

        //get random record
        if ($this->oConfig->get('post_only_once', false)) {
            $aRecord = $this->getRandomUnpostedRecord();
        } else {
            $aRecord = $this->getRandomRecord();
        }

        if ($aRecord) {
            $this->logger->output("- Fetched record that has been posted %d times before.", $aRecord['postcount']);

            //update record with postcount and timestamp of last post
            $sth = $this->oPDO->prepare(sprintf('
                UPDATE %1$s
                SET %3$s = %3$s + 1,
                    %4$s = NOW()
                WHERE %2$s = :id
                LIMIT 1',
                $this->oDbConf->table,
                $this->oDbConf->idcol,
                $this->oDbConf->countercol,
                $this->oDbConf->timestampcol
            ));

            $sth->bindValue(':id', $aRecord[$this->oDbConf->idcol], PDO::PARAM_INT);
            if ($sth->execute() == false) {
                $this->logger->write(2, sprintf('Update query failed. (%d %s)', $sth->errorCode(), $sth->errorInfo()));
                $this->logger->output(sprintf('- Update query failed, halting. (%d %s)', $sth->errorCode(), $sth->errorInfo()));

                return false;
            }

            return $aRecord;

        } else {
            $this->logger->write(3, 'Query yielded no results.');
            $this->logger->output('- Select query yielded no records, halting.');

            return false;
        }
    }

    /**
     * Get random record from database with lowest postcount
     * TODO: refactor this using above generic query() function
     *
     * @return array|false
     */
    private function getRandomRecord()
    {
        $this->logger->output('- Fetching random record with lowest postcount..');

        //fetch random record out of those with the lowest counter value
        $sth = $this->oPDO->prepare(sprintf('
            SELECT *
            FROM %1$s
            WHERE %2$s = (
                SELECT MIN(%2$s)
                FROM %1$s
            )
            ORDER BY RAND()
            LIMIT 1',
            $this->oDbConf->table,
            $this->oDbConf->countercol
        ));

		if ($sth->execute() == false) {
			$this->logger->write(2, sprintf('Select query failed. (%d %s)', $sth->errorCode(), $sth->errorInfo()));
			$this->logger->output(sprintf('- Select query failed, halting. (%d %s)', $sth->errorCode(), $sth->errorInfo()));

			return false;
		}

        return $sth->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Get random unposted record from database
     * TODO: refactor this using above generic query() function
     *
     * @return array|false
     */
    private function getRandomUnpostedRecord()
    {
        $this->logger->output('- Fetching random unposted record..');

        //fetch random unposted record
        $sth = $this->oPDO->prepare(sprintf('
            SELECT *
            FROM %1$s
            WHERE %2$s = 0
            ORDER BY RAND()
            LIMIT 1',
            $this->oDbConf->table,
            $this->oDbConf->countercol
        ));

		if ($sth->execute() == false) {
			$this->logger->write(2, sprintf('Select query failed. (%d %s)', $sth->errorCode(), $sth->errorInfo()));
			$this->logger->output(sprintf('- Select query failed, halting. (%d %s)', $sth->errorCode(), $sth->errorInfo()));

			return false;
		}

        return $sth->fetch(PDO::FETCH_ASSOC);
    }
}
