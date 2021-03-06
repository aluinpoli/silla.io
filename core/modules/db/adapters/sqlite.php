<?php
/**
 * SqlLite adapter.
 *
 * @package    Silla.IO
 * @subpackage Core\Modules\DB\Adapters
 * @author     Kalin Stefanov <kalin@athlonsofia.com>
 * @copyright  Copyright (c) 2015, Silla.io
 * @license    http://opensource.org/licenses/GPL-3.0 GNU General Public License, version 3.0 (GPLv3)
 */

namespace Core\Modules\DB\Adapters;

use Core;
use Core\Modules\DB;
use Core\Modules\DB\Interfaces;

/**
 * Database management driver wrapping SQL Lite 3 extension.
 */
class SQLite extends \SQLite3 implements Interfaces\Adapter
{
    /**
     * DB Cache instance.
     *
     * @var Core\Modules\DB\DbCache
     */
    public $cache;

    /**
     * Run method.
     *
     * @param DB\Query $query Current query object.
     *
     * @return mixed
     */
    public function run(DB\Query $query)
    {
        $query->appendTablesPrefix(Core\Config()->DB['tables_prefix']);
        $sql = $this->buildSql($query);
        $query_hash = md5(serialize(array('query' => $sql, 'bind_params' => $query->bind_params)));

        $query_cache_name = implode(',', array($query->table, implode(',', array_map(function ($item) {
            return $item['table'];
        }, $query->join))));

        if (array_key_exists($query_hash, Core\DbCache()->getCache($query_cache_name))) {
            return Core\DbCache()->getCache($query_cache_name, $query_hash);
        }

        $this->storeQueries($sql, $query->bind_params);

        if ($query->type === 'select') {
            $res = $this->query($sql, $query->bind_params);
            Core\DbCache()->setCache($query_cache_name, $query_hash, $res);

            return $res;
        }

        foreach (Core\DbCache()->cache as $table => $value) {
            if (in_array($query->table, explode(',', $table), true)) {
                Core\DbCache()->clearCache($table);
            }
        }

        return $this->execute($sql, $query->bind_params);
    }

    /**
     * Query construction method.
     *
     * @param string $sql         SQL query.
     * @param array  $bind_params Query parameters.
     *
     * @return array|\SQLite3Result
     */
    public function query($sql, array $bind_params = array())
    {
        if (count($bind_params) > 0) {
            $stmt = $this->prepare($sql);

            foreach ($bind_params as $key => $val) {
                $stmt->bindValue($key + 1, $val);
            }

            $resource = $stmt->execute();
        } else {
            $resource = parent::query($sql);
        }

        return $this->fetchAll($resource);
    }

    /**
     * Execution method.
     *
     * @param string $sql         SQL query string.
     * @param array  $bind_params Query parameters.
     *
     * @return boolean|\SQLite3Result
     */
    public function execute($sql, array $bind_params = array())
    {
        if (count($bind_params) > 0) {
            $stmt = $this->prepare($sql);
            foreach ($bind_params as $key => $val) {
                $stmt->bindValue($key + 1, $val);
            }

            $result = $stmt->execute();
        } else {
            $result = $this->exec($sql);
        }

        return $result;
    }

    /**
     * Set charset method.
     *
     * @param string $charset Character type string.
     *
     * @return boolean|\SQLite3Result
     */
    public function setCharset($charset = 'utf8')
    {
        return $this->execute('PRAGMA encoding = ' . $charset);
    }

    /**
     * Retrieves tables list method.
     *
     * @param string $schema Schema contents.
     *
     * @return array
     */
    public function getTables($schema)
    {
        return Core\Utils::arrayFlatten($this->query("SELECT NAME FROM sqlite_master WHERE type='table'"));
    }

    /**
     * Retrieves table schema.
     *
     * @param string $table  Table name.
     * @param mixed  $schema Schema contents.
     *
     * @return array
     */
    public function getTableSchema($table, $schema)
    {
        $columns = $this->query("PRAGMA table_info({$table})");
        $indexes_list = $this->query("PRAGMA index_list({$table})");
        $indexes = array();
        foreach ($indexes_list as $index) {
            $indexes_info = $this->query("PRAGMA index_info({$index['name']})");
            foreach ($indexes_info as $index_info) {
                $indexes[$index_info['name']] = $index['unique'];
            }
        }

        $res = array();

        foreach ($columns as $column) {
            $column_index = isset($indexes[$column['name']]) ? $indexes[$column['name']] : '';
            $res[] = array(
                'COLUMN_NAME' => $column['name'],
                'DATA_TYPE' => $column['type'],
                'IS_NULLABLE' => $column['notnull'] ? 'NO' : 'YES',
                'COLUMN_DEFAULT' => $column['dflt_value'],
                'COLUMN_KEY' => $column_index ? 'UNI' : '',
                'CHARACTER_MAXIMUM_LENGTH' => 2147483647,
                'EXTRA' => $column['pk'] ? 'auto_increment' : ''
            );
        }

        return $res;
    }

