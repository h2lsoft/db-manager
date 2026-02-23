<?php

namespace h2lsoft\DBManager;
use PDO;

class DBManager
{
	public $connection;
	
	private $query_stack = [];
	private $query_id = -1;
	private $last_query;
	private $last_query_params;
	private $soft_mode;
	private $default_user_UID;
	private $_columns_forbidden = ['deleted', 'created_at', 'created_by', 'updated_at', 'updated_by', 'deleted_at', 'deleted_by'];
	private $table;
	private $_from_query_delete = false;
	
	private $dbQL = [];
	private $transaction_level = 0;

	private $debug = false;
	private $debug_request_context = 'html'; // text, html, json


	/**
	 * @param bool $soft_mode enable soft delete and audit columns
	 * @param int|string $default_user_UID default user id for audit
	 * @param bool $debug enable debug mode
	 */
	public function __construct($soft_mode=true, $default_user_UID='', $debug=false)
	{
		$this->soft_mode = $soft_mode;
		$this->default_user_UID = $default_user_UID;
		$this->debug = $debug;
	}

	public function setDebug(bool $debug)
	{
		$this->debug = $debug;
	}

	public function setDebugRequestContext(string $format)
	{
		$this->debug_request_context = $format;
	}
	
	private function handleException($e)
	{
		$this->exception_handler($e);
	}

	private function findRealCaller($trace)
	{
		foreach ($trace as $item) {
			if (isset($item['file']) &&
				strpos($item['file'], 'DBManager.php') === false &&
				isset($item['line'])) {
				return [
					'file' => $item['file'],
					'line' => $item['line']
				];
			}
		}
		return null;
	}

	public function exception_handler($exception)
	{
		$message = $exception->getMessage();

		// build detailed message in debug mode
		$detail = "DBM Error: {$message}";

		$last_query = $this->getLastQuery();
		$last_params = $this->getLastQueryParams();

		if($last_query)
		{
			$detail .= " [SQL] " . trim($last_query);

			if(!empty($last_params))
			{
				$params_str = [];
				foreach($last_params as $key => $value)
				{
					$val = is_string($value) ? "'{$value}'" : $value;
					$params_str[] = "{$key}={$val}";
				}
				$detail .= " | Params: " . implode(', ', $params_str);
			}
		}

		$caller = $this->findRealCaller($exception->getTrace());
		if($caller)
		{
			$detail .= " in `{$caller['file']}` on line {$caller['line']}";
		}

		if(!$this->debug)
		{
			$detail = "DBM error, please contact administrator";
		}

		throw new \RuntimeException($detail, 0, $exception);
	}
	
	/**
	 * connect to database
	 *
	 * @return PDO
	 */
	public function connect($driver='mysql', $host='localhost', $username='root', $password='', $database='', $port='',  $pdo_options=[])
	{
		$dsn = "{$driver}:host={$host};dbname={$database};port={$port}";
		
		$this->connection = new PDO($dsn, $username, $password, $pdo_options);
		$this->connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		
		return $this->connection;
	}
	
	/**
	 * enable or disable soft mode
	 */
	public function setSoftMode($bool)
	{
		$this->soft_mode = $bool;
	}
	
	/**
	 * get current soft mode state
	 */
	public function getSoftMode()
	{
		return $this->soft_mode;
	}
	
	/**
	 * set default user id for audit columns
	 */
	public function setSoftModeDefaultUID($UID)
	{
		$this->default_user_UID = $UID;
	}
	
	/**
	 * get default user id for audit columns
	 */
	public function getSoftModeDefaultUID()
	{
		return $this->default_user_UID;
	}
	
	/**
	 * get PDO connection object
	 */
	public function getConnectionLink()
	{
		return $this->connection;
	}
	
	/**
	 * close database connection
	 */
	public function close()
	{
		$this->connection = null;
	}
	
	/**
	 * set PDO attribute
	 */
	public function setAttribute($attribute, $value)
	{
		$this->connection->setAttribute($attribute, $value);
	}
	
