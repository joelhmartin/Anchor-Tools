<?php
/**
 * Anchor Locations — per-page content sections.
 *
 * Replaces the Phase-2 content-library CPTs (projects/testimonials/FAQs) with
 * three free-form Monaco HTML sections authored directly on each location and
 * service page. Each section is exposed via a shortcode so it can be placed
 * anywhere (page body, wrapper, or a page-builder module). No JSON-LD is emitted
 * here — structured FAQ/Review schema is handled by the site's SEO plugin.
 *
 * @package Anchor\Locations
 */
namespace Anchor\Locations;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Sections {
    const NONCE = 'anchor_locations_sections_nonce';

    /** meta_key => [ label, shortcode tag, wrapper css class ]. */
    private static function defs() {
        return [
            'al_faq_html'          => [ 'label' => 'FAQ',          'tag' => 'anchor_local_faqs',         'class' => 'al-faqs' ],
            'al_testimonials_html' => [ 'label' => 'Testimonials', 'tag' => 'anchor_local_testimonials', 'class' => 'al-testimonials' ],
            'al_projects_html'     => [ 'label' => 'Projects',     'tag' => 'anchor_local_projects',     'class' => 'al-projects' ],
        ];
    }

    /** "{post_id}{meta_key}" => true recursion guard for section shortcodes. */
    private $rendering = [];

    public function __construct() {
        \add_action( 'add_meta_boxes', [ $this, 'add_metabox' ] );
        \add_action( 'save_post', [ $this, 'save_meta' ] );
        foreach ( self::defs() as $key => $d ) {
            $class = $d['class'];
            \add_shortcode( $d['tag'], function ( $atts ) use ( $key, $class ) {
                return $this->render_shortcode( $atts, $key, $class );
            } );
        }
    }

    public function add_metabox() {
        foreach ( [ Module::CPT_LOCATION, Module::CPT_SERVICE ] as $cpt ) {
            \add_meta_box( 'al_sections', \__( 'Content Sections (FAQ / Testimonials / Projects)', 'anchor-schema' ), [ $this, 'render_metabox' ], $cpt, 'normal', 'default' );
        }
    }

    public function render_metabox( $post ) {
        \wp_nonce_field( self::NONCE, self::NONCE );
        $spec = [];
        foreach ( self::defs() as $key => $d ) {
            $spec[] = [ 'id' => $key, 'label' => $d['label'], 'lang' => 'html' ];
        }
        echo '<div class="anchor-monaco" data-anchor-monaco=\'' . \esc_attr( \wp_json_encode( $spec ) ) . '\'>';
        foreach ( self::defs() as $key => $d ) {
            echo '<textarea id="' . \esc_attr( $key ) . '" name="' . \esc_attr( $key ) . '" style="display:none">' . \esc_textarea( (string) \get_post_meta( $post->ID, $key, true ) ) . '</textarea>';
        }
        echo '</div>';
        echo '<p class="description">' . \esc_html__( 'Free-form HTML. Place each section anywhere with its shortcode: [anchor_local_faqs], [anchor_local_testimonials], [anchor_local_projects].', 'anchor-schema' ) . '</p>';
    }

    public function save_meta( $post_id ) {
        if ( ! isset( $_POST[ self::NONCE ] ) || ! \wp_verify_nonce( $_POST[ self::NONCE ], self::NONCE ) ) { return; }
        if ( \defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) { return; }
        if ( ! \current_user_can( 'edit_post', $post_id ) ) { return; }
        foreach ( self::defs() as $key => $d ) {
            if ( isset( $_POST[ $key ] ) ) { \update_post_meta( $post_id, $key, \wp_unslash( $_POST[ $key ] ) ); }
        }
    }

    /** Render one section for the current (or id-referenced) page. */
    private function render_shortcode( $atts, $meta_key, $class ) {
        $a  = \shortcode_atts( [ 'id' => 0 ], $atts );
        $id = (int) $a['id'] ? (int) $a['id'] : (int) \get_the_ID();
        if ( ! $id ) { return ''; }
        $guard = $id . $meta_key;
        if ( ! empty( $this->rendering[ $guard ] ) ) { return ''; }
        $html = (string) \get_post_meta( $id, $meta_key, true );
        if ( \trim( $html ) === '' ) { return ''; }
        $this->rendering[ $guard ] = true;
        $out = '<div class="' . \esc_attr( $class ) . '">' . \do_shortcode( $html ) . '</div>';
        unset( $this->rendering[ $guard ] );
        return $out;
    }
}
