(function( $ ) {
	'use strict';

	var cfg = ( typeof soldxCategories !== 'undefined' ) ? soldxCategories : {
		ajaxUrl: '',
		ajaxNonce: '',
		searchPlaceholder: 'Search Studio category…'
	};

	var ajaxUrl   = cfg.ajaxUrl;
	var ajaxNonce = cfg.ajaxNonce;

	/**
	 * Live search/filter for the categories table.
	 */
	$( '#soldx-cat-search' ).on( 'input', function() {
		var query = $( this ).val().toLowerCase().trim();
		var $rows = $( '.soldx-table tbody tr' );
		var visible = 0;
		$rows.each( function() {
			var text = $( this ).find( 'td' ).first().text().toLowerCase();
			var match = ! query || text.indexOf( query ) !== -1;
			$( this ).toggle( match );
			if ( match ) visible++;
		} );
		$( '.soldx-search-count' ).text(
			query ? visible + ' / ' + $rows.length + ' matches' : ''
		);
	} );

	/**
	 * Make Studio category dropdowns searchable via SelectWoo
	 * (WC's Select2 fork). If SelectWoo isn't available the
	 * selects fall back to plain HTML dropdowns.
	 */
	if ( $.fn.selectWoo ) {
		$( '.soldx-cat-select' ).selectWoo( {
			placeholder: cfg.searchPlaceholder,
			allowClear: true,
			width: '65%'
		} );
	}

	/**
	 * Add a newly-created category as an <option> to every
	 * dropdown on the page.
	 */
	function addCategoryToAllSelects( cat ) {
		var label = cat.designation || cat.reference || cat.id;
		$( '.soldx-cat-select' ).each( function() {
			if ( $( this ).find( 'option[value="' + cat.id + '"]' ).length === 0 ) {
				$( this ).append( '<option value="' + cat.id + '">' + label + '</option>' );
			}
		} );
	}

	/**
	 * Resolve the Studio parent ID for a button.
	 */
	function resolveParentId( btn ) {
		var wcParent = String( btn.data( 'wc-parent' ) || '0' );
		if ( ! wcParent || wcParent === '0' ) return '';
		var parentBtn = $( '.soldx-create-cat-btn[data-wc-term-id="' + wcParent + '"]' );
		if ( ! parentBtn.length ) return '';
		var parentSel = parentBtn.siblings( '.soldx-cat-select' );
		return parentSel.val() || '';
	}

	/**
	 * Create a single category via AJAX.
	 */
	function createCategory( name, idParent, termId ) {
		return $.post( ajaxUrl, {
			action:      'soldx_create_category',
			nonce:       ajaxNonce,
			designation: name,
			idParent:    idParent || '',
			wcTermId:    termId || ''
		} ).then( function( resp ) {
			if ( resp && resp.success ) {
				return resp.data;
			}
			throw new Error( ( resp && resp.data && resp.data.message ) || 'Unknown error' );
		} );
	}

	/**
	 * Handle a single "+ Studio" button click.
	 */
	$( '.soldx-create-cat-btn' ).on( 'click', function() {
		var btn      = $( this );
		var name     = btn.data( 'wc-name' );
		var termId   = String( btn.data( 'wc-term-id' ) || '' );
		var idParent = resolveParentId( btn );
		var sel      = btn.siblings( '.soldx-cat-select' );

		btn.prop( 'disabled', true ).text( 'Creating…' );

		createCategory( name, idParent, termId ).then( function( cat ) {
			addCategoryToAllSelects( cat );
			sel.val( cat.id ).trigger( 'change' );
			btn.removeClass( 'button-secondary' ).addClass( 'button-primary' ).text( '✓ Created' ).prop( 'disabled', true );
		} ).catch( function( err ) {
			btn.prop( 'disabled', false ).text( '+ Studio' );
			alert( 'Error creating category: ' + err.message );
		} );
	} );

	/**
	 * "Create All Unmapped" — processes rows by depth so that
	 * parents are always created before children.
	 */
	$( '#soldx-create-all' ).on( 'click', function() {
		var btn  = $( this );
		var rows = [];

		$( '.soldx-cat-select' ).each( function() {
			if ( ! $( this ).val() ) {
				var createBtn = $( this ).siblings( '.soldx-create-cat-btn' );
				var name = createBtn.data( 'wc-name' );
				if ( name && ! createBtn.prop( 'disabled' ) ) {
					rows.push( {
						sel: $( this ),
						btn: createBtn,
						name: String( name ),
						termId: String( createBtn.data( 'wc-term-id' ) || '' ),
						wcParent: String( createBtn.data( 'wc-parent' ) || '0' )
					} );
				}
			}
		} );

		if ( rows.length === 0 ) {
			alert( 'No unmapped categories to create.' );
			return;
		}

		if ( ! confirm( 'Create ' + rows.length + ' categor' + ( rows.length > 1 ? 'ies' : 'y' ) + ' in Studio?' ) ) {
			return;
		}

		var createdMap = {};
		var done = 0, failed = 0;

		btn.prop( 'disabled', true ).text( 'Processing…' );

		var parentMap = {};
		$( '.soldx-create-cat-btn' ).each( function() {
			var tid = String( $( this ).data( 'wc-term-id' ) || '' );
			var pid = String( $( this ).data( 'wc-parent' ) || '0' );
			parentMap[ tid ] = pid;
		} );
		function getDepth( termId ) {
			var d = 0, cur = termId, g = 0;
			while ( parentMap[ cur ] && parentMap[ cur ] !== '0' && g < 20 ) { d++; cur = parentMap[ cur ]; g++; }
			return d;
		}
		rows.sort( function( a, b ) { return getDepth( a.termId ) - getDepth( b.termId ); } );

		function processNext( index ) {
			if ( index >= rows.length ) {
				btn.prop( 'disabled', false ).text( 'Create All Unmapped in Studio' );
				if ( failed > 0 ) {
					alert( 'Done: ' + done + ' created, ' + failed + ' failed. Check console (F12) for details.' );
				}
				return;
			}

			var row = rows[ index ];
			row.btn.prop( 'disabled', true ).text( 'Creating…' );

			var idParent = '';
			if ( row.wcParent && row.wcParent !== '0' ) {
				if ( createdMap[ row.wcParent ] ) {
					idParent = createdMap[ row.wcParent ];
				} else {
					var parentBtn = $( '.soldx-create-cat-btn[data-wc-term-id="' + row.wcParent + '"]' );
					var parentSel = parentBtn.siblings( '.soldx-cat-select' );
					idParent = parentSel.val() || '';
				}
			}

			createCategory( row.name, idParent, row.termId ).then( function( cat ) {
				addCategoryToAllSelects( cat );
				row.sel.val( cat.id ).trigger( 'change' );
				createdMap[ row.termId ] = cat.id;
				row.btn.removeClass( 'button-secondary' ).addClass( 'button-primary' ).text( '✓' );
				done++;
			} ).catch( function( err ) {
				row.btn.prop( 'disabled', false ).text( '+ Studio' );
				failed++;
				console.error( 'Failed to create "' + row.name + '":', err.message );
			} ).always( function() {
				processNext( index + 1 );
			} );
		}

		processNext( 0 );
	} );
})( jQuery );