	/**
	 * purge old queries from stack (keeps last 10)
	 */
	protected function purgeQueryStack()
	{
		if(count($this->query_stack) >= 20)
		{
			$this->query_stack = array_slice($this->query_stack, -10, null, false);
			$this->query_id = count($this->query_stack) - 1;
		}
	}
	
	
	/**
	 * execute a SQL query with optional bound parameters
	 *
	 * @return $this
	 */
	public function query($sql, $binds=[])
	{
		$this->purgeQueryStack();
		if($sql != "SELECT FOUND_ROWS()")
		{
			$this->last_query = $sql;
			$this->last_query_params = $binds;
		}
		
		try
		{
			if(!count($binds))
			{
				$this->query_stack[++$this->query_id] = $this->connection->query($sql);
			}
			else
			{
				$stmt = $this->prepare($sql);
				foreach($binds as $bind => $value)
				{
					if($bind == 'id' || str_ends_with($bind, '_id'))
						$stmt->bindValue($bind, $value, PDO::PARAM_INT);
					else
						$stmt->bindValue($bind, $value);
				}
				$stmt->execute();
				$this->query_stack[++$this->query_id] = $stmt;
			}
		}
		catch(\Throwable $e)
		{
			$this->handleException($e);
		}
		
		return $this;
	}
	
	
	/**
	 * prepare a SQL statement
	 */
	public function prepare($sql)
	{
		$this->last_query = $sql;
		$stmt = $this->connection->prepare($sql);
		return $stmt;
	}
	
	public function execute($stmt, $binds=[])
	{
		$this->last_query_params = $binds;
		$stmt->execute($binds);
		return $stmt;
	}
	
	
	/**
	 * fetch single row from last query
	 */
	public function fetch($fetch_mode=PDO::FETCH_ASSOC)
	{
		if(!is_object($this->query_stack[$this->query_id]))return false;
		return $this->query_stack[$this->query_id]->fetch($fetch_mode);
	}
	
	/**
	 * fetch single row as object
	 */
	public function fetchObject()
	{
		return $this->fetch(PDO::FETCH_OBJ);
	}
	
	
	/**
	 * fetch all rows from last query
	 */
	public function fetchAll($fetch_mode=PDO::FETCH_ASSOC)
	{
		if(!is_object($this->query_stack[$this->query_id]))return false;
		return $this->query_stack[$this->query_id]->fetchAll($fetch_mode);
	}
	
	/**
	 * fetch all rows as objects
	 */
	public function fetchObjectAll()
	{
		return $this->fetchAll(PDO::FETCH_OBJ);
	}
	
	/**
	 * fetch first column of first row
	 */
	public function fetchOne()
	{
		if(!is_object($this->query_stack[$this->query_id]))return false;
		$rec = $this->query_stack[$this->query_id]->fetch(PDO::FETCH_NUM);
		if(!$rec)return false;
		return $rec[0];
	}
	
	/**
	 * fetch first column of all rows
	 */
	public function fetchAllOne()
	{
		if(!is_object($this->query_stack[$this->query_id]))return false;
		return $this->query_stack[$this->query_id]->fetchAll(PDO::FETCH_COLUMN, 0);
	}
	
	
	/**
	 * get affected row count from last query
	 */
	public function rowCount()
	{
		if(!is_object($this->query_stack[$this->query_id]))return false;
		
		return $this->query_stack[$this->query_id]->rowCount();
	}
	
	
	/**
	 * get last auto-increment insert id
	 */
	public function lastInsertId()
	{
		return $this->connection->lastInsertId();
	}
	
	/**
	 * get last executed SQL string
	 */
	public function getLastQuery()
	{
		return $this->last_query;
	}
	
	/**
	 * get last query parameters
	 */
	public function getLastQueryParams()
	{
		return $this->last_query_params;
	}
	
	/**
	 * output last query and params as HTML (debug)
	 */
	public function dBugLastQuery()
	{
		echo "<br>last query => ";
		
		echo "<pre style='border:1px solid #ccc; padding:10px'>";
		echo htmlspecialchars($this->getLastQuery());
		echo "</pre>";

		$params = $this->getLastQueryParams();
		if(count($params) > 0)
		{
			echo "params => ";
			echo "<pre style='border:1px solid #ccc; padding:10px'>";
			echo htmlspecialchars(print_r($params, true));
			echo "</pre>";
		}
		
	}
	
	
	/**
	 * set active table for next operation
	 *
	 * @return $this
	 */
	public function table($table)
	{
		$this->table = $table;
		return $this;
	}

