$('.menu-bar-boton').on('click', function(){
	$('.contenido').toggleClass('abrir');
	$('.menu-bar').toggleClass('abrir');
	$('footer').toggleClass('abrir');
	$('.control_turno').toggleClass('abrir');
})

$('.menu_oculto').on('click', function(){
	$('.menu_res').toggleClass('mostrar_ocultar');
	
})

$('.contenido').on('click', function(){
	if ($(".contenido").hasClass("abrir")) {
		$('.contenido').toggleClass('abrir');
		$('.menu-bar').toggleClass('abrir');
		$('footer').toggleClass('abrir');
		$('.control_turno').toggleClass('abrir');
	}
	// if ($(".menu_res").hasClass("mostrar_ocultar")) {
	// 	$('.menu_res').toggleClass('mostrar_ocultar');
	// }
})


$('.submenu').click(function(){
	$(this).children("ul").slideToggle();
})

$("ul").click(function(p){
	p.stopPropagation();
})