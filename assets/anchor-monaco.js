/* global require, AnchorMonaco, jQuery, wp, monaco */
( function ( $ ) {
	'use strict';

	// Set synchronously so module scripts (depending on this handle) can skip
	// their own CodeMirror init before their ready handler runs — but ONLY when
	// the Monaco AMD loader actually loaded. The loader.js script tag runs before
	// this one (dependency order); if the CDN request was blocked/offline the
	// global `require` is absent, so we leave AnchorMonaco.active unset and let
	// each module keep its native textareas + CodeMirror path.
	window.AnchorMonaco = window.AnchorMonaco || {};
	var LOADER_OK = ( typeof window.require === 'function' && typeof window.require.config === 'function' );
	if ( LOADER_OK ) { window.AnchorMonaco.active = true; }

	var UNDO_PREFIX = 'anchorMonacoUndo:';
	var UNDO_CAP    = 40;
	var UNDO_MAX    = 2500000;

	function undoKey( fieldId ) {
		return UNDO_PREFIX + ( ( window.AnchorMonaco && AnchorMonaco.postId ) || 0 ) + ':' + fieldId;
	}
	function undoRead( fieldId ) {
		try {
			var raw = window.localStorage.getItem( undoKey( fieldId ) );
			return raw ? JSON.parse( raw ) : null;
		} catch ( e ) { return null; }
	}
	function undoWrite( fieldId, snaps ) {
		try {
			var str = JSON.stringify( snaps );
			if ( str.length > UNDO_MAX ) { return; }
			window.localStorage.setItem( undoKey( fieldId ), str );
		} catch ( e ) {
			try {
				for ( var i = window.localStorage.length - 1; i >= 0; i-- ) {
					var k = window.localStorage.key( i );
					if ( k && k.indexOf( UNDO_PREFIX ) === 0 && k !== undoKey( fieldId ) ) {
						window.localStorage.removeItem( k );
					}
				}
				window.localStorage.setItem( undoKey( fieldId ), JSON.stringify( snaps ) );
			} catch ( e2 ) { /* give up */ }
		}
	}
	function debounce( fn, wait ) {
		var t;
		return function () {
			var c = this, a = arguments;
			clearTimeout( t );
			t = setTimeout( function () { fn.apply( c, a ); }, wait );
		};
	}
	function flash( message ) {
		var n = document.createElement( 'div' );
		n.setAttribute( 'role', 'status' );
		n.textContent = message;
		n.style.cssText = 'position:fixed;z-index:100000;right:20px;bottom:20px;' +
			'background:#1e1e1e;color:#fff;padding:10px 16px;border-radius:6px;' +
			'font-size:13px;box-shadow:0 4px 16px rgba(0,0,0,.3);opacity:0;' +
			'transition:opacity .25s ease;';
		document.body.appendChild( n );
		requestAnimationFrame( function () { n.style.opacity = '1'; } );
		setTimeout( function () {
			n.style.opacity = '0';
			setTimeout( function () { n.remove(); }, 300 );
		}, 2400 );
	}

	function initWrapper( wrap ) {
		var fields;
		try { fields = JSON.parse( wrap.getAttribute( 'data-anchor-monaco' ) ); }
		catch ( e ) { return; }
		if ( ! fields || ! fields.length ) { return; }

		// Build toolbar (tabs + format + media).
		var toolbar = document.createElement( 'div' );
		toolbar.className = 'anchor-monaco-toolbar';
		var tabs = document.createElement( 'div' );
		tabs.className = 'anchor-monaco-tabs';
		fields.forEach( function ( f, idx ) {
			var b = document.createElement( 'button' );
			b.type = 'button';
			b.className = 'anchor-monaco-tab' + ( idx === 0 ? ' is-active' : '' );
			b.setAttribute( 'data-field', f.id );
			b.textContent = f.label;
			tabs.appendChild( b );
		} );
		toolbar.appendChild( tabs );

		var fmtBtn = document.createElement( 'button' );
		fmtBtn.type = 'button';
		fmtBtn.className = 'button anchor-monaco-format';
		fmtBtn.innerHTML = '<span class="dashicons dashicons-editor-code"></span> Format';
		toolbar.appendChild( fmtBtn );

		var mediaBtn = document.createElement( 'button' );
		mediaBtn.type = 'button';
		mediaBtn.className = 'button anchor-monaco-media';
		mediaBtn.innerHTML = '<span class="dashicons dashicons-admin-media"></span> Insert from Media Library';
		toolbar.appendChild( mediaBtn );

		var host = document.createElement( 'div' );
		host.className = 'anchor-monaco-host';

		wrap.insertBefore( toolbar, wrap.firstChild );
		wrap.appendChild( host );

		// Hide the source textareas and their now-redundant labels (the tabs
		// label each field). We hide the elements themselves — never a parent's
		// display — so mode-toggle logic that shows/hides a field's wrapper
		// (e.g. Popups) can't re-reveal a raw textarea.
		fields.forEach( function ( f ) {
			var ta = document.getElementById( f.id );
			if ( ! ta ) { return; }
			ta.classList.add( 'anchor-monaco-hidden' );
			var prev = ta.previousElementSibling;
			if ( prev && prev.tagName === 'LABEL' ) { prev.classList.add( 'anchor-monaco-hidden' ); }
		} );

		var editors = {};
		var active = fields[ 0 ].id;
		var restored = false;

		// Restore the native textareas if Monaco can't be loaded at runtime, so the
		// code fields never end up hidden-and-uneditable. The module's own input→
		// preview wiring already ran (its else-branch fires when active is set), so
		// reverting just needs to un-hide the fields and drop the Monaco chrome.
		function revertToTextareas() {
			toolbar.parentNode && toolbar.parentNode.removeChild( toolbar );
			host.parentNode && host.parentNode.removeChild( host );
			fields.forEach( function ( f ) {
				var ta = document.getElementById( f.id );
				if ( ! ta ) { return; }
				ta.classList.remove( 'anchor-monaco-hidden' );
				var prev = ta.previousElementSibling;
				if ( prev && prev.tagName === 'LABEL' ) { prev.classList.remove( 'anchor-monaco-hidden' ); }
			} );
			flash( 'Monaco editor failed to load — using plain text fields.' );
		}

		require.config( { paths: { vs: AnchorMonaco.monacoBase + '/vs' } } );
		require( [ 'vs/editor/editor.main' ], function () {
			fields.forEach( function ( f, idx ) {
				var ta = document.getElementById( f.id );
				if ( ! ta ) { return; }
				var pane = document.createElement( 'div' );
				pane.className = 'anchor-monaco-pane';
				pane.style.display = ( idx === 0 ) ? 'block' : 'none';
				host.appendChild( pane );

				var ed = monaco.editor.create( pane, {
					value: ta.value,
					language: f.lang,
					theme: 'vs-dark',
					automaticLayout: true,
					wordWrap: 'on',
					minimap: { enabled: false },
					fontSize: 13,
					tabSize: 2,
					scrollBeyondLastLine: false
				} );

				var model   = ed.getModel();
				var current = ed.getValue();
				var stored  = undoRead( f.id );
				var snaps;
				if ( stored && stored.length >= 2 && stored[ stored.length - 1 ] === current ) {
					model.setValue( stored[ 0 ] );
					for ( var si = 1; si < stored.length; si++ ) {
						model.pushStackElement();
						ed.executeEdits( 'anchor-undo', [ {
							range: model.getFullModelRange(),
							text: stored[ si ],
							forceMoveMarkers: true
						} ] );
					}
					model.pushStackElement();
					snaps = stored.slice();
					var ll = model.getLineCount();
					ed.setPosition( { lineNumber: ll, column: model.getLineMaxColumn( ll ) } );
					restored = true;
				} else {
					snaps = [ current ];
				}

				var persist = debounce( function () {
					var v = ed.getValue();
					if ( snaps[ snaps.length - 1 ] === v ) { return; }
					snaps.push( v );
					if ( snaps.length > UNDO_CAP ) { snaps.shift(); }
					undoWrite( f.id, snaps );
				}, 300 );
				var flushNow = function () {
					var v = ed.getValue();
					if ( snaps[ snaps.length - 1 ] !== v ) {
						snaps.push( v );
						if ( snaps.length > UNDO_CAP ) { snaps.shift(); }
					}
					undoWrite( f.id, snaps );
				};

				model.onDidChangeContent( function () {
					ta.value = ed.getValue();
					// Drive existing live-preview wiring that listens on 'input'.
					ta.dispatchEvent( new Event( 'input', { bubbles: true } ) );
					persist();
				} );

				$( window ).on( 'beforeunload', flushNow );
				$( '#post' ).on( 'submit', flushNow );

				editors[ f.id ] = { ed: ed, pane: pane, lang: f.lang };
			} );

			if ( restored ) { flash( 'Undo history restored — Ctrl/Cmd+Z still works.' ); }
		}, function () {
			// AMD load error (e.g. editor.main blocked) — fall back to textareas.
			revertToTextareas();
		} );

		function showTab( fieldId ) {
			active = fieldId;
			Object.keys( editors ).forEach( function ( id ) {
				editors[ id ].pane.style.display = ( id === fieldId ) ? 'block' : 'none';
				if ( id === fieldId ) { editors[ id ].ed.layout(); }
			} );
			$( toolbar ).find( '.anchor-monaco-tab' ).removeClass( 'is-active' );
			$( toolbar ).find( '.anchor-monaco-tab[data-field="' + fieldId + '"]' ).addClass( 'is-active' );
		}

		$( tabs ).on( 'click', '.anchor-monaco-tab', function () {
			showTab( $( this ).data( 'field' ) );
		} );

		$( fmtBtn ).on( 'click', function ( e ) {
			e.preventDefault();
			var cur = editors[ active ] && editors[ active ].ed;
			if ( ! cur ) { return; }
			cur.focus();
			var action = cur.getAction( 'editor.action.formatDocument' );
			if ( ! action ) { flash( 'Formatter not available for this tab.' ); return; }
			action.run().then( function () {
				var ta = document.getElementById( active );
				if ( ta ) { ta.value = cur.getValue(); ta.dispatchEvent( new Event( 'input', { bubbles: true } ) ); }
				flash( 'Formatted.' );
			} ).catch( function () { flash( 'Could not format.' ); } );
		} );

		var frame = null;
		$( mediaBtn ).on( 'click', function ( e ) {
			e.preventDefault();
			if ( frame ) { frame.open(); return; }
			frame = wp.media( { title: AnchorMonaco.mediaTitle, button: { text: AnchorMonaco.mediaBtn }, multiple: false } );
			frame.on( 'select', function () {
				var att = frame.state().get( 'selection' ).first().toJSON();
				var url = att && att.url;
				var cur = editors[ active ] && editors[ active ].ed;
				if ( ! cur || ! url ) { return; }
				var sel = cur.getSelection() || new monaco.Selection( 1, 1, 1, 1 );
				cur.executeEdits( 'anchor-media', [ { range: sel, text: url, forceMoveMarkers: true } ] );
				cur.focus();
				var ta = document.getElementById( active );
				if ( ta ) { ta.value = cur.getValue(); ta.dispatchEvent( new Event( 'input', { bubbles: true } ) ); }
			} );
			frame.open();
		} );
	}

	$( function () {
		// Loader never arrived — do nothing; native textareas + the module's own
		// CodeMirror path stay in place (AnchorMonaco.active was left unset).
		if ( ! LOADER_OK ) { return; }
		var wraps = document.querySelectorAll( '.anchor-monaco[data-anchor-monaco]' );
		for ( var i = 0; i < wraps.length; i++ ) { initWrapper( wraps[ i ] ); }
	} );

} )( jQuery );
