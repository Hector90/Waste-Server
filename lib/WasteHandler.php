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
	# Private functions, these are not exposed to API.
	#
	
	private function learn($productId) {
		$prod = $this->db->query("SELECT product, Category.id as cat, Category.type, COUNT(*) as C FROM Waste LEFT JOIN Category ON Category.id = Waste.category WHERE product = '%s' GROUP BY Category.id ORDER BY COUNT(*) desc LIMIT 1", array($productId));
		if ($prod[0]['C'] >= $this->learn) {
			$this->db->exec("UPDATE Product set learned = 1, potential = 0, category = '%s' WHERE id = '%s'", array($prod[0]['cat'], $prod[0]['product']));
			return $prod[0]['type'];
		} else {
			return 'Unknown';
		}
	}
	
	#
	# API function to authenticate incoming call.
	#
	# For open access, function can be written as:
	# public function authenticate($response, $api) {
	# 	return true;
	# }
	
	public function authenticate(&$response, &$api) {
		if (!Commons::getParam('serial', $api, 2) && !Commons::getParam('email') && !Commons::getParam('pin') && !Commons::getParam('sid')) {
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
			if (count($data) == 0) {
				$response = array("error" => "Invalid pin or email.");
				return false;
			}
			$this->session = "PROACTIVE";
			session_start();
			$api->sid = session_id();
			$_SESSION['user.name'] = $data[0]['email'];
			$_SESSION['user.id'] = $data[0]['id'];
			$_SESSION['valid'] = true;
		}
		if (Commons::getParam('sid')) {
			session_id(Commons::getParam('sid'));
			session_start();
			if (!$_SESSION['valid']) {
				$response = array("error" => "Invalid session.");
				session_destroy();
				return false;
			} else {
				$api->sid = session_id();
			}
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

	public function identify(&$state, $api) {
		if ($api->method != 'GET') {
			$state = 405;
			return array("error" => "invocation of get method requires HTTP GET");
		} else {
			$arr = NULL;
			$prod = $this->db->query("SELECT Product.id, Product.name, Product.potential, Product.learned, Category.type, Category.id as cat FROM Product LEFT JOIN Category ON Category.id = Product.category WHERE bar_code = '%s'", array(Commons::getParam('bar_code', $api, 3)));
			if (count($prod) == 0) {
				# Insert new product
				$arr = array('category' => 'Unknown', 'learned' => 0);
				$this->db->exec("INSERT INTO Product (bar_code, category, potential) VALUES ('%s', (SELECT id FROM Category WHERE type = 'Unknown'), 1)", array(Commons::getParam('bar_code', $api, 3)));
			} else {
				foreach ($prod as &$row) {
					# Check if potential
					if ($row['potential'] == "1") {
						if (Commons::getParam('category', $api, 4) == NULL) {
							$state = 200;
							$arr = array('category' => 'Unknown', 'learned' => 0);
						} else {
							# Check if category exists.
							$cat = $this->db->query("SELECT id FROM Category WHERE type = '%s'", array(Commons::getParam('category', $api, 4)));
							if (count($cat) == 0) {
								$arr = array("error" => "Invalid category specified.");
								$state = 405;
							} else {						
								$this->db->exec("INSERT INTO Waste (client, product, category) VALUES ('%s', '%s', (SELECT id FROM Category WHERE type = '%s'));", array($this->client, $row['id'], Commons::getParam('category', $api, 4)));
								$arr = array('category' => $this->learn($row['id']), 'learned' => $row['learned']);
							}
						}
					} else {
						$this->db->exec("INSERT INTO Waste (client, product, category) VALUES ('%s', '%s', '%s');", array($this->client, $row['id'], $row['cat']));
						$arr = array('category' => $row['type'], 'learned' => $row['learned']);
					}
				}
			}
			return $arr;
		}
	}

	public function synch(&$state, $api) {
		if ($api->method != 'POST' && $api->method != 'PUT') {
			$state = 405;
			return array("error" => "invocation of synch method requires HTTP POST or PUT");
		} else {
			$entity = file_get_contents('php://input');
			$decoded = json_decode($entity, true);
			foreach ($decoded as &$row) {
				$cat = $this->db->query("SELECT id FROM Category WHERE type = '%s'", array($row['category']));
				if (count($cat) == 0) {
					$row['state'] = 405;
					$row['error'] = "Unknown category";
				} else {
					$prod = $this->db->query("SELECT Product.id, Product.name, Product.potential, Category.type, Category.id as cat FROM Product LEFT JOIN Category ON Category.id = Product.category WHERE bar_code = '%s'", array($row['bar_code']));
					if (count($prod) == 0) {
						$this->db->exec("INSERT INTO Product (bar_code, category, potential) VALUES ('%s', (SELECT id FROM Category WHERE type = 'Unknown'), 1)", array($row['bar_code']));
						array_push($prod, array("id" => $this->db->lastId()));
					}
					if ($this->db->exec("INSERT INTO Waste (client, product, category, time_disposed) VALUES ('%s', '%s', '%s', '%s');", array($this->client, $prod[0]['id'], $cat[0]['id'], $row['timestamp']))) {
						$this->learn($prod[0]['id']);
						$row['state'] = 200;
					} else {
						$row['state'] = 500;
						$row['error'] = mysql_error();
					}
				}
			}
			return $decoded;
		}
	}
	
	public function get(&$state, $api) {
		if ($api->method != 'GET') {
			$state = 405;
			return array("error" => "invocation of get method requires HTTP GET");
		} else {
			return $this->db->query("SELECT * FROM %s;", array(Commons::getParam('table', $api, 3)));
		}
	}
	
	public function logout(&$state, $api) {
		if ($api->method != 'GET') {
			$state = 405;
			return array("error" => "invocation of logout method requires HTTP GET");
		} else {
			$response = array();
			if ($_SESSION['valid']) {
				if (session_destroy()) $response = array("result" => "Logged out.");
				else $response = array("error" => "Logout failed.");
			} else {
				 $response = array("error" => "Invalid session.");
			}
			return $response;
		}
	}
	
	public function login(&$state, $api) {
		if ($_SESSION['valid']) {
			return array("reason" => "OK");
		} else {
			return array("reason" => "Invalid login.");
		}
	}
	
}
?>