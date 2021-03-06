<?php
/**
 * MyBB 1.6
 * Copyright 2010 MyBB Group, All Rights Reserved
 *
 * Website: http://mybb.com
 * License: http://mybb.com/about/license
 *
 * $Id$
 */

class DB_MySQLi
{
	/**
	 * The title of this layer.
	 *
	 * @var string
	 */
	public $title = "MySQLi";
	
	/**
	 * The short title of this layer.
	 *
	 * @var string
	 */
	public $short_title = "MySQLi";
	
	/**
	 * The type of db software being used.
	 *
	 * @var string
	 */
	public $type;

	/**
	 * A count of the number of queries.
	 *
	 * @var int
	 */
	public $query_count = 0;

	/**
	 * A list of the performed queries.
	 *
	 * @var array
	 */
	public $querylist = array();

	/**
	 * 1 if error reporting enabled, 0 if disabled.
	 *
	 * @var boolean
	 */
	public $error_reporting = 1;

	/**
	 * The read database connection resource.
	 *
	 * @var resource
	 */
	public $read_link;
	
	/**
	 * The write database connection resource
	 *
	 * @var resource
	 */
	public $write_link;
	
	/**
	 * Reference to the last database connection resource used.
	 *
	 * @var resource
	 */
	public $current_link;

	/**
	 * Explanation of a query.
	 *
	 * @var string
	 */
	public $explain;

	/**
	 * The current version of MySQL.
	 *
	 * @var string
	 */
	public $version;

	/**
	 * The current table type in use (myisam/innodb)
	 *
	 * @var string
	 */
	public $table_type = "myisam";

	/**
	 * The table prefix used for simple select, update, insert and delete queries
	 *
	 * @var string
	 */
	public $table_prefix;
	
	/**
	 * The extension used to run the SQL database
	 *
	 * @var string
	 */
	public $engine = "mysqli";
	
	/**
	 * Weather or not this engine can use the search functionality
	 *
	 * @var boolean
	 */
	public $can_search = true;

	/**
	 * The database encoding currently in use (if supported)
	 *
	 * @var string
	 */
	public $db_encoding = "utf8";

	/**
	 * The time spent performing queries
	 *
	 * @var float
	 */
	public $query_time = 0;

	/**
	 * Connect to the database server.
	 *
	 * @param array Array of DBMS connection details.
	 * @return resource The DB connection resource. Returns false on fail or -1 on a db connect failure.
	 */
	function connect($config)
	{
		// Simple connection to one server
		if(array_key_exists('hostname', $config))
		{
			$connections['read'][] = $config;
		}
		else
		// Connecting to more than one server
		{
			// Specified multiple servers, but no specific read/write servers
			if(!array_key_exists('read', $config))
			{
				foreach($config as $key => $settings)
				{
					if(is_int($key)) $connections['read'][] = $settings;
				}
			}
			// Specified both read & write servers
			else
			{
				$connections = $config;
			}
		}

		$this->db_encoding = $config['encoding'];

		// Actually connect to the specified servers
		foreach(array('read', 'write') as $type)
		{
			if(!isset($connections[$type]) || !is_array($connections[$type]))
			{
				break;
			}
			
			if(array_key_exists('hostname', $connections[$type]))
			{
				$details = $connections[$type];
				unset($connections);
				$connections[$type][] = $details;
			}

			// Shuffle the connections
			shuffle($connections[$type]);

			// Loop-de-loop
			foreach($connections[$type] as $single_connection)
			{
				$persist = '';
				$connect_function = 'mysqli_connect';
				if(isset($single_connection['pconnect']) && version_compare(PHP_VERSION, '5.3.0', '>='))
				{
					$persist = 'p:';
				}

				$link = "{$type}_link";

				$this->get_execution_time();

				// Specified a custom port for this connection?
				$port = 0;
				if(strstr($single_connection['hostname'],':'))
				{
					list($hostname, $port) = explode(":", $single_connection['hostname'], 2);
				}

				if($port)
				{
					$this->$link = @$connect_function($persist.$hostname, $single_connection['username'], $single_connection['password'], "", $port);
				}
				else
				{
					$this->$link = @$connect_function($persist.$single_connection['hostname'], $single_connection['username'], $single_connection['password']);
				}

				$time_spent = $this->get_execution_time();
				$this->query_time += $time_spent;

				// Successful connection? break down brother!
				if($this->$link)
				{
					$this->connections[] = "[".strtoupper($type)."] {$single_connection['username']}@{$single_connection['hostname']} (Connected in ".number_format($time_spent, 0)."s)";
					break;
				}
				else
				{
					$this->connections[] = "<span style=\"color: red\">[FAILED] [".strtoupper($type)."] {$single_connection['username']}@{$single_connection['hostname']}</span>";
				}
			}
		}

		// No write server was specified (simple connection or just multiple servers) - mirror write link
		if(!array_key_exists('write', $connections))
		{
			$this->write_link = &$this->read_link;
		}

		// Have no read connection?
		if(!$this->read_link)
		{
			$this->error("[READ] Unable to connect to MySQL server");
			return false;
		}
		// No write?
		else if(!$this->write_link)
		{
			$this->error("[WRITE] Unable to connect to MySQL server");
			return false;
		}

		// Select databases
		if(!$this->select_db($config['database']))
		{
			return -1;
		}

		$this->current_link = &$this->read_link;
		return $this->read_link;
	}

