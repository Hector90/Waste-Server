<?php

#
# All public functions defined within handler class are exposed to the API.
#

require_once('Commons.php');
require_once('Database.php');

class WasteHandler {

	#
	# Constructor.
	#
	
	function __construct($learn, $database) {
		$this->learn = $learn ? $learn : 0;
		$this->db = $database;
	}	
		
	#
	# API function to authenticate incoming call.
	#
	# For open access, function can be written as:
	# public function authenticate($response, $api) {
	# 	return true;
	# }
	
	public function authenticate($response, $api) {
		if (!Commons::getParam('serial', $api, 2) && !Commons::getParam('email') && !Commons::getParam('pin')) {
			$response = array("error" => "Authentication data missing.");
			return false;
		} 
		$data = array();
		if (Commons::getParam('serial', $api, 2) && !Commons::getParam('email') && !Commons::getParam('pin')) {
			$data = $this->db->query("SELECT * FROM Clients WHERE serial_number = '%s';", array(Commons::getParam('serial', $api, 2)));
			$this->session = "READ_ONLY";
		}
		if (Commons::getParam('email') && Commons::getParam('pin')) {
			$data = $this->db->query("SELECT * FROM Clients WHERE email = '%s' AND pin = SHA1('%s');", array(Commons::getParam('email'), Commons::getParam('pin')));
			if (count($data) == 0) return false;
			$this->session = "PROACTIVE";
		}
		if (count($data) == 0) {
			$this->db->exec("INSERT INTO Clients (serial_number, email, location) VALUES ('%s', 'n/a', 'n/a');", array(Commons::getParam('serial', $api, 2)));
			$this->db->exec("INSERT INTO ClientRel (parent, child, type) VALUES ((SELECT id FROM Clients WHERE email = 'admin@waste'), '%s', 'ALL');", array($this->db->lastId()));
			$data = $this->db->query("SELECT * FROM Clients WHERE serial_number = '%s';", array(Commons::getParam('serial', $api, 2)));
		}
		$this->client = $data[0]['id'];
		return true;
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
			$result = $this->db->query("SELECT Product.id, Product.name, Product.potential, Category.type, Category.id as cat FROM Product LEFT JOIN Category ON Category.id = Product.category WHERE bar_code = '%s'", array(Commons::getParam('bar_code', $api, 3)));
			if (count($result) == 0) {
				# Insert new product
				$arr = array('category' => 'Unknown');
				$this->db->exec("INSERT INTO Product (bar_code, category, potential) VALUES ('%s', (SELECT id FROM Category WHERE type = 'Unknown'), 1)", array(Commons::getParam('bar_code', $api, 3)));
			} else {
				foreach ($result as &$row) {
					# Check if potential
					if ($row['potential'] == "1") {
						if (Commons::getParam('category', $api, 4) == NULL) {
							$state = 405;
							$arr = array('error' => 'category required');
						} else {
							# Check if category exists.
							$chkCat = $this->db->query("SELECT id FROM Category WHERE type = '%s'", array(Commons::getParam('category', $api, 4)));
							if (count($chkCat) == 0) {
								$arr = array("error" => "Invalid category specified.");
								$state = 405;
							} else {						
								$this->db->exec("INSERT INTO Waste (client, product, category) VALUES ('%s', '%s', (SELECT id FROM Category WHERE type = '%s'));", array($this->client, $row['id'], Commons::getParam('category', $api, 4)));
								$chkRow = $this->db->query("SELECT product, Category.id as cat, Category.type, COUNT(*) as C FROM Waste LEFT JOIN Category ON Category.id = Waste.category WHERE product = '%s' GROUP BY Category.id ORDER BY COUNT(*) desc LIMIT 1", array($row['id']));
								if ($chkRow[0]['C'] >= $this->learn) {
									$this->db->exec("UPDATE Product set learned = 1, potential = 0, category = '%s' WHERE id = '%s'", array($chkRow[0]['cat'], $chkRow[0]['product']));
									$arr = array('category' => $chkRow[0]['type']);
								} else {
									$arr = array('category' => 'Unknown');
								}
							}
						}
					} else {
						$this->db->exec("INSERT INTO Waste (client, product, category) VALUES ('%s', '%s', '%s');", array($this->client, $row['id'], $row['cat']));
						$arr = array('category' => $row['type']);
					}
				}
			}
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
			return $this->db->query("SELECT * FROM %s;", array(Commons::getParam('table', $api, 3)));
		}
	}
}
?>