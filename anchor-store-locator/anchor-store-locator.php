<?php
namespace Anchor\StoreLocator;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

class Module {
    const CPT = 'anchor_store';
    const NONCE = 'anchor_store_locator_nonce';
    const ADMIN_NONCE = 'anchor_store_locator_admin';
    const DEFAULT_LAT = 39.8283;
    const DEFAULT_LNG = -98.5795;
    const DEFAULT_RADIUS_MILES = 50;

    private $assets_enqueued = false;

    public function __construct() {
        \add_action( 'init', [ $this, 'register_cpt' ] );
        \add_action( 'add_meta_boxes', [ $this, 'add_metaboxes' ] );
        \add_action( 'save_post_' . self::CPT, [ $this, 'save_meta' ] );
        \add_action( 'admin_notices', [ $this, 'admin_notices' ] );

        if ( \is_admin() ) {
            \add_action( 'admin_menu', [ $this, 'register_settings_page' ] );
            \add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
            \add_action( 'admin_post_anchor_store_locator_create', [ $this, 'handle_store_creation' ] );
            \add_action( 'wp_ajax_anchor_store_locator_place_search', [ $this, 'ajax_place_search' ] );
            \add_action( 'wp_ajax_anchor_store_locator_place_details', [ $this, 'ajax_place_details' ] );
        }

        \add_shortcode( 'anchor_store_locator', [ $this, 'shortcode' ] );
        \add_shortcode( 'anchor_store_field', [ $this, 'field_shortcode' ] );
    }

    public function register_cpt() {
        $labels = [
            'name' => \__( 'Store Locations', 'anchor-schema' ),
            'singular_name' => \__( 'Store Location', 'anchor-schema' ),
            'add_new_item' => \__( 'Add New Store Location', 'anchor-schema' ),
            'edit_item' => \__( 'Edit Store Location', 'anchor-schema' ),
            'new_item' => \__( 'New Store Location', 'anchor-schema' ),
            'view_item' => \__( 'View Store Location', 'anchor-schema' ),
            'search_items' => \__( 'Search Store Locations', 'anchor-schema' ),
            'not_found' => \__( 'No store locations found.', 'anchor-schema' ),
            'not_found_in_trash' => \__( 'No store locations found in Trash.', 'anchor-schema' ),
            'menu_name' => \__( 'Store Locations', 'anchor-schema' ),
        ];

        \register_post_type( self::CPT, [
            'labels' => $labels,
            'public' => true,
            'show_in_rest' => true,
            'has_archive' => false,
            'rewrite' => [ 'slug' => 'for-doctors/find-a-tmj-centre-near-you', 'with_front' => false ],
            'supports' => [ 'title', 'editor', 'excerpt', 'thumbnail' ],
            'menu_icon' => 'dashicons-location-alt',
        ] );
    }

    public function add_metaboxes() {
        \add_meta_box(
            'anchor_store_locator_details',
            \__( 'Store Locator Details', 'anchor-schema' ),
            [ $this, 'render_metabox' ],
            self::CPT,
            'normal',
            'high'
        );
    }

