# Monaco Editor + CPT Groups Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a tabbed Monaco (VS Code) editor to the Popups/Blocks/Mega Menu code metaboxes, and add a non-public category-style "Group" taxonomy to those three plus Code Snippets and Galleries.

**Architecture:** Two shared core components — `Anchor_Monaco` (a front-end CodeMirror→Monaco swap driven by a `data-anchor-monaco` JSON config on the existing textareas) and `Anchor_Groups` (registers a non-public hierarchical taxonomy + list filter + bulk-assign). Modules opt in with a one-line call each; server-side `save_post` and rendering are untouched.

**Tech Stack:** Raw PHP + jQuery (no build tools), Monaco from the jsDelivr CDN AMD loader (`monaco-editor@0.52.2`), WordPress Settings/Taxonomy APIs.

## Global Constraints

- **No build tools** — raw PHP/CSS/JS, no transpilation/bundling. New JS is hand-written ES5-compatible jQuery IIFE.
- **No automated test suite** — verification is `php -l` / `node --check` syntax checks plus explicit manual steps in a WordPress admin. There is no PHPUnit.
- **Asset URLs:** use `Anchor_Asset_Loader::url( 'path/from/plugin/root' )` (existing helper used by every module), never `plugin_dir_url(__FILE__)`.
- **Options:** any `update_option()` passes `autoload=false` as the 3rd arg.
- **Text domain:** `'anchor-schema'` for translatable strings.
- **AJAX/asset prefixes:** module-prefixed (`up_`, `ab_`, `mm_`, etc.).
- **Admin assets only on the relevant CPT edit screen** — guard on `$hook` ∈ {`post.php`,`post-new.php`} and post type.
- **Monaco loaded from CDN only on those CPT edit screens, never on the front end.**
- **Group taxonomy flags (verbatim):** `public=false`, `publicly_queryable=false`, `rewrite=false`, `query_var=false`, `show_ui=true`, `show_admin_column=true`, `show_in_quick_edit=true`, `show_in_rest=false`, `hierarchical=true`.

---

## File Structure

**New:**
- `includes/class-anchor-monaco.php` — `Anchor_Monaco::enqueue( $cpt )` static helper.
- `assets/anchor-monaco.js` — shared glue (tabs, Monaco mount, format, media insert, undo persistence).
- `assets/anchor-monaco.css` — toolbar/tab/host styling.
- `includes/class-anchor-groups.php` — `Anchor_Groups::register( $tax, $cpt, $labels )` static helper.

**Modified:**
- `anchor-tools.php` — require the two new core classes; version bump.
- `anchor-blocks/anchor-blocks.php` + `anchor-blocks/assets/admin.js`
- `anchor-mega-menu/anchor-mega-menu.php` + `anchor-mega-menu/admin.js`
- `anchor-universal-popups/anchor-universal-popups.php` + `anchor-universal-popups/assets/admin.js`
- `anchor-code-snippets/anchor-code-snippets.php`
- `anchor-gallery/anchor-gallery.php`

---

## Task 1: `Anchor_Monaco` helper + shared assets

**Files:**
- Create: `includes/class-anchor-monaco.php`
- Create: `assets/anchor-monaco.js`
- Create: `assets/anchor-monaco.css`
- Modify: `anchor-tools.php` (require the class during core-class loading)

**Interfaces:**
- Produces: `Anchor_Monaco::enqueue( string $cpt ): void` — registers/enqueues the Monaco loader, `anchor-monaco` script (handle: `anchor-monaco`), and `anchor-monaco` style; localizes `AnchorMonaco` JS object `{ monacoBase, mediaTitle, mediaBtn, postId }`. Sets nothing server-side beyond enqueues.
- Produces (JS): global `window.AnchorMonaco` truthy object set synchronously when `anchor-monaco.js` loads; module scripts depending on handle `anchor-monaco` may read it to skip their own CodeMirror init.
- Produces (markup contract): a wrapper `<div class="anchor-monaco" data-anchor-monaco='[{"id","label","lang"},...]'>` containing the listed `<textarea>`s; the glue hides the textareas, builds a toolbar + stacked Monaco panes, writes each editor's value back to its textarea on change, and dispatches a native `input` event on the textarea after each write.

- [ ] **Step 1: Create `includes/class-anchor-monaco.php`**

