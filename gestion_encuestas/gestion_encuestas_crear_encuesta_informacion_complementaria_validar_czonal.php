<?php
	require_once("../config/conexion_db.php");
	
	$id_filtro=$_POST['id'];

	$consulta = mysqli_query($enlace_db, "SELECT `gercz_id`, `gercz_regional`, `gercz_centro_zonal`, `gercz_registro_fecha` FROM `tb_gestion_encuesta_regional_czonal` WHERE `gercz_regional`='".$id_filtro."' ORDER BY `gercz_centro_zonal` ASC");
	$resultado = mysqli_fetch_all($consulta);
?>
	<option value="">Seleccione</option>
<?php
	for ($i=0; $i < count($resultado); $i++) { 
?>
  <option value="<?php echo $resultado[$i][0]; ?>"><?php echo $resultado[$i][2]; ?></option>
<?php
	}
?>