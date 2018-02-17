<?php
namespace Twitterbot\Lib;
use \PDO;
use \Exception;

/**
 * Database class, connect to database and run queries
 *
 * NOTE: do not use Logger::write here since there's a very high chance of
 * ending up in an infinite loop and stuff.
 */
class Database extends Base
{
    public function __construct($oConfig)
    {
        //override default constructor because we don't need a TwitterAPI object
        $this->oConfig = $oConfig;
        $this->logger = new Logger;
    }

    /**
     * Connect to database (PDO)
     *
     * @return bool
     */
    public function connect()
    {
        $this->checkConfig();

        try {
            //basic dns check to prevent warnings
            if (!$this->validIp4OrIp6Hostname(DB_HOST)) {
                throw new Exception('database hostname not found: ' . DB_HOST);
            }

            //connect to database
            $this->oPDO = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch(Exception $e) {
            //do not write to logger since database connection is down lol
            $this->logger->output(sprintf('- Database connection failed. (%s)', $e->getMessage()));

            die('FATAL' . PHP_EOL);
        }

        $this->checkDatabase();

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

        return $aResult ? $aResult[0] : [];
    }

    public function query_value($sQuery, $aData = [])
    {
        $aResult = $this->query($sQuery, $aData);

        return $aResult ? reset($aResult[0]) : false;
    }

    private function checkConfig()
    {
        if (!defined('DB_HOST') || !defined('DB_NAME') ||
            !defined('DB_USER') || !defined('DB_PASS')) {

            $this->logger->output('- One or more of the MySQL database credentials are missing, halting.');

            return false;
        }

        if (!$this->oConfig) {
            return true;
        }

        $this->oDbConf = $this->oConfig->get('db_settings');
        if ($this->oDbConf &&
            (empty($this->oDbConf->table) ||
            empty($this->oDbConf->idcol) ||
            empty($this->oDbConf->countercol) ||
            empty($this->oDbConf->timestampcol))) {

            $this->logger->output('- One or more of the database table settings are missing.');

            return false;
        }

        return true;
    }

    private function checkDatabase()
    {
        if (!$this->oConfig || !$this->oDbConf) {
            return false;
        }

        $aTable = $this->query('SELECT 1
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = :database
            AND TABLE_NAME = :table',
            [
                ':database' => DB_NAME,
                ':table' => $this->oDbConf->table,
            ]
        );

        if (!$aTable) {
            $this->logger->output('- Configured database table "%s" does not exist!', $this->oDbConf->table);

            die('FATAL');
        }

        foreach (['idcol', 'countercol', 'timestampcol'] as $sColumn) {
            $aTable = $this->query('SELECT 1
                FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = :database
                AND TABLE_NAME = :table
                AND COLUMN_NAME = :column',
                [
                    ':database' => DB_NAME,
                    ':table' => $this->oDbConf->table,
                    ':column' => $this->oDbConf->{$sColumn},
                ]
            );

            if (!$aTable) {
                $this->logger->output('- Configured database column "%s" does not exist in table "%s"!',
                    $this->oDbConf->{$sColumn},
                    $this->oDbConf->table
                );

                die('FATAL');
            }
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

        //get random record
        if ($this->oConfig->get('post_only_once', false)) {
            $aRecord = $this->getRandomUnpostedRecord();
        } else {
            $aRecord = $this->getRandomRecord();
        }

        if ($aRecord) {
            $this->logger->output("- Fetched record that has been posted %d times before.", $aRecord[$this->oDbConf->countercol]);

            //update record with postcount and timestamp of last post
            $this->query(sprintf('
                    UPDATE %1$s
                    SET %3$s = %3$s + 1,
                        %4$s = NOW()
                    WHERE %2$s = :id
                    LIMIT 1',
                    $this->oDbConf->table,
                    $this->oDbConf->idcol,
                    $this->oDbConf->countercol,
                    $this->oDbConf->timestampcol
                ),
                [':id' => $aRecord[$this->oDbConf->idcol]]
            );

            return $aRecord;
        }
    }

    /**
     * Get random record from database with lowest postcount
     *
     * @return array|false
     */
    private function getRandomRecord()
    {
        if (empty($this->oPDO)) {
            $this->connect();
        }

        $this->logger->output('- Fetching random record with lowest postcount..');

        //fetch random record out of those with the lowest counter value
        return $this->query_single(sprintf('
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
            )
        );
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
        return $this->query_single(sprintf('
                SELECT *
                FROM %1$s
                WHERE %2$s = 0
                ORDER BY RAND()
                LIMIT 1',
                $this->oDbConf->table,
                $this->oDbConf->countercol
            )
        );
    }

    private function validIp4OrIp6Hostname($host)
    {
        if ($this->gethostbyname6($host)) {
            return true;
        }

        if (gethostbyname($host) != $host) {
            return true;
        } else {
            return false;
        }
    }

    private function gethostbyname6($host)
    {
        $dns6 = dns_get_record($host, DNS_AAAA);
        $ipv6 = [];
        foreach ($dns6 as $record) {
            switch($record['type']) {
                //case 'A': $ipv4[] = $record['ip'];
                case 'AAAA': $ipv6[] = $record['ipv6'];
            }
        }

        return count($ipv6) > 0 ? $ipv6[0] : false;
    }
}
