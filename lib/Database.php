<?php

class Database {
	
	function __construct($host, $user, $pass, $dbname) {
		$this->con = mysql_connect($host, $user, $pass, true) or die("Cannot connect to database");
		mysql_select_db($dbname, $this->con) or die("Cannot use database ".$dbname);
	}	
	
	function __destruct() {
		mysql_close($this->con);
	}
	
	private function prepareClause($clause, $params) {
		if ($params == NULL) return $clause;
		$escaped = array();
		foreach ($params as &$val) {
			array_push($escaped, mysql_real_escape_string($val));
		}
		return vsprintf($clause, $escaped);
	}
	
	public function query($clause, $params) {
		$ref = mysql_query($this->prepareClause($clause, $params), $this->con) or die(mysql_error());
		$resp = array();
		while ($row = mysql_fetch_assoc($ref)) {
			array_push($resp, $row);
		}
		if (!mysql_free_result($ref)) return false;
		return $resp;
	}

	public function exec($clause, $params) {
		return (mysql_query($this->prepareClause($clause, $params), $this->con) ? true : false);
	}
	
	public function lastId() {
		$id = mysql_insert_id();
		return ($id ? $id : false);
	}

}