<?php
/**
 * @link http://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

namespace yii\db;

use Yii;
use yii\base\Object;
use yii\base\NotSupportedException;
use yii\base\InvalidCallException;
use yii\caching\Cache;
use yii\caching\GroupDependency;

/**
 * Schema is the base class for concrete DBMS-specific schema classes.
 *
 * Schema represents the database schema information that is DBMS specific.
 *
 * @property string $lastInsertID The row ID of the last row inserted, or the last value retrieved from the
 * sequence object. This property is read-only.
 * @property QueryBuilder $queryBuilder The query builder for this connection. This property is read-only.
 * @property string[] $tableNames All table names in the database. This property is read-only.
 * @property TableSchema[] $tableSchemas The metadata for all tables in the database. Each array element is an
 * instance of [[TableSchema]] or its child class. This property is read-only.
 *
 * @author Qiang Xue <qiang.xue@gmail.com>
 * @since 2.0
 */
abstract class Schema extends Object
{
	/**
	 * The followings are the supported abstract column data types.
	 */
	const TYPE_PK = 'pk';
	const TYPE_STRING = 'string';
	const TYPE_TEXT = 'text';
	const TYPE_SMALLINT = 'smallint';
	const TYPE_INTEGER = 'integer';
	const TYPE_BIGINT = 'bigint';
	const TYPE_FLOAT = 'float';
	const TYPE_DECIMAL = 'decimal';
	const TYPE_DATETIME = 'datetime';
	const TYPE_TIMESTAMP = 'timestamp';
	const TYPE_TIME = 'time';
	const TYPE_DATE = 'date';
	const TYPE_BINARY = 'binary';
	const TYPE_BOOLEAN = 'boolean';
	const TYPE_MONEY = 'money';

	/**
	 * @var Connection the database connection
	 */
	public $db;
	/**
	 * @var array list of ALL table names in the database
	 */
	private $_tableNames = array();
	/**
	 * @var array list of loaded table metadata (table name => TableSchema)
	 */
	private $_tables = array();
	/**
	 * @var QueryBuilder the query builder for this database
	 */
	private $_builder;

	/**
	 * Loads the metadata for the specified table.
	 * @param string $name table name
	 * @return TableSchema DBMS-dependent table metadata, null if the table does not exist.
	 */
	abstract protected function loadTableSchema($name);


	/**
	 * Obtains the metadata for the named table.
	 * @param string $name table name. The table name may contain schema name if any. Do not quote the table name.
	 * @param boolean $refresh whether to reload the table schema even if it is found in the cache.
	 * @return TableSchema table metadata. Null if the named table does not exist.
	 */
	public function getTableSchema($name, $refresh = false)
	{
		if (isset($this->_tables[$name]) && !$refresh) {
			return $this->_tables[$name];
		}

		$db = $this->db;
		$realName = $this->getRawTableName($name);

		if ($db->enableSchemaCache && !in_array($name, $db->schemaCacheExclude, true)) {
			/** @var $cache Cache */
			$cache = is_string($db->schemaCache) ? Yii::$app->getComponent($db->schemaCache) : $db->schemaCache;
			if ($cache instanceof Cache) {
				$key = $this->getCacheKey($name);
				if ($refresh || ($table = $cache->get($key)) === false) {
					$table = $this->loadTableSchema($realName);
					if ($table !== null) {
						$cache->set($key, $table, $db->schemaCacheDuration, new GroupDependency($this->getCacheGroup()));
					}
				}
				return $this->_tables[$name] = $table;
			}
		}
		return $this->_tables[$name] = $table = $this->loadTableSchema($realName);
	}

	/**
	 * Returns the cache key for the specified table name.
	 * @param string $name the table name
	 * @return mixed the cache key
	 */
	protected function getCacheKey($name)
	{
		return array(
			__CLASS__,
			$this->db->dsn,
			$this->db->username,
			$name,
		);
	}

	/**
	 * Returns the cache group name.
	 * This allows [[refresh()]] to invalidate all cached table schemas.
	 * @return string the cache group name
	 */
	protected function getCacheGroup()
	{
		return md5(serialize(array(
			__CLASS__,
			$this->db->dsn,
			$this->db->username,
		)));
	}