	/**
	 * Selects the database to use.
	 *
	 * @param string The database name.
	 * @return boolean True when successfully connected, false if not.
	 */
	function select_db($database)
	{
		global $mybb;
		
		$master_success = @mysqli_select_db($this->read_link, $database) or $this->error("[READ] Unable to select database", $this->read_link);
		if($this->write_link)
		{
			$slave_success = @mysqli_select_db($this->write_link, $database) or $this->error("[WRITE] Unable to select slave database", $this->write_link);
			
			$success = ($master_success && $slave_success ? true : false);
		}
		else
		{
			$success = $master_success;
		}
		
		if($success && $this->db_encoding)
		{
			$this->query("SET NAMES '{$this->db_encoding}'");
			
			if($slave_success && count($this->connections) > 1)
			{
				$this->write_query("SET NAMES '{$this->db_encoding}'");
			}
		}
		return $success;
	}

	/**
	 * Query the database.
	 *
	 * @param string The query SQL.
	 * @param boolean 1 if hide errors, 0 if not.
	 * @param integer 1 if executes on slave database, 0 if not.
	 * @return resource The query data.
	 */
	function query($string, $hide_errors=0, $write_query=0)
	{
		global $pagestarttime, $db, $mybb;

		$this->get_execution_time();

		// Only execute write queries on slave server
		if($write_query && $this->write_link)
		{
			$this->current_link = &$this->write_link;
			$query = @mysqli_query($this->write_link, $string);
		}
		else
		{
			$this->current_link = &$this->read_link;
			$query = @mysqli_query($this->read_link, $string);
		}

		if($this->error_number() && !$hide_errors)
		{
			$this->error($string);
			exit;
		}
		
		$query_time = $this->get_execution_time();
		$this->query_time += $query_time;
		$this->query_count++;
		
		if($mybb->debug_mode)
		{
			$this->explain_query($string, $query_time);
		}
		return $query;
	}
	
	/**
	 * Execute a write query on the slave database
	 *
	 * @param string The query SQL.
	 * @param boolean 1 if hide errors, 0 if not.
	 * @return resource The query data.
	 */
	function write_query($query, $hide_errors=0)
	{
		return $this->query($query, $hide_errors, 1);
	}