```php
<?php
/**
 * Shared Monaco editor loader for Anchor Tools code metaboxes.
 *
 * Modules that want a tabbed Monaco editor call Anchor_Monaco::enqueue( CPT )
 * from their admin_enqueue_scripts handler (already guarded to the CPT edit
 * screen) and wrap their code textareas in:
 *   <div class="anchor-monaco" data-anchor-monaco='[{"id":"x","label":"HTML","lang":"html"}]'>
 *
 * @package AnchorTools
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Anchor_Monaco {

	const VERSION      = '1.0.0';
	const MONACO_VER   = '0.52.2';
	const MONACO_BASE  = 'https://cdn.jsdelivr.net/npm/monaco-editor@0.52.2/min';

	/**
	 * Enqueue Monaco loader + glue on the current admin screen.
	 * Caller is responsible for restricting to the right CPT/post.php screen.
	 */
	public static function enqueue( $cpt ) {
		wp_enqueue_media();

		wp_enqueue_script(
			'anchor-monaco-loader',
			self::MONACO_BASE . '/vs/loader.js',
			array(),
			self::MONACO_VER,
			true
		);

		wp_enqueue_style(
			'anchor-monaco',
			Anchor_Asset_Loader::url( 'assets/anchor-monaco.css' ),
			array(),
			self::VERSION
		);

		wp_enqueue_script(
			'anchor-monaco',
			Anchor_Asset_Loader::url( 'assets/anchor-monaco.js' ),
			array( 'jquery', 'anchor-monaco-loader' ),
			self::VERSION,
			true
		);

		$post_id = isset( $GLOBALS['post'] ) && $GLOBALS['post'] ? (int) $GLOBALS['post']->ID : 0;

		wp_localize_script(
			'anchor-monaco',
			'AnchorMonaco',
			array(
				'monacoBase' => self::MONACO_BASE,
				'mediaTitle' => __( 'Select or upload media', 'anchor-schema' ),
				'mediaBtn'   => __( 'Use this URL', 'anchor-schema' ),
				'postId'     => $post_id,
				'cpt'        => (string) $cpt,
			)
		);
	}
}
```

- [ ] **Step 2: Create `assets/anchor-monaco.css`**

```css
.anchor-monaco { margin: 0 0 8px; }
.anchor-monaco-toolbar {
	display: flex; align-items: center; flex-wrap: wrap; gap: 6px;
	padding: 6px; background: #1e1e1e; border: 1px solid #0e0e0e;
	border-bottom: 0; border-radius: 6px 6px 0 0;
}
.anchor-monaco-tabs { display: flex; gap: 2px; margin-right: auto; }
.anchor-monaco-tab {
	background: #2d2d2d; color: #ccc; border: 0; cursor: pointer;
	padding: 6px 14px; font-size: 12px; border-radius: 4px 4px 0 0;
}
.anchor-monaco-tab.is-active { background: #1e1e1e; color: #fff; box-shadow: inset 0 -2px 0 #2271b1; }
.anchor-monaco-toolbar .button { display: inline-flex; align-items: center; gap: 4px; }
.anchor-monaco-host {
	height: 480px; border: 1px solid #0e0e0e; border-radius: 0 0 6px 6px; overflow: hidden;
}
.anchor-monaco-pane { height: 100%; }
.anchor-monaco-hidden { display: none !important; }
```

- [ ] **Step 3: Create `assets/anchor-monaco.js`**

```javascript
/* global require, AnchorMonaco, jQuery, wp, monaco */
( function ( $ ) {
	'use strict';

	// Set synchronously so module scripts (depending on this handle) can skip
	// their own CodeMirror init before their ready handler runs.
	window.AnchorMonaco = window.AnchorMonaco || {};
	window.AnchorMonaco.active = true;

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

		// Hide the source textareas.
		fields.forEach( function ( f ) {
			var ta = document.getElementById( f.id );
			if ( ta ) { ta.classList.add( 'anchor-monaco-hidden' ); }
		} );

		var editors = {};
		var active = fields[ 0 ].id;
		var restored = false;

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
		var wraps = document.querySelectorAll( '.anchor-monaco[data-anchor-monaco]' );
		for ( var i = 0; i < wraps.length; i++ ) { initWrapper( wraps[ i ] ); }
	} );

} )( jQuery );
```