    public function render_metabox( $post ) {
        \wp_nonce_field( self::NONCE, self::NONCE );
        $address = \get_post_meta( $post->ID, '_anchor_store_address', true );
        $lat = \get_post_meta( $post->ID, '_anchor_store_lat', true );
        $lng = \get_post_meta( $post->ID, '_anchor_store_lng', true );
        $website = \get_post_meta( $post->ID, '_anchor_store_website', true );
        $email = \get_post_meta( $post->ID, '_anchor_store_email', true );
        $phone = \get_post_meta( $post->ID, '_anchor_store_phone', true );
        $maps_url = \get_post_meta( $post->ID, '_anchor_store_maps_url', true );
        $place_id = \get_post_meta( $post->ID, '_anchor_store_place_id', true );
        ?>
        <div class="anchor-store-meta">
            <div class="anchor-store-search-panel">
                <strong><?php echo \esc_html__( 'Find a Google Listing', 'anchor-schema' ); ?></strong>
                <input type="text" id="anchor-store-search" class="regular-text" placeholder="<?php echo \esc_attr__( 'Search business name or address...', 'anchor-schema' ); ?>" autocomplete="off" />
                <div id="anchor-store-results" class="anchor-store-results" style="display:none;"></div>
                <div id="anchor-store-status" class="anchor-store-status" aria-live="polite"></div>
            </div>
            <input type="hidden" name="anchor_store_place_id" id="anchor-store-place-id" value="<?php echo \esc_attr( $place_id ); ?>" />
            <p>
                <label for="anchor_store_address"><strong><?php echo \esc_html__( 'Address', 'anchor-schema' ); ?></strong></label><br />
                <input type="text" id="anchor_store_address" name="anchor_store_address" value="<?php echo \esc_attr( $address ); ?>" class="regular-text" required />
            </p>
            <div style="display:flex; gap:16px; flex-wrap:wrap;">
                <p>
                    <label for="anchor_store_lat"><strong><?php echo \esc_html__( 'Latitude', 'anchor-schema' ); ?></strong></label><br />
                    <input type="text" id="anchor_store_lat" name="anchor_store_lat" value="<?php echo \esc_attr( $lat ); ?>" />
                </p>
                <p>
                    <label for="anchor_store_lng"><strong><?php echo \esc_html__( 'Longitude', 'anchor-schema' ); ?></strong></label><br />
                    <input type="text" id="anchor_store_lng" name="anchor_store_lng" value="<?php echo \esc_attr( $lng ); ?>" />
                </p>
            </div>
            <p>
                <label for="anchor_store_website"><strong><?php echo \esc_html__( 'Website URL', 'anchor-schema' ); ?></strong></label><br />
                <input type="url" id="anchor_store_website" name="anchor_store_website" value="<?php echo \esc_attr( $website ); ?>" class="regular-text" />
            </p>
            <p>
                <label for="anchor_store_email"><strong><?php echo \esc_html__( 'Email Address', 'anchor-schema' ); ?></strong></label><br />
                <input type="email" id="anchor_store_email" name="anchor_store_email" value="<?php echo \esc_attr( $email ); ?>" class="regular-text" />
            </p>
            <p>
                <label for="anchor_store_phone"><strong><?php echo \esc_html__( 'Phone Number', 'anchor-schema' ); ?></strong></label><br />
                <input type="text" id="anchor_store_phone" name="anchor_store_phone" value="<?php echo \esc_attr( $phone ); ?>" class="regular-text" />
            </p>
            <p>
                <label for="anchor_store_maps_url"><strong><?php echo \esc_html__( 'Google Maps Link (optional override)', 'anchor-schema' ); ?></strong></label><br />
                <input type="url" id="anchor_store_maps_url" name="anchor_store_maps_url" value="<?php echo \esc_attr( $maps_url ); ?>" class="regular-text" />
            </p>
        </div>
        <?php
    }

