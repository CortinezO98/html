function validar_form_numerico(id_elemento){
    var valor_elemento = document.getElementById(id_elemento).value;
    document.getElementById(id_elemento).value = valor_elemento.replace(/[^0-9]/g,'');
}

function validar_form_string(id_elemento){
    var valor_elemento = document.getElementById(id_elemento).value;
    document.getElementById(id_elemento).value = valor_elemento.replace(/[<|>|'|/]/g,'');
}

function validar_texto(text) {
	var map = {
		'&': '&amp;',
		'<': '&lt;',
		'>': '&gt;',
		'"': '&quot;',
		"'": '&quot;'
	};

	return text.replace(/[&<>"']/g, function(m) { return map[m]; });
}