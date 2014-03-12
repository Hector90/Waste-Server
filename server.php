<?php

require_once('./lib/API.php');
require_once('./lib/WasteHandler.php');

$api = new API("/waste", new WasteHandler(5));
$api->process();

?>
