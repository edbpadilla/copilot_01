<?PHP

/*
if (preg_match("/mysql.class.php/", $_SERVER['SCRIPT_NAME'])) {
    Header("Location: index.php"); die();
}
*/
if (strpos($_SERVER['SCRIPT_NAME'], "mysql.class.php") > 0) {

	Header("Location: index.php");
	die();
}

class sql_db
{

	var $db_connect_id;
	var $query_result;
	var $row = array();
	var $rowset = array();
	var $num_queries = 0;

	public $persistency;
	public $user;
	public $password;
	public $server;
	public $dbname;
	//
	// Constructor
	//
	public function __construct($sqlserver, $sqluser, $sqlpassword, $database, $persistency = true)
	{

		$this->persistency = $persistency;
		$this->user = $sqluser;
		$this->password = $sqlpassword;
		$this->server = $sqlserver;
		$this->dbname = $database;
		/*
		echo  $this->server ."<br>";
		echo  $this->dbname ."<br>";
		echo  $this->user ."<br>";
		echo  $this->password ."<br>";
		exit;
		*/
		if ($this->persistency) {
			$this->db_connect_id = mysqli_connect('p:' . $this->server, $this->user, $this->password, $this->dbname);
		} else {
			$this->db_connect_id = mysqli_connect($this->server, $this->user, $this->password, $this->dbname);
		}
		if ($this->db_connect_id) {
			return $this->db_connect_id;
		} else {

			echo "Error: Unable to connect to MySQL.<br>" . PHP_EOL;
			//echo $link . PHP_EOL;
			echo "Debugging errno: " . mysqli_connect_errno() . PHP_EOL;
			echo "Debugging error: " . mysqli_connect_error() . PHP_EOL;
			return false;
		}
	}

	//
	// Other base methods
	//
	function sql_close()
	{
		if ($this->db_connect_id) {
			if ($this->query_result) {
				mysqli_free_result($this->query_result);
			}
			$result = mysqli_close($this->db_connect_id);
			return $result;
		} else {
			return false;
		}
	}


	function sql_query($query = "", $params = false)
	{
		unset($this->query_result);

		if ($query === "") {
			return false;
		}

		// Prepared statement path
		if (is_array($params)) {

			if (count($params) < 2) {
				trigger_error('sql_query: Invalid params format', E_USER_WARNING);
				return false;
			}

			$types  = array_shift($params);
			$stmt   = mysqli_prepare($this->db_connect_id, $query);

			if (!$stmt) {
				trigger_error(mysqli_error($this->db_connect_id), E_USER_WARNING);
				return false;
			}

			mysqli_stmt_bind_param($stmt, $types, ...$params);
			mysqli_stmt_execute($stmt);

			// SELECT queries
			if (stripos(trim($query), 'SELECT') === 0) {

				// mysqlnd available
				if (function_exists('mysqli_stmt_get_result')) {
					$result = mysqli_stmt_get_result($stmt);
					$this->query_result = $result;
					return $result;
				}

				// mysqlnd NOT available → manual fetch
				$result = [];
				$res = mysqli_stmt_result_metadata($stmt);
				if ($res) {
					$fields = [];
					$row = [];
					while ($field = mysqli_fetch_field($res)) {
						$fields[] = &$row[$field->name];
					}
					mysqli_stmt_bind_result($stmt, ...$fields);

					while (mysqli_stmt_fetch($stmt)) {
						$result[] = array_map(fn($v) => $v, $row);
					}
				}

				mysqli_stmt_close($stmt);
				$this->query_result = $result;
				return $result;
			}

			// INSERT / UPDATE / DELETE
			$affected = mysqli_stmt_affected_rows($stmt);
			mysqli_stmt_close($stmt);

			return $affected >= 0;
		}

		// Legacy fallback (unsafe)
		$this->query_result = mysqli_query($this->db_connect_id, $query);
		return $this->query_result ?: false;
	}




	//
	// Base query method OLD
	//
	function sql_query_OLD($query = "", $transaction = FALSE)
	{
		// Remove any pre-existing queries
		unset($this->query_result);
		if ($query != "") {

			$this->query_result = mysqli_query($this->db_connect_id, $query);
		}
		if ($this->query_result) {
			//unset($this->row[$this->query_result]);
			//unset($this->rowset[$this->query_result]);
			return $this->query_result;
		} else {
			//return ( $transaction == 'END_TRANSACTION' ) ? true : false;
			return  $transaction;
		}
	}

	//
	// Other query methods
	//
	function sql_numrows($query_id)
	{
		if (is_null($query_id)) {
			$query_id = $this->query_result;
		}
		if ((!is_null($query_id)) && (!is_bool($query_id))) {
			$result = mysqli_num_rows($query_id);
			return $result;
		}
		return false;
	}

