(function() {
	'use strict';

	var i18n = ( typeof soldxArticles !== 'undefined' ) ? soldxArticles : {
		noSelection: 'Please select at least one product.',
		pushing: 'Pushing…'
	};

	var selectAll = document.getElementById( 'soldx-select-all' );
	if ( selectAll ) {
		selectAll.addEventListener( 'change', function() {
			var boxes = document.querySelectorAll( '.soldx-row-check' );
			for ( var i = 0; i < boxes.length; i++ ) {
				boxes[ i ].checked = selectAll.checked;
			}
		} );
	}

	var form = document.getElementById( 'soldx-sync-form' );
	var btn  = document.getElementById( 'soldx-bulk-sync' );
	if ( form && btn ) {
		form.addEventListener( 'submit', function( e ) {
			var checked = form.querySelectorAll( '.soldx-row-check:checked' );
			if ( checked.length === 0 ) {
				e.preventDefault();
				alert( i18n.noSelection );
			} else {
				btn.setAttribute( 'disabled', 'disabled' );
				btn.innerText = i18n.pushing;
			}
		} );
	}

	// Toggle pill visual state on checkbox change.
	document.querySelectorAll( '.soldx-pill input[type="checkbox"]' ).forEach( function( cb ) {
		cb.addEventListener( 'change', function() {
			this.closest( '.soldx-pill' ).classList.toggle( 'soldx-pill--on', this.checked );
		} );
	} );
})();