	/**
	 * reset table name after a crud operation to prevent state leak
	 */
	private function resetTable()
	{
		$this->table = '';
	}
	
	
	/**
	 * insert a row and return the new id
	 */
	public function insert($row, $created_by='', $table='')
	{
		if(empty($table))$table = $this->table;
		
		if($this->soft_mode)
		{
			$keys = array_keys($row);
			foreach($keys as $key)
			{
				if(in_array($key, $this->_columns_forbidden))
				{
					$message = "`{$key}` is forbidden";
					trigger_error("DBM Error : ".$message, E_USER_ERROR);
				}
			}
			
			$row['created_at'] = date('Y-m-d H:i:s');
			$row['created_by'] = (empty($created_by)) ? $this->default_user_UID : $created_by;
		}
		
		$columns = [];
		$columns_str = "";
		$values_str = "";
		foreach($row as $key => $value)
		{
			$columns[] = $key;
			
			if(!empty($columns_str))$columns_str .= ", ";
			$columns_str .= "`{$key}`";
			
			if(!empty($values_str))$values_str .= ", ";
			$values_str .= ":{$key}";
		}
		
		
		$columns_str = trim($columns_str);
		$stmt = "INSERT INTO `{$table}`\n";
		$stmt .= "\t($columns_str)\n";
		$stmt .= "VALUES\n";
		$stmt .= "\t($values_str)";
		
		
		$this->purgeQueryStack();
		$this->query_stack[++$this->query_id] = $stmt;
		$this->last_query = $stmt;
		$this->last_query_params = $row;
		
		try
		{
			$prepared = $this->connection->prepare($stmt);

			// bind parameters
			foreach($columns as $column)
			{
				$prepared->bindValue(":{$column}", $row[$column]);
			}

			$prepared->execute();
		}
		catch(\Throwable $e)
		{
			$this->handleException($e);
		}

		$this->resetTable();
		return $this->lastInsertId();
	}

	/**
	 * update record(s), return affected row count
	 * $where: numeric id, raw string, or ['clause', [':param' => value]]
	 */
	public function update($row, $where='', $limit=-1, $updated_by='', $table='')
	{
		if($this->soft_mode)
		{
			$keys = array_keys($row);
			foreach($keys as $key)
			{
				if(in_array($key, $this->_columns_forbidden) && ($key != 'deleted' && !$this->_from_query_delete))
				{
					$message = "`{$key}` is forbidden";
					trigger_error("DBM Error : ".$message, E_USER_ERROR);
				}
			}
			
			if(!$this->_from_query_delete)
			{
				$row['updated_at'] = date('Y-m-d H:i:s');
				$row['updated_by'] = (empty($updated_by)) ? $this->default_user_UID : $updated_by;
			}
		}
		
		
		if(empty($table))$table = $this->table;
		
		$sql = "UPDATE\n";
		$sql .= "	`{$table}`\n";
		$sql .= "SET\n";
		
		$_params = [];
		
		$init = false;
		foreach($row as $key => $value)
		{
			if($init)$sql .= ",\n";
			$sql .= "\t`{$key}` = :{$key}";
			$init = true;
			
			$_params[$key] = $value;
		}
		
		$where_dyn = [];
		if($this->soft_mode)
		{
			$where_dyn[] = "`deleted` = 'NO'";
		}
		
		if(!is_array($where))
		{
			if(!empty($where))
			{
				// ID found
				if(is_numeric($where))
				{
					$where_dyn[] = "`ID` = :_where_id";
					$_params['_where_id'] = (int)$where;
				}
				else
				{
					$where_dyn[] = $where;
				}
			}
		}
		else
		{
			$where_dyn[] = $where[0]; # raw
			
			// $_ps = array_slice($where, 1, count($where));
			$_ps = $where[1];
			foreach($_ps as $p_name => $p_val)
			{
				$_params[$p_name] = $p_val;
			}
		}
		
		// where dynamic
		if(count($where_dyn))
		{
			$sql .= "\nWHERE ";
			
			$init = false;
			foreach($where_dyn as $wd => $val)
			{
				if($init) $sql .= " AND\n";
				$sql .= "\n\t\t{$val}";
				
				$init = true;
			}
		}
		
		// add limit
		if($limit != -1 && is_numeric($limit))
		{
			$sql .= "\nLIMIT " . (int)$limit;
		}
		
		
		$this->purgeQueryStack();
		$this->query_stack[++$this->query_id] = $sql;
		$this->last_query = $sql;
		$this->last_query_params = $_params;
		
		try
		{
			$prepared = $this->connection->prepare($sql);

			if(count($_params))
			{
				foreach($_params as $p => $v)
				{
					$prepared->bindValue($p, $v);
				}
			}

			$prepared->execute();
		}
		catch(\Throwable $e)
		{
			$this->handleException($e);
		}
		
		$this->_from_query_delete = false;
		$this->resetTable();

		return $prepared->rowCount();
	}