	function sql_affectedrows()
	{
		if ($this->db_connect_id) {
			$result = mysqli_affected_rows($this->db_connect_id);
			return $result;
		} else {
			return false;
		}
	}
	function sql_numfields($query_id = 0)
	{
		if (!$query_id) {
			$query_id = $this->query_result;
		}
		if ($query_id) {
			$result = mysqli_num_fields($query_id);
			return $result;
		} else {
			return false;
		}
	}
	function sql_fieldname($offset, $query_id = 0)
	{
		if (!$query_id) {
			$query_id = $this->query_result;
		}
		if ($query_id) {
			//$result = mysqli_field_name($query_id, $offset);
			$field = mysqli_fetch_field_direct($query_id, $offset);
			return $field ? $field->name : false;
		} else {
			return false;
		}
	}
	function sql_fieldtype($offset, $query_id = 0)
	{
		if (!$query_id) {
			$query_id = $this->query_result;
		}
		if ($query_id) {
			$field = mysqli_fetch_field_direct($query_id, $offset);
			return $field ? $field->type : false;
		} else {
			return false;
		}
	}



	function sql_fetchrow($query_id = 0)
	{
		if (!$query_id) {
			$query_id = $this->query_result;
		}
		if ($query_id) {
			//$this->row[$query_id] = mysqli_fetch_array($query_id);
			//return $this->row[$query_id];
			$row = mysqli_fetch_assoc($query_id);
			//print_r($row);
			//exit;
			return $row;
		} else {
			return false;
		}
	}
	function sql_fetchrowset($query_id = null)
	{
		if (is_null($query_id)) {
			$query_id = $this->query_result;
		}

		if ((!is_null($query_id)) && (!is_bool($query_id))) {
			//unset($this->rowset[$query_id]);
			//unset($this->row[$query_id]);
			while ($this->rowset = mysqli_fetch_array($query_id)) {
				$result[] = $this->rowset;
			}
			if (isset($result)) {
				return $result;
			} else {
				return false;
			}
		} else {
			return false;
		}
	}

	function sql_fetchallrows($query_id = null)
	{
		if (is_null($query_id)) {
			$query_id = $this->query_result;
		}

		if ((!is_null($query_id)) && (!is_bool($query_id))) {
			//unset($this->rowset[$query_id]);
			//unset($this->row[$query_id]);
			while ($this->rowset = mysqli_fetch_array($query_id, MYSQLI_ASSOC)) {
				$result[] = $this->rowset;
			}
			if (isset($result)) {
				return $result;
			} else {
				return false;
			}
		} else {
			return false;
		}
	}

	function sql_fetchfield($field, $rownum = -1, $query_id = 0)
	{
		if (!$query_id) {
			$query_id = $this->query_result;
		}

		if (!$query_id) {
			return false;
		}

		// Direct row access (mysql_result replacement)
		if ($rownum > -1) {
			if (!mysqli_data_seek($query_id, $rownum)) {
				return false;
			}

			$row = mysqli_fetch_assoc($query_id);
			return $row[$field] ?? false;
		}

		// Cached row handling (legacy behavior)
		if (empty($this->row[$query_id]) && empty($this->rowset[$query_id])) {
			if ($this->sql_fetchrow($query_id)) {
				return $this->row[$query_id][$field] ?? false;
			}
			return false;
		}

		if (!empty($this->rowset[$query_id])) {
			return $this->rowset[$query_id][$field] ?? false;
		}

		if (!empty($this->row[$query_id])) {
			return $this->row[$query_id][$field] ?? false;
		}

		return false;
	}


