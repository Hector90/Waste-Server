<?php

#
# All public functions defined within handler class are exposed to the API.
#

require_once('Commons.php');

class WasteHandler {

	#
	# Constructor.
	#
	
	function __construct($learn) {
		$this->learn = $learn ? $learn : 0;
	}	
		
	#
	# API function to authenticate incoming call.
	#
	# For open access, function can be written as:
	# public function authenticate($response, $api) {
	# 	return true;
	# }
	
	public function authenticate($response, $api) {
		if (!Commons::getParam('serial', $api, 2)) {
			$response = array("error" => "Serial missing.");
			return false;
		}
		$auth = false;
		$query = vsprintf("SELECT * FROM Clients WHERE serial_number = '%s';", array(mysql_real_escape_string(Commons::getParam('serial', $api, 2))));
		$result = mysql_query($query);
		if (mysql_num_rows($result) == 0) {
			# TODO: Write new client.
			mysql_query(vsprintf("INSERT INTO Clients (serial_number, email, location) VALUES ('%s', 'n/a', 'n/a');", array(mysql_real_escape_string(Commons::getParam('serial', $api, 2)))));
			$result = mysql_query($query);
		}
		while ($row = mysql_fetch_assoc($result)) {
			$this->client = $row['id'];
			$this->privilege = $row['privilege'];
			$auth = true;
		}
		mysql_free_result($result);
		return $auth;
	}
	
	#
	# API functions.
	#

	public function identify($state, $api) {
		if ($api->method != 'GET') {
			$state = 405;
			return array("error", "invocation of get method requires HTTP GET");
		} else {
			$arr = NULL;
			$result = mysql_query(vsprintf("SELECT Product.id, Product.name, Product.potential, Category.type, Category.id as cat FROM Product LEFT JOIN Category ON Category.id = Product.category WHERE bar_code = '%s';",
				array(mysql_real_escape_string(Commons::getParam('bar_code', $api, 3)))));
			if (mysql_num_rows($result) == 0) {
				# Insert new product
				$arr = array('category' => 'Unknown');
				mysql_query(vsprintf("INSERT INTO Product (bar_code, category, potential) VALUES ('%s', (SELECT id FROM Category WHERE type = 'Unknown'), 1)",
					array(mysql_real_escape_string(Commons::getParam('bar_code', $api, 3)))));
			} else {
				while ($row = mysql_fetch_assoc($result)) {
					# Check if potential
					if ($row['potential'] == "1") {
						if (Commons::getParam('category', $api, 4) == NULL) {
							$state = 405;
							$arr = array('error' => 'category required');
						} else {
							# Check if category exists.
							$chkCat = mysql_query(vsprintf("SELECT id FROM Category WHERE type = '%s'", array(mysql_real_escape_string(Commons::getParam('category', $api, 4)))));
							if (mysql_num_rows($chkCat) == 0) {
								$arr = array("error" => "Invalid category specified.");
								$state = 405;
							} else {						
								mysql_query(vsprintf("INSERT INTO Waste (client, product, category) VALUES ('%s', '%s', (SELECT id FROM Category WHERE type = '%s'));",
									array(mysql_real_escape_string($this->client), $row['id'], mysql_real_escape_string(Commons::getParam('category', $api, 4)))));
								$chk = mysql_query(vsprintf("SELECT product, Category.id as cat, Category.type, COUNT(*) as C FROM Waste LEFT JOIN Category ON Category.id = Waste.category WHERE product = '%s' GROUP BY Category.id ORDER BY COUNT(*) desc LIMIT 1", array($row['id'])));
								$chkRow = mysql_fetch_assoc($chk);
								if ($chkRow['C'] >= $this->learn) {
									mysql_query(vsprintf("UPDATE Product set learned = 1, potential = 0, category = '%s' WHERE id = '%s'", array($chkRow['cat'], $chkRow['product'])));
									$arr = array('category' => $chkRow['type']);
								} else {
									$arr = array('category' => 'Unknown');
								}
								mysql_free_result($chk);
							}
							mysql_free_result($chkCat);
						}
					} else {
						mysql_query(vsprintf("INSERT INTO Waste (client, product, category) VALUES ('%s', '%s', '%s');",
							array(mysql_real_escape_string($this->client), $row['id'], $row['cat'])));
						$arr = array('category' => $row['type']);
					}
				}
			}
			mysql_free_result($result);

			return $arr;
		}
	}

	public function synch($state, $api) {
		
		
	}
	
	public function get($state, $api) {
		if ($api->method != 'GET') {
			$state = 405;
			return array("error", "invocation of get method requires HTTP GET");
		} else {
			$result = mysql_query(vsprintf("SELECT * FROM %s;", array(mysql_real_escape_string(Commons::getParam('table', $api, 3)))));
			$rows = array();
			while($row = mysql_fetch_assoc($result)) {
				$rows[] = $row;
			}
			return $rows;
		}
	}
}
?>