	/**
	 * Explain a query on the database.
	 *
	 * @param string The query SQL.
	 * @param string The time it took to perform the query.
	 */
	function explain_query($string, $qtime)
	{
		global $plugins;

		$debug_extra = '';
		if($plugins->current_hook)
		{
			$debug_extra = "<div style=\"float_right\">(Plugin Hook: {$plugins->current_hook})</div>";
		}
		if(preg_match("#^\s*select#i", $string))
		{
			$query = mysqli_query($this->current_link, "EXPLAIN $string");
			$this->explain .= "<table style=\"background-color: #666;\" width=\"95%\" cellpadding=\"4\" cellspacing=\"1\" align=\"center\">\n".
				"<tr>\n".
				"<td colspan=\"8\" style=\"background-color: #ccc;\">{$debug_extra}<div><strong>#".$this->query_count." - Select Query</strong></div></td>\n".
				"</tr>\n".
				"<tr>\n".
				"<td colspan=\"8\" style=\"background-color: #fefefe;\"><span style=\"font-family: Courier; font-size: 14px;\">".htmlspecialchars_uni($string)."</span></td>\n".
				"</tr>\n".
				"<tr style=\"background-color: #efefef;\">\n".
				"<td><strong>table</strong></td>\n".
				"<td><strong>type</strong></td>\n".
				"<td><strong>possible_keys</strong></td>\n".
				"<td><strong>key</strong></td>\n".
				"<td><strong>key_len</strong></td>\n".
				"<td><strong>ref</strong></td>\n".
				"<td><strong>rows</strong></td>\n".
				"<td><strong>Extra</strong></td>\n".
				"</tr>\n";

			while($table = mysqli_fetch_assoc($query))
			{
				$this->explain .=
					"<tr bgcolor=\"#ffffff\">\n".
					"<td>".$table['table']."</td>\n".
					"<td>".$table['type']."</td>\n".
					"<td>".$table['possible_keys']."</td>\n".
					"<td>".$table['key']."</td>\n".
					"<td>".$table['key_len']."</td>\n".
					"<td>".$table['ref']."</td>\n".
					"<td>".$table['rows']."</td>\n".
					"<td>".$table['Extra']."</td>\n".
					"</tr>\n";
			}
			$this->explain .=
				"<tr>\n".
				"<td colspan=\"8\" style=\"background-color: #fff;\">Query Time: ".$qtime."</td>\n".
				"</tr>\n".
				"</table>\n".
				"<br />\n";
		}
		else
		{
			$this->explain .= "<table style=\"background-color: #666;\" width=\"95%\" cellpadding=\"4\" cellspacing=\"1\" align=\"center\">\n".
				"<tr>\n".
				"<td style=\"background-color: #ccc;\">{$debug_extra}<div><strong>#".$this->query_count." - Write Query</strong></div></td>\n".
				"</tr>\n".
				"<tr style=\"background-color: #fefefe;\">\n".
				"<td><span style=\"font-family: Courier; font-size: 14px;\">".htmlspecialchars_uni($string)."</span></td>\n".
				"</tr>\n".
				"<tr>\n".
				"<td bgcolor=\"#ffffff\">Query Time: ".$qtime."</td>\n".
				"</tr>\n".
				"</table>\n".
				"<br />\n";
		}

		$this->querylist[$this->query_count]['query'] = $string;
		$this->querylist[$this->query_count]['time'] = $qtime;
	}


	/**
	 * Return a result array for a query.
	 *
	 * @param resource The query data.
	 * @param constant The type of array to return.
	 * @return array The array of results.
	 */
	function fetch_array($query, $resulttype=MYSQLI_ASSOC)
	{
		switch($resulttype)
		{
			case MYSQLI_NUM:
			case MYSQLI_BOTH:
				break;
			default:
				$resulttype = MYSQLI_ASSOC;
				break;
		}

		$array = mysqli_fetch_array($query, $resulttype);

		return $array;
	}

	/**
	 * Return a specific field from a query.
	 *
	 * @param resource The query ID.
	 * @param string The name of the field to return.
	 * @param int The number of the row to fetch it from.
	 */
	function fetch_field($query, $field, $row=false)
	{
		if($row !== false)
		{
			$this->data_seek($query, $row);
		}
		$array = $this->fetch_array($query);
		return $array[$field];
	}

	/**
	 * Moves internal row pointer to the next row
	 *
	 * @param resource The query ID.
	 * @param int The pointer to move the row to.
	 */
	function data_seek($query, $row)
	{
		return mysqli_data_seek($query, $row);
	}

	/**
	 * Return the number of rows resulting from a query.
	 *
	 * @param resource The query data.
	 * @return int The number of rows in the result.
	 */
	function num_rows($query)
	{
		return mysqli_num_rows($query);
	}

	/**
	 * Return the last id number of inserted data.
	 *
	 * @return int The id number.
	 */
	function insert_id()
	{
		$id = mysqli_insert_id($this->current_link);
		return $id;
	}

	/**
	 * Close the connection with the DBMS.
	 *
	 */
	function close()
	{
		@mysqli_close($this->read_link);
		if($this->write_link)
		{
			@mysqli_close($this->write_link);
		}
	}

	/**
	 * Return an error number.
	 *
	 * @return int The error number of the current error.
	 */
	function error_number()
	{
		if($this->current_link)
		{
			return mysqli_errno($this->current_link);			
		}
		else
		{
			return mysqli_connect_errno();
		}
	}