	function sql_fetchfield_old($field, $rownum = -1, $query_id = 0)
	{
		if (!$query_id) {
			$query_id = $this->query_result;
		}
		if ($query_id) {
			if ($rownum > -1) {
				// not supported in mysqli, use data_seek + fetch_assoc
				//$result = mysqli_result($query_id, $rownum, $field);
			} else {
				if (empty($this->row[$query_id]) && empty($this->rowset[$query_id])) {
					if ($this->sql_fetchrow()) {
						$result = $this->row[$query_id][$field];
					}
				} else {
					if ($this->rowset[$query_id]) {
						$result = $this->rowset[$query_id][$field];
					} else if ($this->row[$query_id]) {
						$result = $this->row[$query_id][$field];
					}
				}
			}
			return $result;
		} else {
			return false;
		}
	}
	function sql_rowseek($rownum, $query_id = 0)
	{
		if (!$query_id) {
			$query_id = $this->query_result;
		}
		if ($query_id) {
			$result = mysqli_data_seek($query_id, $rownum);
			return $result;
		} else {
			return false;
		}
	}
	function sql_nextid()
	{
		if ($this->db_connect_id) {
			$result = mysqli_insert_id($this->db_connect_id);
			return $result;
		} else {
			return false;
		}
	}
	function sql_freeresult($query_id = 0)
	{
		if (!$query_id) {
			$query_id = $this->query_result;
		}

		if ($query_id) {
			unset($this->row[$query_id]);
			unset($this->rowset[$query_id]);

			mysqli_free_result($query_id);

			return true;
		} else {
			return false;
		}
	}
	function sql_error($query_id = 0)
	{
		$result["message"] = mysqli_error($this->db_connect_id);
		$result["code"] = mysqli_errno($this->db_connect_id);

		return $result;
	}
	/**
	 * Check if a value is a valid date or datetime
	 *
	 * @param string $value The date string to check
	 * @return bool True if valid date, False otherwise
	 */
	public function isDate($value)
	{
		// Quick reject for empty or non-string values
		if (empty($value) || !is_string($value)) {
			return false;
		}

		// Reject MySQL zero dates
		$zeroDates = ['0000-00-00 00:00:00', '0000-00-00'];
		if (in_array($value, $zeroDates, true)) {
			return false;
		}

		// Formats to check
		$formats = ['Y-m-d H:i:s', 'Y-m-d'];

		foreach ($formats as $format) {
			$d = DateTime::createFromFormat($format, $value);
			// Check if the parsing succeeded and matches exactly
			if ($d && $d->format($format) === $value) {
				return true;
			}
		}

		return false;
	}

	public function beginTransaction()
	{
		mysqli_autocommit($this->db_connect_id, false);
	}

	public function commit()
	{
		return mysqli_commit($this->db_connect_id);
	}

	public function rollback()
	{
		return mysqli_rollback($this->db_connect_id);
	}

	public function autoCommit($mode = true)
	{
		mysqli_autocommit($this->db_connect_id, $mode);
	}

