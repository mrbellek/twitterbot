<?php
namespace Twitterbot\Lib;
use \PDO;

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
        //connect to database
		try {
			$this->oPDO = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME, DB_USER, DB_PASS);
		} catch(Exception $e) {
			$this->logger->write(2, sprintf('Database connection failed. (%s)', $e->getMessage()));
			$this->logger->output(sprintf('- Database connection failed. (%s)', $e->getMessage()));

			return false;
		}

        return true;
    }

    /**
     * Wrapper for fetching record and updating postcount
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
