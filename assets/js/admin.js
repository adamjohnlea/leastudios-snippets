/**
 * leaStudios Snippets — Admin JavaScript
 *
 * Handles CodeMirror initialization, snippet type switching,
 * location show/hide, and repeatable condition rows.
 *
 * @package LEAStudios\Snippets
 */

/* global leastudiosSnippetsAdmin, jQuery, wp */

( function( $ ) {
	'use strict';

	var cmInstance = null;

	var modeMap = {
		php:  'application/x-httpd-php',
		js:   'text/javascript',
		css:  'text/css',
		html: 'text/html'
	};

	/**
	 * Initialize CodeMirror on the code textarea.
	 */
	function initCodeMirror() {
		var textarea = document.getElementById( 'leastudios-snippets-code' );

		if ( ! textarea || typeof wp === 'undefined' || ! wp.codeEditor ) {
			return;
		}

		var settings = leastudiosSnippetsAdmin.editorSettings || {};
		var instance = wp.codeEditor.initialize( $( textarea ), settings );

		cmInstance = instance.codemirror;
	}

	/**
	 * Handle snippet type changes — update CodeMirror mode and PHP warning.
	 */
	function initTypeSwitch() {
		var typeSelect = document.getElementById( 'leastudios-snippets-type' );
		var warning    = document.getElementById( 'leastudios-snippets-php-warning' );

		if ( ! typeSelect ) {
			return;
		}

		$( typeSelect ).on( 'change', function() {
			var type = this.value;

			// Update CodeMirror mode.
			if ( cmInstance ) {
				var mode = modeMap[ type ] || 'application/x-httpd-php';
				cmInstance.setOption( 'mode', mode );
			}

			// Show/hide PHP warning.
			if ( warning ) {
				warning.style.display = ( 'php' === type ) ? '' : 'none';
			}
		} );
	}

	/**
	 * Handle location dropdown — show/hide custom hook input.
	 */
	function initLocationSwitch() {
		var locationSelect = document.getElementById( 'leastudios-snippets-location' );
		var customHookWrap = document.getElementById( 'leastudios-snippets-custom-hook-wrap' );

		if ( ! locationSelect || ! customHookWrap ) {
			return;
		}

		function toggle() {
			customHookWrap.style.display = ( 'custom_hook' === locationSelect.value ) ? '' : 'none';
		}

		$( locationSelect ).on( 'change', toggle );
		toggle();
	}

	/**
	 * Initialize repeatable condition rows.
	 */
	function initConditions() {
		var container = document.getElementById( 'leastudios-snippets-conditions-rows' );
		var addButton = document.getElementById( 'leastudios-snippets-add-condition' );
		var dataField = document.getElementById( 'leastudios-snippets-conditions-data' );

		if ( ! container || ! addButton ) {
			return;
		}

		var conditionTypes = leastudiosSnippetsAdmin.conditionTypes || {};

		function getNextIndex() {
			var rows = container.querySelectorAll( '.leastudios-snippets-condition-row' );
			var max  = -1;

			rows.forEach( function( row ) {
				var idx = parseInt( row.getAttribute( 'data-index' ), 10 );
				if ( idx > max ) {
					max = idx;
				}
			} );

			return max + 1;
		}

		function buildTypeOptions() {
			var html = '';

			Object.keys( conditionTypes ).forEach( function( key ) {
				html += '<option value="' + key + '">' + conditionTypes[ key ] + '</option>';
			} );

			return html;
		}

		function addRow() {
			var index = getNextIndex();
			var html  = '<div class="leastudios-snippets-condition-row" data-index="' + index + '">'
				+ '<select class="leastudios-snippets-condition-type">' + buildTypeOptions() + '</select>'
				+ '<select class="leastudios-snippets-condition-operator">'
				+ '<option value="is">is</option>'
				+ '<option value="is_not">is not</option>'
				+ '</select>'
				+ '<input type="text" class="leastudios-snippets-condition-value" placeholder="Value" />'
				+ '<button type="button" class="button leastudios-snippets-remove-condition">'
				+ '<span class="dashicons dashicons-no-alt"></span>'
				+ '</button>'
				+ '</div>';

			container.insertAdjacentHTML( 'beforeend', html );
			syncConditions();
		}

		function syncConditions() {
			if ( ! dataField ) {
				return;
			}

			var conditions = [];
			var rows       = container.querySelectorAll( '.leastudios-snippets-condition-row' );

			rows.forEach( function( row ) {
				var typeEl     = row.querySelector( '.leastudios-snippets-condition-type' );
				var operatorEl = row.querySelector( '.leastudios-snippets-condition-operator' );
				var valueEl    = row.querySelector( '.leastudios-snippets-condition-value' );

				conditions.push( {
					type:     typeEl ? typeEl.value : '',
					operator: operatorEl ? operatorEl.value : 'is',
					value:    valueEl ? valueEl.value : ''
				} );
			} );

			dataField.value = JSON.stringify( conditions );
		}

		// Add condition button.
		addButton.addEventListener( 'click', addRow );

		// Remove condition (delegated).
		$( container ).on( 'click', '.leastudios-snippets-remove-condition', function() {
			$( this ).closest( '.leastudios-snippets-condition-row' ).remove();
			syncConditions();
		} );

		// Sync on any change within conditions.
		$( container ).on( 'change input', 'select, input', syncConditions );

		// Sync before form submit.
		$( '#post' ).on( 'submit', syncConditions );

		// Initial sync.
		syncConditions();
	}

	/**
	 * DOM ready.
	 */
	$( function() {
		initCodeMirror();
		initTypeSwitch();
		initLocationSwitch();
		initConditions();
	} );

}( jQuery ) );
