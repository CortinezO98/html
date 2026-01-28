<script type="text/javascript" src="../js/jquery-3.7.1.min.js"></script>
<script type="text/javascript" src="../js/popper.min.js"></script>
<script type="text/javascript" src="../js/bootstrap.min.js"></script>
<script type="text/javascript" src="../js/menu-abrir.js"></script>
<script type="text/javascript">
	// Función para cancelar operación
    function guardar_cancelar() {
      alertify.confirm(
        "¿Está seguro de que desea cancelar la operación?",
        "¡Toda la información que no haya sido guardada se perderá!",
        function() {
          // Fallback seguro: si no existe, vuelve atrás
          var ruta = "<?php echo isset($ruta_cancelar_finalizar) ? $ruta_cancelar_finalizar : 'javascript:history.back()'; ?>";
          location.href = ruta;
        },
        ''
      );
    }
</script>
