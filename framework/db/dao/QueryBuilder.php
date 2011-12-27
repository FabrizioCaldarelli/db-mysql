<?php
/**
 * This file contains the Command class.
 *
 * @author Qiang Xue <qiang.xue@gmail.com>
 * @link http://www.yiiframework.com/
 * @copyright Copyright &copy; 2008-2012 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

namespace yii\db\dao;

use yii\db\Exception;

/**
 * QueryBuilder builds a SQL statement based on the specification given as a [[Query]] object.
 *
 * QueryBuilder is often used behind the scenes by [[Query]] to build a DBMS-dependent SQL statement
 * from a [[Query]] object.
 *
 * @author Qiang Xue <qiang.xue@gmail.com>
 * @since 2.0
 */
class QueryBuilder extends \yii\base\Object
{
	/**
	 * @var array the abstract column types mapped to physical column types.
	 * This is mainly used to support creating/modifying tables using DB-independent data type specifications.
	 * Child classes should override this property to declare supported type mappings.
	 */
	public $typeMap = array();
	/**
	 * @var Connection the database connection.
	 */
	public $connection;
	/**
	 * @var Driver the database driver
	 */
	public $driver;
	/**
	 * @var string the separator between different fragments of a SQL statement.
	 * Defaults to an empty space. This is mainly used by [[build()]] when generating a SQL statement.
	 */
	public $separator = " ";
	/**
	 * @var Query the Query object. This is set when calling [[build()]] to generate a non-SELECT SQL statement.
	 */
	private $_query;

	/**
	 * Constructor.
	 * @param Connection $connection the database connection.
	 */
	public function __construct($connection)
	{
		$this->connection = $connection;
		$this->driver = $connection->getDriver();
	}

	/**
	 * Generates a SQL statement from a [[Query]] object.
	 * Note that when generating SQL statements for INSERT and UPDATE queries,
	 * the query object's [[Query::params]] property may be appended with new parameters.
	 * @param Query $query the [[Query]] object from which the SQL statement will be generated
	 * @return string the generated SQL statement
	 */
	public function build($query)
	{
		if ($query->operation !== null) {
			// non-SELECT query
			$this->_query = $query;
			$method = array_shift($query->operation);
			$sql = call_user_func_array(array($this, $method), $query->operation);
			$this->_query = null;
			return $sql;
		} else {
			// SELECT query
			$clauses = array(
				$this->buildSelect($query),
				$this->buildFrom($query),
				$this->buildJoin($query),
				$this->buildWhere($query),
				$this->buildGroupBy($query),
				$this->buildHaving($query),
				$this->buildUnion($query),
				$this->buildOrderBy($query),
				$this->buildLimit($query),
			);
			return implode($this->separator, array_filter($clauses));
		}
	}

	/**
	 * Creates and executes an INSERT SQL statement.
	 * The method will properly escape the column names, and bind the values to be inserted.
	 * @param string $table the table that new rows will be inserted into.
	 * @param array $columns the column data (name=>value) to be inserted into the table.
	 * @param array $params the parameters to be bound to the query. This method will modify
	 * this parameter by appending new parameters to be bound to the query.
	 * @return integer number of rows affected by the execution.
	 */
	public function insert($table, $columns, &$params = array())
	{
		$names = array();
		$placeholders = array();
		$count = 0;
		foreach ($columns as $name => $value) {
			$names[] = $this->driver->quoteColumnName($name);
			if ($value instanceof Expression) {
				$placeholders[] = $value->expression;
				foreach ($value->params as $n => $v) {
					$params[$n] = $v;
				}
			} else {
				$placeholders[] = ':p' . $count;
				$params[':p' . $count] = $value;
				$count++;
			}
		}
		if ($this->_query instanceof Query) {
			$this->_query->addParams($params);
		}

		return 'INSERT INTO ' . $this->driver->quoteTableName($table)
			. ' (' . implode(', ', $names) . ') VALUES ('
			. implode(', ', $placeholders) . ')';
	}