- [ ] **Step 4: Require the class in `anchor-tools.php`**

Find where other `includes/class-anchor-*.php` core classes are `require_once`'d (search `require_once` near `Anchor_Asset_Loader` / `class-anchor-`). Add alongside them:

```php
require_once ANCHOR_TOOLS_PLUGIN_DIR . 'includes/class-anchor-monaco.php';
```

- [ ] **Step 5: Syntax-check both PHP and JS**

Run:
```bash
php -l includes/class-anchor-monaco.php
node --check assets/anchor-monaco.js
```
Expected: `No syntax errors detected` for both.

- [ ] **Step 6: Commit**

```bash
git add includes/class-anchor-monaco.php assets/anchor-monaco.js assets/anchor-monaco.css anchor-tools.php
git commit -m "feat(monaco): shared Anchor_Monaco loader + tabbed editor glue"
```

---

## Task 2: Blocks → Monaco

**Files:**
- Modify: `anchor-blocks/anchor-blocks.php` (`render_box_code`, `admin_assets`)
- Modify: `anchor-blocks/assets/admin.js` (guard CodeMirror init)

**Interfaces:**
- Consumes: `Anchor_Monaco::enqueue( 'anchor_block' )`; markup contract from Task 1.
- Produces: nothing for later tasks.

- [ ] **Step 1: Wrap the code fields in `render_box_code()`**

In `anchor-blocks/anchor-blocks.php`, replace the three `wp_enqueue_code_editor` + `wp_enqueue_script('code-editor')` + `wp_enqueue_style('code-editor')` lines (currently lines ~80-84) — delete them. Then wrap the `.ab-fields` block. Change:

```php
        <div class="ab-fields">
```
to:
```php
        <div class="anchor-monaco" data-anchor-monaco='<?php echo esc_attr( wp_json_encode( array(
            array( 'id' => 'ab_html', 'label' => 'HTML', 'lang' => 'html' ),
            array( 'id' => 'ab_css',  'label' => 'CSS',  'lang' => 'css' ),
            array( 'id' => 'ab_js',   'label' => 'JS',   'lang' => 'javascript' ),
        ) ) ); ?>'>
        <div class="ab-fields">
```
and add one extra `</div>` to close `.anchor-monaco` right after the existing `.ab-fields` closing `</div>` (before the `<?php` that ends the method).

- [ ] **Step 2: Enqueue Monaco in `admin_assets()`**

In `anchor-blocks/anchor-blocks.php` `admin_assets()`, locate the `wp_enqueue_script( 'ab-admin', ... )` call (line ~175). Immediately before it add:

```php
            Anchor_Monaco::enqueue( self::CPT );
```
Then in that same `wp_enqueue_script( 'ab-admin', ... )` dependency array, replace `'code-editor'` with `'anchor-monaco'`:
```php
            wp_enqueue_script( 'ab-admin', Anchor_Asset_Loader::url( 'anchor-blocks/assets/admin.js' ), [ 'jquery', 'anchor-monaco', 'anchor-preview' ], self::ASSET_VER, true );
```

- [ ] **Step 3: Guard CodeMirror init in `anchor-blocks/assets/admin.js`**

Open `anchor-blocks/assets/admin.js`. Find the init block (around line 36): `if (window.wp && wp.codeEditor) {`. Change the condition to also require Monaco to be absent:

```javascript
      if (!window.AnchorMonaco && window.wp && wp.codeEditor) {
```

This routes execution to the existing `else` branch (`$('#ab_html, #ab_css, #ab_js').on('input', applyPreviewDebounced);`) when Monaco is active, which Monaco drives via dispatched `input` events.

- [ ] **Step 4: Apply the same guard to the minified `admin.min.js`**

`Anchor_Asset_Loader::url` serves `.min.js` siblings at runtime when present (confirmed: it prefers minified unless `SCRIPT_DEBUG`). `anchor-blocks/assets/admin.min.js` exists, so the guard must be applied there too or it won't take effect. In `anchor-blocks/assets/admin.min.js` find `wp.codeEditor?` and change it to `!window.AnchorMonaco&&wp.codeEditor?` (the minified code uses a ternary `wp&&wp.codeEditor?[...]:[...]`; prefix the condition with `!window.AnchorMonaco&&`).