    public function save_meta( $post_id ) {
        if ( ! isset( $_POST[ self::NONCE ] ) || ! \wp_verify_nonce( $_POST[ self::NONCE ], self::NONCE ) ) {
            return;
        }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( ! \current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        $address = \sanitize_text_field( $_POST['anchor_store_address'] ?? '' );
        $lat = \sanitize_text_field( $_POST['anchor_store_lat'] ?? '' );
        $lng = \sanitize_text_field( $_POST['anchor_store_lng'] ?? '' );
        $website = \esc_url_raw( $_POST['anchor_store_website'] ?? '' );
        $email = \sanitize_email( $_POST['anchor_store_email'] ?? '' );
        $phone = \sanitize_text_field( $_POST['anchor_store_phone'] ?? '' );
        $maps_url = \esc_url_raw( $_POST['anchor_store_maps_url'] ?? '' );

        \update_post_meta( $post_id, '_anchor_store_address', $address );
        \update_post_meta( $post_id, '_anchor_store_website', $website );
        \update_post_meta( $post_id, '_anchor_store_email', $email );
        \update_post_meta( $post_id, '_anchor_store_phone', $phone );
        \update_post_meta( $post_id, '_anchor_store_maps_url', $maps_url );

        if ( ! $address ) {
            \add_filter( 'redirect_post_location', function( $location ) {
                return \add_query_arg( 'anchor_store_notice', 'missing_address', $location );
            } );
        }

        $lat_value = is_numeric( $lat ) ? (float) $lat : 0;
        $lng_value = is_numeric( $lng ) ? (float) $lng : 0;

        $prev_address = \get_post_meta( $post_id, '_anchor_store_address_prev', true );
        $needs_geocode = ( $address && ( ! $lat_value || ! $lng_value || $address !== $prev_address ) );

        if ( $needs_geocode ) {
            $coords = $this->geocode_address( $address );
            if ( $coords ) {
                $lat_value = $coords['lat'];
                $lng_value = $coords['lng'];
                \update_post_meta( $post_id, '_anchor_store_address_prev', $address );
            } else {
                \add_filter( 'redirect_post_location', function( $location ) {
                    return \add_query_arg( 'anchor_store_notice', 'geocode_failed', $location );
                } );
            }
        }

        \update_post_meta( $post_id, '_anchor_store_lat', $lat_value );
        \update_post_meta( $post_id, '_anchor_store_lng', $lng_value );

        if ( ! empty( $_POST['anchor_store_place_id'] ) ) {
            \update_post_meta( $post_id, '_anchor_store_place_id', \sanitize_text_field( $_POST['anchor_store_place_id'] ) );
        }
    }

    public function admin_notices() {
        if ( empty( $_GET['anchor_store_notice'] ) ) {
            return;
        }
        $notice = \sanitize_text_field( $_GET['anchor_store_notice'] );
        if ( $notice === 'geocode_failed' ) {
            echo '<div class="notice notice-warning"><p>' . \esc_html__( 'Unable to geocode the address. Check the Google API key and address format.', 'anchor-schema' ) . '</p></div>';
        }
        if ( $notice === 'missing_address' ) {
            echo '<div class="notice notice-error"><p>' . \esc_html__( 'Store address is required to display on the locator.', 'anchor-schema' ) . '</p></div>';
        }
        if ( $notice === 'store_created' ) {
            $post_id = isset( $_GET['anchor_store_id'] ) ? (int) $_GET['anchor_store_id'] : 0;
            $link = '';
            if ( $post_id ) {
                $edit_link = \get_edit_post_link( $post_id );
                if ( $edit_link ) {
                    $link = ' <a href="' . \esc_url( $edit_link ) . '">' . \esc_html__( 'Edit store', 'anchor-schema' ) . '</a>';
                }
            }
            echo '<div class="notice notice-success"><p>' . \esc_html__( 'Store location created as a draft.', 'anchor-schema' ) . $link . '</p></div>';
        }
        if ( $notice === 'store_error' ) {
            $message = isset( $_GET['anchor_store_error'] ) ? \sanitize_text_field( \wp_unslash( $_GET['anchor_store_error'] ) ) : '';
            if ( ! $message ) {
                $message = \__( 'Unable to create the store location. Try again.', 'anchor-schema' );
            }
            echo '<div class="notice notice-error"><p>' . \esc_html( $message ) . '</p></div>';
        }
    }

    public function register_settings_page() {
        \add_options_page(
            \__( 'Anchor Store Locator', 'anchor-schema' ),
            \__( 'Store Locator', 'anchor-schema' ),
            'manage_options',
            'anchor-store-locator',
            [ $this, 'render_settings_page' ]
        );
    }

    public function enqueue_admin_assets( $hook ) {
        $is_settings = ( $hook === 'settings_page_anchor-store-locator' );
        $is_post_editor = in_array( $hook, [ 'post.php', 'post-new.php' ], true )
            && \get_post_type() === self::CPT;

        if ( ! $is_settings && ! $is_post_editor ) {
            return;
        }

        \wp_enqueue_style(
            'anchor-store-locator-admin',
            \plugins_url( 'assets/admin.css', __FILE__ ),
            [],
            '1.0.0'
        );

        \wp_enqueue_script(
            'anchor-store-locator-admin',
            \plugins_url( 'assets/admin.js', __FILE__ ),
            [ 'jquery' ],
            '1.0.0',
            true
        );

        \wp_localize_script( 'anchor-store-locator-admin', 'ANCHOR_STORE_LOCATOR_ADMIN', [
            'ajax' => \admin_url( 'admin-ajax.php' ),
            'nonce' => \wp_create_nonce( self::ADMIN_NONCE ),
            'strings' => [
                'searching' => \esc_html__( 'Searching Google Places...', 'anchor-schema' ),
                'noResults' => \esc_html__( 'No matching businesses found.', 'anchor-schema' ),
                'missingKey' => \esc_html__( 'Google API key is missing. Add it in Anchor Tools Settings.', 'anchor-schema' ),
                'searchError' => \esc_html__( 'Search failed. Check the API key and try again.', 'anchor-schema' ),
                'prefilled' => \esc_html__( 'Details loaded. Review the fields below, then create the store.', 'anchor-schema' ),
                'detailsError' => \esc_html__( 'Unable to load details for that listing.', 'anchor-schema' ),
                'loadingDetails' => \esc_html__( 'Loading listing details...', 'anchor-schema' ),
                'tooShort' => \esc_html__( 'Type at least 3 characters to search.', 'anchor-schema' ),
                'useListing' => \esc_html__( 'Use listing', 'anchor-schema' ),
            ],
        ] );
    }

    public function render_settings_page() {
        if ( ! \current_user_can( 'manage_options' ) ) {
            return;
        }

        $api_key = $this->get_google_api_key();
        ?>
        <div class="wrap anchor-store-locator-admin">
            <h1><?php echo \esc_html__( 'Store Locator', 'anchor-schema' ); ?></h1>
            <p class="description"><?php echo \esc_html__( 'Search live Google listings, populate store fields, and create Store Location drafts.', 'anchor-schema' ); ?></p>

            <?php if ( ! $api_key ) : ?>
                <div class="notice notice-warning"><p><?php echo \esc_html__( 'Google API key is missing. Add it in Anchor Tools Settings to search live listings.', 'anchor-schema' ); ?></p></div>
            <?php endif; ?>

            <div class="anchor-store-panel">
                <h2><?php echo \esc_html__( 'Display on your site', 'anchor-schema' ); ?></h2>
                <p><?php echo \esc_html__( 'Add the shortcode below to any page or post to render the store locator.', 'anchor-schema' ); ?></p>
                <p><code>[anchor_store_locator]</code></p>
                <p class="description"><?php echo \esc_html__( 'Stores must be published to appear in the locator results.', 'anchor-schema' ); ?></p>
            </div>

            <div class="anchor-store-panel">
                <h2><?php echo \esc_html__( 'Find a Google listing', 'anchor-schema' ); ?></h2>
                <p><?php echo \esc_html__( 'Search active Google Places listings to prefill the store fields below.', 'anchor-schema' ); ?></p>
                <input type="text" id="anchor-store-search" class="regular-text" placeholder="<?php echo \esc_attr__( 'Search by business name or address', 'anchor-schema' ); ?>" autocomplete="off" />
                <div id="anchor-store-results" class="anchor-store-results" style="display:none;"></div>
            </div>

            <div class="anchor-store-panel">
                <h2><?php echo \esc_html__( 'Create a Store Location', 'anchor-schema' ); ?></h2>
                <p class="description"><?php echo \esc_html__( 'Fields are editable. The store will be created as a draft so you can add custom content.', 'anchor-schema' ); ?></p>
                <div id="anchor-store-status" class="anchor-store-status" aria-live="polite"></div>

                <form method="post" action="<?php echo \esc_url( \admin_url( 'admin-post.php' ) ); ?>" id="anchor-store-form" class="anchor-store-form">
                    <input type="hidden" name="action" value="anchor_store_locator_create" />
                    <?php \wp_nonce_field( self::ADMIN_NONCE, 'anchor_store_locator_admin_nonce' ); ?>
                    <?php \wp_nonce_field( self::NONCE, self::NONCE ); ?>
                    <input type="hidden" name="anchor_store_place_id" id="anchor-store-place-id" value="" />

                    <p>
                        <label for="anchor-store-title"><strong><?php echo \esc_html__( 'Business name', 'anchor-schema' ); ?></strong></label><br />
                        <input type="text" id="anchor-store-title" name="anchor_store_title" class="regular-text" required />
                    </p>
                    <p>
                        <label for="anchor-store-address"><strong><?php echo \esc_html__( 'Address', 'anchor-schema' ); ?></strong></label><br />
                        <textarea id="anchor-store-address" name="anchor_store_address" class="large-text" rows="2" required></textarea>
                    </p>

                    <div class="anchor-store-grid">
                        <p>
                            <label for="anchor-store-lat"><strong><?php echo \esc_html__( 'Latitude', 'anchor-schema' ); ?></strong></label><br />
                            <input type="text" id="anchor-store-lat" name="anchor_store_lat" />
                        </p>
                        <p>
                            <label for="anchor-store-lng"><strong><?php echo \esc_html__( 'Longitude', 'anchor-schema' ); ?></strong></label><br />
                            <input type="text" id="anchor-store-lng" name="anchor_store_lng" />
                        </p>
                    </div>

                    <p>
                        <label for="anchor-store-website"><strong><?php echo \esc_html__( 'Website', 'anchor-schema' ); ?></strong></label><br />
                        <input type="url" id="anchor-store-website" name="anchor_store_website" class="regular-text" />
                    </p>
                    <p>
                        <label for="anchor-store-phone"><strong><?php echo \esc_html__( 'Phone', 'anchor-schema' ); ?></strong></label><br />
                        <input type="text" id="anchor-store-phone" name="anchor_store_phone" class="regular-text" />
                    </p>
                    <p>
                        <label for="anchor-store-email"><strong><?php echo \esc_html__( 'Email (optional)', 'anchor-schema' ); ?></strong></label><br />
                        <input type="email" id="anchor-store-email" name="anchor_store_email" class="regular-text" />
                    </p>
                    <p>
                        <label for="anchor-store-maps"><strong><?php echo \esc_html__( 'Google Maps URL (optional override)', 'anchor-schema' ); ?></strong></label><br />
                        <input type="url" id="anchor-store-maps" name="anchor_store_maps_url" class="regular-text" />
                    </p>

                    <p>
                        <button type="submit" class="button button-primary"><?php echo \esc_html__( 'Create Store Location', 'anchor-schema' ); ?></button>
                        <span class="description"><?php echo \esc_html__( 'A draft Store Location post will be created.', 'anchor-schema' ); ?></span>
                    </p>
                </form>
            </div>
        </div>
        <?php
    }

    public function ajax_place_search() {
        \check_ajax_referer( self::ADMIN_NONCE, 'nonce' );
        if ( ! \current_user_can( 'manage_options' ) ) {
            \wp_send_json_error( [ 'message' => 'no_cap' ] );
        }

        $query = isset( $_POST['query'] ) ? \sanitize_text_field( \wp_unslash( $_POST['query'] ) ) : '';
        if ( \strlen( $query ) < 3 ) {
            \wp_send_json_error( [ 'message' => 'too_short' ] );
        }

        $api_key = $this->get_google_api_key();
        if ( ! $api_key ) {
            \wp_send_json_error( [ 'message' => 'missing_key' ] );
        }

        $results = [];
        if ( \class_exists( '\Anchor_Reviews_Google_Provider' ) ) {
            $provider = new \Anchor_Reviews_Google_Provider();
            if ( \method_exists( $provider, 'search' ) ) {
                $results = $provider->search( $query, $api_key, 8 );
            }
        }

        if ( \is_wp_error( $results ) ) {
            \wp_send_json_error( [ 'message' => $results->get_error_message() ] );
        }

        $clean = [];
        foreach ( (array) $results as $row ) {
            if ( empty( $row['place_id'] ) ) {
                continue;
            }
            $clean[] = [
                'place_id' => (string) ( $row['place_id'] ?? '' ),
                'name' => isset( $row['name'] ) ? \sanitize_text_field( $row['name'] ) : '',
                'address' => isset( $row['address'] ) ? \sanitize_text_field( $row['address'] ) : '',
                'business_status' => isset( $row['business_status'] ) ? \sanitize_text_field( $row['business_status'] ) : '',
            ];
        }

        \wp_send_json_success( [ 'results' => $clean ] );
    }

    public function ajax_place_details() {
        \check_ajax_referer( self::ADMIN_NONCE, 'nonce' );
        if ( ! \current_user_can( 'manage_options' ) ) {
            \wp_send_json_error( [ 'message' => 'no_cap' ] );
        }

        $place_id = isset( $_POST['place_id'] ) ? \sanitize_text_field( \wp_unslash( $_POST['place_id'] ) ) : '';
        if ( ! $place_id ) {
            \wp_send_json_error( [ 'message' => 'missing_place' ] );
        }

        $details = $this->get_place_details( $place_id );
        if ( \is_wp_error( $details ) ) {
            \wp_send_json_error( [ 'message' => $details->get_error_message() ] );
        }

        \wp_send_json_success( [ 'details' => $details ] );
    }

    public function handle_store_creation() {
        if ( ! \current_user_can( 'manage_options' ) ) {
            \wp_die( \esc_html__( 'Unauthorized request.', 'anchor-schema' ) );
        }
        if ( empty( $_POST['anchor_store_locator_admin_nonce'] ) || ! \wp_verify_nonce( $_POST['anchor_store_locator_admin_nonce'], self::ADMIN_NONCE ) ) {
            \wp_die( \esc_html__( 'Invalid request.', 'anchor-schema' ) );
        }

        $title = isset( $_POST['anchor_store_title'] ) ? \sanitize_text_field( \wp_unslash( $_POST['anchor_store_title'] ) ) : '';
        $address = isset( $_POST['anchor_store_address'] ) ? \sanitize_text_field( \wp_unslash( $_POST['anchor_store_address'] ) ) : '';
        $lat = isset( $_POST['anchor_store_lat'] ) ? (float) \sanitize_text_field( \wp_unslash( $_POST['anchor_store_lat'] ) ) : 0;
        $lng = isset( $_POST['anchor_store_lng'] ) ? (float) \sanitize_text_field( \wp_unslash( $_POST['anchor_store_lng'] ) ) : 0;
        $website = isset( $_POST['anchor_store_website'] ) ? \esc_url_raw( $_POST['anchor_store_website'] ) : '';
        $email = isset( $_POST['anchor_store_email'] ) ? \sanitize_email( \wp_unslash( $_POST['anchor_store_email'] ) ) : '';
        $phone = isset( $_POST['anchor_store_phone'] ) ? \sanitize_text_field( \wp_unslash( $_POST['anchor_store_phone'] ) ) : '';
        $maps_url = isset( $_POST['anchor_store_maps_url'] ) ? \esc_url_raw( $_POST['anchor_store_maps_url'] ) : '';
        $place_id = isset( $_POST['anchor_store_place_id'] ) ? \sanitize_text_field( \wp_unslash( $_POST['anchor_store_place_id'] ) ) : '';

        if ( ! $title || ! $address ) {
            $this->redirect_with_notice( 'store_error', [
                'anchor_store_error' => \__( 'Store name and address are required.', 'anchor-schema' ),
            ] );
        }

        if ( ! $maps_url && $address ) {
            $maps_url = 'https://www.google.com/maps/search/?api=1&query=' . \rawurlencode( $address );
        }

        $content = $this->build_default_content( [
            'website' => $website,
            'phone' => $phone,
            'email' => $email,
            'maps_url' => $maps_url,
        ] );

        $post_id = \wp_insert_post( [
            'post_type' => self::CPT,
            'post_status' => 'draft',
            'post_title' => $title,
            'post_content' => $content,
        ], true );

        if ( \is_wp_error( $post_id ) ) {
            $this->redirect_with_notice( 'store_error', [
                'anchor_store_error' => \__( 'Unable to create the store location.', 'anchor-schema' ),
            ] );
        }

        if ( ! $lat || ! $lng ) {
            $coords = $this->geocode_address( $address );
            if ( $coords ) {
                $lat = $coords['lat'];
                $lng = $coords['lng'];
            }
        }

        \update_post_meta( $post_id, '_anchor_store_address', $address );
        \update_post_meta( $post_id, '_anchor_store_lat', $lat );
        \update_post_meta( $post_id, '_anchor_store_lng', $lng );
        \update_post_meta( $post_id, '_anchor_store_website', $website );
        \update_post_meta( $post_id, '_anchor_store_email', $email );
        \update_post_meta( $post_id, '_anchor_store_phone', $phone );
        \update_post_meta( $post_id, '_anchor_store_maps_url', $maps_url );

        if ( $place_id ) {
            \update_post_meta( $post_id, '_anchor_store_place_id', $place_id );
        }

        $this->redirect_with_notice( 'store_created', [
            'anchor_store_id' => $post_id,
        ] );
    }

    private function build_default_content( $data ) {
        $website = isset( $data['website'] ) ? \esc_url_raw( $data['website'] ) : '';
        $phone = isset( $data['phone'] ) ? \sanitize_text_field( $data['phone'] ) : '';
        $email = isset( $data['email'] ) ? \sanitize_email( $data['email'] ) : '';
        $maps_url = isset( $data['maps_url'] ) ? \esc_url_raw( $data['maps_url'] ) : '';

        $lines = [];
        if ( $website ) {
            $lines[] = sprintf(
                '<p><strong>%s</strong> <a href="%s" target="_blank" rel="noopener">%s</a></p>',
                \esc_html__( 'Website:', 'anchor-schema' ),
                \esc_url( $website ),
                \esc_html( $website )
            );
        }
        if ( $phone ) {
            $tel = preg_replace( '/[^0-9+]/', '', $phone );
            $lines[] = sprintf(
                '<p><strong>%s</strong> <a href="tel:%s">%s</a></p>',
                \esc_html__( 'Phone:', 'anchor-schema' ),
                \esc_attr( $tel ),
                \esc_html( $phone )
            );
        }
        if ( $email ) {
            $lines[] = sprintf(
                '<p><strong>%s</strong> <a href="mailto:%s">%s</a></p>',
                \esc_html__( 'Email:', 'anchor-schema' ),
                \antispambot( $email ),
                \esc_html( $email )
            );
        }
        if ( $maps_url ) {
            $lines[] = sprintf(
                '<p><strong>%s</strong> <a href="%s" target="_blank" rel="noopener">%s</a></p>',
                \esc_html__( 'Google Maps:', 'anchor-schema' ),
                \esc_url( $maps_url ),
                \esc_html__( 'View on Google Maps', 'anchor-schema' )
            );
        }

        return implode( "\n", $lines );
    }

    private function get_place_details( $place_id ) {
        $api_key = $this->get_google_api_key();
        if ( ! $api_key ) {
            return new \WP_Error( 'anchor_store_missing_key', \__( 'Google API key is missing.', 'anchor-schema' ) );
        }

        $endpoint = \add_query_arg(
            [
                'place_id' => $place_id,
                'fields' => 'place_id,name,formatted_address,geometry,url,website,formatted_phone_number,international_phone_number,business_status',
                'key' => $api_key,
            ],
            'https://maps.googleapis.com/maps/api/place/details/json'
        );

        $resp = \wp_remote_get( $endpoint, [ 'timeout' => 12 ] );
        if ( \is_wp_error( $resp ) ) {
            return $resp;
        }
        $code = \wp_remote_retrieve_response_code( $resp );
        if ( $code !== 200 ) {
            return new \WP_Error( 'anchor_store_http', 'Google API HTTP ' . $code );
        }

        $data = \json_decode( \wp_remote_retrieve_body( $resp ), true );
        if ( empty( $data['result'] ) || ( $data['status'] ?? '' ) !== 'OK' ) {
            $status = $data['status'] ?? 'unknown';
            return new \WP_Error( 'anchor_store_api', 'Google API error: ' . $status );
        }

        $result = $data['result'];
        $location = $result['geometry']['location'] ?? [];
        $phone = $result['formatted_phone_number'] ?? '';
        if ( ! $phone && ! empty( $result['international_phone_number'] ) ) {
            $phone = $result['international_phone_number'];
        }

        return [
            'place_id' => isset( $result['place_id'] ) ? \sanitize_text_field( $result['place_id'] ) : '',
            'name' => isset( $result['name'] ) ? \sanitize_text_field( $result['name'] ) : '',
            'address' => isset( $result['formatted_address'] ) ? \sanitize_text_field( $result['formatted_address'] ) : '',
            'lat' => isset( $location['lat'] ) ? (float) $location['lat'] : 0,
            'lng' => isset( $location['lng'] ) ? (float) $location['lng'] : 0,
            'website' => isset( $result['website'] ) ? \esc_url_raw( $result['website'] ) : '',
            'phone' => $phone ? \sanitize_text_field( $phone ) : '',
            'maps_url' => isset( $result['url'] ) ? \esc_url_raw( $result['url'] ) : '',
            'business_status' => isset( $result['business_status'] ) ? \sanitize_text_field( $result['business_status'] ) : '',
        ];
    }

    private function redirect_with_notice( $notice, $args = [] ) {
        $url = \add_query_arg(
            \array_merge(
                [
                    'page' => 'anchor-store-locator',
                    'anchor_store_notice' => $notice,
                ],
                $args
            ),
            \admin_url( 'options-general.php' )
        );

        \wp_safe_redirect( $url );
        exit;
    }

    public function shortcode() {
        $this->enqueue_frontend_assets();

        if ( ! $this->get_google_api_key() ) {
            return '<div class="anchor-store-locator anchor-store-missing-key">' . \esc_html__( 'Google Maps API key is missing. Add it in Anchor Tools Settings.', 'anchor-schema' ) . '</div>';
        }

        $output = '<div class="anchor-store-locator" data-anchor-store-locator>';
        $output .= '<div class="anchor-store-controls">';
        $output .= '<label class="screen-reader-text" for="anchor-store-search">' . \esc_html__( 'Search location', 'anchor-schema' ) . '</label>';
        $output .= '<input id="anchor-store-search" class="anchor-store-search" type="text" placeholder="' . \esc_attr__( 'Search by city, ZIP, or address', 'anchor-schema' ) . '" aria-label="' . \esc_attr__( 'Search by city, ZIP, or address', 'anchor-schema' ) . '" data-anchor-store-search />';
        $output .= '<button type="button" class="anchor-store-button" data-anchor-store-geolocate aria-label="' . \esc_attr__( 'Use My Current Location', 'anchor-schema' ) . '">' . \esc_html__( 'Use My Current Location', 'anchor-schema' ) . '</button>';
        $output .= '<span class="anchor-store-status" data-anchor-store-status aria-live="polite"></span>';
        $output .= '</div>';
        $output .= '<div class="anchor-store-map" data-anchor-store-map aria-label="' . \esc_attr__( 'Store locations map', 'anchor-schema' ) . '"></div>';
        $output .= '<div class="anchor-store-results" data-anchor-store-results aria-live="polite"></div>';
        $output .= '</div>';

        return $output;
    }

    public function field_shortcode( $atts ) {
        $atts = \shortcode_atts( [
            'field'  => '',
            'id'     => '',
            'format' => '',
            'label'  => '',
        ], $atts, 'anchor_store_field' );

        $allowed = [
            'address'  => '_anchor_store_address',
            'lat'      => '_anchor_store_lat',
            'lng'      => '_anchor_store_lng',
            'website'  => '_anchor_store_website',
            'email'    => '_anchor_store_email',
            'phone'    => '_anchor_store_phone',
            'maps_url' => '_anchor_store_maps_url',
            'place_id' => '_anchor_store_place_id',
        ];

        $field = \sanitize_key( $atts['field'] );
        if ( ! isset( $allowed[ $field ] ) ) {
            return '';
        }

        $post_id = $atts['id'] ? (int) $atts['id'] : \get_the_ID();
        if ( ! $post_id ) {
            return '';
        }

        $value = \get_post_meta( $post_id, $allowed[ $field ], true );
        if ( ! $value ) {
            return '';
        }

        $format = \sanitize_key( $atts['format'] );

        if ( $format === 'tel' ) {
            $tel = \preg_replace( '/[^0-9+]/', '', $value );
            return '<a href="tel:' . \esc_attr( $tel ) . '">' . \esc_html( $value ) . '</a>';
        }

        if ( $format === 'email' ) {
            return '<a href="mailto:' . \antispambot( $value ) . '">' . \esc_html( $value ) . '</a>';
        }

        if ( $format === 'link' ) {
            return '<a href="' . \esc_url( $value ) . '" target="_blank" rel="noopener">' . \esc_html( $value ) . '</a>';
        }

        if ( $format === 'map' ) {
            $maps_url = \get_post_meta( $post_id, '_anchor_store_maps_url', true );
            if ( ! $maps_url ) {
                $maps_url = 'https://www.google.com/maps/search/?api=1&query=' . \rawurlencode( $value );
            }
            $label = $atts['label'] ? $atts['label'] : $value;
            return '<a href="' . \esc_url( $maps_url ) . '" target="_blank" rel="noopener">' . \esc_html( $label ) . '</a>';
        }

        return \esc_html( $value );
    }

    private function enqueue_frontend_assets() {
        if ( $this->assets_enqueued ) {
            return;
        }
        $this->assets_enqueued = true;

        \wp_enqueue_style( 'anchor-store-locator', \plugins_url( 'assets/frontend.css', __FILE__ ), [], '1.0.0' );

        $api_key = $this->get_google_api_key();
        if ( ! $api_key ) {
            return;
        }

        $locations = $this->get_locations();
        \wp_enqueue_script( 'anchor-store-maps', 'https://maps.googleapis.com/maps/api/js?key=' . \rawurlencode( $api_key ) . '&libraries=places', [], null, true );
        \wp_enqueue_script( 'anchor-store-locator', \plugins_url( 'assets/frontend.js', __FILE__ ), [ 'anchor-store-maps' ], '1.0.0', true );

        \wp_localize_script( 'anchor-store-locator', 'ANCHOR_STORE_LOCATOR', [
            'locations' => $locations,
            'defaultLat' => self::DEFAULT_LAT,
            'defaultLng' => self::DEFAULT_LNG,
            'defaultZoom' => 10,
            'radiusMiles' => self::DEFAULT_RADIUS_MILES,
        ] );
    }

    private function get_google_api_key() {
        if ( ! class_exists( 'Anchor_Schema_Admin' ) ) {
            return '';
        }
        $opts = \get_option( \Anchor_Schema_Admin::OPTION_KEY, [] );
        return isset( $opts['google_api_key'] ) ? \sanitize_text_field( $opts['google_api_key'] ) : '';
    }

    private function get_locations() {
        $query = new \WP_Query( [
            'post_type' => self::CPT,
            'post_status' => 'publish',
            'posts_per_page' => -1,
        ] );

        $locations = [];
        foreach ( $query->posts as $post ) {
            $lat = (float) \get_post_meta( $post->ID, '_anchor_store_lat', true );
            $lng = (float) \get_post_meta( $post->ID, '_anchor_store_lng', true );
            $address = \sanitize_text_field( \get_post_meta( $post->ID, '_anchor_store_address', true ) );
            $website = \esc_url_raw( \get_post_meta( $post->ID, '_anchor_store_website', true ) );
            $email = \sanitize_email( \get_post_meta( $post->ID, '_anchor_store_email', true ) );
            $phone = \sanitize_text_field( \get_post_meta( $post->ID, '_anchor_store_phone', true ) );
            $maps_url = \esc_url_raw( \get_post_meta( $post->ID, '_anchor_store_maps_url', true ) );
            if ( ! $maps_url && $address ) {
                $maps_url = 'https://www.google.com/maps/search/?api=1&query=' . \rawurlencode( $address );
            }

            $locations[] = [
                'id' => $post->ID,
                'title' => \html_entity_decode( \wp_strip_all_tags( \get_the_title( $post ) ), ENT_QUOTES, 'UTF-8' ),
                'lat' => $lat,
                'lng' => $lng,
                'address' => $address,
                'website' => $website,
                'email' => $email,
                'phone' => $phone,
                'mapsUrl' => $maps_url,
                'permalink' => \esc_url_raw( \get_permalink( $post ) ),
                'excerpt' => \wp_strip_all_tags( \get_the_excerpt( $post ) ),
                'image' => \esc_url_raw( \get_the_post_thumbnail_url( $post, 'medium' ) ),
            ];
        }

        return $locations;
    }

    private function geocode_address( $address ) {
        $api_key = $this->get_google_api_key();
        if ( ! $api_key ) {
            return null;
        }

        $response = \wp_remote_get(
            'https://maps.googleapis.com/maps/api/geocode/json?address=' . \rawurlencode( $address ) . '&key=' . \rawurlencode( $api_key ),
            [
                'timeout' => 8,
            ]
        );

        if ( \is_wp_error( $response ) ) {
            return null;
        }

        $body = \wp_remote_retrieve_body( $response );
        $data = \json_decode( $body, true );
        if ( empty( $data['status'] ) || $data['status'] !== 'OK' ) {
            return null;
        }
        $location = $data['results'][0]['geometry']['location'] ?? null;
        if ( ! $location ) {
            return null;
        }

        return [
            'lat' => (float) $location['lat'],
            'lng' => (float) $location['lng'],
        ];
    }
}