	/**
	 * Creates and executes an UPDATE SQL statement.
	 * The method will properly escape the column names and bind the values to be updated.
	 * @param string $table the table to be updated.
	 * @param array $columns the column data (name=>value) to be updated.
	 * @param mixed $condition the condition that will be put in the WHERE part. Please
	 * refer to [[Query::where()]] on how to specify condition.
	 * @param array $params the parameters to be bound to the query. This method will modify
	 * this parameter by appending new parameters to be bound to the query.
	 * @return integer number of rows affected by the execution.
	 */
	public function update($table, $columns, $condition = '', &$params = array())
	{
		$lines = array();
		$count = 0;
		foreach ($columns as $name => $value) {
			if ($value instanceof Expression) {
				$lines[] = $this->driver->quoteSimpleColumnName($name) . '=' . $value->expression;
				foreach ($value->params as $n => $v) {
					$params[$n] = $v;
				}
			} else {
				$lines[] = $this->driver->quoteSimpleColumnName($name) . '=:p' . $count;
				$params[':p' . $count] = $value;
				$count++;
			}
		}
		if ($this->_query instanceof Query) {
			$this->_query->addParams($params);
		}
		$sql = 'UPDATE ' . $this->driver->quoteTableName($table) . ' SET ' . implode(', ', $lines);
		if (($where = $this->buildCondition($condition)) != '') {
			$sql .= ' WHERE ' . $where;
		}

		return $sql;
	}

	/**
	 * Creates and executes a DELETE SQL statement.
	 * @param string $table the table where the data will be deleted from.
	 * @param mixed $condition the condition that will be put in the WHERE part. Please
	 * refer to [[Query::where()]] on how to specify condition.
	 * @return integer number of rows affected by the execution.
	 */
	public function delete($table, $condition = '')
	{
		$sql = 'DELETE FROM ' . $this->driver->quoteTableName($table);
		if (($where = $this->buildCondition($condition)) != '') {
			$sql .= ' WHERE ' . $where;
		}
		return $sql;
	}

	/**
	 * Builds a SQL statement for creating a new DB table.
	 *
	 * The columns in the new  table should be specified as name-definition pairs (e.g. 'name'=>'string'),
	 * where name stands for a column name which will be properly quoted by the method, and definition
	 * stands for the column type which can contain an abstract DB type.
	 * The [[getColumnType()]] method will be invoked to convert any abstract type into a physical one.
	 *
	 * If a column is specified with definition only (e.g. 'PRIMARY KEY (name, type)'), it will be directly
	 * inserted into the generated SQL.
	 *
	 * @param string $table the name of the table to be created. The name will be properly quoted by the method.
	 * @param array $columns the columns (name=>definition) in the new table.
	 * @param string $options additional SQL fragment that will be appended to the generated SQL.
	 * @return string the SQL statement for creating a new DB table.
	 */
	public function createTable($table, $columns, $options = null)
	{
		$cols = array();
		foreach ($columns as $name => $type) {
			if (is_string($name)) {
				$cols[] = "\t" . $this->driver->quoteColumnName($name) . ' ' . $this->driver->getColumnType($type);
			} else
			{
				$cols[] = "\t" . $type;
			}
		}
		$sql = "CREATE TABLE " . $this->driver->quoteTableName($table) . " (\n" . implode(",\n", $cols) . "\n)";
		return $options === null ? $sql : $sql . ' ' . $options;
	}

	/**
	 * Builds a SQL statement for renaming a DB table.
	 * @param string $table the table to be renamed. The name will be properly quoted by the method.
	 * @param string $newName the new table name. The name will be properly quoted by the method.
	 * @return string the SQL statement for renaming a DB table.
	 */
	public function renameTable($table, $newName)
	{
		return 'RENAME TABLE ' . $this->driver->quoteTableName($table) . ' TO ' . $this->driver->quoteTableName($newName);
	}

	/**
	 * Builds a SQL statement for dropping a DB table.
	 * @param string $table the table to be dropped. The name will be properly quoted by the method.
	 * @return string the SQL statement for dropping a DB table.
	 */
	public function dropTable($table)
	{
		return "DROP TABLE " . $this->driver->quoteTableName($table);
	}

	/**
	 * Builds a SQL statement for truncating a DB table.
	 * @param string $table the table to be truncated. The name will be properly quoted by the method.
	 * @return string the SQL statement for truncating a DB table.
	 */
	public function truncateTable($table)
	{
		return "TRUNCATE TABLE " . $this->driver->quoteTableName($table);
	}

	/**
	 * Builds a SQL statement for adding a new DB column.
	 * @param string $table the table that the new column will be added to. The table name will be properly quoted by the method.
	 * @param string $column the name of the new column. The name will be properly quoted by the method.
	 * @param string $type the column type. The [[getColumnType()]] method will be invoked to convert abstract column type (if any)
	 * into the physical one. Anything that is not recognized as abstract type will be kept in the generated SQL.
	 * For example, 'string' will be turned into 'varchar(255)', while 'string not null' will become 'varchar(255) not null'.
	 * @return string the SQL statement for adding a new column.
	 */
	public function addColumn($table, $column, $type)
	{
		return 'ALTER TABLE ' . $this->driver->quoteTableName($table)
			. ' ADD ' . $this->driver->quoteColumnName($column) . ' '
			. $this->getColumnType($type);
	}

