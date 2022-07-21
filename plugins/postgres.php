<?php

/* Copyright (C) 2022 Manticore Software Ltd
 * You may use, distribute and modify this code under the
 * terms of the AGPLv3 license.
 *
 * You can find a copy of the AGPLv3 license here
 * https://www.gnu.org/licenses/agpl-3.0.txt
 */

class postgres extends engine
{

    protected $port = 5432;
    protected $mysql = null; // mysql connection
    protected $user = 'postgres';
    protected $db = "default";

    public function __construct($type)
    {
        parent::__construct($type);

        $extensions = get_loaded_extensions();
        $extensions = array_flip($extensions);
        if (!isset($extensions['pgsql'])) {
            throw new RuntimeException('PGSQL extension is not enabled');
        }

    }

    protected function url()
    {
        return "https://www.postgresql.org/";
    }

    protected function description()
    {
        return "The PostgreSQL object-relational database system provides reliability and data integrity.";
    }

    private function getConnection()
    {

        if ($this->mysql !== null) {
            return $this->mysql;
        }
        return pg_pconnect("host=127.0.0.1 port=$this->port user=$this->user");
    }

    // attempts to fetch info about engine and return it
    protected function getInfo()
    {

        $connection = $this->getConnection();
        $status = pg_connection_status($connection);
        if ($status === PGSQL_CONNECTION_OK) {
            $result = pg_query($connection, "SELECT name, setting FROM pg_settings;");
            if ($result) {
                while ($row = pg_fetch_row($result)) {
                    $out[$row[0]] = $row[1];
                }
                $ret['variables'] = $out;
            }

            $result = pg_query($connection, "SHOW server_version;");
            if ($result) {
                $result = pg_fetch_all($result);
                $ret['version'] = $result[0]['server_version'];
            }
        }


        return $ret ?? false;
    }

    protected function appendType($info)
    {
        return "";
    }

    protected function prepareQuery($query)
    {
        return $query; // no modifications required
    }

    // returns true when it's possible to connect to engine and it can run a simple query
    protected function canConnect()
    {
        $connection = $this->getConnection();
        $status = pg_connection_status($connection);
        if ($status === PGSQL_CONNECTION_OK) {
            $result = pg_query($connection, "show all");
            if ($result) {
                return true;
            }

        } else {
            if (pg_ping($connection)) {
                $result = pg_query($connection, "show all");
                if ($result) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function beforeQuery()
    {
        $this->mysql = $this->getConnection();

        $timeout = self::$commandLineArguments['query_timeout'] * 1000;
        pg_query($this->mysql, "SET statement_timeout TO ".$timeout.";");
    }

    // runs one query against engine
    // must respect self::$commandLineArguments['query_timeout']
    // must return ['timeout' => true] in case of timeout
    protected function testOnce($query)
    {
        if (!pg_connection_busy($this->mysql)) {
            pg_send_query($this->mysql, $query.';');
        }

        $res = pg_get_result($this->mysql);
        if ($res) {
            $state = pg_result_error_field($res, PGSQL_DIAG_SQLSTATE);

            if ($state == 0) {
                return $res;
            } else {
                $errorDescription = pg_result_error($res);
                $out = ['mysqlError' => trim($errorDescription), 'mysqlErrorCode' => $state];

                // TODO check timeout code.
                if ($state == "!@#SA") {
                    $out['timeout'] = true;
                }
                return $out;
            }
        }
    }

    // parses query result and returns it in the format that should be common across all engines
    protected function parseResult($result)
    {
        $res = [];
        if ($result and pg_num_rows($result) > 0) {
            while ($hit = pg_fetch_assoc($result)) {
                $ar = [];
                foreach ($hit as $k => $v) {
                    if ($k == 'id') {
                        continue;
                    } // removing id from the output sice Elasticsearch can't return it https://github.com/elastic/elasticsearch/issues/30266
                    if (is_float($v)) {
                        $v = round($v, 4);
                    } // this is a workaround against different floating point calculations in different engines
                    $ar[$k] = $v;
                }
                ksort($ar);
                $res[] = $ar;
            }
        }
        return $res;
    }

    // sends a command to engine to drop its caches
    protected function dropEngineCache()
    {
        if (!pg_connection_busy($this->getConnection())) {
            $query = pg_query($this->getConnection(), "DISCARD PLANS;");
        }

        if (!empty($query)) {
            return true;
        }

        return false;
    }
}