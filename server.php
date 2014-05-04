<?php

# Including API and handler classes.
require_once('./lib/API.php');
require_once('./lib/WasteHandler.php');
require_once('./lib/Database.php');

# Initializing API and handler.
date_default_timezone_set('Europe/Helsinki');
$api = new API("/waste", new WasteHandler(5, new Database("localhost", "root", "", "waste")));

# Processing RESTful API call
$api->process();

?>