	/**
	 * Builds a SQL statement for dropping a DB column.
	 * @param string $table the table whose column is to be dropped. The name will be properly quoted by the method.
	 * @param string $column the name of the column to be dropped. The name will be properly quoted by the method.
	 * @return string the SQL statement for dropping a DB column.
	 */
	public function dropColumn($table, $column)
	{
		return "ALTER TABLE " . $this->driver->quoteTableName($table)
			. " DROP COLUMN " . $this->driver->quoteSimpleColumnName($column);
	}

	/**
	 * Builds a SQL statement for renaming a column.
	 * @param string $table the table whose column is to be renamed. The name will be properly quoted by the method.
	 * @param string $name the old name of the column. The name will be properly quoted by the method.
	 * @param string $newName the new name of the column. The name will be properly quoted by the method.
	 * @return string the SQL statement for renaming a DB column.
	 */
	public function renameColumn($table, $name, $newName)
	{
		return "ALTER TABLE " . $this->driver->quoteTableName($table)
			. " RENAME COLUMN " . $this->driver->quoteSimpleColumnName($name)
			. " TO " . $this->driver->quoteSimpleColumnName($newName);
	}

	/**
	 * Builds a SQL statement for changing the definition of a column.
	 * @param string $table the table whose column is to be changed. The table name will be properly quoted by the method.
	 * @param string $column the name of the column to be changed. The name will be properly quoted by the method.
	 * @param string $type the new column type. The [[getColumnType]] method will be invoked to convert abstract column type (if any)
	 * into the physical one. Anything that is not recognized as abstract type will be kept in the generated SQL.
	 * For example, 'string' will be turned into 'varchar(255)', while 'string not null' will become 'varchar(255) not null'.
	 * @return string the SQL statement for changing the definition of a column.
	 */
	public function alterColumn($table, $column, $type)
	{
		return 'ALTER TABLE ' . $this->driver->quoteTableName($table) . ' CHANGE '
			. $this->driver->quoteSimpleColumnName($column) . ' '
			. $this->driver->quoteSimpleColumnName($column) . ' '
			. $this->getColumnType($type);
	}

	/**
	 * Builds a SQL statement for adding a foreign key constraint to an existing table.
	 * The method will properly quote the table and column names.
	 * @param string $name the name of the foreign key constraint.
	 * @param string $table the table that the foreign key constraint will be added to.
	 * @param string $columns the name of the column to that the constraint will be added on. If there are multiple columns, separate them with commas.
	 * @param string $refTable the table that the foreign key references to.
	 * @param string $refColumns the name of the column that the foreign key references to. If there are multiple columns, separate them with commas.
	 * @param string $delete the ON DELETE option. Most DBMS support these options: RESTRICT, CASCADE, NO ACTION, SET DEFAULT, SET NULL
	 * @param string $update the ON UPDATE option. Most DBMS support these options: RESTRICT, CASCADE, NO ACTION, SET DEFAULT, SET NULL
	 * @return string the SQL statement for adding a foreign key constraint to an existing table.
	 */
	public function addForeignKey($name, $table, $columns, $refTable, $refColumns, $delete = null, $update = null)
	{
		$columns = preg_split('/\s*,\s*/', $columns, -1, PREG_SPLIT_NO_EMPTY);
		foreach ($columns as $i => $col) {
			$columns[$i] = $this->driver->quoteColumnName($col);
		}
		$refColumns = preg_split('/\s*,\s*/', $refColumns, -1, PREG_SPLIT_NO_EMPTY);
		foreach ($refColumns as $i => $col) {
			$refColumns[$i] = $this->driver->quoteColumnName($col);
		}
		$sql = 'ALTER TABLE ' . $this->driver->quoteTableName($table)
			. ' ADD CONSTRAINT ' . $this->driver->quoteColumnName($name)
			. ' FOREIGN KEY (' . implode(', ', $columns) . ')'
			. ' REFERENCES ' . $this->driver->quoteTableName($refTable)
			. ' (' . implode(', ', $refColumns) . ')';
		if ($delete !== null) {
			$sql .= ' ON DELETE ' . $delete;
		}
		if ($update !== null) {
			$sql .= ' ON UPDATE ' . $update;
		}
		return $sql;
	}

