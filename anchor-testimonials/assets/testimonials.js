(function() {
	'use strict';

	// A bare DOMContentLoaded listener never fires if this script is loaded
	// after DOM parsing has already finished (a defer/async attribute added
	// by an optimisation plugin, a "defer JS" caching-layer setting, or a
	// dynamic re-injection), leaving every block on the page inert with no
	// error. Same guard other Anchor Tools front-end scripts use.
	function ready( fn ) {
		if ( document.readyState !== 'loading' ) {
			fn();
		} else {
			document.addEventListener( 'DOMContentLoaded', fn );
		}
	}

	function mediaButtons( root ) {
		return Array.prototype.slice.call( root.querySelectorAll( '.anchor-testimonial__media' ) );
	}

	function lightboxItems( buttons ) {
		return buttons.map( function( btn ) {
			var figure = btn.closest( '.anchor-testimonial' );
			var name = figure ? figure.querySelector( '.anchor-testimonial__name' ) : null;
			return {
				type: 'video',
				provider: btn.getAttribute( 'data-provider' ) || '',
				videoId: btn.getAttribute( 'data-video-id' ) || '',
				start: parseInt( btn.getAttribute( 'data-start' ), 10 ) || 0,
				caption: name ? name.textContent : ''
			};
		} );
	}

	function initLightbox( root ) {
		var LB = window.AnchorLightbox;
		if ( !LB ) {
			if ( typeof console !== 'undefined' && console.warn ) {
				console.warn( 'Anchor Testimonials: window.AnchorLightbox is missing (anchor-lightbox dependency not enqueued), video playback is disabled.' );
			}
			return;
		}

		root.addEventListener( 'click', function( e ) {
			var btn = e.target.closest( '.anchor-testimonial__media' );
			if ( !btn || !root.contains( btn ) ) return;

			var buttons = mediaButtons( root );
			var index = buttons.indexOf( btn );
			if ( index < 0 ) return;

			LB.open( lightboxItems( buttons ), index, { autoplay: true, origin: btn } );
		} );
	}

	function initCarousel( root ) {
		if ( root.getAttribute( 'data-layout' ) !== 'slider' ) return;

		if ( !window.AnchorCarousel ) {
			if ( typeof console !== 'undefined' && console.warn ) {
				console.warn( 'Anchor Testimonials: window.AnchorCarousel is missing (anchor-carousel dependency not enqueued), slider navigation is disabled.' );
			}
			return;
		}

		var track = root.querySelector( '.anchor-testimonials__track' );
		if ( !track ) return;

		var cols = parseInt( getComputedStyle( root ).getPropertyValue( '--at-cols' ), 10 ) || 3;

		window.AnchorCarousel.init( root, {
			track: track,
			items: track.children,
			prev: root.querySelector( '.anchor-testimonials__prev' ),
			next: root.querySelector( '.anchor-testimonials__next' ),
			dotsContainer: root.querySelector( '.anchor-testimonials__dots' ),
			dotClass: 'anchor-testimonials__dot',
			mode: 'carousel',
			loop: true,
			cols: {
				desktop: cols,
				tablet: Math.min( cols, 2 ),
				mobile: 1
			},
			gapVar: '--at-gap'
		} );
	}

	ready( function() {
		document.querySelectorAll( '.anchor-testimonials' ).forEach( function( root ) {
			if ( root.dataset.anchorTestimonialsBound ) return;
			root.dataset.anchorTestimonialsBound = '1';

			initLightbox( root );
			initCarousel( root );
		} );
	} );

})();