- [ ] **Step 5: Syntax-check**

Run:
```bash
php -l anchor-blocks/anchor-blocks.php
node --check anchor-blocks/assets/admin.js
```
Expected: `No syntax errors detected`.

- [ ] **Step 6: Manual verification**

In WP admin, edit an Anchor Block. Confirm: dark tabbed editor with HTML/CSS/JS tabs; typing in HTML updates the Live Preview; Format button reindents; "Insert from Media Library" inserts a URL at the cursor; Save persists content; after the save reload, Ctrl/Cmd+Z still undoes.

- [ ] **Step 7: Commit**

```bash
git add anchor-blocks/
git commit -m "feat(blocks): tabbed Monaco editor for HTML/CSS/JS"
```

---

## Task 3: Mega Menu → Monaco

**Files:**
- Modify: `anchor-mega-menu/anchor-mega-menu.php` (`render_box_code` ~line 114, `admin_assets` ~line 304)
- Modify: `anchor-mega-menu/admin.js` (guard CodeMirror init ~line 33)

**Interfaces:**
- Consumes: `Anchor_Monaco::enqueue( 'anchor_mega_snippet' )`; markup contract from Task 1.

- [ ] **Step 1: Wrap the code fields in `render_box_code()`**

Delete the `wp_enqueue_code_editor` (×3) + `wp_enqueue_script('code-editor')` + `wp_enqueue_style('code-editor')` lines (~114-118). Wrap the four textareas (`mm_html`, `mm_global_css`, `mm_css`, `mm_js`, lines ~123-135) in a wrapper. Immediately before the first of those fields' container, open:

```php
        <div class="anchor-monaco" data-anchor-monaco='<?php echo esc_attr( wp_json_encode( array(
            array( 'id' => 'mm_html',       'label' => 'HTML',       'lang' => 'html' ),
            array( 'id' => 'mm_global_css', 'label' => 'Global CSS', 'lang' => 'css' ),
            array( 'id' => 'mm_css',        'label' => 'CSS',        'lang' => 'css' ),
            array( 'id' => 'mm_js',         'label' => 'JS',         'lang' => 'javascript' ),
        ) ) ); ?>'>
```
and close it with `</div>` immediately after the last (`mm_js`) field's container. (Match the existing markup indentation; the wrapper must contain all four `<textarea>` elements.)

- [ ] **Step 2: Enqueue Monaco in `admin_assets()`**

Before the `wp_enqueue_script( 'mm-admin', ... )` call (line ~311) add:
```php
            Anchor_Monaco::enqueue( self::CPT );
```
and change that script's dependency array from `['jquery', 'code-editor', 'anchor-preview']` to `['jquery', 'anchor-monaco', 'anchor-preview']`.

- [ ] **Step 3: Guard CodeMirror init in `anchor-mega-menu/admin.js`**

Change line ~33 `if (window.wp && wp.codeEditor) {` to:
```javascript
    if (!window.AnchorMonaco && window.wp && wp.codeEditor) {
```
The existing `else` fallback (`$('#mm_html, #mm_global_css, #mm_css, #mm_js').on('input', applyPreviewDebounced);`) handles the Monaco-active case. Then apply the same guard to `anchor-mega-menu/admin.min.js` (find `wp.codeEditor?` / `wp.codeEditor)` and prefix the condition with `!window.AnchorMonaco&&`), since the minified sibling is served at runtime.

- [ ] **Step 4: Syntax-check**

```bash
php -l anchor-mega-menu/anchor-mega-menu.php
node --check anchor-mega-menu/admin.js
node --check anchor-mega-menu/admin.min.js
```
Expected: `No syntax errors detected`.

- [ ] **Step 5: Manual verification**

Edit a Mega Menu snippet: confirm 4 tabs (HTML / Global CSS / CSS / JS), live preview updates, format + media buttons work, save + undo-after-reload work.

- [ ] **Step 6: Commit**

```bash
git add anchor-mega-menu/
git commit -m "feat(mega-menu): tabbed Monaco editor for HTML/Global CSS/CSS/JS"
```

---

## Task 4: Popups → Monaco

**Files:**
- Modify: `anchor-universal-popups/anchor-universal-popups.php` (`render_box_code` ~line 444, `admin_assets` ~line 993)
- Modify: `anchor-universal-popups/assets/admin.js` (guard CodeMirror init)

