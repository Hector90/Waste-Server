<?php

# Including API and handler classes.
require_once('./lib/API.php');
require_once('./lib/WasteHandler.php');
require_once('./lib/Database.php');

# Initializing API and handler.
$api = new API("/waste", new WasteHandler(5, new Database("localhost", "waste", "RoskaKuskit", "waste")));

# Processing RESTful API call
$api->process();

?>