	/**
	 * insert multiple rows in chunks inside a transaction
	 */
	public function insertBulk($rows, $chunk_size, $created_by='', $table='')
	{
		if(empty($table))$table = $this->table;
		if(!count($rows))return;
		
		$rows = array_chunk($rows, $chunk_size);
		
		// verify soft mode
		$row = $rows[0][0];
		$keys = array_keys($row);
		
		if($this->soft_mode)
		{
			foreach($this->_columns_forbidden as $key)
			{
				if(in_array($key, $keys))
				{
					$message = "`{$key}` is forbidden";
					trigger_error("DBM Error : ".$message, E_USER_ERROR);
				}
			}
			
			$keys[] = 'created_by';
			$keys[] = 'created_at';
		}
		
		$columns = $keys;
		$placeholders = array_fill(0, count($columns), '?');
		try
		{
			$this->beginTransaction();
			foreach($rows as $rows_splitted)
			{
				$sql = "INSERT INTO `{$table}` ";
				$sql .= "	(".join(',', $columns).") ";
				$sql .= " VALUES\n";

				$init = false;
				$values = [];
				foreach($rows_splitted as $row)
				{
					if($init)$sql .= ",\n";
					$sql .= "(".join(',', $placeholders).")";
					$init = true;

					if($this->soft_mode)
					{
						$row['created_at'] = date('Y-m-d H:i:s');
						$row['created_by'] = (empty($created_by)) ? $this->default_user_UID : $created_by;
					}

					foreach($row as $key => $val)
						$values[] = $val;
				}

				$stmt = $this->prepare($sql);
				$stmt->execute($values);
			}

			$this->commit();
		}
		catch(\Throwable $e)
		{
			$this->rollBack();
			$this->handleException($e);
		}

		$this->resetTable();
	}


	/**
	 * delete record(s) — soft delete in soft mode, hard delete otherwise
	 */
	public function delete($where, $limit=-1, $deleted_by='', $table='')
	{
		if(empty($table))$table = $this->table;
		
		if($this->soft_mode)
		{
			$row = [];
			$row['deleted'] = 'YES';
			$row['deleted_at'] = date('Y-m-d H:i:s');
			$row['deleted_by'] = (empty($deleted_by)) ? $this->default_user_UID : $deleted_by;
			$this->_from_query_delete = true;
			
			return $this->update($row, $where, $limit, '', $table);
		}
		
		
		// real mode
		$_params = [];
		$sql = "DELETE FROM {$table}";
		
		
		if(!is_array($where))
		{
			if(!empty($where))
			{
				$sql .= " WHERE ";
				
				if(is_numeric($where))
				{
					$sql .= " ID = ? ";
					$_params[] = (int)$where;
				}
				else
				{
					$sql .= " {$where} ";
				}
			}
		}
		else
		{
			$sql .= " WHERE \n{$where[0]} ";
			$_params = array_slice($where, 1, count($where));
		}
		
		// limit
		if($limit != -1 && is_numeric($limit))
		{
			$sql .= " LIMIT " . (int)$limit;
		}
		
		
		$stmt = $this->prepare($sql);
		$result = $this->execute($stmt, $_params)->rowCount();
		$this->resetTable();

		return $result;
	}