**Interfaces:**
- Consumes: `Anchor_Monaco::enqueue( 'anchor_popup' )`; markup contract from Task 1.

- [ ] **Step 1: Confirm the popups admin.js CodeMirror init pattern**

```bash
grep -n "codeEditor\|initialize\|on('input'\|up_html\|up_css\|up_js" anchor-universal-popups/assets/admin.js
```
Note the exact `if (window.wp && wp.codeEditor)` line and the `else` fallback selector — they mirror Blocks/Mega.

- [ ] **Step 2: Wrap the code fields in `render_box_code()`**

Delete the `wp_enqueue_code_editor` (×3) + `wp_enqueue_script('code-editor')` + `wp_enqueue_style('code-editor')` lines (~444-448). Wrap the three textareas `up_html`/`up_css`/`up_js` (lines ~609-618) in:

```php
        <div class="anchor-monaco" data-anchor-monaco='<?php echo esc_attr( wp_json_encode( array(
            array( 'id' => 'up_html', 'label' => 'HTML', 'lang' => 'html' ),
            array( 'id' => 'up_css',  'label' => 'CSS',  'lang' => 'css' ),
            array( 'id' => 'up_js',   'label' => 'JS',   'lang' => 'javascript' ),
        ) ) ); ?>'>
```
…opening immediately before the `up_html` label/field container and closing with `</div>` immediately after the `up_js` field container. Leave the `up_shortcode` field and all other inputs outside the wrapper, untouched.

- [ ] **Step 3: Enqueue Monaco in `admin_assets()`**

Popups already calls `wp_enqueue_media()`. Before the `wp_enqueue_script( 'up-admin', ... )` (line ~1002) add:
```php
            Anchor_Monaco::enqueue( self::CPT );
```
and change its deps from `['jquery','code-editor','anchor-preview']` to `['jquery','anchor-monaco','anchor-preview']`.

- [ ] **Step 4: Guard CodeMirror init in `anchor-universal-popups/assets/admin.js`**

Change the `if (window.wp && wp.codeEditor){` line (line ~139, identified in Step 1) to:
```javascript
    if (!window.AnchorMonaco && window.wp && wp.codeEditor){
```
Then apply the same guard to `anchor-universal-popups/assets/admin.min.js` (find `wp.codeEditor?`/`wp.codeEditor)` and prefix the condition with `!window.AnchorMonaco&&`), since the minified sibling is served at runtime.

- [ ] **Step 5: Syntax-check**

```bash
php -l anchor-universal-popups/anchor-universal-popups.php
node --check anchor-universal-popups/assets/admin.js
node --check anchor-universal-popups/assets/admin.min.js
```
Expected: `No syntax errors detected`.

- [ ] **Step 6: Manual verification**

Edit a Popup: 3 tabs, live preview updates, format + media insert work, save + undo-after-reload work. Confirm the Shortcode field and side settings are unaffected.

- [ ] **Step 7: Commit**

```bash
git add anchor-universal-popups/
git commit -m "feat(popups): tabbed Monaco editor for HTML/CSS/JS"
```

---

## Task 5: `Anchor_Groups` helper

**Files:**
- Create: `includes/class-anchor-groups.php`
- Modify: `anchor-tools.php` (require the class)

**Interfaces:**
- Produces: `Anchor_Groups::register( string $tax, string $cpt, array $labels ): void` — registers the non-public taxonomy with the Global-Constraints flags, then hooks `restrict_manage_posts` (filter dropdown), `parse_query` (apply filter), `bulk_actions-edit-{cpt}` (add "Add to group →" actions), and `handle_bulk_actions-edit-{cpt}` (assign). Idempotent per (tax,cpt).

- [ ] **Step 1: Create `includes/class-anchor-groups.php`**