	/**
	 * Returns the metadata for all tables in the database.
	 * @param string $schema the schema of the tables. Defaults to empty string, meaning the current or default schema name.
	 * @param boolean $refresh whether to fetch the latest available table schemas. If this is false,
	 * cached data may be returned if available.
	 * @return TableSchema[] the metadata for all tables in the database.
	 * Each array element is an instance of [[TableSchema]] or its child class.
	 */
	public function getTableSchemas($schema = '', $refresh = false)
	{
		$tables = array();
		foreach ($this->getTableNames($schema, $refresh) as $name) {
			if ($schema !== '') {
				$name = $schema . '.' . $name;
			}
			if (($table = $this->getTableSchema($name, $refresh)) !== null) {
				$tables[] = $table;
			}
		}
		return $tables;
	}

	/**
	 * Returns all table names in the database.
	 * @param string $schema the schema of the tables. Defaults to empty string, meaning the current or default schema name.
	 * If not empty, the returned table names will be prefixed with the schema name.
	 * @param boolean $refresh whether to fetch the latest available table names. If this is false,
	 * table names fetched previously (if available) will be returned.
	 * @return string[] all table names in the database.
	 */
	public function getTableNames($schema = '', $refresh = false)
	{
		if (!isset($this->_tableNames[$schema]) || $refresh) {
			$this->_tableNames[$schema] = $this->findTableNames($schema);
		}
		return $this->_tableNames[$schema];
	}

	/**
	 * @return QueryBuilder the query builder for this connection.
	 */
	public function getQueryBuilder()
	{
		if ($this->_builder === null) {
			$this->_builder = $this->createQueryBuilder();
		}
		return $this->_builder;
	}

	/**
	 * Refreshes the schema.
	 * This method cleans up all cached table schemas so that they can be re-created later
	 * to reflect the database schema change.
	 */
	public function refresh()
	{
		/** @var $cache Cache */
		$cache = is_string($this->db->schemaCache) ? Yii::$app->getComponent($this->db->schemaCache) : $this->db->schemaCache;
		if ($this->db->enableSchemaCache && $cache instanceof Cache) {
			GroupDependency::invalidate($cache, $this->getCacheGroup());
		}
		$this->_tableNames = array();
		$this->_tables = array();
	}

	/**
	 * Creates a query builder for the database.
	 * This method may be overridden by child classes to create a DBMS-specific query builder.
	 * @return QueryBuilder query builder instance
	 */
	public function createQueryBuilder()
	{
		return new QueryBuilder($this->db);
	}

	/**
	 * Returns all table names in the database.
	 * This method should be overridden by child classes in order to support this feature
	 * because the default implementation simply throws an exception.
	 * @param string $schema the schema of the tables. Defaults to empty string, meaning the current or default schema.
	 * @return array all table names in the database. The names have NO schema name prefix.
	 * @throws NotSupportedException if this method is called
	 */
	protected function findTableNames($schema = '')
	{
		throw new NotSupportedException(get_class($this) . ' does not support fetching all table names.');
	}

	/**
	 * Returns the ID of the last inserted row or sequence value.
	 * @param string $sequenceName name of the sequence object (required by some DBMS)
	 * @return string the row ID of the last row inserted, or the last value retrieved from the sequence object
	 * @throws InvalidCallException if the DB connection is not active
	 * @see http://www.php.net/manual/en/function.PDO-lastInsertId.php
	 */
	public function getLastInsertID($sequenceName = '')
	{
		if ($this->db->isActive) {
			return $this->db->pdo->lastInsertId($sequenceName);
		} else {
			throw new InvalidCallException('DB Connection is not active.');
		}
	}

	/**
	 * Quotes a string value for use in a query.
	 * Note that if the parameter is not a string, it will be returned without change.
	 * @param string $str string to be quoted
	 * @return string the properly quoted string
	 * @see http://www.php.net/manual/en/function.PDO-quote.php
	 */
	public function quoteValue($str)
	{
		if (!is_string($str)) {
			return $str;
		}

		$this->db->open();
		if (($value = $this->db->pdo->quote($str)) !== false) {
			return $value;
		} else { // the driver doesn't support quote (e.g. oci)
			return "'" . addcslashes(str_replace("'", "''", $str), "\000\n\r\\\032") . "'";
		}
	}

