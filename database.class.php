<?php

	/*
	Revised code by Dominick Lee
	Original code derived from "Run your own PDO PHP class" by Philip Brown
	Last Modified 2/27/2017
	*/

	// Define database configuration
	define("DB_HOST", "localhost");
	define("DB_USER", "mikisoft_admin");
	define("DB_PASS", "***REMOVED***");
	define("DB_NAME", "mikisoft_icebreaker");

	class Database{
		private $host      = DB_HOST;
		private $user      = DB_USER;
		private $pass      = DB_PASS;
		private $dbname    = DB_NAME;
		private $dbh;
		private $error;
		private $stmt;
	    
		public function __construct(){
			// Set DSN
			$dsn = 'mysql:host=' . $this->host . ';dbname=' . $this->dbname;
			// Set options
			$options = array(
				PDO::ATTR_PERSISTENT    => true,
				PDO::ATTR_ERRMODE       => PDO::ERRMODE_EXCEPTION,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8"
			);
			// Create a new PDO instanace
			try{
				$this->dbh = new PDO($dsn, $this->user, $this->pass, $options);
			}
			// Catch any errors
			catch(PDOException $e){
				$this->error = $e->getMessage();
			}
		}
		
		public function query($query){
		    // file_put_contents('db_debug.txt', $query.PHP_EOL, FILE_APPEND);
			$this->stmt = $this->dbh->prepare($query);
		}
		public function bind($param, $value = null, $type = null){
		    // file_put_contents('db_debug.txt', print_r($param, true).PHP_EOL, FILE_APPEND);
		    if ($value != null) {
		        $param = [$param => [$value, $type]];
		    }
		    foreach ($param as $p => $val) {
		        $type = null;
		        $value = is_array($val) ? $val[0] : $val;
    			if (!is_array($val) || count($val) == 1 || is_null($val[1])) {
    				switch (true) {
    					case is_int($value):
    						$type = PDO::PARAM_INT;
    						break;
    					case is_bool($value):
    						$type = PDO::PARAM_BOOL;
    						break;
    					case is_null($value):
    						$type = PDO::PARAM_NULL;
    						break;
    					default:
    						$type = PDO::PARAM_STR;
    				}
    			}
    			$this->stmt->bindValue($p, $value, is_null($type) ? $val[1] : $type);
		    }
		}
		public function execute(){
			return $this->stmt->execute();
		}
		
		public function resultset(){
			$this->execute();
			return $this->stmt->fetchAll(PDO::FETCH_ASSOC);
		}
		
		public function single(){
			$this->execute();
			return $this->stmt->fetch(PDO::FETCH_ASSOC);
		}
		
		public function rowCount(){
			return $this->stmt->rowCount();
		}
		
		public function lastInsertId(){
			return $this->dbh->lastInsertId();
		}
		
		public function beginTransaction(){
			return $this->dbh->beginTransaction();
		}
		
		public function endTransaction(){
			return $this->dbh->commit();
		}
		
		public function cancelTransaction(){
			return $this->dbh->rollBack();
		}
		
		public function debugDumpParams(){
			return $this->stmt->debugDumpParams();
		}
		
		public function close(){
		  $this->dbh = null;
		}
	}
?>