```php
<?php
/**
 * Shared non-public "Group" taxonomy for Anchor Tools CPTs.
 *
 * Registers a hierarchical, category-style taxonomy with no public archive
 * pages or sitemap exposure, plus a list-table filter dropdown and a bulk
 * "Add to group" action. Quick Edit + admin column come from core via the
 * registration flags.
 *
 * @package AnchorTools
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Anchor_Groups {

	/** @var array<string,string> taxonomy => cpt, for hooked callbacks. */
	private static $map = array();

	public static function register( $taxonomy, $cpt, $labels = array() ) {
		$labels = wp_parse_args( $labels, array(
			'name'          => __( 'Groups', 'anchor-schema' ),
			'singular_name' => __( 'Group', 'anchor-schema' ),
			'menu_name'     => __( 'Groups', 'anchor-schema' ),
			'all_items'     => __( 'All Groups', 'anchor-schema' ),
			'edit_item'     => __( 'Edit Group', 'anchor-schema' ),
			'add_new_item'  => __( 'Add New Group', 'anchor-schema' ),
			'new_item_name' => __( 'New Group Name', 'anchor-schema' ),
			'search_items'  => __( 'Search Groups', 'anchor-schema' ),
		) );

		register_taxonomy( $taxonomy, $cpt, array(
			'labels'             => $labels,
			'hierarchical'       => true,
			'public'             => false,
			'publicly_queryable' => false,
			'rewrite'            => false,
			'query_var'          => false,
			'show_ui'            => true,
			'show_in_menu'       => true,
			'show_admin_column'  => true,
			'show_in_quick_edit' => true,
			'show_in_rest'       => false,
		) );

		if ( isset( self::$map[ $taxonomy ] ) ) { return; }
		self::$map[ $taxonomy ] = $cpt;

		add_action( 'restrict_manage_posts', array( __CLASS__, 'render_filter' ) );
		add_filter( 'parse_query',           array( __CLASS__, 'apply_filter' ) );
		add_filter( "bulk_actions-edit-{$cpt}",        array( __CLASS__, 'bulk_actions' ) );
		add_filter( "handle_bulk_actions-edit-{$cpt}", array( __CLASS__, 'handle_bulk' ), 10, 3 );
	}

	/** Group <select> at the top of the list table. */
	public static function render_filter( $post_type ) {
		$taxonomy = array_search( $post_type, self::$map, true );
		if ( ! $taxonomy ) { return; }
		$terms = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false ) );
		if ( is_wp_error( $terms ) || empty( $terms ) ) { return; }
		$current = isset( $_GET[ $taxonomy ] ) ? sanitize_text_field( wp_unslash( $_GET[ $taxonomy ] ) ) : '';
		echo '<select name="' . esc_attr( $taxonomy ) . '">';
		echo '<option value="">' . esc_html__( 'All Groups', 'anchor-schema' ) . '</option>';
		foreach ( $terms as $t ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $t->slug ),
				selected( $current, $t->slug, false ),
				esc_html( $t->name )
			);
		}
		echo '</select>';
	}

	/** Translate the selected term slug into a taxonomy query on the list screen. */
	public static function apply_filter( $query ) {
		global $pagenow;
		if ( 'edit.php' !== $pagenow || ! is_admin() || ! $query->is_main_query() ) { return; }
		$post_type = isset( $query->query_vars['post_type'] ) ? $query->query_vars['post_type'] : '';
		$taxonomy  = array_search( $post_type, self::$map, true );
		if ( ! $taxonomy ) { return; }
		if ( ! empty( $_GET[ $taxonomy ] ) ) {
			$query->query_vars['tax_query'] = array( array(
				'taxonomy' => $taxonomy,
				'field'    => 'slug',
				'terms'    => sanitize_text_field( wp_unslash( $_GET[ $taxonomy ] ) ),
			) );
		}
	}

	/** Add one "Add to: <group>" bulk action per existing term. */
	public static function bulk_actions( $actions ) {
		$screen = get_current_screen();
		if ( ! $screen ) { return $actions; }
		$taxonomy = array_search( $screen->post_type, self::$map, true );
		if ( ! $taxonomy ) { return $actions; }
		$terms = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false ) );
		if ( is_wp_error( $terms ) ) { return $actions; }
		foreach ( $terms as $t ) {
			$actions[ 'anchor_group_add_' . $t->term_id ] = sprintf(
				/* translators: %s group name */
				__( 'Add to group: %s', 'anchor-schema' ),
				$t->name
			);
		}
		return $actions;
	}

	/** Assign the selected posts to the chosen group term. */
	public static function handle_bulk( $redirect, $action, $post_ids ) {
		if ( 0 !== strpos( $action, 'anchor_group_add_' ) ) { return $redirect; }
		$term_id = (int) substr( $action, strlen( 'anchor_group_add_' ) );
		$term    = get_term( $term_id );
		if ( ! $term || is_wp_error( $term ) ) { return $redirect; }
		$taxonomy = $term->taxonomy;
		if ( ! in_array( $taxonomy, array_keys( self::$map ), true ) ) { return $redirect; }
		foreach ( (array) $post_ids as $pid ) {
			if ( current_user_can( 'edit_post', $pid ) ) {
				wp_set_object_terms( (int) $pid, $term_id, $taxonomy, true );
			}
		}
		return add_query_arg( 'anchor_grouped', count( (array) $post_ids ), $redirect );
	}
}
```

