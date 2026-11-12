/* global require, ACEditor, jQuery, wp, monaco */
( function ( $ ) {
	'use strict';

	var editors = {};        // key -> monaco editor
	var fields = { html: '#content', css: '#ac_custom_css', js: '#ac_custom_js' };
	var langs = { html: 'html', css: 'css', js: 'javascript' };
	var active = 'html';
	var ready = false;
	var diffEditors = []; // outstanding AI diff editors awaiting accept/reject

	/* ------------------------------------------------------------------
	 * Undo persistence across the full-page reload that follows a save.
	 *
	 * Saving a page does a normal POST + reload, which destroys Monaco and
	 * its undo stack. We mirror each editor's content as debounced snapshots
	 * into localStorage (keyed per post + tab) and, on the next load, replay
	 * those snapshots as edits to rebuild Monaco's native undo history — so
	 * Ctrl/Cmd+Z keeps working after an update.
	 * ------------------------------------------------------------------ */
	var UNDO_PREFIX = 'acMonacoUndo:';
	var UNDO_CAP    = 40;          // max snapshots kept per tab
	var UNDO_MAX    = 2500000;     // ~2.5MB cap per entry; skip if larger
	var acUndoRestored = false;

	function undoKey( key ) {
		return UNDO_PREFIX + ( ACEditor.postId || 0 ) + ':' + key;
	}
	function undoRead( key ) {
		try {
			var raw = window.localStorage.getItem( undoKey( key ) );
			return raw ? JSON.parse( raw ) : null;
		} catch ( e ) { return null; }
	}
	function undoWrite( key, snaps ) {
		try {
			var str = JSON.stringify( snaps );
			if ( str.length > UNDO_MAX ) { return; }
			window.localStorage.setItem( undoKey( key ), str );
		} catch ( e ) {
			// Quota/private mode: drop our other entries and retry once.
			try {
				for ( var i = window.localStorage.length - 1; i >= 0; i-- ) {
					var k = window.localStorage.key( i );
					if ( k && k.indexOf( UNDO_PREFIX ) === 0 && k !== undoKey( key ) ) {
						window.localStorage.removeItem( k );
					}
				}
				window.localStorage.setItem( undoKey( key ), JSON.stringify( snaps ) );
			} catch ( e2 ) { /* give up silently */ }
		}
	}
	function undoDebounce( fn, wait ) {
		var t;
		return function () {
			var c = this, a = arguments;
			clearTimeout( t );
			t = setTimeout( function () { fn.apply( c, a ); }, wait );
		};
	}
	function acFlash( message ) {
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
		}, 2600 );
	}

	require.config( { paths: { vs: ACEditor.monacoBase + '/vs' } } );

	require( [ 'vs/editor/editor.main' ], function () {
		var host = document.getElementById( 'ac-monaco-editor' );
		// Create one editor per tab, stacked; show only the active one.
		Object.keys( fields ).forEach( function ( key ) {
			var wrap = document.createElement( 'div' );
			wrap.className = 'ac-editor-pane';
			wrap.style.display = ( key === active ) ? 'block' : 'none';
			wrap.style.height = '100%';
			host.appendChild( wrap );
			var ed = monaco.editor.create( wrap, {
				value: $( fields[ key ] ).val(),
				language: langs[ key ],
				theme: 'vs-dark',
				automaticLayout: true,
				wordWrap: 'on',
				minimap: { enabled: false },
				fontSize: 13,
				tabSize: 2,
				scrollBeyondLastLine: false
			} );
			// --- Rebuild undo history from the previous (pre-reload) session. ---
			var model   = ed.getModel();
			var current = ed.getValue();
			var stored  = undoRead( key );
			var snaps;

			if ( stored && stored.length >= 2 && stored[ stored.length - 1 ] === current ) {
				// Replay snapshots as edits so Monaco's native undo stack is rebuilt.
				model.setValue( stored[ 0 ] );
				for ( var si = 1; si < stored.length; si++ ) {
					model.pushStackElement();
					ed.executeEdits( 'ac-undo-replay', [ {
						range: model.getFullModelRange(),
						text: stored[ si ],
						forceMoveMarkers: true
					} ] );
				}
				model.pushStackElement();
				snaps = stored.slice();
				var lastLine = model.getLineCount();
				ed.setPosition( { lineNumber: lastLine, column: model.getLineMaxColumn( lastLine ) } );
				acUndoRestored = true;
			} else {
				snaps = [ current ];
			}

			var persist = undoDebounce( function () {
				var v = ed.getValue();
				if ( snaps[ snaps.length - 1 ] === v ) { return; }
				snaps.push( v );
				if ( snaps.length > UNDO_CAP ) { snaps.shift(); }
				undoWrite( key, snaps );
			}, 300 );

			// Synchronous flush so the final pre-save edit is captured before unload.
			var flushNow = function () {
				var v = ed.getValue();
				if ( snaps[ snaps.length - 1 ] !== v ) {
					snaps.push( v );
					if ( snaps.length > UNDO_CAP ) { snaps.shift(); }
				}
				undoWrite( key, snaps );
			};

			model.onDidChangeContent( function () {
				$( fields[ key ] ).val( ed.getValue() );
				persist();
			} );

			$( window ).on( 'beforeunload', flushNow );
			$( '#post' ).on( 'submit', flushNow );

			editors[ key ] = { ed: ed, wrap: wrap, flush: flushNow };
		} );
		if ( acUndoRestored ) {
			acFlash( 'Undo history restored — Ctrl/Cmd+Z still works.' );
		}
		ready = true;
	} );

	function showTab( key ) {
		active = key;
		Object.keys( editors ).forEach( function ( k ) {
			editors[ k ].wrap.style.display = ( k === key ) ? 'block' : 'none';
			if ( k === key ) { editors[ k ].ed.layout(); }
		} );
		$( '.ac-tab' ).removeClass( 'is-active' );
		$( '.ac-tab[data-tab="' + key + '"]' ).addClass( 'is-active' );
	}

	$( document ).on( 'click', '.ac-tab', function () {
		showTab( $( this ).data( 'tab' ) );
	} );

	// ---- Media library (inserts into the active editor) ----
	var frame = null;
	$( '#ac-media-button' ).on( 'click', function ( e ) {
		e.preventDefault();
		if ( frame ) { frame.open(); return; }
		frame = wp.media( { title: ACEditor.mediaTitle, button: { text: ACEditor.mediaBtn }, multiple: false } );
		frame.on( 'select', function () {
			var att = frame.state().get( 'selection' ).first().toJSON();
			var url = att.url;
			var cur = editors[ active ] && editors[ active ].ed;
			if ( ! cur || ! url ) { return; }
			var sel = cur.getSelection() || new monaco.Selection( 1, 1, 1, 1 );
			cur.executeEdits( 'ac-media', [ { range: sel, text: url, forceMoveMarkers: true } ] );
			cur.focus();
			$( fields[ active ] ).val( cur.getValue() );
		} );
		frame.open();
	} );

	// ---- Format / beautify the active tab ----
	$( '#ac-format-code' ).on( 'click', function ( e ) {
		e.preventDefault();
		var cur = editors[ active ] && editors[ active ].ed;
		if ( ! cur ) { return; }
		cur.focus();
		var action = cur.getAction( 'editor.action.formatDocument' );
		if ( ! action ) {
			acFlash( 'Formatter not available for ' + active.toUpperCase() + '.' );
			return;
		}
		action.run().then( function () {
			$( fields[ active ] ).val( cur.getValue() );
			acFlash( 'Formatted ' + active.toUpperCase() + '.' );
		} ).catch( function () {
			acFlash( 'Could not format ' + active.toUpperCase() + '.' );
		} );
	} );

	// ---- AI panel ----
	$( '#ac-ai-toggle' ).on( 'click', function () {
		var p = document.getElementById( 'ac-ai-panel' );
		if ( p ) { p.hidden = ! p.hidden; }
	} );

	function setStatus( msg ) { $( '#ac-ai-status' ).text( msg || '' ); }
	function appendTrace( line ) {
		$( '#ac-ai-status' ).append( $( '<div class="ac-ai-trace-line"></div>' ).text( line ) );
	}

	function disposeDiff( diffEd ) {
		var idx = diffEditors.indexOf( diffEd );
		if ( idx > -1 ) { diffEditors.splice( idx, 1 ); }
		var m = diffEd.getModel();
		diffEd.dispose();
		if ( m ) {
			if ( m.original ) { m.original.dispose(); }
			if ( m.modified ) { m.modified.dispose(); }
		}
	}

	function clearResults() {
		diffEditors.slice().forEach( disposeDiff );
		$( '#ac-ai-results' ).empty();
	}

	function applyEdit( key, newText ) {
		var cur = editors[ key ] && editors[ key ].ed;
		if ( ! cur ) { return; }
		var model = cur.getModel();
		var full = model.getFullModelRange();
		// Replace whole content via executeEdits so it stays in the undo stack.
		cur.executeEdits( 'ac-ai', [ { range: full, text: newText, forceMoveMarkers: true } ] );
		$( fields[ key ] ).val( cur.getValue() );
	}

	function renderDiff( key, proposed ) {
		var current = editors[ key ] ? editors[ key ].ed.getValue() : '';
		if ( current === proposed ) { return null; }

		var block = $(
			'<div class="ac-ai-diff-block">' +
				'<div class="ac-ai-diff-head"><strong></strong> ' +
					'<button type="button" class="button button-small ac-ai-accept">' + ACEditor.i18n.accept + '</button> ' +
					'<button type="button" class="button button-small ac-ai-reject">' + ACEditor.i18n.reject + '</button>' +
				'</div>' +
				'<div class="ac-ai-diff"></div>' +
			'</div>'
		);
		block.find( 'strong' ).text( key.toUpperCase() );
		$( '#ac-ai-results' ).append( block );

		var diffHost = block.find( '.ac-ai-diff' )[ 0 ];
		var diffEd = monaco.editor.createDiffEditor( diffHost, {
			theme: 'vs-dark', readOnly: true, automaticLayout: true, renderSideBySide: true,
			minimap: { enabled: false }, scrollBeyondLastLine: false
		} );
		diffEd.setModel( {
			original: monaco.editor.createModel( current, langs[ key ] ),
			modified: monaco.editor.createModel( proposed, langs[ key ] )
		} );
		diffEditors.push( diffEd );

		block.find( '.ac-ai-accept' ).on( 'click', function () {
			applyEdit( key, proposed );
			disposeDiff( diffEd );
			block.remove();
		} );
		block.find( '.ac-ai-reject' ).on( 'click', function () {
			disposeDiff( diffEd );
			block.remove();
		} );
		return true;
	}

	$( '#ac-ai-send' ).on( 'click', function () {
		if ( ! ready ) { return; }
		var prompt = $.trim( $( '#ac-ai-prompt' ).val() );
		if ( ! prompt ) { return; }
		var $btn = $( this ).prop( 'disabled', true );
		clearResults();
		setStatus( ACEditor.i18n.thinking );

		$.ajax( {
			url: ACEditor.restUrl,
			method: 'POST',
			beforeSend: function ( xhr ) { xhr.setRequestHeader( 'X-WP-Nonce', ACEditor.restNonce ); },
			contentType: 'application/json',
			data: JSON.stringify( {
				post_id: ACEditor.postId,
				prompt: prompt,
				html: editors.html ? editors.html.ed.getValue() : '',
				css: editors.css ? editors.css.ed.getValue() : '',
				js: editors.js ? editors.js.ed.getValue() : ''
			} )
		} ).done( function ( res ) {
			setStatus( '' );
			if ( res.trace ) {
				res.trace.forEach( function ( t ) {
					appendTrace( '🔧 ' + t.tool + ( t.tool === 'read_theme_file' && t.args && t.args.path ? ': ' + t.args.path : '' ) );
				} );
			}
			if ( ! res.ok ) { appendTrace( '⚠ ' + ( res.error || ACEditor.i18n.error ) ); return; }
			if ( res.explanation ) { $( '#ac-ai-results' ).append( $( '<p class="ac-ai-explain"></p>' ).text( res.explanation ) ); }

			var edits = res.edits || {};
			var any = false;
			[ 'html', 'css', 'js' ].forEach( function ( k ) {
				if ( typeof edits[ k ] === 'string' ) {
					if ( renderDiff( k, edits[ k ] ) ) { any = true; }
				}
			} );
			if ( ! any ) { appendTrace( ACEditor.i18n.noChange ); }
		} ).fail( function ( xhr ) {
			setStatus( '' );
			appendTrace( '⚠ ' + ACEditor.i18n.error + ' (' + xhr.status + ')' );
		} ).always( function () {
			$btn.prop( 'disabled', false );
		} );
	} );

} )( jQuery );