	/**
	 * get record(s) by id — pass array for multiple
	 */
	public function getByID($ID, $fields='*', $fetch_mode=PDO::FETCH_ASSOC, $table='')
	{
		if(empty($table))$table = $this->table;
		
		$dyn = (!$this->soft_mode) ? '' : " AND deleted = 'NO' ";
		
		if(!is_array($ID))
		{
			$sql = "SELECT {$fields} FROM {$table} WHERE ID = :ID {$dyn}";
			$binds = [':ID' => $ID];
			
			$query = $this->query($sql, $binds);
			$records = $query->fetch();
		}
		else
		{
			$in  = str_repeat('?,', count($ID) - 1) . '?';
			
			
			$sql = "SELECT {$fields} FROM {$table} WHERE ID IN({$in}) {$dyn}";
			$stmt = $this->prepare($sql);
			$stmt = $this->execute($stmt, $ID);
			
			$records = $stmt->fetchAll($fetch_mode);
		}

		$this->resetTable();
		return $records;
	}

	/**
	 * @deprecated use getByID()
	 */
	public function get($ID, $fields='*', $fetch_mode=PDO::FETCH_ASSOC, $table='')
	{
		return $this->getByID($ID, $fields, $fetch_mode, $table);
	}

	/**
	 * count records in table
	 *
	 * @param string $where
	 * @param array $params
	 * @param string $table
	 * @return int
	 */
	public function count($where='', $params=[], $table='')
	{
		if(empty($table))$table = $this->table;

		$sql = "SELECT COUNT(*) FROM `{$table}`";

		$where_parts = [];
		if($this->soft_mode)
		{
			$where_parts[] = "`deleted` = 'NO'";
		}
		if(!empty($where))
		{
			$where_parts[] = $where;
		}

		if(count($where_parts))
		{
			$sql .= " WHERE " . implode(" AND ", $where_parts);
		}

		$result = (int)$this->query($sql, $params)->fetchOne();
		$this->resetTable();

		return $result;
	}

	/**
	 * find one record by where clause
	 *
	 * @param string $where
	 * @param array $params
	 * @param string $fields
	 * @param int $fetch_mode
	 * @param string $table
	 * @return array|false
	 */
	public function findOne($where='', $params=[], $fields='*', $fetch_mode=PDO::FETCH_ASSOC, $table='')
	{
		if(empty($table))$table = $this->table;

		$sql = "SELECT {$fields} FROM `{$table}`";

		$where_parts = [];
		if($this->soft_mode)
		{
			$where_parts[] = "`deleted` = 'NO'";
		}
		if(!empty($where))
		{
			$where_parts[] = $where;
		}

		if(count($where_parts))
		{
			$sql .= " WHERE " . implode(" AND ", $where_parts);
		}

		$sql .= " LIMIT 1";

		$result = $this->query($sql, $params)->fetch($fetch_mode);
		$this->resetTable();

		return $result;
	}

	/**
	 * find all records by where clause
	 *
	 * @param string $where
	 * @param array $params
	 * @param string $fields
	 * @param string $order_by
	 * @param int $limit (0 = no limit)
	 * @param int $fetch_mode
	 * @param string $table
	 * @return array|false
	 */
	public function findAll($where='', $params=[], $fields='*', $order_by='', $limit=0, $fetch_mode=PDO::FETCH_ASSOC, $table='')
	{
		if(empty($table))$table = $this->table;

		$sql = "SELECT {$fields} FROM `{$table}`";

		$where_parts = [];
		if($this->soft_mode)
		{
			$where_parts[] = "`deleted` = 'NO'";
		}
		if(!empty($where))
		{
			$where_parts[] = $where;
		}

		if(count($where_parts))
		{
			$sql .= " WHERE " . implode(" AND ", $where_parts);
		}

		if(!empty($order_by))
		{
			$sql .= " ORDER BY {$order_by}";
		}

		if($limit > 0)
		{
			$sql .= " LIMIT " . (int)$limit;
		}

		$result = $this->query($sql, $params)->fetchAll($fetch_mode);
		$this->resetTable();

		return $result;
	}