- [ ] **Step 2: Require the class in `anchor-tools.php`**

Next to the Task 1 `require_once` line add:
```php
require_once ANCHOR_TOOLS_PLUGIN_DIR . 'includes/class-anchor-groups.php';
```

- [ ] **Step 3: Syntax-check**

```bash
php -l includes/class-anchor-groups.php
```
Expected: `No syntax errors detected`.

- [ ] **Step 4: Commit**

```bash
git add includes/class-anchor-groups.php anchor-tools.php
git commit -m "feat(groups): shared non-public Group taxonomy helper"
```

---

## Task 6: Wire Groups into all five modules

**Files:**
- Modify: `anchor-blocks/anchor-blocks.php`
- Modify: `anchor-mega-menu/anchor-mega-menu.php`
- Modify: `anchor-universal-popups/anchor-universal-popups.php`
- Modify: `anchor-code-snippets/anchor-code-snippets.php`
- Modify: `anchor-gallery/anchor-gallery.php`

**Interfaces:**
- Consumes: `Anchor_Groups::register( self::TAX, self::CPT, [...] )` from Task 5.

For each module: add a `const TAX`, an `init`-hooked `register_groups()` method, and register the hook in the constructor. The taxonomy must register on `init` at the same or later priority as the CPT (the CPT registers on `init`; registering the taxonomy in a separate `init` callback added after the CPT callback is fine — WordPress runs them in registration order, but taxonomies can register independently of CPT order as long as both fire on `init`).

- [ ] **Step 1: Blocks**

In `anchor-blocks/anchor-blocks.php`, add after `const CPT = 'anchor_block';`:
```php
    const TAX          = 'anchor_block_group';
```
In the constructor, after `add_action( 'init', [ $this, 'register_cpt' ] );` add:
```php
        add_action( 'init', [ $this, 'register_groups' ] );
```
After the `register_cpt()` method add:
```php
    public function register_groups() {
        Anchor_Groups::register( self::TAX, self::CPT, [
            'name'          => __( 'Block Groups', 'anchor-schema' ),
            'singular_name' => __( 'Block Group', 'anchor-schema' ),
            'menu_name'     => __( 'Groups', 'anchor-schema' ),
        ] );
    }
```

- [ ] **Step 2: Mega Menu**

In `anchor-mega-menu/anchor-mega-menu.php`, after `const CPT = 'anchor_mega_snippet';`:
```php
    const TAX = 'anchor_mega_group';
```
In the constructor, after the `add_action('init', [$this, 'register_cpt'...])` (or equivalent CPT registration hook — confirm the method name with `grep -n "register_post_type\|add_action('init'" anchor-mega-menu/anchor-mega-menu.php`) add:
```php
        add_action('init', [$this, 'register_groups']);
```
Add the method:
```php
    public function register_groups() {
        Anchor_Groups::register( self::TAX, self::CPT, [
            'name'          => __( 'Mega Menu Groups', 'anchor-schema' ),
            'singular_name' => __( 'Mega Menu Group', 'anchor-schema' ),
            'menu_name'     => __( 'Groups', 'anchor-schema' ),
        ] );
    }
```

- [ ] **Step 3: Popups**

In `anchor-universal-popups/anchor-universal-popups.php`, after `const CPT = 'anchor_popup';`:
```php
    const TAX = 'anchor_popup_group';
```
Constructor: add `add_action('init', [$this, 'register_groups']);` next to the CPT init hook. Method:
```php
    public function register_groups() {
        Anchor_Groups::register( self::TAX, self::CPT, [
            'name'          => __( 'Popup Groups', 'anchor-schema' ),
            'singular_name' => __( 'Popup Group', 'anchor-schema' ),
            'menu_name'     => __( 'Groups', 'anchor-schema' ),
        ] );
    }
```

