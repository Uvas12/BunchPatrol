function checkAll( selected ) {
	for ( var i = 0; i < document.checkform.elements.length; i++ ) {
		var e = document.checkform.elements[i];
		if ( e.type == 'checkbox' ) {
			e.checked = selected;
		}
	}
}

$( function() {
	$( 'input[name="wpCheckAll"]' ).on( 'click', checkAll( true ) );
	$( 'input[name="wpCheckNone"]' ).on( 'click', checkAll( false ) );
} );
