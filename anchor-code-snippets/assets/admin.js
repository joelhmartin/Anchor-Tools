(function($){
    'use strict';

    var editor = null;

    /* ─── Code Editor Init ─────────────────────────────── */

    function initEditor() {
        if ( typeof wp.codeEditor === 'undefined' || ! ACS.editorSettings ) return;

        var $textarea = $( '#acs_code' );
        if ( ! $textarea.length ) return;

        var instance = wp.codeEditor.initialize( $textarea, ACS.editorSettings );
        editor = instance.codemirror;
    }

    function getEditorMime( lang ) {
        var map = {
            javascript: 'text/javascript',
            css:        'text/css',
            html:       'text/html',
            php:        'application/x-httpd-php',
            universal:  'text/html'
        };
        return map[ lang ] || 'text/html';
    }

    /* ─── Language Toggle ──────────────────────────────── */

    $( '#acs_language' ).on( 'change', function() {
        if ( ! editor ) return;
        editor.setOption( 'mode', getEditorMime( this.value ) );
    });

    /* ─── Scope Toggle ─────────────────────────────────── */

    $( 'input[name="acs_scope"]' ).on( 'change', function() {
        $( '.acs-page-search-wrap' ).toggle( this.value === 'specific' );
    });

    /* ─── Page Search ──────────────────────────────────── */

    var searchTimer = null;
    var $searchInput   = $( '#acs_page_search' );
    var $searchResults = $( '#acs_search_results' );
    var $pageTags      = $( '#acs_page_tags' );
    var $hiddenInput   = $( '#acs_target_pages' );

    function getSelectedIds() {
        var val = $hiddenInput.val();
        return val ? val.split( ',' ).map( Number ).filter( Boolean ) : [];
    }

    function syncHiddenInput() {
        var ids = [];
        $pageTags.find( '.acs-tag' ).each( function() {
            ids.push( $( this ).data( 'id' ) );
        });
        $hiddenInput.val( ids.join( ',' ) );
    }

    $searchInput.on( 'input', function() {
        clearTimeout( searchTimer );
        var term = $.trim( this.value );
        if ( term.length < 2 ) { $searchResults.empty().hide(); return; }

        searchTimer = setTimeout( function() {
            $.ajax({
                url:  ACS.ajaxUrl,
                data: { action: 'acs_search_pages', nonce: ACS.nonce, search: term },
                success: function( res ) {
                    if ( ! res.success || ! res.data.length ) { $searchResults.empty().hide(); return; }
                    var selected = getSelectedIds();
                    var html = '';
                    $.each( res.data, function( _, item ) {
                        if ( selected.indexOf( item.id ) !== -1 ) return;
                        html += '<div class="acs-search-item" data-id="' + item.id + '">' +
                                    '<span class="acs-search-title">' + $('<span>').text(item.title).html() + '</span>' +
                                    '<span class="acs-search-type">' + item.type + '</span>' +
                                '</div>';
                    });
                    if ( html ) {
                        $searchResults.html( html ).show();
                    } else {
                        $searchResults.empty().hide();
                    }
                }
            });
        }, 300 );
    });

    $searchResults.on( 'click', '.acs-search-item', function() {
        var $item = $( this );
        var id    = $item.data( 'id' );
        var title = $item.find( '.acs-search-title' ).text();

        $pageTags.append(
            '<span class="acs-tag" data-id="' + id + '">' +
                $('<span>').text(title).html() +
                ' <button type="button" class="acs-tag-remove">&times;</button>' +
            '</span>'
        );
        syncHiddenInput();
        $searchResults.empty().hide();
        $searchInput.val( '' );
    });

    $pageTags.on( 'click', '.acs-tag-remove', function() {
        $( this ).closest( '.acs-tag' ).remove();
        syncHiddenInput();
    });

    // Close dropdown on outside click
    $( document ).on( 'click', function( e ) {
        if ( ! $( e.target ).closest( '.acs-page-search-wrap' ).length ) {
            $searchResults.empty().hide();
        }
    });

    /* ─── AI Generate ──────────────────────────────────── */

    $( '#acs_ai_btn' ).on( 'click', function() {
        var prompt = $.trim( $( '#acs_ai_prompt' ).val() );
        if ( ! prompt ) return;

        var $btn     = $( this );
        var $spinner = $( '#acs_ai_spinner' );
        var $error   = $( '#acs_ai_error' );

        $btn.prop( 'disabled', true );
        $spinner.addClass( 'is-active' );
        $error.text( '' );

        $.ajax({
            url:    ACS.ajaxUrl,
            method: 'POST',
            data: {
                action:   'acs_ai_generate',
                nonce:    ACS.nonce,
                prompt:   prompt,
                language: $( '#acs_language' ).val()
            },
            success: function( res ) {
                if ( res.success && res.data.code ) {
                    if ( editor ) {
                        editor.setValue( res.data.code );
                    } else {
                        $( '#acs_code' ).val( res.data.code );
                    }
                } else {
                    $error.text( res.data && res.data.message ? res.data.message : 'Generation failed.' );
                }
            },
            error: function() {
                $error.text( 'Request failed. Check your connection.' );
            },
            complete: function() {
                $btn.prop( 'disabled', false );
                $spinner.removeClass( 'is-active' );
            }
        });
    });

    /* ─── Init ─────────────────────────────────────────── */

    $( document ).ready( function() {
        initEditor();
    });

})(jQuery);