- [ ] **Step 4: Code Snippets**

In `anchor-code-snippets/anchor-code-snippets.php`, after `const CPT = 'anchor_snippet';`:
```php
    const TAX = 'anchor_snippet_group';
```
Confirm the CPT init hook (`grep -n "add_action.*init\|register_post_type" anchor-code-snippets/anchor-code-snippets.php`) and add next to it:
```php
        add_action( 'init', [ $this, 'register_groups' ] );
```
Method:
```php
    public function register_groups() {
        Anchor_Groups::register( self::TAX, self::CPT, [
            'name'          => __( 'Snippet Groups', 'anchor-schema' ),
            'singular_name' => __( 'Snippet Group', 'anchor-schema' ),
            'menu_name'     => __( 'Groups', 'anchor-schema' ),
        ] );
    }
```

- [ ] **Step 5: Galleries**

In `anchor-gallery/anchor-gallery.php`, after `const CPT = 'anchor_gallery';`:
```php
    const TAX        = 'anchor_gallery_group';
```
Confirm the CPT init hook and add next to it `add_action('init', [$this, 'register_groups']);`. Method:
```php
    public function register_groups() {
        Anchor_Groups::register( self::TAX, self::CPT, [
            'name'          => __( 'Gallery Groups', 'anchor-schema' ),
            'singular_name' => __( 'Gallery Group', 'anchor-schema' ),
            'menu_name'     => __( 'Groups', 'anchor-schema' ),
        ] );
    }
```

- [ ] **Step 6: Syntax-check all five**

```bash
php -l anchor-blocks/anchor-blocks.php
php -l anchor-mega-menu/anchor-mega-menu.php
php -l anchor-universal-popups/anchor-universal-popups.php
php -l anchor-code-snippets/anchor-code-snippets.php
php -l anchor-gallery/anchor-gallery.php
```
Expected: `No syntax errors detected` for each.

- [ ] **Step 7: Manual verification**

For each of the five CPT list screens: a "Groups" submenu appears under the CPT menu; a Group column shows on the list table; the filter dropdown appears and filters; Quick Edit shows group checkboxes; the post-edit screen has a Groups metabox with "+ Add New Group"; selecting several rows + a "Add to group: X" bulk action assigns them. Confirm visiting `/?anchor_block_group=foo` does NOT render an archive, and `wp-sitemap.xml` (or the SEO plugin sitemap index) lists no group taxonomy.

- [ ] **Step 8: Commit**

```bash
git add anchor-blocks/ anchor-mega-menu/ anchor-universal-popups/ anchor-code-snippets/ anchor-gallery/
git commit -m "feat(groups): enable Group taxonomy on popups, blocks, mega menu, snippets, galleries"
```

---

## Task 7: Version bump

**Files:**
- Modify: `anchor-tools.php` (plugin header `Version:`)

- [ ] **Step 1: Bump the version**

In `anchor-tools.php` change `* Version: 3.8.06` to `* Version: 3.9.00` (minor feature bump). If a version constant exists nearby (`grep -n "ANCHOR_TOOLS_VERSION\|3.8.06" anchor-tools.php`), update it to match.

- [ ] **Step 2: Commit**

```bash
git add anchor-tools.php
git commit -m "chore: bump version to 3.9.00 (Monaco editor + CPT groups)"
```

---

## Self-Review Notes

- **Spec coverage:** Monaco helper (T1), per-module Monaco (T2-4), Groups helper (T5), per-module groups (T6), version bump (T7). All spec sections mapped.
- **CodeMirror→Monaco compatibility** handled identically in T2/T3/T4 via the `!window.AnchorMonaco` guard + dispatched `input` events, preserving each module's live preview.
- **No-public-archive requirement** enforced centrally in T5's registration flags.
- **Open verification points** (flagged for the executor, not blockers): (a) whether `Anchor_Asset_Loader::url` serves `.min.js` — resolved in T2 Step 4; (b) exact `if (wp.codeEditor)` line numbers drift per file — each task greps to confirm before editing.
