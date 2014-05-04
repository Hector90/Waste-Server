<?php

#
# All public functions defined within handler class are exposed to the API.
#

require_once('Commons.php');
require_once('Database.php');
require_once('PHPExcel/Classes/PHPExcel/IOFactory.php');

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
		if (isset($_GET['sid'])) {
			session_id($_GET['sid']);
			session_start();
			if (!$_SESSION['valid']) {
				$response = array("error" => "Invalid session.");
				session_destroy();
				return false;
			} else {
				$api->sid = session_id();
				$this->client = $_SESSION['user.id'];
			}
		} else {
			if (!Commons::getParam('serial', $api, 2) && !Commons::getParam('email', null, null) && !Commons::getParam('pin', null, null) && !array_key_exists('sid', $_GET)) {
				$response = array("error" => "Authentication data missing.");
				return false;
			} 
			$data = array();
			if (Commons::getParam('serial', $api, 2) && !Commons::getParam('email', null, null) && !Commons::getParam('pin', null, null)) {
				$data = $this->db->query("SELECT * FROM Clients WHERE serial_number = '%s';", array(Commons::getParam('serial', $api, 2)));
				$this->session = "READ_ONLY";
			}
			if (Commons::getParam('email', null, null) && Commons::getParam('pin', null, null)) {
				$data = $this->db->query("SELECT * FROM Clients WHERE email = '%s' AND pin = SHA1('%s');", array(Commons::getParam('email', null, null), Commons::getParam('pin', null, null)));
				if (count($data) == 0) {
					$response = array("error" => "Invalid pin or email.");
					return false;
				}
				$this->session = "PROACTIVE";
				session_start();
				$api->sid = session_id();
				$_SESSION['user.name'] = $data[0]['email'];
				$_SESSION['user.id'] = $data[0]['id']; 
				$_SESSION['privi.lvl'] =$data[0]['privi_lvl']; //User levels: 0 normal user, 1 Company, 2 administrator
				$_SESSION['valid'] = true;
			}
			
			if (count($data) == 0 && Commons::getParam('serial', $api, 2) != null) {
				$this->db->exec("INSERT INTO Clients (serial_number, email, location) VALUES ('%s', 'n/a', 'n/a');", array(Commons::getParam('serial', $api, 2)));
				$this->db->exec("INSERT INTO ClientRel (parent, child, type) VALUES ((SELECT id FROM Clients WHERE email = 'admin@waste'), '%s', 'ALL');", array($this->db->lastId()));
				$data = $this->db->query("SELECT * FROM Clients WHERE serial_number = '%s';", array(Commons::getParam('serial', $api, 2)));
			}
					
			if(isset($data[0]['id'])){
				$this->client = $data[0]['id'];
			}
		}
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
			if ($decoded == NULL) {
				$state = 500;
				return array("error" => "Cannot parse JSON payload");
			} else {
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
	
	/*
	* Doing the registration in the way of the client and the user are the same and 
	* when you registre you also claim serial, will modify it if we decide to separate
	* users and clients
	*/
	public function register(&$state, $api) {
		if ($api->method != 'GET') {
			$state = 405;
			return array("error" => "invocation of get method requires HTTP GET");
		} else {
			if (Commons::getParam('email', null, null) && Commons::getParam('pin', null, null)) {
				$result = $this->db->query("SELECT * FROM Clients WHERE email = '%s';", array(Commons::getParam('email', null, null),Commons::getParam('serial', $api, 2)));
				if(Commons::valid_serial(Commons::getParam('serial', $api, 2),$this)){
					if (count($result) == 0) {
						$this->db->exec("INSERT INTO Clients (serial_number, email, pin ,location) VALUES('%s', '%s', SHA1('%s'), '%s')",array(Commons::getParam('serial', $api, 2),Commons::getParam('email', null, null),Commons::getParam('pin', null, null),Commons::getParam('location', null, null)));
						$this->db->exec("UPDATE Serials SET claimed=1 WHERE serial_number='%s'",array(Commons::getParam('serial', $api, 2)));
						$response = array("message" => "Register successful");
						return $response;
					} else {
						$response = array("error" => "This email is already on use.");
						return $response;
					}
				} else {
					$response = array("error" => "This is not a valid serial");
					return $response;
				}
			} else {
				$response = array("error" => "insufficient data");
				return $response;
			}
		}
	}

	public function claim(&$state, $api){
		if ($api->method != 'GET') {
			$state = 405;
			return array("error" => "invocation of get method requires HTTP GET");
		} else {
			if (Commons::valid_serial(Commons::getParam('serial', $api, 2),$this)) {
				$this->db->exec("UPDATE Serials SET claimed=1 WHERE serial_number='%s'",array(Commons::getParam('serial', $api, 2)));
				$this->db->exec("UPDATE Clients SET serial_number='%s' WHERE id='%s'",array(Commons::getParam('serial', $api, 2),$_SESSION['user.id']));
				return array("reason" => "OK");;                               
			} else {
				$response = array("error" => "This is not a valid serial");
				return $response;
			}
		}
	}

	public function query(&$state, $api) {
		if ($api->method != 'GET') {
			$state = 405;
			return array("error" => "invocation of get method requires HTTP GET");
		} else if (!file_exists('./res/queries.xml')) {
			$state = 405;
			return array("error" => "Cannot locate queries file!");
		} else {
			$queries = simplexml_load_file('./res/queries.xml');
			$query = "";
			foreach ($queries->Query as $q) {
				if ($q->attributes()->name == Commons::getParam('query', $api, 3)) {
					$query = $q[0];
				}
			}
			if ($query == '') return array("error" => "Invalid query.");			
			$tokens = array(
				'ClientId' => $this->client,
				'CurrentMonthFD' => date('Y-m-01')." 00:00:00",
				'CurrentMonthLD' => date('Y-m-t')." 23:59:59",
				'CurrentWeekFD' => date('Y-m-d', strtotime('last Sunday', time()))." 00:00:00",
				'CurrentWeekLD' => date('Y-m-d', strtotime('next Sunday', time()))." 23:59:59",
				'CurrentYearFD' => date('Y-m-d', strtotime('first day of January', time()))." 00:00:00",
				'CurrentYearLD' => date('Y-m-d', strtotime('last day of December', time()))." 23:59:59",
			);
			foreach ($tokens as $key => $val) {
				$query = str_replace('{?'.$key.'}', $val, $query);
			}
			return $this->db->query($query, null);
		}
	}

	public function listQueries(&$state, $api) {
		if ($api->method != 'GET') {
			$state = 405;
			return array("error" => "invocation of get method requires HTTP GET");
		} else if (!file_exists('./res/queries.xml')) {
			$state = 405;
			return array("error" => "Cannot locate queries file!");
		} else {
			$queries = simplexml_load_file('./res/queries.xml');
			$query = "";
			$data = array();
			foreach ($queries->Query as $q) {
				array_push($data, array("title" => (string)$q->attributes()->title, "name" => (string)$q->attributes()->name));
			}
			return $data;
		}
	}
	
	public function update(&$state, $api) {
		#TODO: security
		if ($api->method != 'POST') {
			$state = 405;
			return array("error" => "invocation of update method requires HTTP POST");
		} else if ($_SESSION['privi.lvl'] == 0) {
			$state = 403;
			return array("error" => "Not enough privileges.");
		} else {
			$entity = file_get_contents('php://input');
			$decoded = json_decode($entity, true);
			if ($decoded == NULL) {
				$state = 500;
				return array("error" => "Cannot parse JSON payload.");
			} else if (!array_key_exists('table', $decoded) || !array_key_exists('id', $decoded)) {
				$state = 500;
				return array("error" => "Invocation of update requires attributes table and id.");
			} else {
				$statement = "UPDATE ".$decoded['table']." SET {?attrs} WHERE id = ".$decoded['id'];
				$attrs = "";
				foreach ($decoded as $attr => $val) {
					if ($attr != 'table' && $attr != 'id') {
						$attrs .= $attr." = '".$val."',";
					}
				}
				if ($this->db->exec(str_replace('{?attrs}', substr($attrs, 0, strlen($attrs)-1), $statement), null)) {
					return array('reason' => 'OK');
				} else {
					$state = 500;
					return array("error" => mysql_error());
				}
			}
		}
	}

	public function insert(&$state, $api) {
		#TODO: security
		if ($api->method != 'POST') {
			$state = 405;
			return array("error" => "invocation of insert method requires HTTP POST");
		} else if ($_SESSION['privi.lvl'] == 0) {
			$state = 403;
			return array("error" => "Not enough privileges.");
		} else {
			$entity = file_get_contents('php://input');
			$decoded = json_decode($entity, true);
			if ($decoded == NULL) {
				$state = 500;
				return array("error" => "Cannot parse JSON payload.");
			} else if (!array_key_exists('table', $decoded)) {
				$state = 500;
				return array("error" => "Invocation of insert requires attribute table.");
			} else {
				$statement = "INSERT INTO ".$decoded['table']." ({?attrs}) VALUES ({?vals});";
				$attrs = "";
				$vals = "";
				foreach ($decoded as $attr => $val) {
					if ($attr != 'table' && $attr != 'id') {
						$attrs .= $attr.",";
						$vals .= "'".$val."',";
					}
				}
				$statement = str_replace('{?attrs}', substr($attrs, 0, strlen($attrs)-1), $statement);
				$statement = str_replace('{?vals}', substr($vals, 0, strlen($vals)-1), $statement);
				
				if ($this->db->exec($statement)) {
					return array('reason' => 'OK');
				} else {
					$state = 500;
					return array("error" => mysql_error());
				}
			}
		}
	}
	
	//---------Administration functions------------------
	//to control if adminisrator: if($_SESSION['privi_lvl']==2){
	// 
	public function add_serials(&$state, $api) {
		if ($api->method != 'GET') {
			$state = 405;
			return array("error" => "invocation of add_serials method requires HTTP GET");
		} else if (isset($_SESSION['privi.lvl']) && Commons::getParam('serials', null, null) != NULL && $_SESSION['privi.lvl'] == 2) {
			$serials = split(",",Commons::getParam('serials', $api, 2));
			foreach($serials as $val) {
				$this->db->exec("INSERT INTO Serials (serial_number, claimed) VALUES ('%s', '0');",(array($val)));
			}
			return array("reason" => "Serial numbers added to the database");
		} else {
			return array("error" => "Not enough privileges");
		}
	}
	
	public function log($data) {
		$this->db->exec("INSERT INTO ClientLog (method, cli_call, server_state, response, server_time, client) VALUES ('%s', '%s', '%s', '%s', '%s', '%s');",(array($data['method'], $data['call'], $data['state'], json_encode($data), $data['server_time'], $this->client)));
	}
	
	public function import(&$state, $api) {
		if ($api->method != 'POST') {
			$state = 405;
			return array("error" => "invocation of import method requires HTTP POST");
		} else if (isset($_SESSION['privi.lvl']) && $_SESSION['privi.lvl'] != 0) {
			// Sample source: http://stackoverflow.com/questions/21507898/reading-spreadsheet-using-phpexcel
			$inputFile = $_FILES['import-file']['tmp_name'];
			$extension = strtoupper(pathinfo($inputFile, PATHINFO_EXTENSION));
			try {
				$inputFileType = PHPExcel_IOFactory::identify($inputFile);
				$objReader = PHPExcel_IOFactory::createReader($inputFileType);
				$objPHPExcel = $objReader->load($inputFile);
			} catch(Exception $e) {
				$state = 500;
				return array("error" => $e->getMessage());
			}
			
			$sheet = $objPHPExcel->getSheet(0); 
			$highestRow = $sheet->getHighestRow(); 
			$highestColumn = $sheet->getHighestColumn();
			
			$array = array();
			$statement = "INSERT INTO ".Commons::getParam('table', $api, 2)." ({?attrs}) VALUES ({?vals});";
			
			for ($row = 1; $row <= $highestRow; $row++) { 
				$rowData = $sheet->rangeToArray('A' . $row . ':' . $highestColumn . $row, NULL, TRUE, FALSE);
				if ($row == 1) {
					$attrs = "";
					for ($col = 0; $col <= count($rowData[0]); $col++) {
						if (isset($rowData[0][$col])) {
							$attrs .= $rowData[0][$col] . ",";
						}
					}
					$statement = str_replace('{?attrs}', substr($attrs, 0, strlen($attrs)-1), $statement);
				} else {
					$vals = "";
					for ($col = 0; $col <= count($rowData[0]); $col++) {
						if (isset($rowData[0][$col])) {
							$vals .= "'" . $rowData[0][$col] . "',";
						}
					}
					if ($vals != "") {
						array_push($array, $this->db->exec(str_replace('{?vals}', substr($vals, 0, strlen($vals)-1), $statement), null) ? "OK" : mysql_error());
					}
				}
			}
			return $array;
		} else {
			$state = 403;
			return array("error" => "Not enough privileges");
		}
	}
}
?>