	/**
	 * Duplicate a vehicle record and all related child records by VIN.
	 * Excludes 'id' in all tables (assumes auto-increment).
	 * Child tables: tvininspection, tfindings, tvinvrc, tuploaddetail
	 *
	 * @param string $originalVin The original VIN to duplicate
	 * @return array|false Returns summary or false on failure
	 */
	public function duplicateVehicleRecord($originalVin)
	{
		// Start transaction
		mysqli_autocommit($this->db_connect_id, false);

		try {
			// STEP 1: Count existing records with this VIN prefix (for -X suffix)
			$stmt = mysqli_prepare($this->db_connect_id, "SELECT COUNT(*) FROM tvehicleinventory WHERE vin LIKE CONCAT(?, '%')");
			mysqli_stmt_bind_param($stmt, "s", $originalVin);
			mysqli_stmt_execute($stmt);
			mysqli_stmt_bind_result($stmt, $count);
			mysqli_stmt_fetch($stmt);
			mysqli_stmt_close($stmt);

			//$suffix = $count + 1;
			$suffix = $count; // just the count 
			$newVin = $originalVin . '-' . $suffix;

			// STEP 2: Fetch and duplicate tvehicleinventory (parent)
			$stmt = mysqli_prepare($this->db_connect_id, "SELECT * FROM tvehicleinventory WHERE vin = ?");
			mysqli_stmt_bind_param($stmt, "s", $originalVin);
			mysqli_stmt_execute($stmt);
			$result = mysqli_stmt_get_result($stmt);
			$originalRow = mysqli_fetch_assoc($result);
			mysqli_stmt_close($stmt);

			if (!$originalRow) {
				throw new Exception("Original VIN not found: $originalVin");
			}

			// Exclude parent's auto-increment id
			unset($originalRow['id']);
			$originalCsNumber = $originalRow['csnumber'] ?? '';

			$newCsNumber = $originalCsNumber . '-' . $suffix;

			$originalRow['csnumber'] = $originalCsNumber . '-' . $suffix;
			$originalRow['vin'] = $newVin;
			$originalRow['doctype'] = 'TRAN-COPY';

			// Insert new parent
			$this->insertRow('tvehicleinventory', $originalRow);
			$newParentId = mysqli_insert_id($this->db_connect_id); // if needed later

			// STEP 3: Helper function to duplicate child table records (by vin, exclude id)
			$duplicateChildByVin = function ($table) use ($originalVin, $newVin, $newCsNumber) {
				$stmt = mysqli_prepare($this->db_connect_id, "SELECT * FROM `$table` WHERE vin = ?");
				mysqli_stmt_bind_param($stmt, "s", $originalVin);
				mysqli_stmt_execute($stmt);
				$result = mysqli_stmt_get_result($stmt);

				$copied = 0;
				while ($row = mysqli_fetch_assoc($result)) {
					unset($row['id']); // ← Exclude auto-increment ID in child table
					$row['vin'] = $newVin; //  Update to new VIN
					// Check if 'csnumber' column exists in this table/row and update it
					if (isset($row['csnumber'])) {
						$row['csnumber'] = $newCsNumber;
					}


					$this->insertRow($table, $row);
					$copied++;
				}
				mysqli_stmt_close($stmt);
				return $copied;
			};

			// STEP 4: Duplicate all child tables
			$tvininspectionCopied = $duplicateChildByVin('tvininspection');
			$tfindingsCopied = $duplicateChildByVin('tfindings');       // ← has id, excluded
			$tvinvrcCopied = $duplicateChildByVin('tvinvrc');
			$tuploaddetailCopied = $duplicateChildByVin('tuploaddetail'); // ← has id, excluded


			// STEP 5: Duplicate associated files (if any)
			$directories = [
				'vinpdi',
				'vinpdi_out',
				'vinqi'
			];

			$filesCopied = [];
			$filesMoved = [];
			foreach ($directories as $dir) {
				if (!is_dir($dir) || !is_readable($dir)) {
					continue;
				}

				$pattern = $originalVin . '.*';
				$files = glob($dir . '/' . $pattern);
				if ($files === false) {
					continue; // glob error
				}
				foreach ($files as $file) {
					$extension = pathinfo($file, PATHINFO_EXTENSION);
					$newFilename = $newVin . '.' . $extension;
					$newPath = $dir . '/' . $newFilename;
					if (file_exists($newPath)) {
						trigger_error("Destination already exists, skipping rename: $newPath", E_USER_NOTICE);
						continue;
					}
					if (rename($file, $newPath)) {
						$filesMoved[] = $newPath; // or keep name "filesCopied" — your choice
					} else {
						trigger_error("Failed to rename file: $file  $newPath", E_USER_WARNING);
					}
					/*
                    if (copy($file, $newPath)) { 
                        $filesCopied[] = $newPath;
                    } else { 
                        trigger_error("Failed to copy file: $file  $newPath", E_USER_WARNING);
                    }
                    */
				}
			}
			// Commit all changes
			mysqli_commit($this->db_connect_id);
			mysqli_autocommit($this->db_connect_id, true);

			return [
				'new_vin' => $newVin,
				'new_csnumber' => $originalRow['csnumber'],
				'new_id' => $newParentId,
				'copied' => [
					'tvininspection' => $tvininspectionCopied,
					'tfindings' => $tfindingsCopied,
					'tvinvrc' => $tvinvrcCopied,
					'tuploaddetail' => $tuploaddetailCopied
				],
				'files_moved' => $filesMoved
			];
		} catch (Exception $e) {
			mysqli_rollback($this->db_connect_id);
			mysqli_autocommit($this->db_connect_id, true);
			trigger_error("Duplication failed: " . $e->getMessage(), E_USER_WARNING);
			return false;
		}
	}

	/**
	 * Helper: Insert associative array as row into table (excludes 'id' if exists)
	 * Automatically builds INSERT with placeholders and binds types.
	 *
	 * @param string $table Table name
	 * @param array $data Associative array [column => value]
	 * @return bool
	 */
	private function insertRow($table, $data)
	{
		if (isset($data['id'])) {
			unset($data['id']); // safety: ensure id is never inserted
		}

		$columns = array_keys($data);
		$placeholders = array_fill(0, count($columns), '?');
		$values = array_values($data);

		$types = '';
		foreach ($values as $value) {
			$types .= is_int($value) ? 'i' : (is_float($value) ? 'd' : 's');
		}

		$sql = "INSERT INTO `$table` (`" . implode('`, `', $columns) . "`) VALUES (" . implode(', ', $placeholders) . ")";
		$stmt = mysqli_prepare($this->db_connect_id, $sql);

		if (!$stmt) {
			throw new Exception("Prepare failed for table `$table`: " . mysqli_error($this->db_connect_id));
		}

		$refs = [];
		foreach ($values as $key => &$value) {
			$refs[$key] = &$value;
		}
		array_unshift($refs, $types);

		$success = call_user_func_array([$stmt, 'bind_param'], $refs);
		if (!$success || !mysqli_stmt_execute($stmt)) {
			throw new Exception("Insert failed for table `$table`: " . mysqli_stmt_error($stmt));
		}

		mysqli_stmt_close($stmt);
		return true;
	}
} // class sql_db
