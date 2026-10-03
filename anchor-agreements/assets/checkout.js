/* Anchor Agreements: checkout signing modal. No dependencies beyond jQuery (for WooCommerce's events). */
( function ( $ ) {
	'use strict';
	const cfg = window.aagrCheckout;
	if ( ! cfg ) {
		return;
	}
	const t = cfg.i18n;
	let modal = null, queue = [], index = 0, pad = null, state = { method: 'draw', font: '' };

	function el( tag, attrs, html ) {
		const n = document.createElement( tag );
		Object.entries( attrs || {} ).forEach( ( [ k, v ] ) => n.setAttribute( k, v ) );
		if ( html !== undefined ) n.innerHTML = html;
		return n;
	}

	/* ---- Signature pad: pointer events, DPR-aware, keeps strokes on resize. ---- */
	function Pad( canvas, onChange ) {
		const ctx = canvas.getContext( '2d' );
		let strokes = [], current = null;
		function size() {
			const r = canvas.getBoundingClientRect(), dpr = Math.min( window.devicePixelRatio || 1, 2000 / r.width, 1000 / r.height ); // server caps signature PNGs at 2000x1000
			canvas.width = Math.round( r.width * dpr );
			canvas.height = Math.round( r.height * dpr );
			ctx.setTransform( dpr, 0, 0, dpr, 0, 0 );
			redraw();
		}
		function point( e ) {
			const r = canvas.getBoundingClientRect();
			return { x: e.clientX - r.left, y: e.clientY - r.top };
		}
		function redraw() {
			ctx.clearRect( 0, 0, canvas.width, canvas.height );
			ctx.lineWidth = 2.4; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = '#101828';
			strokes.forEach( ( s ) => {
				ctx.beginPath();
				s.forEach( ( p, i ) => ( i ? ctx.lineTo( p.x, p.y ) : ctx.moveTo( p.x, p.y ) ) );
				if ( s.length === 1 ) ctx.lineTo( s[ 0 ].x + 0.1, s[ 0 ].y + 0.1 );
				ctx.stroke();
			} );
		}
		canvas.addEventListener( 'pointerdown', ( e ) => {
			e.preventDefault();
			canvas.setPointerCapture( e.pointerId );
			current = [ point( e ) ];
			strokes.push( current );
			redraw();
		} );
		canvas.addEventListener( 'pointermove', ( e ) => {
			if ( ! current ) return;
			e.preventDefault();
			current.push( point( e ) );
			redraw();
		} );
		const end = () => { if ( current ) { current = null; onChange(); } };
		canvas.addEventListener( 'pointerup', end );
		canvas.addEventListener( 'pointercancel', end );
		window.addEventListener( 'resize', size );
		this.size = size;
		this.clear = () => { strokes = []; redraw(); onChange(); };
		this.isEmpty = () => strokes.length === 0;
		this.toDataURL = () => canvas.toDataURL( 'image/png' );
		this.destroy = () => window.removeEventListener( 'resize', size );
	}

	/* ---- Generated signature: render the name in the chosen font onto a canvas. ---- */
	function renderGenerated( name, family ) {
		const c = document.createElement( 'canvas' ), w = 900, h = 220;
		c.width = w; c.height = h;
		const ctx = c.getContext( '2d' );
		let size = 110;
		ctx.fillStyle = '#101828'; ctx.textBaseline = 'middle';
		do { ctx.font = size + 'px "' + family + '"'; size -= 4; } while ( ctx.measureText( name ).width > w - 40 && size > 24 );
		ctx.fillText( name, 20, h / 2 );
		return c.toDataURL( 'image/png' );
	}

	function build() {
		modal = el( 'div', { class: 'aagr-modal', role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': 'aagr-title', hidden: '' } );
		modal.innerHTML =
			'<div class="aagr-modal__dialog">' +
			'<div class="aagr-modal__head"><div><h2 id="aagr-title"></h2><span class="aagr-modal__step"></span> <span class="aagr-modal__version"></span></div>' +
			'<button type="button" class="aagr-modal__close" aria-label="' + t.close + '">&times;</button></div>' +
			'<div class="aagr-modal__body"><div class="aagr-modal__doc" tabindex="0"></div>' +
			'<label class="aagr-modal__field">' + t.name + '<input type="text" name="aagr-name" autocomplete="name" maxlength="120" /></label>' +
			'<div class="aagr-tabs" role="tablist"><button type="button" class="aagr-tab" data-tab="draw" role="tab" aria-selected="true">' + t.draw + '</button>' +
			'<button type="button" class="aagr-tab" data-tab="generate" role="tab" aria-selected="false">' + t.generate + '</button></div>' +
			'<div class="aagr-panel" data-panel="draw"><div class="aagr-pad-wrap"><canvas class="aagr-pad"></canvas><span class="aagr-pad-line"></span>' +
			'<button type="button" class="aagr-pad-clear">' + t.clear + '</button></div></div>' +
			'<div class="aagr-panel" data-panel="generate" hidden><div class="aagr-fonts"></div></div></div>' +
			'<div class="aagr-modal__foot"><label class="aagr-modal__consent"><input type="checkbox" name="aagr-consent" /> <span>' + t.consent + '</span></label>' +
			'<div class="aagr-modal__error" role="alert" hidden></div>' +
			'<button type="button" class="aagr-modal__sign" disabled>' + t.sign + '</button>' +
			'<button type="button" class="aagr-modal__next">' + t.next + '</button></div></div>';
		document.body.appendChild( modal );

		const fontsBox = modal.querySelector( '.aagr-fonts' );
		cfg.fonts.forEach( ( f ) => {
			const b = el( 'button', { type: 'button', class: 'aagr-font-choice', 'data-font': f.key, 'aria-pressed': 'false', style: 'font-family:"' + f.family + '"' } );
			fontsBox.appendChild( b );
		} );

		modal.addEventListener( 'click', ( e ) => {
			const tab = e.target.closest( '.aagr-tab' );
			if ( tab ) return selectTab( tab.dataset.tab );
			const font = e.target.closest( '.aagr-font-choice' );
			if ( font ) {
				state.font = font.dataset.font;
				modal.querySelectorAll( '.aagr-font-choice' ).forEach( ( b ) => b.setAttribute( 'aria-pressed', String( b === font ) ) );
				return validate();
			}
			if ( e.target.closest( '.aagr-pad-clear' ) ) return pad.clear();
			if ( e.target.closest( '.aagr-modal__close' ) || e.target === modal ) return close();
			if ( e.target.closest( '.aagr-modal__sign' ) ) return submit();
			if ( e.target.closest( '.aagr-modal__next' ) ) { // review mode: step through every document
				if ( index < queue.length - 1 ) { index++; return show(); }
				return close();
			}
		} );
		modal.addEventListener( 'input', ( e ) => {
			if ( e.target.name === 'aagr-name' ) {
				modal.querySelectorAll( '.aagr-font-choice' ).forEach( ( b ) => { b.textContent = e.target.value; } );
			}
			validate();
		} );
		modal.addEventListener( 'change', validate );
		document.addEventListener( 'keydown', ( e ) => { if ( e.key === 'Escape' && ! modal.hidden ) close(); } );
		pad = new Pad( modal.querySelector( '.aagr-pad' ), validate );
	}

	function selectTab( name ) {
		state.method = name;
		modal.querySelectorAll( '.aagr-tab' ).forEach( ( b ) => b.setAttribute( 'aria-selected', String( b.dataset.tab === name ) ) );
		modal.querySelectorAll( '.aagr-panel' ).forEach( ( p ) => { p.hidden = p.dataset.panel !== name; } );
		if ( name === 'draw' ) pad.size();
		validate();
	}

	function nameValue() {
		return modal.querySelector( 'input[name="aagr-name"]' ).value.trim();
	}

	function validate() {
		const hasSig = state.method === 'draw' ? ! pad.isEmpty() : !! state.font;
		const ok = nameValue() !== '' && hasSig && modal.querySelector( 'input[name="aagr-consent"]' ).checked;
		modal.querySelector( '.aagr-modal__sign' ).disabled = ! ok;
	}

	function show() {
		const doc = queue[ index ];
		modal.querySelector( '#aagr-title' ).textContent = doc.dataset.title;
		modal.querySelector( '.aagr-modal__step' ).textContent = queue.length > 1 ? t.step.replace( '%1$d', index + 1 ).replace( '%2$d', queue.length ) : '';
		modal.querySelector( '.aagr-modal__version' ).textContent = t.version.replace( '%s', doc.dataset.versionDate );
		modal.querySelector( '.aagr-modal__doc' ).innerHTML = doc.innerHTML; // server-side wp_kses_post'd
		modal.querySelector( '.aagr-modal__doc' ).scrollTop = 0;
		modal.querySelector( 'input[name="aagr-consent"]' ).checked = false;
		modal.querySelector( '.aagr-modal__error' ).hidden = true;
		state.font = '';
		modal.querySelectorAll( '.aagr-font-choice' ).forEach( ( b ) => b.setAttribute( 'aria-pressed', 'false' ) );
		const next = modal.querySelector( '.aagr-modal__next' );
		next.textContent = index < queue.length - 1 ? t.next : t.close;
		pad.clear();
		selectTab( state.method );
	}

	function open() {
		const docs = Array.from( document.querySelectorAll( '.aagr-checkout template.aagr-doc' ) );
		queue = docs.filter( ( d ) => d.dataset.signed !== '1' );
		const review = ! queue.length; // all signed: read-only review of the documents
		if ( review ) queue = docs;
		if ( ! queue.length ) return;
		if ( ! modal ) build();
		modal.classList.toggle( 'aagr-modal--review', review );
		const nameInput = modal.querySelector( 'input[name="aagr-name"]' );
		if ( ! nameInput.value ) {
			const f = ( $( '#billing_first_name' ).val() || '' ).trim(), l = ( $( '#billing_last_name' ).val() || '' ).trim();
			nameInput.value = ( f + ' ' + l ).trim();
		}
		modal.querySelectorAll( '.aagr-font-choice' ).forEach( ( b ) => { b.textContent = nameInput.value; } );
		index = 0;
		modal.hidden = false;
		document.documentElement.classList.add( 'aagr-lock' );
		show();
		modal.querySelector( '.aagr-modal__close' ).focus();
	}

	function close() {
		modal.hidden = true;
		document.documentElement.classList.remove( 'aagr-lock' );
	}

	async function submit() {
		const btn = modal.querySelector( '.aagr-modal__sign' ), err = modal.querySelector( '.aagr-modal__error' );
		const doc = queue[ index ], family = ( cfg.fonts.find( ( f ) => f.key === state.font ) || {} ).family;
		btn.disabled = true;
		try {
			// Load the font BEFORE rendering the canvas, or the name is drawn in a fallback face.
			if ( state.method === 'generate' ) await document.fonts.load( '60px "' + family + '"' );
			const body = new FormData();
			body.append( 'nonce', cfg.nonce );
			body.append( 'agreement_id', doc.dataset.agreementId );
			body.append( 'name', nameValue() );
			body.append( 'method', state.method );
			body.append( 'font', state.method === 'generate' ? state.font : '' );
			body.append( 'image', state.method === 'draw' ? pad.toDataURL() : renderGenerated( nameValue(), family ) );
			body.append( 'consent', '1' );
			const res = await fetch( cfg.endpoint, { method: 'POST', body, credentials: 'same-origin' } ).then( ( r ) => r.json() );
			if ( ! res.ok ) throw new Error( res.error || 'error' );
			doc.dataset.signed = '1';
			if ( index < queue.length - 1 ) {
				index++;
				show();
			} else {
				close();
				$( document.body ).trigger( 'update_checkout' );
			}
		} catch ( e ) {
			err.textContent = t.error;
			err.hidden = false;
			btn.disabled = false;
		}
	}

	/* Delegated on body: WooCommerce/FunnelKit replace this fragment on every update_checkout.
	   preventDefault on the label click stops the browser toggling #aagr-confirm, so the box
	   can only ever be ticked by the server render after a real signature. */
	$( document.body ).on( 'click', '.aagr-open, .aagr-checkout__label, #aagr-confirm', ( e ) => { e.preventDefault(); open(); } );
	$( document.body ).on( 'keydown', '#aagr-confirm', ( e ) => { if ( e.key === ' ' || e.key === 'Enter' ) { e.preventDefault(); open(); } } );
	$( document.body ).on( 'checkout_error', () => {
		if ( document.querySelector( '.aagr-checkout[data-complete="0"]' ) ) open();
	} );
} )( jQuery );
