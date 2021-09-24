<?php

namespace SimpleSAML\Module\sqlattribslookup\Auth\Process;

/**
 * Filter to add attributes from a SQL data source
 *
 * This filter allows you to add attributes via an SQL lookup
 *
 * @author    Aaron Parker
 * @copyright Copyright (c) 2021, SourceCube Ltd
 * @license   MIT License
 * @package   SimpleSAMLphp
 */
class LookupAttributesFromSQL extends \SimpleSAML\Auth\ProcessingFilter
{
    /** @var string The DSN we should connect to. */
    private $dsn = 'mysql:host=localhost;dbname=simplesamlphp';

    /** @var string The username we should connect to the database with. */
    private $username;

    /** @var string The password we should connect to the database with. */
    private $password;

    /** @var string The name of the database table to use. */
    private $table = 'samlLookup';

    /** @var string lookup attribute. */
    private $lookupAttr = 'urn:oid:2.5.4.10';

    /** @var string update attribute. */
    private $updateAttr = 'urn:oid:1.3.6.1.4.1.5923.1.1.1.7';

    /** @var bool|false Should we replace existing attribute? */
    private $replace = false;

    /** @var bool|false Should we ignore expiry */
    private $ignoreExpiry = false;

    /**
     * Initialize this filter, parse configuration.
     *
     * @param array $config Configuration information about this filter.
     * @param mixed $reserved For future use.
     * @throws \SimpleSAML\Error\Exception
     */
    public function __construct($config, $reserved)
    {
        parent::__construct($config, $reserved);
        assert(is_array($config));

        if (array_key_exists('attribute', $config)) {
            $this->lookupAttr = $config['attribute'];
        }
        if (!is_string($this->lookupAttr) || !$this->lookupAttr) {
            throw new \SimpleSAML\Error\Exception('LookupAttributesFromSQL: attribute name not valid');
        }

        if (array_key_exists('database', $config)) {
            if (array_key_exists('dsn', $config['database'])) {
                $this->dsn = $config['database']['dsn'];
            }
            if (array_key_exists('username', $config['database'])) {
                $this->username = $config['database']['username'];
            }
            if (array_key_exists('password', $config['database'])) {
                $this->password = $config['database']['password'];
            }
            if (array_key_exists('table', $config['database'])) {
                $this->table = $config['database']['table'];
            }
        }
        if (!is_string($this->dsn) || !$this->dsn) {
            throw new \SimpleSAML\Error\Exception('LookupAttributesFromSQL: invalid database DSN given');
        }
        if (!is_string($this->table) || !$this->table) {
            throw new \SimpleSAML\Error\Exception('LookupAttributesFromSQL: invalid database table');
        }

        if (array_key_exists('replace', $config)) {
            $this->replace = (bool)$config['replace'];
        }

        if (array_key_exists('limit', $config)) {
            if (!is_array($config['limit'])) {
                throw new \SimpleSAML\Error\Exception('LookupAttributesFromSQL: limit must be an array of attribute names');
            }
            $this->limit = $config['limit'];
        }

        if (array_key_exists('ignoreExpiry', $config)) {
            $this->ignoreExpiry = (bool)$config['ignoreExpiry'];
        }
    }

    /**
     * Create a database connection.
     *
     * @return \PDO The database connection.
     * @throws \SimpleSAML\Error\Exception
     */
    private function connect()
    {
        try {
            $db = new \PDO($this->dsn, $this->username, $this->password);
        } catch (\PDOException $e) {
            throw new \SimpleSAML\Error\Exception('LookupAttributesFromSQL: Failed to connect to \'' .
                $this->dsn . '\': ' . $e->getMessage()
            );
        }
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $driver = explode(':', $this->dsn, 2);
        $driver = strtolower($driver[0]);

        /* Driver specific initialization. */
        switch ($driver) {
            case 'mysql':
                $db->exec("SET NAMES 'utf8'");
                break;
            case 'pgsql':
                $db->exec("SET NAMES 'UTF8'");
                break;
        }

        return $db;
    }

    /**
     * Process this filter
     *
     * Logic is largely the same as (and lifted from) sqlauth:sql
     * @param mixed &$request
     * @throws \SimpleSAML\Error\Exception
     * @return void
     */
    public function process(&$request)
    {
        assert(is_array($request));
        assert(array_key_exists("Attributes", $request));
        assert(array_key_exists("entityid", $request["Destination"]));

        $attributes =& $request['Attributes'];

        if (!array_key_exists($this->lookupAttr, $attributes)) {
            \SimpleSAML\Logger::info('LookupAttributesFromSQL: attribute \'' . $this->lookupAttr . '\' not set, declining');
            return;
        }

        $db = $this->connect();

        try {
            $sth = $db->prepare(
                'SELECT `value` FROM ' .
                $this->table .
                ' WHERE `lookupattr`=? AND (`sp`=\'%\' OR `sp`=?)' .
                ($this->ignoreExpiry ? '' : ' AND `expires`>CURRENT_DATE') .
                ';'
            );
        } catch (\PDOException $e) {
            throw new \SimpleSAML\Error\Exception('LookupAttributesFromSQL: prepare() failed: ' . $e->getMessage());
        }

        try {
            $res = $sth->execute([$attributes[$this->lookupAttr][0], $request["Destination"]["entityid"]]);
        } catch (\PDOException $e) {
            throw new \SimpleSAML\Error\Exception(
                'LookupAttributesFromSQL: execute(' . $attributes[$this->lookupAttr][0] .
                ', ' . $request["Destination"]["entityid"] . ') failed: ' . $e->getMessage()
            );
        }

        try {
            $data = $sth->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            throw new \SimpleSAML\Error\Exception('LookupAttributesFromSQL: fetchAll() failed: ' . $e->getMessage());
        }

        if (count($data) === 0) {
            \SimpleSAML\Logger::info(
                'LookupAttributesFromSQL: no additional attributes for ' .
                $this->lookupAttr . '=\'' . $attributes[$this->lookupAttr][0] . '\''
            );
            return;
        }

        /* Extract attributes from the SQL datasource, and then merge them into
         * the existing attribute set. If $replace is set, overwrite any existing
         * attribute of the same name; otherwise add it as a multi-valued attribute
         */
        if (!array_key_exists($this->updateAttr, $attributes) || $this->replace === true) {
          $attributes[$this->updateAttr] = [];
        }
        foreach ($data as $row) {
            if ($row['value'] === null) {
                \SimpleSAML\Logger::debug('LookupAttributesFromSQL: skipping invalid value: ' . var_export($row, true));
                continue;
            }

            $value = (string)$row['value'];

            if (in_array($value, $attributes[$this->updateAttr], true)) {
                /* Value already exists in attribute. */
                \SimpleSAML\Logger::debug(
                    'LookupAttributesFromSQL: skipping duplicate attribute/value tuple ' .
                    $this->updateAttr . '=\'' . $value . '\''
                );
                continue;
            }

            $attributes[$this->updateAttr][] = $value;
        }
    }
}