	/**
	 * insert or update on duplicate key
	 *
	 * @param array $row
	 * @param array $update_columns columns to update on duplicate (empty = all)
	 * @param string $created_by
	 * @param string $table
	 * @return int last insert id
	 */
	public function upsert($row, $update_columns=[], $created_by='', $table='')
	{
		if(empty($table))$table = $this->table;

		if($this->soft_mode)
		{
			$keys = array_keys($row);
			foreach($keys as $key)
			{
				if(in_array($key, $this->_columns_forbidden))
				{
					trigger_error("DBM Error : `{$key}` is forbidden", E_USER_ERROR);
				}
			}

			$row['created_at'] = date('Y-m-d H:i:s');
			$row['created_by'] = (empty($created_by)) ? $this->default_user_UID : $created_by;
		}

		$columns = array_keys($row);
		$columns_str = implode(', ', array_map(fn($c) => "`{$c}`", $columns));
		$values_str = implode(', ', array_map(fn($c) => ":{$c}", $columns));

		// columns to update on duplicate
		if(empty($update_columns))
		{
			$update_columns = array_diff($columns, ['created_at', 'created_by']);
		}

		$update_str = implode(', ', array_map(fn($c) => "`{$c}` = VALUES(`{$c}`)", $update_columns));

		if($this->soft_mode)
		{
			$update_str .= ", `updated_at` = '" . date('Y-m-d H:i:s') . "'";
			$update_str .= ", `updated_by` = '" . ((empty($created_by)) ? $this->default_user_UID : $created_by) . "'";
		}

		$sql = "INSERT INTO `{$table}` ({$columns_str}) VALUES ({$values_str})";
		$sql .= "\nON DUPLICATE KEY UPDATE {$update_str}";

		$this->purgeQueryStack();
		$this->last_query = $sql;
		$this->last_query_params = $row;

		try
		{
			$prepared = $this->connection->prepare($sql);
			foreach($columns as $column)
			{
				$prepared->bindValue(":{$column}", $row[$column]);
			}
			$prepared->execute();
		}
		catch(\Throwable $e)
		{
			$this->handleException($e);
		}

		$this->resetTable();
		return $this->lastInsertId();
	}

	/**
	 * add soft mode columns to table if missing
	 */
	public function addSoftModeColumns($table='')
	{
		if(empty($table))$table = $this->table;
		
		$sql = "SHOW FIELDS FROM `{$table}`";
		$fields = $this->query($sql)->fetchAll();
		
		$cols = [];
		foreach($fields as $field)
			$cols[] = $field['Field'];
		
		$sqls = [];
		
		// deleted
		if(!in_array('deleted', $cols))
			$sqls[] = "ALTER TABLE `{$table}` ADD COLUMN `deleted` ENUM('NO', 'YES') NOT NULL DEFAULT 'NO', ADD INDEX `deleted` (`deleted` ASC)";
		
		// created_at
		if(!in_array('created_at', $cols))
			$sqls[] = "ALTER TABLE `{$table}` ADD COLUMN `created_at` DATETIME NULL";
		
		// created_by
		if(!in_array('created_by', $cols))
			$sqls[] = "ALTER TABLE `{$table}` ADD COLUMN `created_by` VARCHAR(255) NULL";
		
		// updated_at
		if(!in_array('updated_at', $cols))
			$sqls[] = "ALTER TABLE `{$table}` ADD COLUMN `updated_at` DATETIME NULL";
		
		// updated_by
		if(!in_array('updated_by', $cols))
			$sqls[] = "ALTER TABLE `{$table}` ADD COLUMN `updated_by` VARCHAR(255) NULL";
		
		// deleted_at
		if(!in_array('deleted_at', $cols))
			$sqls[] = "ALTER TABLE `{$table}` ADD COLUMN `deleted_at` DATETIME NULL";
		
		// deleted_by
		if(!in_array('deleted_by', $cols))
			$sqls[] = "ALTER TABLE `{$table}` ADD COLUMN `deleted_by` VARCHAR(255) NULL";
		
		
		foreach($sqls as $sql)
			$this->query($sql);
	}
	
	/**
	 * set SELECT clause for query builder
	 */
	public function select($fields="*")
	{
		$this->dbQL = [];
		$this->dbQL['SELECT'] = $fields;
		return $this;
	}
	
	/**
	 * set FROM clause for query builder
	 */
	public function from($tables)
	{
		$this->dbQL['FROM'] = $tables;
		return $this;
	}
	
