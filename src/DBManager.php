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
	
	
	private $_columns_forbidden = ['deleted', 'created_at', 'created_by', 'updated_at', 'updated_by', 'deleted_at', 'deleted_by'];
	private $_sql_functions = ['NOW()', 'CURRENT_DATE()'];
	
	private $table;
	private $_from_query_delete = false;
	
	private $dbQL = [];
	
	
	/**
	 * DBManager constructor.
	 *
	 * @param bool $soft_mode (auto turn soft mode)
	 */
	public function __construct($soft_mode=true)
	{
		$this->soft_mode = $soft_mode;
	}
	
	private function error_interceptor($bool)
	{
		if($bool)
			set_exception_handler(array(__CLASS__, 'exception_handler'));
		else
			restore_exception_handler();
	}
	
	/**
	 * @param $exception
	 */
	public static function exception_handler($exception)
	{
		$message = $exception->getMessage();
		trigger_error("DBM Error : ".$message, E_USER_ERROR);
	}
	
	/**
	 * Connection to database
	 *
	 * @param string $driver
	 * @param string $host
	 * @param string $username
	 * @param string $password
	 * @param string $database
	 * @param string $port
	 * @param array  $pdo_options (see official documentation)
	 */
	public function connect($driver='mysql', $host='localhost', $username='root', $password='', $database='', $port='',  $pdo_options=[])
	{
		$dsn = "{$driver}:host={$host};dbname={$database};port={$port}";
		
		set_exception_handler(array(__CLASS__, 'exception_handler'));
		$this->connection = new PDO($dsn, $username, $password, $pdo_options);
		$this->connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		
		restore_exception_handler();
	}
	
	/**
	 * Turn soft mode
	 * @param $bool
	 */
	public function setSoftMode($bool)
	{
		$this->soft_mode = $bool;
	}
	
	/**
	 * Get current soft mode
	 */
	public function getSoftMode()
	{
		return $this->soft_mode;
	}
	
	/**
	 * Get PDO connection link
	 * @return mixed
	 */
	public function getConnectionLink()
	{
		return $this->connection;
	}
		
		/**
	 * Close connection
	 *
	 * @param string $name
	 */
	public function close()
	{
		$this->connection = null;
	}
	
	/**
	 * Set connection attribute (see official pdo documentation)
	 *
	 * @param $attribute
	 * @param $value
	 */
	public function setAttribute($attribute, $value)
	{
		$this->connection->setAttribute($attribute, $value);
	}
	
	/**
	 * Purge query stack
	 */
	protected function purgeQueryStack()
	{
		if(count($this->query_stack) >= 100)
		{
			$this->query_stack = [];
			$this->query_id = 0;
		}
	}
	
		
	/**
	 * Execute query
	 *
	 * @param string $sql
	 * @param array $binds [parameters to protect :column ]
	 */
	public function query($sql, $binds=[])
	{
		$this->purgeQueryStack();
		if($sql != "SELECT FOUND_ROWS()")$this->last_query = $sql;
		
		$this->error_interceptor(1);
		
		if(!count($binds))
		{
			$this->query_stack[++$this->query_id] = $this->connection->query($sql);
		}
		else
		{
			$stmt = $this->prepare($sql);
			foreach($binds as $bind => $value)
			{
				$stmt->bindParam($bind, $value);
			}
			$stmt->execute();
			$this->query_stack[++$this->query_id] = $stmt;
		}
		
		$this->error_interceptor(0);
		
		return $this;
	}
	
	
	/**
	 * Prepare statement
	 *
	 * @param $sql
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
	 * fetch resultset
	 *
	 * @param int $fetch_mode
	 *
	 * @return  bool|array or object
	 */
	public function fetch($fetch_mode=PDO::FETCH_ASSOC)
	{
		if(!is_object($this->query_stack[$this->query_id]))return false;
		return $this->query_stack[$this->query_id]->fetch($fetch_mode);
	}
	
	/**
	 * fetch object resultset
	 *
	 * @param int $fetch_mode
	 *
	 * @return  bool|object
	 */
	public function fetchObject()
	{
		return $this->fetch(PDO::FETCH_OBJ);
	}
	
	
	/**
	 * fetch all resultset
	 *
	 * @param int $fetch_mode
	 *
	 * @return  bool|array
	 */
	public function fetchAll($fetch_mode=PDO::FETCH_ASSOC)
	{
		if(!is_object($this->query_stack[$this->query_id]))return false;
		return $this->query_stack[$this->query_id]->fetchAll($fetch_mode);
	}
	
	/**
	 * Fetch all in object
	 *
	 * @return bool|array
	 */
	public function fetchObjectAll()
	{
		return $this->fetchAll(PDO::FETCH_OBJ);
	}
	
	/**
	 * fetch and return the first column of resultset
	 *
	 * @return bool|string
	 */
	public function fetchOne()
	{
		if(!is_object($this->query_stack[$this->query_id]))return false;
		return $this->query_stack[$this->query_id]->fetch(PDO::FETCH_NUM)[0];
	}
	
	/**
	 * Fetch data with the first column
	 * @return bool
	 */
	public function fetchAllOne()
	{
		if(!is_object($this->query_stack[$this->query_id]))return false;
		return $this->query_stack[$this->query_id]->fetchAll(PDO::FETCH_COLUMN, 0);
	}
	
	
	/**
	 * get count row
	 *
	 * @return int
	 */
	public function rowCount()
	{
		if(!is_object($this->query_stack[$this->query_id]))return false;
		
		return $this->query_stack[$this->query_id]->rowCount();
	}
	
	
	/**
	 * get last insert id
	 *
	 * @return mixed
	 */
	public function lastInsertId()
	{
		return $this->connection->lastInsertId();
	}
	
	/**
	 * Get last query
	 * @return string last_query
	 */
	public function getLastQuery()
	{
		return $this->last_query;
	}
	
	/**
	 * Get last query paramaters
	 * @return array
	 */
	public function getLastQueryParams()
	{
		return $this->last_query_params;
	}
	
	/**
	 * Debug last query
	 */
	public function dBugLastQuery()
	{
		echo "<br>last query => ";
		
		echo "<pre style='border:1px solid #ccc; padding:10px'>";
		echo $this->getLastQuery();
		echo "</pre>";
		
		$params = $this->getLastQueryParams();
		if(count($params) > 0)
		{
			echo "params => ";
			echo "<pre style='border:1px solid #ccc; padding:10px'>";
			print_r($params);
			echo "</pre>";
		}
		
	}
	
	
	/**
	 * Assign table for operation
	 * @param $table
	 *
	 * @return $this
	 */
	public function table($table)
	{
		$this->table = $table;
		return $this;
	}
	
	
	/**
	 * Insert a row
	 *
	 * @param array|object       $row
	 * @param int    $created_by
	 * @param string $table
	 *
	 * @return last inserted ID
	 */
	public function insert($row, $created_by='', $table='')
	{
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
			$row['created_by'] = $created_by;
		}
		
		if(empty($table))$table = $this->table;
		
		
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
		
		$this->error_interceptor(1);
		$prepared = $this->connection->prepare($stmt);
		
		// bind parameters
		foreach($columns as $column)
		{
			$prepared->bindParam(":{$column}", $row[$column]);
		}
		
		$prepared->execute();
		$this->error_interceptor(0);
		
		return $this->lastInsertId();
	}
	
	
	/**
	 * Update a record
	 *
	 * @param array     	$row
	 * @param string|array  $where if is numeric ID or array bind parameters are generated
	 * @param int    		$limit (default -1 no limit)
	 * @param        		$table
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
				$row['updated_by'] = $updated_by;
			}
		}
		
		
		if(empty($table))$table = $this->table;
		
		$stmt = "UPDATE\n";
		$stmt .= "	`{$table}`\n";
		$stmt .= "SET\n";
		
		$_params = [];
		
		$init = false;
		foreach($row as $key => $value)
		{
			if($init)$stmt .= ",\n";
			// $stmt .= "\t`{$key}` = ?";
			$stmt .= "\t`{$key}` = :{$key}";
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
				if(ctype_digit($where))
				{
					$where_dyn[] = "`ID` = {$where}";
				}
				else
				{
					$where_dyn[] = $where;
				}
			}
		}
		else
		{
			$where_dyn[] = $where[0];
			$_ps = array_slice($where, 1, count($where));
			
			/*
			foreach($_ps as $_p)
			{
				$_params[] = $_p;
			}
			*/
			
		}
		
		
		// where dynamic
		if(count($where_dyn))
		{
			$stmt .= "\nWHERE ";
			
			$init = false;
			foreach($where_dyn as $wd => $val)
			{
				if($init) $stmt .= " AND\n";
				$stmt .= "\n\t\t{$val}";
				
				$init = true;
			}
		}
		
		
		// add limit
		if($limit != -1 && is_numeric($limit))
		{
			$stmt .= "\nLIMIT {$limit}";
		}
		
		$this->purgeQueryStack();
		$this->query_stack[++$this->query_id] = $stmt;
		$this->last_query = $stmt;
		$this->last_query_params = $_params;
		
		$this->error_interceptor(1);
		$prepared = $this->connection->prepare($stmt);
		
		if(count($_params))
		{
			foreach($_params as $p => $v)
			{
				$prepared->bindValue(":{$p}", $v);
			}
		}
		
		$affected_rows = $prepared->execute();
		$this->error_interceptor(0);
		
		$this->_from_query_delete = false;
		
		return $affected_rows;
	}
	
	
	/**
	 * Delete query
	 *
	 * @param  int|string|array      $where
	 * @param string $limit
	 * @param string $deleted_by
	 * @param string $table
	 *
	 * @return mixed
	 */
	public function delete($where, $limit=-1, $deleted_by='', $table='')
	{
		if(empty($table))$table = $this->table;
		
		if($this->soft_mode)
		{
			$row = [];
			$row['deleted'] = 'YES';
			$row['deleted_at'] = date('Y-m-d H:i:s');
			$row['deleted_by'] = $deleted_by;
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
				
				if(ctype_digit($where))
				{
					$sql .= " ID = {$where} ";
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
		if($limit != -1)
		{
			$sql .= " LIMIT {$limit} ";
		}
		
		
		$stmt = $this->prepare($sql);
		return $this->execute($stmt, $_params)->rowCount();
		
		
	}
	
	
	/**
	 * Get a record by ID (if array multiple is returned)
	 *
	 * @param int|array $ID
	 * @param string $fields
	 * @param string $table
	 *
	 * @return array|bool
	 */
	public function get($ID, $fields='*', $fetch_mode=PDO::FETCH_ASSOC, $table='')
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
		
		return $records;
		
	}
	
	
	/**
	 * Add dynamically table soft mode columns (deleted, created_at, created_by, updated_at, updated_by, deleted_at, deleted_by)
	 * @param string $table
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
		
		
		// force field at end
		/*$sqls[] = "ALTER TABLE `{$table}` MODIFY COLUMN `deleted` END";
		$sqls[] = "ALTER TABLE `{$table}` MODIFY COLUMN `created_at` AFTER `deleted`";
		$sqls[] = "ALTER TABLE `{$table}` MODIFY COLUMN `created_by` AFTER `created_at`";
		$sqls[] = "ALTER TABLE `{$table}` MODIFY COLUMN `updated_at` AFTER `created_by`";
		$sqls[] = "ALTER TABLE `{$table}` MODIFY COLUMN `updated_by` AFTER `updated_at`";
		$sqls[] = "ALTER TABLE `{$table}` MODIFY COLUMN `deleted_at` AFTER `updated_by`";
		$sqls[] = "ALTER TABLE `{$table}` MODIFY COLUMN `deleted_by` AFTER `deleted_at`";
		*/
		
		foreach($sqls as $sql)
			$this->query($sql);
	}
	
	/**
	 * SELECT clause for query
	 * @param string $fields
	 *
	 * @return $this
	 */
	public function select($fields="*")
	{
		$this->dbQL = [];
		$this->dbQL['SELECT'] = $fields;
		return $this;
	}
	
	/**
	 * FROM clause for query
	 * @param string $tables
	 *
	 * @return $this
	 */
	public function from($tables)
	{
		$this->dbQL['FROM'] = $tables;
		return $this;
	}
	
	/**
	 * WHERE clause for query
	 * @param string $where
	 *
	 * @return $this
	 */
	public function where($where)
	{
		$this->dbQL['WHERE'][] = [$where];
		return $this;
	}
	
	/**
	 * GROUP BY clause for query
	 *
	 * @param string $fields
	 *
	 * @return $this
	 */
	public function groupBy($groupBy)
	{
		$this->dbQL['GROUP BY'] = $groupBy;
		return $this;
	}
	
	/**
	 * ORDER BY clause for query
	 *
	 * @param $orderBy
	 *
	 * @return $this
	 */
	public function orderBy($orderBy)
	{
		$this->dbQL['ORDER BY'] = $orderBy;
		return $this;
	}
	
	/**
	 * LIMIT clause for query
	 *
	 * @param int $limit
	 *
	 * @return $this
	 */
	public function limit($limit)
	{
		$this->dbQL['LIMIT'] = $limit;
		return $this;
	}
	
	
	/**
	 * Construct query
	 *
	 * @return string
	 */
	public function getSQL()
	{
		if(!isset($this->dbQL['SELECT']))
			trigger_error("DBM Error : SELECT not initialized", E_USER_ERROR);
		
		if(!isset($this->dbQL['FROM']))
			trigger_error("DBM Error : FROM not initialized", E_USER_ERROR);
		
		
		
		if($this->soft_mode)
		{
			$this->where("deleted = 'no'");
		}
		
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
				$sql .= "\t\t{$where[0]}";
				$init = true;
			}
		}
		
		// group by
		if(isset($this->dbQL['GROUP BY']))
		{
			$sql .= "\nGROUP BY\n";
			$sql .= "\t\t{$this->dbQL['GROUP BY']}\n";
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
	 * Get full pagination
	 *
	 * @param string $sql
	 * @param array  $params
	 * @param int    $limit
	 * @param int    $current_page
	 *
	 *
	 */
	public function paginate($sql, $params=[], $current_page=1, $limit=20)
	{
		$current_page = (int)$current_page;
		if($current_page <= 0)$current_page = 1;
		$offset = ($current_page-1) * $limit;
		
		$sql = str_replace("SELECT\n", "SELECT\nSQL_CALC_FOUND_ROWS\n", $sql);
		$sql .= "\nLIMIT {$offset}, {$limit}";
		
		$result = $this->query($sql, $params)->fetchAll();
		
		
		$total_found = $this->query("SELECT FOUND_ROWS()")->fetchOne();
		$total_page = (int)ceil($total_found / $limit);
		
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
	
	
	
}