	/**
	 * Builds a SQL statement for dropping a foreign key constraint.
	 * @param string $name the name of the foreign key constraint to be dropped. The name will be properly quoted by the method.
	 * @param string $table the table whose foreign is to be dropped. The name will be properly quoted by the method.
	 * @return string the SQL statement for dropping a foreign key constraint.
	 */
	public function dropForeignKey($name, $table)
	{
		return 'ALTER TABLE ' . $this->driver->quoteTableName($table)
			. ' DROP CONSTRAINT ' . $this->driver->quoteColumnName($name);
	}

	/**
	 * Builds a SQL statement for creating a new index.
	 * @param string $name the name of the index. The name will be properly quoted by the method.
	 * @param string $table the table that the new index will be created for. The table name will be properly quoted by the method.
	 * @param string $column the column(s) that should be included in the index. If there are multiple columns, please separate them
	 * by commas. Each column name will be properly quoted by the method, unless a parenthesis is found in the name.
	 * @param boolean $unique whether to add UNIQUE constraint on the created index.
	 * @return string the SQL statement for creating a new index.
	 */
	public function createIndex($name, $table, $column, $unique = false)
	{
		$cols = array();
		$columns = preg_split('/\s*,\s*/', $column, -1, PREG_SPLIT_NO_EMPTY);
		foreach ($columns as $col)
		{
			if (strpos($col, '(') !== false) {
				$cols[] = $col;
			} else
			{
				$cols[] = $this->driver->quoteColumnName($col);
			}
		}
		return ($unique ? 'CREATE UNIQUE INDEX ' : 'CREATE INDEX ')
			. $this->driver->quoteTableName($name) . ' ON '
			. $this->driver->quoteTableName($table) . ' (' . implode(', ', $cols) . ')';
	}

	/**
	 * Builds a SQL statement for dropping an index.
	 * @param string $name the name of the index to be dropped. The name will be properly quoted by the method.
	 * @param string $table the table whose index is to be dropped. The name will be properly quoted by the method.
	 * @return string the SQL statement for dropping an index.
	 */
	public function dropIndex($name, $table)
	{
		return 'DROP INDEX ' . $this->driver->quoteTableName($name) . ' ON ' . $this->driver->quoteTableName($table);
	}

	/**
	 * Resets the sequence value of a table's primary key.
	 * The sequence will be reset such that the primary key of the next new row inserted
	 * will have the specified value or 1.
	 * @param CDbTableSchema $table the table schema whose primary key sequence will be reset
	 * @param mixed $value the value for the primary key of the next new row inserted. If this is not set,
	 * the next new row's primary key will have a value 1.
	 */
	public function resetSequence($table, $value = null)
	{
	}

	/**
	 * Enables or disables integrity check.
	 * @param boolean $check whether to turn on or off the integrity check.
	 * @param string $schema the schema of the tables. Defaults to empty string, meaning the current or default schema.
	 */
	public function checkIntegrity($check = true, $schema = '')
	{
	}

	/**
	 * Converts an abstract column type into a physical column type.
	 * The conversion is done using the type map specified in [[typeMap]].
	 * These abstract column types are supported (using MySQL as example to explain the corresponding
	 * physical types):
	 * <ul>
	 * <li>pk: an auto-incremental primary key type, will be converted into "int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY"</li>
	 * <li>string: string type, will be converted into "varchar(255)"</li>
	 * <li>text: a long string type, will be converted into "text"</li>
	 * <li>integer: integer type, will be converted into "int(11)"</li>
	 * <li>boolean: boolean type, will be converted into "tinyint(1)"</li>
	 * <li>float: float number type, will be converted into "float"</li>
	 * <li>decimal: decimal number type, will be converted into "decimal"</li>
	 * <li>datetime: datetime type, will be converted into "datetime"</li>
	 * <li>timestamp: timestamp type, will be converted into "timestamp"</li>
	 * <li>time: time type, will be converted into "time"</li>
	 * <li>date: date type, will be converted into "date"</li>
	 * <li>binary: binary data type, will be converted into "blob"</li>
	 * </ul>
	 *
	 * If the abstract type contains two or more parts separated by spaces (e.g. "string NOT NULL"), then only
	 * the first part will be converted, and the rest of the parts will be appended to the conversion result.
	 * For example, 'string NOT NULL' is converted to 'varchar(255) NOT NULL'.
	 * @param string $type abstract column type
	 * @return string physical column type.
	 */
	public function getColumnType($type)
	{
		if (isset($this->typeMap[$type])) {
			return $this->typeMap[$type];
		} elseif (($pos = strpos($type, ' ')) !== false) {
			$t = substr($type, 0, $pos);
			return (isset($this->typeMap[$t]) ? $this->typeMap[$t] : $t) . substr($type, $pos);
		} else {
			return $type;
		}
	}