	/**
	 * add WHERE condition (multiple calls are joined with AND)
	 */
	public function where($where)
	{
		$this->dbQL['WHERE'][] = [$where];
		return $this;
	}
	
	/**
	 * set GROUP BY clause
	 */
	public function groupBy($groupBy)
	{
		$this->dbQL['GROUP BY'] = $groupBy;
		return $this;
	}

	/**
	 * set HAVING clause
	 */
	public function having($having)
	{
		$this->dbQL['HAVING'] = $having;
		return $this;
	}
	
	/**
	 * set ORDER BY clause
	 */
	public function orderBy($orderBy)
	{
		$this->dbQL['ORDER BY'] = $orderBy;
		return $this;
	}
	
	/**
	 * set LIMIT clause
	 */
	public function limit($limit)
	{
		$this->dbQL['LIMIT'] = $limit;
		return $this;
	}
	
	/**
	 * execute built query from query builder
	 */
	public function executeSQL($params=[])
	{
		$sql = $this->getSQL();
		return $this->query($sql, $params);
	}
	
	/**
	 * build SQL string from query builder and reset state
	 */
	public function getSQL()
	{
		if(!isset($this->dbQL['SELECT']))
			$this->dbQL['SELECT'] = '*';
		
		if(!isset($this->dbQL['FROM']))
		{
			if(empty($this->table))
				trigger_error("DBM Error : FROM not initialized", E_USER_ERROR);
			else
				$this->dbQL['FROM'] = $this->table;
		}
		
		if($this->soft_mode)
			$this->where("deleted = 'NO'");
		
		$sql = "SELECT\n";
		$sql .= "\t\t{$this->dbQL['SELECT']}\n";
		$sql .= "FROM\n";
		$sql .= "\t\t{$this->dbQL['FROM']}\n";
		
		if(isset($this->dbQL['WHERE']) && count($this->dbQL['WHERE']))
		{
			$sql .= "WHERE\n";
			
			$init = false;
			foreach($this->dbQL['WHERE'] as $where)
			{
				if($init)$sql .= " AND\n";
				
				if(is_array($where))
				{
					if(is_numeric($where[0])) $where[0] = "ID = {$where[0]}";
					$sql .= "\t\t{$where[0]}";
				}
				else
				{
					if(is_numeric($where)) $where = "ID = {$where}";
					$sql .= "\t\t{$where}";
				}
				
				$init = true;
			}
		}
		
		// group by
		if(isset($this->dbQL['GROUP BY']))
		{
			$sql .= "\nGROUP BY\n";
			$sql .= "\t\t{$this->dbQL['GROUP BY']}\n";
		}

		// having
		if(isset($this->dbQL['HAVING']))
		{
			$sql .= "\nHAVING\n";
			$sql .= "\t\t{$this->dbQL['HAVING']}\n";
		}

		// order by
		if(isset($this->dbQL['ORDER BY']))
		{
			$sql .= "\nORDER BY\n";
			$sql .= "\t\t{$this->dbQL['ORDER BY']}\n";
		}
		
		// limit
		if(isset($this->dbQL['LIMIT']))
		{
			$sql .= "\nLIMIT\n";
			$sql .= "\t\t{$this->dbQL['LIMIT']}\n";
		}
		
		$this->dbQL = [];
		return $sql;
	}
	
	
	/**
	 * paginate a query — returns array with total, per_page, data, etc.
	 */
	public function paginate($sql, $params=[], $current_page=1, $limit=20, $sql_cal_found_mode=false)
	{
		$current_page = (int)$current_page;
		if($current_page <= 0)$current_page = 1;
		$offset = ($current_page-1) * $limit;
		if($offset < 0)$offset = 0;
		
		$sql = trim($sql);
		if(!$sql_cal_found_mode)
		{
			// strip ORDER BY from count query for performance
			$sql_inner = $sql;
			if(preg_match('/\bORDER\s+BY\b/si', $sql_inner))
			{
				$sql_inner = explode("\nORDER BY", $sql_inner);
				$sql_inner = join("", array_slice($sql_inner, 0, -1));
			}

			$sql_count = "SELECT COUNT(*) FROM ({$sql_inner}) AS _count_query";
			$total_found = $this->query(trim($sql_count), $params)->fetchOne();

			$total_page = (int)ceil($total_found / $limit);
			if($total_page > 0 && $current_page > $total_page)
			{
				$current_page = $total_page;
				$offset = ($current_page-1) * $limit;
			}

			$sql .= "\nLIMIT " . (int)$offset . ", " . (int)$limit;
			if($total_page == 0)
			{
				$current_page = 1;
			}

			$result = $this->query($sql, $params)->fetchAll();
		}
		else
		{
			$sql = str_replace(["SELECT\n", "SELECT\r\n"], "SELECT\nSQL_CALC_FOUND_ROWS\n", $sql);
			$sql .= "\nLIMIT " . (int)$offset . ", " . (int)$limit;
			$result = $this->query($sql, $params)->fetchAll();
			$total_found = $this->query("SELECT FOUND_ROWS()")->fetchOne();

			$total_page = (int)ceil($total_found / $limit);
			if($current_page >= $total_page)$current_page = $total_page;
		}
		
		$_response = [];
		$_response['total'] = $total_found;
		$_response['per_page'] = $limit;
		$_response['last_page'] = $total_page;
		$_response['current_page'] = $current_page;
		$_response['from'] = (($current_page-1) * $limit) + 1;
		$_response['to'] = ($_response['from'] + $limit) - 1;
		
		if($_response['to'] > $total_found)
			$_response['to'] = $total_found;
		
		$_response['page_start'] = 1;
		$_response['page_end'] = 10;
		
		if($_response['page_end'] > $total_page)
			$_response['page_end'] = $total_page;
		
		$_response['data'] = $result;
		
		if($total_page > 10)
		{
			if($current_page >= 6)
			{
				$_response['page_start'] = $current_page - 5;
				$_response['page_end'] = $current_page + 4;
				
				if($_response['page_end'] > $total_page)
				{
					$diff =  $_response['page_end'] - $total_page;
					
					$_response['page_end'] = $total_page;
					$_response['page_start'] -= $diff;
				}
			}
		}
		
		return $_response;
	}
	