	/**
	 * Quotes a table name for use in a query.
	 * If the table name contains schema prefix, the prefix will also be properly quoted.
	 * If the table name is already quoted or contains '(' or '{{',
	 * then this method will do nothing.
	 * @param string $name table name
	 * @return string the properly quoted table name
	 * @see quoteSimpleTableName
	 */
	public function quoteTableName($name)
	{
		if (strpos($name, '(') !== false || strpos($name, '{{') !== false) {
			return $name;
		}
		if (strpos($name, '.') === false) {
			return $this->quoteSimpleTableName($name);
		}
		$parts = explode('.', $name);
		foreach ($parts as $i => $part) {
			$parts[$i] = $this->quoteSimpleTableName($part);
		}
		return implode('.', $parts);

	}

	/**
	 * Quotes a column name for use in a query.
	 * If the column name contains prefix, the prefix will also be properly quoted.
	 * If the column name is already quoted or contains '(', '[[' or '{{',
	 * then this method will do nothing.
	 * @param string $name column name
	 * @return string the properly quoted column name
	 * @see quoteSimpleColumnName
	 */
	public function quoteColumnName($name)
	{
		if (strpos($name, '(') !== false || strpos($name, '[[') !== false || strpos($name, '{{') !== false) {
			return $name;
		}
		if (($pos = strrpos($name, '.')) !== false) {
			$prefix = $this->quoteTableName(substr($name, 0, $pos)) . '.';
			$name = substr($name, $pos + 1);
		} else {
			$prefix = '';
		}
		return $prefix . $this->quoteSimpleColumnName($name);
	}

	/**
	 * Quotes a simple table name for use in a query.
	 * A simple table name should contain the table name only without any schema prefix.
	 * If the table name is already quoted, this method will do nothing.
	 * @param string $name table name
	 * @return string the properly quoted table name
	 */
	public function quoteSimpleTableName($name)
	{
		return strpos($name, "'") !== false ? $name : "'" . $name . "'";
	}

	/**
	 * Quotes a simple column name for use in a query.
	 * A simple column name should contain the column name only without any prefix.
	 * If the column name is already quoted or is the asterisk character '*', this method will do nothing.
	 * @param string $name column name
	 * @return string the properly quoted column name
	 */
	public function quoteSimpleColumnName($name)
	{
		return strpos($name, '"') !== false || $name === '*' ? $name : '"' . $name . '"';
	}

	/**
	 * Returns the actual name of a given table name.
	 * This method will strip off curly brackets from the given table name
	 * and replace the percentage character '%' with [[Connection::tablePrefix]].
	 * @param string $name the table name to be converted
	 * @return string the real name of the given table name
	 */
	public function getRawTableName($name)
	{
		if (strpos($name, '{{') !== false) {
			$name = preg_replace('/\\{\\{(.*?)\\}\\}/', '\1', $name);
			return str_replace('%', $this->db->tablePrefix, $name);
		} else {
			return $name;
		}
	}

	/**
	 * Extracts the PHP type from abstract DB type.
	 * @param ColumnSchema $column the column schema information
	 * @return string PHP type name
	 */
	protected function getColumnPhpType($column)
	{
		static $typeMap = array( // abstract type => php type
			'smallint' => 'integer',
			'integer' => 'integer',
			'bigint' => 'integer',
			'boolean' => 'boolean',
			'float' => 'double',
		);
		if (isset($typeMap[$column->type])) {
			if ($column->type === 'bigint') {
				return PHP_INT_SIZE == 8 && !$column->unsigned ? 'integer' : 'string';
			} elseif ($column->type === 'integer') {
				return PHP_INT_SIZE == 4 && $column->unsigned ? 'string' : 'integer';
			} else {
				return $typeMap[$column->type];
			}
		} else {
			return 'string';
		}
	}
}