	protected function buildSelect($query)
	{
		$select = $query->distinct ? 'SELECT DISTINCT' : 'SELECT';
		if ($query->selectOption != '') {
			$select .= ' ' . $query->selectOption;
		}

		$columns = $query->select;
		if (empty($columns)) {
			return $select . ' *';
		}

		if (is_string($columns)) {
			if (strpos($columns, '(') !== false) {
				return $select . ' ' . $columns;
			}
			$columns = preg_split('/\s*,\s*/', trim($columns), -1, PREG_SPLIT_NO_EMPTY);
		}

		foreach ($columns as $i => $column) {
			if (is_object($column)) {
				$columns[$i] = (string)$column;
			} elseif (strpos($column, '(') === false) {
				if (preg_match('/^(.*?)(?i:\s+as\s+|\s+)([\w\-\.])$/', $column, $matches)) {
					$columns[$i] = $this->connection->quoteColumnName($matches[1]) . ' AS ' . $this->driver->quoteSimpleColumnName($matches[2]);
				} else {
					$columns[$i] = $this->connection->quoteColumnName($column);
				}
			}
		}

		return $select . ' ' . implode(', ', $columns);
	}

	protected function buildFrom($query)
	{
		if (empty($query->from)) {
			return '';
		}

		$tables = $query->from;
		if (is_string($tables) && strpos($tables, '(') !== false) {
			return 'FROM ' . $tables;
		}

		if (!is_array($tables)) {
			$tables = preg_split('/\s*,\s*/', trim($tables), -1, PREG_SPLIT_NO_EMPTY);
		}
		foreach ($tables as $i => $table) {
			if (strpos($table, '(') === false) {
				if (preg_match('/^(.*?)(?i:\s+as\s+|\s+)(.*)$/i', $table, $matches)) { // with alias
					$tables[$i] = $this->connection->quoteTableName($matches[1]) . ' ' . $this->connection->quoteTableName($matches[2]);
				} else {
					$tables[$i] = $this->connection->quoteTableName($table);
				}
			}
		}

		return 'FROM ' . implode(', ', $tables);
	}

	protected function buildJoin($query)
	{
		$joins = $query->join;
		if (empty($joins)) {
			return '';
		}
		if (is_string($joins)) {
			return $joins;
		}

		foreach ($joins as $i => $join) {
			if (is_array($join)) { // join type, table name, on-condition
				if (isset($join[0], $join[1])) {
					$table = $join[1];
					if (strpos($table, '(') === false) {
						if (preg_match('/^(.*?)(?i:\s+as\s+|\s+)(.*)$/', $table, $matches)) { // with alias
							$table = $this->connection->quoteTableName($matches[1]) . ' ' . $this->connection->quoteTableName($matches[2]);
						} else {
							$table = $this->connection->quoteTableName($table);
						}
					}
					$joins[$i] = strtoupper($join[0]) . ' ' . $table;
					if (isset($join[2])) { // join condition
						$joins[$i] .= ' ON ' . $this->buildCondition($join[2]);
					}
				} else {
					throw new Exception('The join clause may be specified as an array of at least two elements.');
				}
			}
		}

		return implode("\n", $joins);
	}

	protected function buildWhere($query)
	{
		$where = $this->buildCondition($query->where);
		return empty($where) ? '' : 'WHERE ' . $where;
	}

	protected function buildGroupBy($query)
	{
		$columns = $query->groupBy;
		if (empty($columns)) {
			return '';
		}
		if (is_string($columns) && strpos($columns, '(') !== false) {
			return 'GROUP BY ' . $columns;
		}

		if (!is_array($columns)) {
			$columns = preg_split('/\s*,\s*/', trim($columns), -1, PREG_SPLIT_NO_EMPTY);
		}
		foreach ($columns as $i => $column) {
			if (is_object($column)) {
				$columns[$i] = (string)$column;
			} elseif (strpos($column, '(') === false) {
				$columns[$i] = $this->connection->quoteColumnName($column);
			}
		}
		return 'GROUP BY ' . implode(', ', $columns);
	}