	/**
	 * check if inside a transaction
	 */
	public function inTransaction()
	{
		return $this->transaction_level > 0;
	}

	/**
	 * get transaction nesting depth (0 = none)
	 */
	public function getTransactionLevel()
	{
		return $this->transaction_level;
	}

	/**
	 * begin transaction (nested calls create savepoints)
	 */
	public function beginTransaction()
	{
		if($this->transaction_level == 0)
		{
			$this->connection->beginTransaction();
		}
		else
		{
			$this->connection->exec("SAVEPOINT sp_{$this->transaction_level}");
		}

		$this->transaction_level++;
	}

	/**
	 * commit transaction (releases savepoint if nested)
	 */
	public function commit()
	{
		if($this->transaction_level <= 0) return;

		$this->transaction_level--;

		try
		{
			if($this->transaction_level == 0)
			{
				$this->connection->commit();
			}
			else
			{
				$this->connection->exec("RELEASE SAVEPOINT sp_{$this->transaction_level}");
			}
		}
		catch(\Throwable $e)
		{
			$this->transaction_level++;
			throw $e;
		}
	}

	/**
	 * rollback transaction (rolls back to savepoint if nested)
	 */
	public function rollBack()
	{
		if($this->transaction_level <= 0) return;

		$this->transaction_level--;

		if($this->transaction_level == 0)
		{
			$this->connection->rollBack();
		}
		else
		{
			$this->connection->exec("ROLLBACK TO SAVEPOINT sp_{$this->transaction_level}");
		}
	}

	/**
	 * run callback in a transaction — auto commit/rollback, throws on error
	 */
	public function transaction(callable $callback)
	{
		$this->beginTransaction();

		try
		{
			$result = $callback($this);
			$this->commit();
			return $result;
		}
		catch(\Throwable $e)
		{
			$this->rollBack();
			throw $e;
		}
	}

	/**
	 * run callback in a transaction — returns true/false, no exception
	 * pass $error by reference to get the exception on failure
	 */
	public function safeTransaction(callable $callback, &$error=null)
	{
		try
		{
			$this->transaction($callback);
			return true;
		}
		catch(\Throwable $e)
		{
			$error = $e;
			return false;
		}
	}
	
}