	/**
	 * Return an error string.
	 *
	 * @return string The explanation for the current error.
	 */
	function error_string()
	{
		if($this->current_link)
		{
			return mysqli_error($this->current_link);			
		}
		else
		{
			return mysqli_connect_error();
		}
	}

	/**
	 * Output a database error.
	 *
	 * @param string The string to present as an error.
	 */
	function error($string="")
	{
		if($this->error_reporting)
		{
			if(class_exists("errorHandler"))
			{
				global $error_handler;
				
				if(!is_object($error_handler))
				{
					require_once MYBB_ROOT."inc/class_error.php";
					$error_handler = new errorHandler();
				}
				
				$error = array(
					"error_no" => $this->error_number(),
					"error" => $this->error_string(),
					"query" => $string
				);
				$error_handler->error(MYBB_SQL, $error);
			}
			else
			{
				trigger_error("<strong>[SQL] [".$this->error_number()."] ".$this->error_string()."</strong><br />{$string}", E_USER_ERROR);
			}
		}
		else
		{
			return false;
		}
	}


	/**
	 * Returns the number of affected rows in a query.
	 *
	 * @return int The number of affected rows.
	 */
	function affected_rows()
	{
		return mysqli_affected_rows($this->current_link);
	}


	/**
	 * Return the number of fields.
	 *
	 * @param resource The query data.
	 * @return int The number of fields.
	 */
	function num_fields($query)
	{
		return mysqli_num_fields($query);
	}

	/**
	 * Lists all functions in the database.
	 *
	 * @param string The database name.
	 * @param string Prefix of the table (optional)
	 * @return array The table list.
	 */
	function list_tables($database, $prefix='')
	{
		if($prefix)
		{
			$query = $this->query("SHOW TABLES FROM `$database` LIKE '".$this->escape_string($prefix)."%'");
		}
		else
		{
			$query = $this->query("SHOW TABLES FROM `$database`");
		}
		
		while(list($table) = mysqli_fetch_array($query))
		{
			$tables[] = $table;
		}
		return $tables;
	}

