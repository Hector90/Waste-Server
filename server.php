<?php

# Including API and handler classes.
require_once('./lib/API.php');
require_once('./lib/WasteHandler.php');

# Initializing database connection parameters for API.
API::$DB_HOST = "localhost";
API::$DB_USER = "waste";
API::$DB_PASS = "RoskaKuskit";
API::$DB_NAME = "waste";

# Initializing API and handler.
$api = new API("/waste", new WasteHandler(5));

# Processing RESTful API call
$api->process();

?>
