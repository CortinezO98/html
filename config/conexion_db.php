<?php
	$server='localhost';
	$user='root';
	$password='rwa347DF$7$6#26';
	$db='icbf_iqgis';
	$enlace_db = new mysqli($server, $user, $password, $db);
	$acentos = mysqli_query($enlace_db, "SET NAMES 'utf8'");
	if ($enlace_db->connect_errno) {
	    echo "Fallo al conectar a Base de Datos: (" . $enlace_db->connect_errno . ") " . $enlace_db->connect_error;
	}
?>