	/**
	 * Check if a table exists in a database.
	 *
	 * @param string The table name.
	 * @return boolean True when exists, false if not.
	 */
	function table_exists($table)
	{
		// Execute on master server to ensure if we've just created a table that we get the correct result
		$query = $this->write_query("
			SHOW TABLES 
			LIKE '{$this->table_prefix}$table'
		");
		$exists = $this->num_rows($query);
		
		if($exists > 0)
		{
			return true;
		}
		else
		{
			return false;
		}
	}

	/**
	 * Check if a field exists in a database.
	 *
	 * @param string The field name.
	 * @param string The table name.
	 * @return boolean True when exists, false if not.
	 */
	function field_exists($field, $table)
	{
		$query = $this->write_query("
			SHOW COLUMNS 
			FROM {$this->table_prefix}$table 
			LIKE '$field'
		");
		$exists = $this->num_rows($query);
		
		if($exists > 0)
		{
			return true;
		}
		else
		{
			return false;
		}
	}

	/**
	 * Add a shutdown query.
	 *
	 * @param resource The query data.
	 * @param string An optional name for the query.
	 */
	function shutdown_query($query, $name=0)
	{
		global $shutdown_queries;
		if($name)
		{
			$shutdown_queries[$name] = $query;
		}
		else
		{
			$shutdown_queries[] = $query;
		}
	}

	/**
	 * Performs a simple select query.
	 *
	 * @param string The table name to be queried.
	 * @param string Comma delimetered list of fields to be selected.
	 * @param string SQL formatted list of conditions to be matched.
	 * @param array List of options, order by, order direction, limit, limit start.
	 * @return resource The query data.
	 */
	
	function simple_select($table, $fields="*", $conditions="", $options=array())
	{
		$query = "SELECT ".$fields." FROM ".$this->table_prefix.$table;
		
		if($conditions != "")
		{
			$query .= " WHERE ".$conditions;
		}
		
		if(isset($options['order_by']))
		{
			$query .= " ORDER BY ".$options['order_by'];
			if(isset($options['order_dir']))
			{
				$query .= " ".my_strtoupper($options['order_dir']);
			}
		}
		
		if(isset($options['limit_start']) && isset($options['limit']))
		{
			$query .= " LIMIT ".$options['limit_start'].", ".$options['limit'];
		}
		else if(isset($options['limit']))
		{
			$query .= " LIMIT ".$options['limit'];
		}
		
		return $this->query($query);
	}
	
	/**
	 * Build an insert query from an array.
	 *
	 * @param string The table name to perform the query on.
	 * @param array An array of fields and their values.
	 * @return int The insert ID if available
	 */
	function insert_query($table, $array)
	{
		if(!is_array($array))
		{
			return false;
		}
		$fields = "`".implode("`,`", array_keys($array))."`";
		$values = implode("','", $array);
		$this->write_query("
			INSERT 
			INTO {$this->table_prefix}{$table} (".$fields.") 
			VALUES ('".$values."')
		");
		return $this->insert_id();
	}
	
	/**
	 * Build one query for multiple inserts from a multidimensional array.
	 *
	 * @param string The table name to perform the query on.
	 * @param array An array of inserts.
	 * @return int The insert ID if available
	 */
	function insert_query_multiple($table, $array)
	{
		if(!is_array($array))
		{
			return false;
		}
		// Field names
		$fields = array_keys($array[0]);
		$fields = "`".implode("`,`", $fields)."`";

		$insert_rows = array();
		foreach($array as $values)
		{
			$insert_rows[] = "('".implode("','", $values)."')";
		}
		$insert_rows = implode(", ", $insert_rows);

		$this->write_query("
			INSERT 
			INTO {$this->table_prefix}{$table} ({$fields}) 
			VALUES {$insert_rows}
		");
	}

	/**
	 * Build an update query from an array.
	 *
	 * @param string The table name to perform the query on.
	 * @param array An array of fields and their values.
	 * @param string An optional where clause for the query.
	 * @param string An optional limit clause for the query.
	 * @param boolean An option to quote incoming values of the array.
	 * @return resource The query data.
	 */
	function update_query($table, $array, $where="", $limit="", $no_quote=false)
	{
		if(!is_array($array))
		{
			return false;
		}
		
		$comma = "";
		$query = "";
		$quote = "'";
		
		if($no_quote == true)
		{
			$quote = "";
		}
		
		foreach($array as $field => $value)
		{
			$query .= $comma."`".$field."`={$quote}{$value}{$quote}";
			$comma = ', ';
		}
		
		if(!empty($where))
		{
			$query .= " WHERE $where";
		}
		
		if(!empty($limit))
		{
			$query .= " LIMIT $limit";
		}

		return $this->write_query("
			UPDATE {$this->table_prefix}$table
			SET $query
		");
	}

	/**
	 * Build a delete query.
	 *
	 * @param string The table name to perform the query on.
	 * @param string An optional where clause for the query.
	 * @param string An optional limit clause for the query.
	 * @return resource The query data.
	 */
	function delete_query($table, $where="", $limit="")
	{
		$query = "";
		if(!empty($where))
		{
			$query .= " WHERE $where";
		}
		if(!empty($limit))
		{
			$query .= " LIMIT $limit";
		}
		return $this->write_query("DELETE FROM {$this->table_prefix}$table $query");
	}

	/**
	 * Escape a string according to the MySQL escape format.
	 *
	 * @param string The string to be escaped.
	 * @return string The escaped string.
	 */
	function escape_string($string)
	{
		if($this->db_encoding == 'utf8')
		{
			$string = validate_utf8_string($string, false);
		}
		elseif($this->db_encoding == 'utf8mb4')
		{
			$string = validate_utf8_string($string);
		}

		if(function_exists("mysqli_real_escape_string") && $this->read_link)
		{
			$string = mysqli_real_escape_string($this->read_link, $string);
		}
		else
		{
			$string = addslashes($string);
		}
		return $string;
	}
	
	/**
	 * Frees the resources of a MySQLi query.
	 *
	 * @param object The query to destroy.
	 * @return boolean Returns true on success, false on faliure
	 */
	function free_result($query)
	{
		return mysqli_free_result($query);
	}
	
	/**
	 * Escape a string used within a like command.
	 *
	 * @param string The string to be escaped.
	 * @return string The escaped string.
	 */
	function escape_string_like($string)
	{
		return $this->escape_string(str_replace(array('%', '_') , array('\\%' , '\\_') , $string));
	}

	/**
	 * Gets the current version of MySQL.
	 *
	 * @return string Version of MySQL.
	 */
	function get_version()
	{
		if($this->version)
		{
			return $this->version;
		}
		$query = $this->query("SELECT VERSION() as version");
		$ver = $this->fetch_array($query);
		if($ver['version'])
		{
			$version = explode(".", $ver['version'], 3);
			$this->version = intval($version[0]).".".intval($version[1]).".".intval($version[2]);
		}
		return $this->version;
	}

	/**
	 * Optimizes a specific table.
	 *
	 * @param string The name of the table to be optimized.
	 */
	function optimize_table($table)
	{
		$this->write_query("OPTIMIZE TABLE ".$this->table_prefix.$table."");
	}
	
	/**
	 * Analyzes a specific table.
	 *
	 * @param string The name of the table to be analyzed.
	 */
	function analyze_table($table)
	{
		$this->write_query("ANALYZE TABLE ".$this->table_prefix.$table."");
	}

	/**
	 * Show the "create table" command for a specific table.
	 *
	 * @param string The name of the table.
	 * @return string The MySQL command to create the specified table.
	 */
	function show_create_table($table)
	{
		$query = $this->write_query("SHOW CREATE TABLE ".$this->table_prefix.$table."");
		$structure = $this->fetch_array($query);
		
		return $structure['Create Table'];
	}

	/**
	 * Show the "show fields from" command for a specific table.
	 *
	 * @param string The name of the table.
	 * @return string Field info for that table
	 */
	function show_fields_from($table)
	{
		$query = $this->write_query("SHOW FIELDS FROM ".$this->table_prefix.$table."");
		while($field = $this->fetch_array($query))
		{
			$field_info[] = $field;
		}
		return $field_info;
	}

	/**
	 * Returns whether or not the table contains a fulltext index.
	 *
	 * @param string The name of the table.
	 * @param string Optionally specify the name of the index.
	 * @return boolean True or false if the table has a fulltext index or not.
	 */
	function is_fulltext($table, $index="")
	{
		$structure = $this->show_create_table($table);
		if($index != "")
		{
			if(preg_match("#FULLTEXT KEY (`?)$index(`?)#i", $structure))
			{
				return true;
			}
			else
			{
				return false;
			}
		}
		if(preg_match('#FULLTEXT KEY#i', $structure))
		{
			return true;
		}
		return false;
	}

	/**
	 * Returns whether or not this database engine supports fulltext indexing.
	 *
	 * @param string The table to be checked.
	 * @return boolean True or false if supported or not.
	 */

	function supports_fulltext($table)
	{
		$version = $this->get_version();
		$query = $this->write_query("SHOW TABLE STATUS LIKE '{$this->table_prefix}$table'");
		$status = $this->fetch_array($query);
		$table_type = my_strtoupper($status['Engine']);
		if(version_compare($version, '3.23.23', '>=') && ($table_type == 'MYISAM' || $table_type == 'ARIA'))
		{
			return true;
		}
		elseif(version_compare($version, '5.6', '>=') && $table_type == 'INNODB')
		{
			return true;
		}
		return false;
	}

	/**
	 * Returns whether or not this database engine supports boolean fulltext matching.
	 *
	 * @param string The table to be checked.
	 * @return boolean True or false if supported or not.
	 */
	function supports_fulltext_boolean($table)
	{
		$version = $this->get_version();
		$supports_fulltext = $this->supports_fulltext($table);
		if(version_compare($version, '4.0.1', '>=') && $supports_fulltext == true)
		{
			return true;
		}
		return false;
	}
	
	/**
	 * Checks to see if an index exists on a specified table
	 *
	 * @param string The name of the table.
	 * @param string The name of the index.
	 */
	function index_exists($table, $index)
	{
		$index_exists = false;
		$query = $this->write_query("SHOW INDEX FROM {$this->table_prefix}{$table}");
		while($ukey = $this->fetch_array($query))
		{
			if($ukey['Key_name'] == $index)
			{
				$index_exists = true;
				break;
			}
		}
		
		if($index_exists)
		{
			return true;
		}
		
		return false;
	}

	/**
	 * Creates a fulltext index on the specified column in the specified table with optional index name.
	 *
	 * @param string The name of the table.
	 * @param string Name of the column to be indexed.
	 * @param string The index name, optional.
	 */
	function create_fulltext_index($table, $column, $name="")
	{
		$this->write_query("ALTER TABLE {$this->table_prefix}$table ADD FULLTEXT $name ($column)");
	}

	/**
	 * Drop an index with the specified name from the specified table
	 *
	 * @param string The name of the table.
	 * @param string The name of the index.
	 */
	function drop_index($table, $name)
	{
		$this->write_query("ALTER TABLE {$this->table_prefix}$table DROP INDEX $name");
	}
	
	/**
	 * Drop an table with the specified table
	 *
	 * @param boolean hard drop - no checking
	 * @param boolean use table prefix
	 */
	function drop_table($table, $hard=false, $table_prefix=true)
	{
		if($table_prefix == false)
		{
			$table_prefix = "";
		}
		else
		{
			$table_prefix = $this->table_prefix;
		}
		
		if($hard == false)
		{
			$this->write_query('DROP TABLE IF EXISTS '.$table_prefix.$table);
		}
		else
		{
			$this->write_query('DROP TABLE '.$table_prefix.$table);
		}
	}
	
	/**
	 * Replace contents of table with values
	 *
	 * @param string The table
	 * @param array The replacements
	 */
	function replace_query($table, $replacements=array())
	{
		$values = '';
		$comma = '';
		foreach($replacements as $column => $value)
		{
			$values .= $comma."`".$column."`='".$value."'";
			
			$comma = ',';
		}
		
		if(empty($replacements))
		{
			 return false;
		}
		
		return $this->write_query("REPLACE INTO {$this->table_prefix}{$table} SET {$values}");
	}
	
	/**
	 * Drops a column
	 *
	 * @param string The table
	 * @param string The column name
	 */
	function drop_column($table, $column)
	{
		return $this->write_query("ALTER TABLE {$this->table_prefix}{$table} DROP {$column}");
	}
	
	/**
	 * Adds a column
	 *
	 * @param string The table
	 * @param string The column name
	 * @param string the new column definition
	 */
	function add_column($table, $column, $definition)
	{
		return $this->write_query("ALTER TABLE {$this->table_prefix}{$table} ADD {$column} {$definition}");
	}
	
	/**
	 * Modifies a column
	 *
	 * @param string The table
	 * @param string The column name
	 * @param string the new column definition
	 */
	function modify_column($table, $column, $new_definition)
	{
		return $this->write_query("ALTER TABLE {$this->table_prefix}{$table} MODIFY {$column} {$new_definition}");
	}
	
	/**
	 * Renames a column
	 *
	 * @param string The table
	 * @param string The old column name
	 * @param string the new column name
	 * @param string the new column definition
	 */
	function rename_column($table, $old_column, $new_column, $new_definition)
	{
		return $this->write_query("ALTER TABLE {$this->table_prefix}{$table} CHANGE {$old_column} {$new_column} {$new_definition}");
	}

	/**
	 * Sets the table prefix used by the simple select, insert, update and delete functions
	 *
	 * @param string The new table prefix
	 */
	function set_table_prefix($prefix)
	{
		$this->table_prefix = $prefix;
	}
	
	/**
	 * Fetched the total size of all mysql tables or a specific table
	 *
	 * @param string The table (optional)
	 * @return integer the total size of all mysql tables or a specific table
	 */
	function fetch_size($table='')
	{
		if($table != '')
		{
			$query = $this->query("SHOW TABLE STATUS LIKE '".$this->table_prefix.$table."'");
		}
		else
		{
			$query = $this->query("SHOW TABLE STATUS");
		}
		$total = 0;
		while($table = $this->fetch_array($query))
		{
			$total += $table['Data_length']+$table['Index_length'];
		}
		return $total;
	}

	/**
	 * Fetch a list of database character sets this DBMS supports
	 *
	 * @return array Array of supported character sets with array key being the name, array value being display name. False if unsupported
	 */
	function fetch_db_charsets()
	{
		if($this->link && version_compare($this->get_version(), "4.1", "<"))
		{
			return false;
		}
		return array(
			'big5' => 'Big5 Traditional Chinese',
			'dec8' => 'DEC West European',
			'cp850' => 'DOS West European',
			'hp8' => 'HP West European',
			'koi8r' => 'KOI8-R Relcom Russian',
			'latin1' => 'cp1252 West European',
			'latin2' => 'ISO 8859-2 Central European',
			'swe7' => '7bit Swedish',
			'ascii' => 'US ASCII',
			'ujis' => 'EUC-JP Japanese',
			'sjis' => 'Shift-JIS Japanese',
			'hebrew' => 'ISO 8859-8 Hebrew',
			'tis620' => 'TIS620 Thai',
			'euckr' => 'EUC-KR Korean',
			'koi8u' => 'KOI8-U Ukrainian',
			'gb2312' => 'GB2312 Simplified Chinese',
			'greek' => 'ISO 8859-7 Greek',
			'cp1250' => 'Windows Central European',
			'gbk' => 'GBK Simplified Chinese',
			'latin5' => 'ISO 8859-9 Turkish',
			'armscii8' => 'ARMSCII-8 Armenian',
			'utf8' => 'UTF-8 Unicode',
			'utf8mb4' => '4-Byte UTF-8 Unicode (requires MySQL 5.5.3 or above)',
			'ucs2' => 'UCS-2 Unicode',
			'cp866' => 'DOS Russian',
			'keybcs2' => 'DOS Kamenicky Czech-Slovak',
			'macce' => 'Mac Central European',
			'macroman' => 'Mac West European',
			'cp852' => 'DOS Central European',
			'latin7' => 'ISO 8859-13 Baltic',
			'cp1251' => 'Windows Cyrillic',
			'cp1256' => 'Windows Arabic',
			'cp1257' => 'Windows Baltic',
			'binary' => 'Binary pseudo charset',
			'geostd8' => 'GEOSTD8 Georgian',
			'cp932' => 'SJIS for Windows Japanese',
			'eucjpms' => 'UJIS for Windows Japanese',
		);
	}

	/**
	 * Fetch a database collation for a particular database character set
	 *
	 * @param string The database character set
	 * @return string The matching database collation, false if unsupported
	 */
	function fetch_charset_collation($charset)
	{
		$collations = array(
			'big5' => 'big5_chinese_ci',
			'dec8' => 'dec8_swedish_ci',
			'cp850' => 'cp850_general_ci',
			'hp8' => 'hp8_english_ci',
			'koi8r' => 'koi8r_general_ci',
			'latin1' => 'latin1_swedish_ci',
			'latin2' => 'latin2_general_ci',
			'swe7' => 'swe7_swedish_ci',
			'ascii' => 'ascii_general_ci',
			'ujis' => 'ujis_japanese_ci',
			'sjis' => 'sjis_japanese_ci',
			'hebrew' => 'hebrew_general_ci',
			'tis620' => 'tis620_thai_ci',
			'euckr' => 'euckr_korean_ci',
			'koi8u' => 'koi8u_general_ci',
			'gb2312' => 'gb2312_chinese_ci',
			'greek' => 'greek_general_ci',
			'cp1250' => 'cp1250_general_ci',
			'gbk' => 'gbk_chinese_ci',
			'latin5' => 'latin5_turkish_ci',
			'armscii8' => 'armscii8_general_ci',
			'utf8' => 'utf8_general_ci',
			'utf8mb4' => 'utf8mb4_general_ci',
			'ucs2' => 'ucs2_general_ci',
			'cp866' => 'cp866_general_ci',
			'keybcs2' => 'keybcs2_general_ci',
			'macce' => 'macce_general_ci',
			'macroman' => 'macroman_general_ci',
			'cp852' => 'cp852_general_ci',
			'latin7' => 'latin7_general_ci',
			'cp1251' => 'cp1251_general_ci',
			'cp1256' => 'cp1256_general_ci',
			'cp1257' => 'cp1257_general_ci',
			'binary' => 'binary',
			'geostd8' => 'geostd8_general_ci',
			'cp932' => 'cp932_japanese_ci',
			'eucjpms' => 'eucjpms_japanese_ci',
		);
		if($collations[$charset])
		{
			return $collations[$charset];
		}
		return false;
	}

	/**
	 * Fetch a character set/collation string for use with CREATE TABLE statements. Uses current DB encoding
	 *
	 * @return string The built string, empty if unsupported
	 */
	function build_create_table_collation()
	{
		if(!$this->db_encoding)
		{
			return '';
		}

		$collation = $this->fetch_charset_collation($this->db_encoding);
		if(!$collation)
		{
			return '';
		}
		return " CHARACTER SET {$this->db_encoding} COLLATE {$collation}";
	}

	/**
	 * Time how long it takes for a particular piece of code to run. Place calls above & below the block of code.
	 *
	 * @return float The time taken
	 */
	function get_execution_time()
	{
		static $time_start;

		$time = microtime(true);


		// Just starting timer, init and return
		if(!$time_start)
		{
			$time_start = $time;
			return;
		}
		// Timer has run, return execution time
		else
		{
			$total = $time-$time_start;
			if($total < 0) $total = 0;
			$time_start = 0;
			return $total;
		}
	}
}

?>