    /**
     * Store queries method.
     *
     * @param string $query  Query string.
     * @param array  $params Query parameters.
     *
     * @return void
     */
    public function storeQueries($query, array $params = array())
    {
        if (empty($params)) {
            DB\DB::$queries[] = $query;
        } else {
            DB\DB::$queries[] = array('query' => $query, 'params' => $params);
        }
    }

    /**
     * Retrieval of the last inserted id.
     *
     * @return integer
     */
    public function getLastInsertId()
    {
        return $this->lastInsertRowID();
    }

    /**
     * Clears a table.
     *
     * @param string $table Table name.
     *
     * @return boolean
     */
    public function clearTable($table)
    {
        foreach (Core\DbCache()->cache as $tbl => $value) {
            if (in_array($table, explode(',', $tbl), true)) {
                Core\DbCache()->clearCache($tbl);
            }
        }

        return $this->exec("DELETE FROM {$table}") && $this->exec("DELETE FROM sqlite_sequence WHERE name='{$table}'");
    }

    /**
     * SQL builder method.
     *
     * @param DB\Query $query Query string.
     *
     * @throws \DomainException DB Adapter does not support the required JOIN type.
     *
     * @return string
     */
    private function buildSql(DB\Query $query)
    {
        $sql = array();

        if ($query->type === 'select') {
            $sql[] = 'SELECT';
            $sql[] = ($query->db_fields === 'all') ? '*' :
                (is_array($query->db_fields) ? implode(',', $query->db_fields) : $query->db_fields);
            $sql[] = 'FROM';
            $sql[] = $query->table;

            if ($query->join) {
                foreach ($query->join as $join) {
                    if (!in_array($join['type'], self::getSupportedJoinTypes(), true)) {
                        throw new \DomainException('DB Adapter not supporting the required JOIN type:' . $join['type']);
                    }

                    $sql[] = $join['type'];
                    $sql[] = 'JOIN';
                    $sql[] = Core\Config()->DB['tables_prefix'] . $join['table'];

                    if ($join['condition']) {
                        $sql[] = 'ON (' . $join['condition'] . ')';
                    }
                }
            }

            if ($query->where) {
                $sql[] = 'WHERE';
                $sql[] = implode(' AND ', array_map(function ($item) {
                    return '(' . $item . ')';
                }, $query->where));
            }

            if ($query->order) {
                $sql[] = 'ORDER BY';
                $sql[] = implode(', ', array_map(function ($item) {
                    return "{$item['field']} {$item['direction']}";
                }, $query->order));
            }

            if ($query->limit) {
                $sql[] = 'LIMIT';
                $sql[] = $query->limit;

                if ($query->offset) {
                    $sql[] = 'OFFSET';
                    $sql[] = $query->offset;
                }
            }
        } elseif ($query->type === 'insert') {
            $sql[] = 'INSERT OR IGNORE INTO';
            $sql[] = $query->table;
            $sql[] = '(' . implode(',', $query->db_fields) . ')';

            if (isset($query->bind_params[0]) && is_array($query->bind_params[0])) {
                $sql[] = 'SELECT ' . implode(',', array_map(function ($item) {
                    return '? as ' . $item;
                }, $query->db_fields));
                $sql[] = implode(' ', array_map(function ($item) {
                    return 'UNION SELECT ' . implode(',', array_map(function () {
                        return '?';
                    }, $item));
                }, array_slice($query->bind_params, 1)));
                $query->bind_params = Core\Utils::arrayFlatten($query->bind_params);
            } else {
                $sql[] = 'VALUES';
                $sql[] = '(' . implode(',', array_map(function () {
                    return '?';
                }, $query->bind_params)) . ')';
            }
        } elseif ($query->type === 'update') {
            $sql[] = 'UPDATE';
            $sql[] = $query->table;
            $sql[] = 'SET';
            $sql[] = implode(',', array_map(function ($item) {
                return $item . ' = ?';
            }, $query->db_fields));
            $sql[] = 'WHERE';
            $sql[] = implode(' AND ', array_map(function ($item) {
                return '(' . $item . ')';
            }, $query->where));
        } elseif ($query->type === 'remove') {
            $sql[] = 'DELETE FROM';
            $sql[] = $query->table;
            $sql[] = 'WHERE';
            $sql[] = implode(' AND ', array_map(function ($item) {
                return '(' . $item . ')';
            }, $query->where));
        } elseif ($query->type === 'truncate') {
            $sql[] = 'DELETE FROM';
            $sql[] = $query->table;
        }

        return implode(' ', $sql);
    }

    /**
     * Retrieve all results.
     *
     * @param mixed $resource Resource source.
     *
     * @return array
     */
    private function fetchAll($resource)
    {
        $result = array();

        while ($row = $resource->fetchArray(SQLITE3_ASSOC)) {
            $result[] = $row;
        }

        return $result;
    }

    /**
     * Retrieves all supported types of JOIN.
     *
     * @static
     *
     * @return array
     */
    public static function getSupportedJoinTypes()
    {
        return array('INNER', 'LEFT', 'CROSS', 'LEFT OUTER');
    }
}