	protected function buildHaving($query)
	{
		$having = $this->buildCondition($query->having);
		return empty($having) ? '' : 'HAVING ' . $having;
	}

	protected function buildOrderBy($query)
	{
		$columns = $query->orderBy;
		if (empty($columns)) {
			return '';
		}
		if (is_string($columns) && strpos($columns, '(') !== false) {
			return 'ORDER BY ' . $columns;
		}

		if (!is_array($columns)) {
			$columns = preg_split('/\s*,\s*/', trim($columns), -1, PREG_SPLIT_NO_EMPTY);
		}
		foreach ($columns as $i => $column) {
			if (is_object($column)) {
				$columns[$i] = (string)$column;
			} elseif (strpos($column, '(') === false) {
				if (preg_match('/^(.*?)\s+(asc|desc)$/i', $column, $matches)) {
					$columns[$i] = $this->connection->quoteColumnName($matches[1]) . ' ' . strtoupper($matches[2]);
				} else {
					$columns[$i] = $this->connection->quoteColumnName($column);
				}
			}
		}
		return 'ORDER BY ' . implode(', ', $columns);
	}

	protected function buildLimit($query)
	{
		$sql = '';
		if ($query->limit !== null && $query->limit >= 0) {
			$sql = 'LIMIT ' . (int)$query->limit;
		}
		if ($query->offset > 0) {
			$sql .= ' OFFSET ' . (int)$query->offset;
		}
		return ltrim($sql);
	}

	protected function buildUnion($query)
	{
		$unions = $query->union;
		if (empty($unions)) {
			return '';
		}
		if (!is_array($unions)) {
			$unions = array($unions);
		}
		foreach ($unions as $i => $union) {
			if ($union instanceof Query) {
				$unions[$i] = $union->getSql($this->connection);
			}
		}
		return "UNION (\n" . implode("\n) UNION (\n", $unions) . "\n)";
	}

	protected function buildCondition($condition)
	{
		if (!is_array($condition)) {
			return $condition;
		} elseif ($condition === array()) {
			return '';
		}

		$n = count($condition);
		$operator = strtoupper($condition[0]);
		if ($operator === 'OR' || $operator === 'AND') {
			$parts = array();
			for ($i = 1; $i < $n; ++$i) {
				$part = $this->buildCondition($condition[$i]);
				if ($part !== '') {
					$parts[] = '(' . $part . ')';
				}
			}
			return $parts === array() ? '' : implode(' ' . $operator . ' ', $parts);
		}

		if (!isset($condition[1], $condition[2])) {
			throw new Exception("Operator $operator requires at least two operands.");
		}

		$column = $condition[1];
		if (strpos($column, '(') === false) {
			$column = $this->connection->quoteColumnName($column);
		}

		if ($operator === 'BETWEEN' || $operator === 'NOT BETWEEN') {
			if (!isset($condition[3])) {
				throw new Exception("Operator $operator requires three operands.");
			}
			$value1 = is_string($condition[2]) ? $this->connection->quoteValue($condition[2]) : (string)$condition[2];
			$value2 = is_string($condition[3]) ? $this->connection->quoteValue($condition[3]) : (string)$condition[3];
			return "$column $operator $value1 AND $value2";
		}

		$values = $condition[2];
		if (!is_array($values)) {
			$values = array($values);
		}

		if ($operator === 'IN' || $operator === 'NOT IN') {
			if ($values === array()) {
				return $operator === 'IN' ? '0=1' : '';
			}
			foreach ($values as $i => $value) {
				if (is_string($value)) {
					$values[$i] = $this->connection->quoteValue($value);
				} else {
					$values[$i] = (string)$value;
				}
			}
			return $column . ' ' . $operator . ' (' . implode(', ', $values) . ')';
		}

		if ($operator === 'LIKE' || $operator === 'NOT LIKE' || $operator === 'OR LIKE' || $operator === 'OR NOT LIKE') {
			if ($values === array()) {
				return $operator === 'LIKE' || $operator === 'OR LIKE' ? '0=1' : '';
			}

			if ($operator === 'LIKE' || $operator === 'NOT LIKE') {
				$andor = ' AND ';
			} else {
				$andor = ' OR ';
				$operator = $operator === 'OR LIKE' ? 'LIKE' : 'NOT LIKE';
			}
			$expressions = array();
			foreach ($values as $value) {
				$expressions[] = $column . ' ' . $operator . ' ' . $this->connection->quoteValue($value);
			}
			return implode($andor, $expressions);
		}

		throw new Exception('Unknown operator: ' . $operator);
	}
}
