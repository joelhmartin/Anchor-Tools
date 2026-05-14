/* Anchor Site Config — admin scripts */
( function( $ ) {
    'use strict';

    $( function() {
        // Initialize WP Iris color pickers.
        $( '.anchor-color-field' ).wpColorPicker();

        // Media-picker handlers for brand-asset rows.
        $( '.anchor-media-choose' ).on( 'click', function( e ) {
            e.preventDefault();
            var $row     = $( this ).closest( '.anchor-media-row' );
            var $idInput = $row.find( '.anchor-media-id' );
            var $preview = $row.find( '.anchor-media-preview' );
            var $remove  = $row.find( '.anchor-media-remove' );

            var frame = wp.media( {
                title: 'Choose Image',
                multiple: false,
                library: { type: 'image' },
                button: { text: 'Use this image' },
            } );

            frame.on( 'select', function() {
                var att = frame.state().get( 'selection' ).first().toJSON();
                $idInput.val( att.id );
                var thumbUrl = att.sizes && att.sizes.thumbnail ? att.sizes.thumbnail.url : att.url;
                $preview.html( '<img src="' + thumbUrl + '" alt="" style="max-width:120px;max-height:80px;" />' );
                $remove.prop( 'disabled', false );
            } );

            frame.open();
        } );

        $( '.anchor-media-remove' ).on( 'click', function( e ) {
            e.preventDefault();
            var $row = $( this ).closest( '.anchor-media-row' );
            $row.find( '.anchor-media-id' ).val( '0' );
            $row.find( '.anchor-media-preview' ).empty();
            $( this ).prop( 'disabled', true );
        } );

        // Hours table — disable time inputs on the row when "Closed" is checked.
        $( '.anchor-hours-closed' ).on( 'change', function() {
            var $row    = $( this ).closest( '.anchor-hours-row' );
            var closed  = $( this ).is( ':checked' );
            $row.find( 'input[type="time"]' ).prop( 'disabled', closed );
        } );

        // Custom-shortcodes repeater.
        var rowCounter = $( '.anchor-site-config-rows .anchor-site-config-row' ).length;

        $( '#anchor-site-config-add-row' ).on( 'click', function() {
            var tmpl = $( '#tmpl-anchor-site-config-row' ).html().replace( /\{\{INDEX\}\}/g, rowCounter++ );
            $( '.anchor-site-config-rows' ).append( tmpl );
        } );

        $( '.anchor-site-config-rows' ).on( 'click', '.anchor-site-config-remove-row', function() {
            $( this ).closest( '.anchor-site-config-row' ).remove();
        } );
    });
} )( jQuery );
