<?php

class Commons {

	public static function getStateMessage($state) {
        $status = array(  
            200 => 'OK',
			401 => 'Unauthorized',
            404 => 'Not Found',   
            405 => 'Method Not Allowed',
            500 => 'Internal Server Error',
        ); 
        return (array_key_exists($state, $status)) ? $status[$state] : $status[500];	
	}
	
	public static function getParam($name, $api, $pathIndex) { 
		$val = ($_SERVER['REQUEST_METHOD'] == 'POST' && array_key_exists($name, $_POST) ? $_POST[$name] : ($_SERVER['REQUEST_METHOD'] != 'POST' && array_key_exists($name, $_GET) ? $_GET[$name] : NULL));
		if ($val == NULL && $api != NULL && $pathIndex != NULL && count($api->path_parts) >= $pathIndex) {
			$val = isset($api->path_parts[$pathIndex]) ? $api->path_parts[$pathIndex] : NULL;
		}
		return $val;
	}
	
}

?>