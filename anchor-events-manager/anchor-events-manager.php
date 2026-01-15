<?php
namespace Anchor\Events;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

class Module {
    const CPT = 'event';
    const REG_CPT = 'anchor_event_reg';
    const OPTION_KEY = 'anchor_events_settings';
    const CACHE_OPTION = 'anchor_events_cache_keys';
    const NONCE = 'anchor_event_meta_nonce';
    const REG_NONCE = 'anchor_event_reg_nonce';

    private static $instance = null;
    private $assets_enqueued = false;

    public function __construct() {
        self::$instance = $this;

        \add_action( 'init', [ $this, 'register_cpt' ] );
        \add_action( 'init', [ $this, 'register_taxonomies' ] );
        \add_action( 'init', [ $this, 'register_registration_cpt' ] );
        \add_action( 'init', [ $this, 'register_meta' ] );

        \add_action( 'add_meta_boxes', [ $this, 'add_metaboxes' ] );
        \add_action( 'save_post_' . self::CPT, [ $this, 'save_meta' ] );

        \add_action( 'admin_enqueue_scripts', [ $this, 'admin_assets' ] );
        \add_action( 'wp_enqueue_scripts', [ $this, 'frontend_assets' ] );

        \add_filter( 'manage_' . self::CPT . '_posts_columns', [ $this, 'columns' ] );
        \add_action( 'manage_' . self::CPT . '_posts_custom_column', [ $this, 'render_column' ], 10, 2 );
        \add_filter( 'manage_edit-' . self::CPT . '_sortable_columns', [ $this, 'sortable_columns' ] );
        \add_action( 'pre_get_posts', [ $this, 'admin_sorting' ] );
        \add_filter( 'views_edit-' . self::CPT, [ $this, 'add_quick_filters' ] );
        \add_action( 'pre_get_posts', [ $this, 'apply_quick_filters' ] );
        \add_action( 'pre_get_posts', [ $this, 'filter_archive_query' ] );

        \add_filter( 'template_include', [ $this, 'template_include' ] );

        \add_shortcode( 'events_list', [ $this, 'shortcode_events_list' ] );
        \add_shortcode( 'event_calendar', [ $this, 'shortcode_event_calendar' ] );
        \add_shortcode( 'featured_events', [ $this, 'shortcode_featured_events' ] );

        \add_action( 'admin_menu', [ $this, 'register_settings_page' ] );
        \add_action( 'admin_init', [ $this, 'register_settings' ] );
        \add_action( 'admin_notices', [ $this, 'admin_notices' ] );

        \add_action( 'admin_post_anchor_event_register', [ $this, 'handle_registration' ] );
        \add_action( 'admin_post_nopriv_anchor_event_register', [ $this, 'handle_registration' ] );
        \add_action( 'admin_post_anchor_event_export', [ $this, 'handle_export' ] );

        \add_action( 'update_option_' . self::OPTION_KEY, [ $this, 'handle_settings_update' ], 10, 2 );
        \add_action( 'before_delete_post', [ $this, 'clear_caches_on_delete' ] );
    }

    public static function instance() {
        return self::$instance;
    }

    public function register_cpt() {
        $settings = $this->get_settings();
        $slug = sanitize_title( $settings['event_slug'] );
        if ( ! $slug ) {
            $slug = 'event';
        }

        $labels = [
            'name'               => __( 'Events', 'anchor-schema' ),
            'singular_name'      => __( 'Event', 'anchor-schema' ),
            'add_new_item'       => __( 'Add New Event', 'anchor-schema' ),
            'edit_item'          => __( 'Edit Event', 'anchor-schema' ),
            'new_item'           => __( 'New Event', 'anchor-schema' ),
            'view_item'          => __( 'View Event', 'anchor-schema' ),
            'search_items'       => __( 'Search Events', 'anchor-schema' ),
            'not_found'          => __( 'No events found.', 'anchor-schema' ),
            'not_found_in_trash' => __( 'No events found in Trash.', 'anchor-schema' ),
            'menu_name'          => __( 'Events', 'anchor-schema' ),
        ];

        \register_post_type( self::CPT, [
            'labels' => $labels,
            'public' => true,
            'has_archive' => true,
            'rewrite' => [ 'slug' => $slug ],
            'show_in_rest' => true,
            'supports' => [ 'title', 'editor', 'thumbnail', 'excerpt', 'custom-fields' ],
            'menu_icon' => 'dashicons-calendar-alt',
        ] );
    }

    public function register_taxonomies() {
        \register_taxonomy( 'event_category', self::CPT, [
            'label' => __( 'Event Categories', 'anchor-schema' ),
            'hierarchical' => true,
            'show_in_rest' => true,
            'rewrite' => [ 'slug' => 'event-category' ],
        ] );

        \register_taxonomy( 'event_tag', self::CPT, [
            'label' => __( 'Event Tags', 'anchor-schema' ),
            'hierarchical' => false,
            'show_in_rest' => true,
            'rewrite' => [ 'slug' => 'event-tag' ],
        ] );

        \register_taxonomy( 'event_type', self::CPT, [
            'label' => __( 'Event Types', 'anchor-schema' ),
            'hierarchical' => false,
            'show_in_rest' => true,
            'rewrite' => [ 'slug' => 'event-type' ],
        ] );
    }

    public function register_registration_cpt() {
        \register_post_type( self::REG_CPT, [
            'labels' => [
                'name' => __( 'Event Registrations', 'anchor-schema' ),
                'singular_name' => __( 'Event Registration', 'anchor-schema' ),
            ],
            'public' => false,
            'show_ui' => false,
            'show_in_rest' => true,
            'supports' => [ 'title' ],
            'capability_type' => 'post',
            'map_meta_cap' => true,
        ] );
    }

    public function register_meta() {
        foreach ( $this->get_meta_schema() as $key => $schema ) {
            \register_post_meta( self::CPT, $this->meta_key( $key ), array_merge( [
                'single' => true,
                'show_in_rest' => true,
            ], $schema ) );
        }

        \register_post_meta( self::REG_CPT, '_anchor_event_id', [
            'type' => 'integer',
            'single' => true,
            'show_in_rest' => true,
        ] );
        \register_post_meta( self::REG_CPT, '_anchor_event_name', [
            'type' => 'string',
            'single' => true,
            'show_in_rest' => true,
        ] );
        \register_post_meta( self::REG_CPT, '_anchor_event_email', [
            'type' => 'string',
            'single' => true,
            'show_in_rest' => true,
        ] );
        \register_post_meta( self::REG_CPT, '_anchor_event_reg_status', [
            'type' => 'string',
            'single' => true,
            'show_in_rest' => true,
        ] );
        \register_post_meta( self::REG_CPT, '_anchor_event_reg_fields', [
            'type' => 'array',
            'single' => true,
            'show_in_rest' => true,
        ] );
    }

    private function get_meta_schema() {
        return [
            'start_date' => [ 'type' => 'string' ],
            'end_date' => [ 'type' => 'string' ],
            'start_time' => [ 'type' => 'string' ],
            'end_time' => [ 'type' => 'string' ],
            'timezone' => [ 'type' => 'string' ],
            'all_day' => [ 'type' => 'boolean' ],
            'venue' => [ 'type' => 'string' ],
            'address_street' => [ 'type' => 'string' ],
            'address_city' => [ 'type' => 'string' ],
            'address_state' => [ 'type' => 'string' ],
            'address_zip' => [ 'type' => 'string' ],
            'address_country' => [ 'type' => 'string' ],
            'virtual' => [ 'type' => 'boolean' ],
            'virtual_url' => [ 'type' => 'string' ],
            'status_mode' => [ 'type' => 'string' ],
            'status' => [ 'type' => 'string' ],
            'registration_enabled' => [ 'type' => 'boolean' ],
            'capacity' => [ 'type' => 'integer' ],
            'registration_open' => [ 'type' => 'string' ],
            'registration_close' => [ 'type' => 'string' ],
            'waitlist' => [ 'type' => 'boolean' ],
            'registration_type' => [ 'type' => 'string' ],
            'registration_url' => [ 'type' => 'string' ],
            'price' => [ 'type' => 'string' ],
            'hide_from_archive' => [ 'type' => 'boolean' ],
            'featured' => [ 'type' => 'boolean' ],
            'priority' => [ 'type' => 'integer' ],
            'start_ts' => [ 'type' => 'integer' ],
            'end_ts' => [ 'type' => 'integer' ],
        ];
    }

    private function get_meta_defaults() {
        $timezone = \get_option( 'timezone_string' );
        if ( ! $timezone ) {
            $offset = \get_option( 'gmt_offset' );
            $timezone = $offset ? 'UTC' . ( $offset >= 0 ? '+' : '' ) . $offset : 'UTC';
        }

        return [
            'start_date' => '',
            'end_date' => '',
            'start_time' => '',
            'end_time' => '',
            'timezone' => $timezone,
            'all_day' => false,
            'venue' => '',
            'address_street' => '',
            'address_city' => '',
            'address_state' => '',
            'address_zip' => '',
            'address_country' => '',
            'virtual' => false,
            'virtual_url' => '',
            'status_mode' => 'auto',
            'status' => 'upcoming',
            'registration_enabled' => false,
            'capacity' => 0,
            'registration_open' => '',
            'registration_close' => '',
            'waitlist' => false,
            'registration_type' => 'internal',
            'registration_url' => '',
            'price' => '',
            'hide_from_archive' => false,
            'featured' => false,
            'priority' => 0,
            'start_ts' => 0,
            'end_ts' => 0,
        ];
    }

    public function add_metaboxes() {
        \add_meta_box(
            'anchor_event_details',
            __( 'Event Details', 'anchor-schema' ),
            [ $this, 'render_meta_box' ],
            self::CPT,
            'normal',
            'high'
        );

        \add_meta_box(
            'anchor_event_registrants',
            __( 'Registrations', 'anchor-schema' ),
            [ $this, 'render_registrants_metabox' ],
            self::CPT,
            'normal',
            'default'
        );
    }

    public function render_meta_box( $post ) {
        \wp_nonce_field( self::NONCE, self::NONCE );
        $meta = $this->get_meta( $post->ID );
        $settings = $this->get_settings();
        $timezone_options = \wp_timezone_choice( $meta['timezone'] );
        ?>
        <div class="anchor-event-meta">
            <div class="anchor-event-section">
                <h3><?php echo esc_html__( 'Date & Time', 'anchor-schema' ); ?></h3>
                <div class="anchor-event-grid">
                    <div class="anchor-event-field">
                        <label for="anchor_event_start_date"><?php echo esc_html__( 'Start Date', 'anchor-schema' ); ?></label>
                        <input type="date" id="anchor_event_start_date" name="anchor_event_start_date" value="<?php echo esc_attr( $meta['start_date'] ); ?>" required />
                    </div>
                    <div class="anchor-event-field">
                        <label for="anchor_event_end_date"><?php echo esc_html__( 'End Date', 'anchor-schema' ); ?></label>
                        <input type="date" id="anchor_event_end_date" name="anchor_event_end_date" value="<?php echo esc_attr( $meta['end_date'] ); ?>" />
                    </div>
                    <div class="anchor-event-field anchor-event-time-fields">
                        <label for="anchor_event_start_time"><?php echo esc_html__( 'Start Time', 'anchor-schema' ); ?></label>
                        <input type="time" id="anchor_event_start_time" name="anchor_event_start_time" value="<?php echo esc_attr( $meta['start_time'] ); ?>" />
                    </div>
                    <div class="anchor-event-field anchor-event-time-fields">
                        <label for="anchor_event_end_time"><?php echo esc_html__( 'End Time', 'anchor-schema' ); ?></label>
                        <input type="time" id="anchor_event_end_time" name="anchor_event_end_time" value="<?php echo esc_attr( $meta['end_time'] ); ?>" />
                    </div>
                    <div class="anchor-event-field">
                        <label for="anchor_event_timezone"><?php echo esc_html__( 'Timezone', 'anchor-schema' ); ?></label>
                        <select id="anchor_event_timezone" name="anchor_event_timezone">
                            <?php echo $timezone_options; ?>
                        </select>
                    </div>
                    <div class="anchor-event-field">
                        <label>
                            <input type="checkbox" id="anchor_event_all_day" name="anchor_event_all_day" value="1" <?php checked( $meta['all_day'] ); ?> />
                            <?php echo esc_html__( 'All day event', 'anchor-schema' ); ?>
                        </label>
                    </div>
                </div>
            </div>

            <div class="anchor-event-section">
                <h3><?php echo esc_html__( 'Location', 'anchor-schema' ); ?></h3>
                <div class="anchor-event-grid">
                    <div class="anchor-event-field">
                        <label for="anchor_event_venue"><?php echo esc_html__( 'Venue Name', 'anchor-schema' ); ?></label>
                        <input type="text" id="anchor_event_venue" name="anchor_event_venue" value="<?php echo esc_attr( $meta['venue'] ); ?>" />
                    </div>
                    <div class="anchor-event-field">
                        <label for="anchor_event_address_street"><?php echo esc_html__( 'Street Address', 'anchor-schema' ); ?></label>
                        <input type="text" id="anchor_event_address_street" name="anchor_event_address_street" value="<?php echo esc_attr( $meta['address_street'] ); ?>" />
                    </div>
                    <div class="anchor-event-field">
                        <label for="anchor_event_address_city"><?php echo esc_html__( 'City', 'anchor-schema' ); ?></label>
                        <input type="text" id="anchor_event_address_city" name="anchor_event_address_city" value="<?php echo esc_attr( $meta['address_city'] ); ?>" />
                    </div>
                    <div class="anchor-event-field">
                        <label for="anchor_event_address_state"><?php echo esc_html__( 'State/Region', 'anchor-schema' ); ?></label>
                        <input type="text" id="anchor_event_address_state" name="anchor_event_address_state" value="<?php echo esc_attr( $meta['address_state'] ); ?>" />
                    </div>
                    <div class="anchor-event-field">
                        <label for="anchor_event_address_zip"><?php echo esc_html__( 'Postal Code', 'anchor-schema' ); ?></label>
                        <input type="text" id="anchor_event_address_zip" name="anchor_event_address_zip" value="<?php echo esc_attr( $meta['address_zip'] ); ?>" />
                    </div>
                    <div class="anchor-event-field">
                        <label for="anchor_event_address_country"><?php echo esc_html__( 'Country', 'anchor-schema' ); ?></label>
                        <input type="text" id="anchor_event_address_country" name="anchor_event_address_country" value="<?php echo esc_attr( $meta['address_country'] ); ?>" />
                    </div>
                    <div class="anchor-event-field">
                        <label>
                            <input type="checkbox" id="anchor_event_virtual" name="anchor_event_virtual" value="1" <?php checked( $meta['virtual'] ); ?> />
                            <?php echo esc_html__( 'Virtual event', 'anchor-schema' ); ?>
                        </label>
                    </div>
                    <div class="anchor-event-field" id="anchor-event-virtual-url">
                        <label for="anchor_event_virtual_url"><?php echo esc_html__( 'Virtual Event URL', 'anchor-schema' ); ?></label>
                        <input type="url" id="anchor_event_virtual_url" name="anchor_event_virtual_url" value="<?php echo esc_attr( $meta['virtual_url'] ); ?>" />
                    </div>
                </div>
            </div>

            <div class="anchor-event-section">
                <h3><?php echo esc_html__( 'Status', 'anchor-schema' ); ?></h3>
                <div class="anchor-event-grid">
                    <div class="anchor-event-field">
                        <label for="anchor_event_status"><?php echo esc_html__( 'Event Status', 'anchor-schema' ); ?></label>
                        <select id="anchor_event_status" name="anchor_event_status">
                            <option value="auto" <?php selected( $meta['status_mode'], 'auto' ); ?>><?php echo esc_html__( 'Auto (based on dates)', 'anchor-schema' ); ?></option>
                            <?php foreach ( $this->get_status_options() as $key => $label ) : ?>
                                <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $meta['status_mode'] === 'manual' && $meta['status'] === $key ); ?>><?php echo esc_html( $label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">
                            <?php echo esc_html__( 'Auto status updates based on dates but can be overridden manually.', 'anchor-schema' ); ?>
                        </p>
                    </div>
                </div>
            </div>

            <div class="anchor-event-section">
                <h3><?php echo esc_html__( 'Registration', 'anchor-schema' ); ?></h3>
                <div class="anchor-event-grid">
                    <div class="anchor-event-field">
                        <label>
                            <input type="checkbox" id="anchor_event_registration_enabled" name="anchor_event_registration_enabled" value="1" <?php checked( $meta['registration_enabled'] ); ?> />
                            <?php echo esc_html__( 'Enable registration', 'anchor-schema' ); ?>
                        </label>
                        <?php if ( empty( $settings['registration_internal'] ) ) : ?>
                            <p class="description"><?php echo esc_html__( 'Internal registration is disabled in Events settings. External registration URLs are still available.', 'anchor-schema' ); ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="anchor-event-field anchor-event-registration-fields">
                        <label for="anchor_event_capacity"><?php echo esc_html__( 'Maximum capacity', 'anchor-schema' ); ?></label>
                        <input type="number" id="anchor_event_capacity" name="anchor_event_capacity" min="0" value="<?php echo esc_attr( $meta['capacity'] ); ?>" />
                    </div>
                    <div class="anchor-event-field anchor-event-registration-fields">
                        <label for="anchor_event_registration_open"><?php echo esc_html__( 'Registration opens', 'anchor-schema' ); ?></label>
                        <input type="date" id="anchor_event_registration_open" name="anchor_event_registration_open" value="<?php echo esc_attr( $meta['registration_open'] ); ?>" />
                    </div>
                    <div class="anchor-event-field anchor-event-registration-fields">
                        <label for="anchor_event_registration_close"><?php echo esc_html__( 'Registration closes', 'anchor-schema' ); ?></label>
                        <input type="date" id="anchor_event_registration_close" name="anchor_event_registration_close" value="<?php echo esc_attr( $meta['registration_close'] ); ?>" />
                    </div>
                    <div class="anchor-event-field anchor-event-registration-fields">
                        <label>
                            <input type="checkbox" id="anchor_event_waitlist" name="anchor_event_waitlist" value="1" <?php checked( $meta['waitlist'] ); ?> />
                            <?php echo esc_html__( 'Enable waitlist', 'anchor-schema' ); ?>
                        </label>
                    </div>
                    <div class="anchor-event-field anchor-event-registration-fields">
                        <label for="anchor_event_registration_type"><?php echo esc_html__( 'Registration type', 'anchor-schema' ); ?></label>
                        <select id="anchor_event_registration_type" name="anchor_event_registration_type">
                            <option value="internal" <?php selected( $meta['registration_type'], 'internal' ); ?>><?php echo esc_html__( 'Internal', 'anchor-schema' ); ?></option>
                            <option value="external" <?php selected( $meta['registration_type'], 'external' ); ?>><?php echo esc_html__( 'External URL', 'anchor-schema' ); ?></option>
                        </select>
                    </div>
                    <div class="anchor-event-field anchor-event-registration-fields" id="anchor-event-registration-url">
                        <label for="anchor_event_registration_url"><?php echo esc_html__( 'External Registration URL', 'anchor-schema' ); ?></label>
                        <input type="url" id="anchor_event_registration_url" name="anchor_event_registration_url" value="<?php echo esc_attr( $meta['registration_url'] ); ?>" />
                    </div>
                    <div class="anchor-event-field anchor-event-registration-fields">
                        <label for="anchor_event_price"><?php echo esc_html__( 'Price (optional)', 'anchor-schema' ); ?></label>
                        <input type="text" id="anchor_event_price" name="anchor_event_price" value="<?php echo esc_attr( $meta['price'] ); ?>" />
                    </div>
                </div>
            </div>

            <div class="anchor-event-section">
                <h3><?php echo esc_html__( 'Display Controls', 'anchor-schema' ); ?></h3>
                <div class="anchor-event-grid">
                    <div class="anchor-event-field">
                        <label>
                            <input type="checkbox" id="anchor_event_hide_from_archive" name="anchor_event_hide_from_archive" value="1" <?php checked( $meta['hide_from_archive'] ); ?> />
                            <?php echo esc_html__( 'Hide from archive', 'anchor-schema' ); ?>
                        </label>
                    </div>
                    <div class="anchor-event-field">
                        <label>
                            <input type="checkbox" id="anchor_event_featured" name="anchor_event_featured" value="1" <?php checked( $meta['featured'] ); ?> />
                            <?php echo esc_html__( 'Featured / pinned', 'anchor-schema' ); ?>
                        </label>
                    </div>
                    <div class="anchor-event-field">
                        <label for="anchor_event_priority"><?php echo esc_html__( 'Priority order', 'anchor-schema' ); ?></label>
                        <input type="number" id="anchor_event_priority" name="anchor_event_priority" value="<?php echo esc_attr( $meta['priority'] ); ?>" />
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    public function render_registrants_metabox( $post ) {
        $registrations = $this->get_registrations( $post->ID );
        $count = $this->get_registration_count( $post->ID );
        $waitlist = $this->get_registration_count( $post->ID, 'waitlist' );
        $export_url = \wp_nonce_url(
            \admin_url( 'admin-post.php?action=anchor_event_export&event_id=' . $post->ID ),
            'anchor_event_export'
        );
        ?>
        <p><strong><?php echo esc_html__( 'Registrations', 'anchor-schema' ); ?>:</strong> <?php echo esc_html( $count ); ?></p>
        <?php if ( $waitlist ) : ?>
            <p><strong><?php echo esc_html__( 'Waitlist', 'anchor-schema' ); ?>:</strong> <?php echo esc_html( $waitlist ); ?></p>
        <?php endif; ?>
        <p>
            <a class="button" href="<?php echo esc_url( $export_url ); ?>"><?php echo esc_html__( 'Export CSV', 'anchor-schema' ); ?></a>
        </p>
        <div class="anchor-event-registrants">
            <?php if ( empty( $registrations ) ) : ?>
                <p class="description"><?php echo esc_html__( 'No registrations yet.', 'anchor-schema' ); ?></p>
            <?php else : ?>
                <table class="widefat">
                    <thead>
                        <tr>
                            <th><?php echo esc_html__( 'Name', 'anchor-schema' ); ?></th>
                            <th><?php echo esc_html__( 'Email', 'anchor-schema' ); ?></th>
                            <th><?php echo esc_html__( 'Status', 'anchor-schema' ); ?></th>
                            <th><?php echo esc_html__( 'Date', 'anchor-schema' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $registrations as $reg ) : ?>
                            <tr>
                                <td><?php echo esc_html( $reg['name'] ); ?></td>
                                <td><?php echo esc_html( $reg['email'] ); ?></td>
                                <td><?php echo esc_html( ucfirst( $reg['status'] ) ); ?></td>
                                <td><?php echo esc_html( $reg['date'] ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
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

        $input = [
            'start_date' => $this->sanitize_date( $_POST['anchor_event_start_date'] ?? '' ),
            'end_date' => $this->sanitize_date( $_POST['anchor_event_end_date'] ?? '' ),
            'start_time' => $this->sanitize_time( $_POST['anchor_event_start_time'] ?? '' ),
            'end_time' => $this->sanitize_time( $_POST['anchor_event_end_time'] ?? '' ),
            'timezone' => sanitize_text_field( $_POST['anchor_event_timezone'] ?? '' ),
            'all_day' => ! empty( $_POST['anchor_event_all_day'] ),
            'venue' => sanitize_text_field( $_POST['anchor_event_venue'] ?? '' ),
            'address_street' => sanitize_text_field( $_POST['anchor_event_address_street'] ?? '' ),
            'address_city' => sanitize_text_field( $_POST['anchor_event_address_city'] ?? '' ),
            'address_state' => sanitize_text_field( $_POST['anchor_event_address_state'] ?? '' ),
            'address_zip' => sanitize_text_field( $_POST['anchor_event_address_zip'] ?? '' ),
            'address_country' => sanitize_text_field( $_POST['anchor_event_address_country'] ?? '' ),
            'virtual' => ! empty( $_POST['anchor_event_virtual'] ),
            'virtual_url' => esc_url_raw( $_POST['anchor_event_virtual_url'] ?? '' ),
            'registration_enabled' => ! empty( $_POST['anchor_event_registration_enabled'] ),
            'capacity' => (int) ( $_POST['anchor_event_capacity'] ?? 0 ),
            'registration_open' => $this->sanitize_date( $_POST['anchor_event_registration_open'] ?? '' ),
            'registration_close' => $this->sanitize_date( $_POST['anchor_event_registration_close'] ?? '' ),
            'waitlist' => ! empty( $_POST['anchor_event_waitlist'] ),
            'registration_type' => sanitize_text_field( $_POST['anchor_event_registration_type'] ?? 'internal' ),
            'registration_url' => esc_url_raw( $_POST['anchor_event_registration_url'] ?? '' ),
            'price' => sanitize_text_field( $_POST['anchor_event_price'] ?? '' ),
            'hide_from_archive' => ! empty( $_POST['anchor_event_hide_from_archive'] ),
            'featured' => ! empty( $_POST['anchor_event_featured'] ),
            'priority' => (int) ( $_POST['anchor_event_priority'] ?? 0 ),
        ];

        if ( ! $input['start_date'] ) {
            \add_filter( 'redirect_post_location', function( $location ) {
                return \add_query_arg( 'anchor_event_notice', 'missing_start_date', $location );
            } );
        }

        $status_raw = sanitize_text_field( $_POST['anchor_event_status'] ?? 'auto' );
        if ( $status_raw === 'auto' ) {
            $input['status_mode'] = 'auto';
            $input['status'] = $this->calculate_status( $input );
        } else {
            $input['status_mode'] = 'manual';
            $input['status'] = in_array( $status_raw, array_keys( $this->get_status_options() ), true ) ? $status_raw : 'upcoming';
        }

        $timestamps = $this->calculate_timestamps( $input );
        $input['start_ts'] = $timestamps['start'];
        $input['end_ts'] = $timestamps['end'];

        foreach ( $input as $key => $value ) {
            \update_post_meta( $post_id, $this->meta_key( $key ), $value );
        }

        $this->clear_caches();
    }

    public function admin_notices() {
        if ( ! isset( $_GET['anchor_event_notice'] ) ) {
            return;
        }
        $notice = sanitize_text_field( $_GET['anchor_event_notice'] );
        if ( $notice === 'missing_start_date' ) {
            echo '<div class="notice notice-error"><p>' . esc_html__( 'Event start date is required.', 'anchor-schema' ) . '</p></div>';
        }
    }

    public function admin_assets( $hook ) {
        if ( ! in_array( $hook, [ 'post-new.php', 'post.php' ], true ) ) {
            return;
        }
        $screen = \get_current_screen();
        if ( ! $screen || $screen->post_type !== self::CPT ) {
            return;
        }
        \wp_enqueue_style( 'anchor-events-admin', \plugins_url( 'assets/admin.css', __FILE__ ), [], '1.0.0' );
        \wp_enqueue_script( 'anchor-events-admin', \plugins_url( 'assets/admin.js', __FILE__ ), [ 'jquery' ], '1.0.0', true );
    }

    public function frontend_assets() {
        if ( \is_admin() ) {
            return;
        }
        if ( \is_singular( self::CPT ) || \is_post_type_archive( self::CPT ) ) {
            $this->enqueue_frontend_assets();
        }
    }

    public function enqueue_frontend_assets() {
        if ( $this->assets_enqueued ) {
            return;
        }
        \wp_enqueue_style( 'anchor-events-frontend', \plugins_url( 'assets/frontend.css', __FILE__ ), [], '1.0.0' );
        $this->assets_enqueued = true;
    }

    public function columns( $columns ) {
        $columns['anchor_event_start'] = __( 'Start Date', 'anchor-schema' );
        $columns['anchor_event_status'] = __( 'Status', 'anchor-schema' );
        $columns['anchor_event_venue'] = __( 'Venue', 'anchor-schema' );
        $columns['anchor_event_capacity'] = __( 'Capacity', 'anchor-schema' );
        return $columns;
    }

    public function render_column( $column, $post_id ) {
        $meta = $this->get_meta( $post_id );
        switch ( $column ) {
            case 'anchor_event_start':
                echo esc_html( $this->format_date_time( $meta ) );
                break;
            case 'anchor_event_status':
                echo esc_html( ucfirst( $this->get_event_status( $post_id, $meta ) ) );
                break;
            case 'anchor_event_venue':
                echo esc_html( $meta['venue'] );
                break;
            case 'anchor_event_capacity':
                echo esc_html( $meta['capacity'] ? $meta['capacity'] : '-' );
                break;
        }
    }

    public function sortable_columns( $columns ) {
        $columns['anchor_event_start'] = 'anchor_event_start';
        $columns['anchor_event_status'] = 'anchor_event_status';
        $columns['anchor_event_venue'] = 'anchor_event_venue';
        $columns['anchor_event_capacity'] = 'anchor_event_capacity';
        return $columns;
    }

    public function admin_sorting( $query ) {
        if ( ! \is_admin() || ! $query->is_main_query() ) {
            return;
        }
        if ( $query->get( 'post_type' ) !== self::CPT ) {
            return;
        }
        $orderby = $query->get( 'orderby' );
        switch ( $orderby ) {
            case 'anchor_event_start':
                $query->set( 'meta_key', $this->meta_key( 'start_ts' ) );
                $query->set( 'orderby', 'meta_value_num' );
                break;
            case 'anchor_event_status':
                $query->set( 'meta_key', $this->meta_key( 'status' ) );
                $query->set( 'orderby', 'meta_value' );
                break;
            case 'anchor_event_venue':
                $query->set( 'meta_key', $this->meta_key( 'venue' ) );
                $query->set( 'orderby', 'meta_value' );
                break;
            case 'anchor_event_capacity':
                $query->set( 'meta_key', $this->meta_key( 'capacity' ) );
                $query->set( 'orderby', 'meta_value_num' );
                break;
        }
    }

    public function add_quick_filters( $views ) {
        $base_url = \admin_url( 'edit.php?post_type=' . self::CPT );
        $current = sanitize_text_field( $_GET['event_status'] ?? '' );
        $statuses = [
            'upcoming' => __( 'Upcoming', 'anchor-schema' ),
            'past' => __( 'Past', 'anchor-schema' ),
            'cancelled' => __( 'Cancelled', 'anchor-schema' ),
        ];
        foreach ( $statuses as $key => $label ) {
            $count = $this->count_events_by_status( $key );
            $url = \add_query_arg( 'event_status', $key, $base_url );
            $class = $current === $key ? 'class="current"' : '';
            $views[ 'anchor_event_' . $key ] = '<a href="' . esc_url( $url ) . '" ' . $class . '>' . esc_html( $label ) . ' <span class="count">(' . intval( $count ) . ')</span></a>';
        }
        return $views;
    }

    public function apply_quick_filters( $query ) {
        if ( ! \is_admin() || ! $query->is_main_query() ) {
            return;
        }
        if ( $query->get( 'post_type' ) !== self::CPT ) {
            return;
        }
        $status = sanitize_text_field( $_GET['event_status'] ?? '' );
        if ( ! $status ) {
            return;
        }
        $query->set( 'meta_query', [
            [
                'key' => $this->meta_key( 'status' ),
                'value' => $status,
                'compare' => '=',
            ],
        ] );
    }

    public function template_include( $template ) {
        if ( \is_singular( self::CPT ) ) {
            return $this->locate_template( 'single-event.php' );
        }
        if ( \is_post_type_archive( self::CPT ) ) {
            return $this->locate_template( 'archive-event.php' );
        }
        return $template;
    }

    private function locate_template( $file ) {
        $settings = $this->get_settings();
        if ( $settings['template_source'] === 'theme' ) {
            $theme_template = \locate_template( 'events/' . $file );
            if ( $theme_template ) {
                return $theme_template;
            }
        }
        return \plugin_dir_path( __FILE__ ) . 'templates/' . $file;
    }

    public function shortcode_events_list( $atts ) {
        $atts = shortcode_atts( [
            'category' => '',
            'tag' => '',
            'type' => '',
            'status' => '',
            'start_date' => '',
            'end_date' => '',
            'orderby' => 'date',
            'order' => 'ASC',
            'limit' => 10,
            'show_past' => 'no',
        ], $atts );

        return $this->render_events_list( $atts, 'shortcode' );
    }

    public function shortcode_featured_events( $atts ) {
        $atts = shortcode_atts( [
            'limit' => 5,
            'orderby' => 'priority',
            'order' => 'DESC',
        ], $atts );
        $atts['featured'] = 'yes';
        return $this->render_events_list( $atts, 'featured' );
    }

    public function shortcode_event_calendar( $atts ) {
        $atts = shortcode_atts( [
            'view' => 'month',
            'month' => '',
            'show_past' => 'yes',
        ], $atts );

        $this->enqueue_frontend_assets();

        if ( $atts['view'] === 'list' ) {
            return $this->render_events_list( [ 'limit' => 20 ], 'calendar' );
        }

        $month = $atts['month'] ? sanitize_text_field( $atts['month'] ) : date( 'Y-m' );
        $month_start = $month . '-01';
        $timezone = \get_option( 'timezone_string' ) ?: 'UTC';
        $start = $this->to_timestamp( $month_start, '00:00', $timezone );
        $end = strtotime( '+1 month', strtotime( $month_start ) );

        $meta_query = [
            [
                'key' => $this->meta_key( 'start_ts' ),
                'value' => [ $start, $end ],
                'compare' => 'BETWEEN',
                'type' => 'NUMERIC',
            ],
            $this->build_hide_clause(),
        ];
        if ( $atts['show_past'] === 'no' ) {
            $meta_query[] = $this->build_visibility_clause();
        }

        $args = [
            'post_type' => self::CPT,
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'meta_query' => $meta_query,
            'orderby' => 'meta_value_num',
            'meta_key' => $this->meta_key( 'start_ts' ),
            'order' => 'ASC',
        ];

        $args = \apply_filters( 'anchor_events_query_args', $args, $atts );
        $events = $this->get_cached_ids( $args );
        $by_day = [];
        foreach ( $events as $event_id ) {
            $meta = $this->get_meta( $event_id );
            $day = $meta['start_date'];
            if ( ! $day ) {
                continue;
            }
            if ( ! isset( $by_day[ $day ] ) ) {
                $by_day[ $day ] = [];
            }
            $by_day[ $day ][] = $event_id;
        }

        $calendar_month = $month_start;
        $calendar_first = strtotime( $month_start );
        $calendar_days = (int) date( 't', $calendar_first );
        $calendar_start_weekday = (int) date( 'N', $calendar_first );
        $calendar_events = $by_day;

        $template = $this->locate_template( 'calendar.php' );
        if ( $template && file_exists( $template ) ) {
            ob_start();
            include $template;
            return ob_get_clean();
        }

        return '<div class="anchor-events-empty">' . esc_html__( 'Calendar template not found.', 'anchor-schema' ) . '</div>';
    }

    public function render_events_list( $atts, $context ) {
        $atts = \wp_parse_args( $atts, [
            'limit' => 10,
            'orderby' => 'date',
            'order' => 'ASC',
            'show_past' => 'no',
            'featured' => 'no',
        ] );

        $meta_query = [];
        if ( ! empty( $atts['status'] ) ) {
            $meta_query[] = [
                'key' => $this->meta_key( 'status' ),
                'value' => sanitize_text_field( $atts['status'] ),
                'compare' => '=',
            ];
        }

        if ( empty( $atts['show_past'] ) || $atts['show_past'] === 'no' ) {
            $meta_query[] = $this->build_visibility_clause();
        }
        $meta_query[] = $this->build_hide_clause();

        if ( ! empty( $atts['featured'] ) && $atts['featured'] === 'yes' ) {
            $meta_query[] = [
                'key' => $this->meta_key( 'featured' ),
                'value' => '1',
                'compare' => '=',
            ];
        }

        if ( ! empty( $atts['start_date'] ) || ! empty( $atts['end_date'] ) ) {
            $range = $this->build_range_clause( $atts['start_date'] ?? '', $atts['end_date'] ?? '' );
            if ( $range ) {
                $meta_query[] = $range;
            }
        }

        $tax_query = [];
        if ( ! empty( $atts['category'] ) ) {
            $tax_query[] = [
                'taxonomy' => 'event_category',
                'field' => 'slug',
                'terms' => array_map( 'sanitize_title', explode( ',', $atts['category'] ) ),
            ];
        }
        if ( ! empty( $atts['tag'] ) ) {
            $tax_query[] = [
                'taxonomy' => 'event_tag',
                'field' => 'slug',
                'terms' => array_map( 'sanitize_title', explode( ',', $atts['tag'] ) ),
            ];
        }
        if ( ! empty( $atts['type'] ) ) {
            $tax_query[] = [
                'taxonomy' => 'event_type',
                'field' => 'slug',
                'terms' => array_map( 'sanitize_title', explode( ',', $atts['type'] ) ),
            ];
        }

        $orderby = strtolower( $atts['orderby'] );
        $order = strtoupper( $atts['order'] ) === 'DESC' ? 'DESC' : 'ASC';

        $limit = (int) $atts['limit'];
        if ( $limit === 0 ) {
            $limit = -1;
        }

        $query_args = [
            'post_type' => self::CPT,
            'post_status' => 'publish',
            'posts_per_page' => $limit,
            'orderby' => 'meta_value_num',
            'meta_key' => $this->meta_key( 'start_ts' ),
            'order' => $order,
            'meta_query' => $meta_query,
            'tax_query' => $tax_query,
        ];

        if ( $orderby === 'title' ) {
            $query_args['orderby'] = 'title';
            unset( $query_args['meta_key'] );
        } elseif ( $orderby === 'priority' ) {
            $query_args['meta_key'] = $this->meta_key( 'priority' );
            $query_args['orderby'] = 'meta_value_num';
        }

        $query_args = \apply_filters( 'anchor_events_query_args', $query_args, $atts );
        $ids = $this->get_cached_ids( $query_args );

        if ( empty( $ids ) ) {
            return '<div class="anchor-events-empty">' . esc_html__( 'No events found.', 'anchor-schema' ) . '</div>';
        }

        $this->enqueue_frontend_assets();

        $output = '<div class="anchor-events-list anchor-events-context-' . esc_attr( $context ) . '">';
        foreach ( $ids as $event_id ) {
            $output .= $this->render_event_card( $event_id, $context );
        }
        $output .= '</div>';

        return $output;
    }

    public function render_event_card( $post_id, $context ) {
        $meta = $this->get_meta( $post_id );
        $status = $this->get_event_status( $post_id, $meta );
        $classes = [
            'anchor-event-card',
            'anchor-event-status-' . $status,
        ];
        $classes = \apply_filters( 'anchor_events_event_classes', $classes, $post_id, $context );

        \do_action( 'anchor_events_before_render', $post_id, $context );

        $output = '<article class="' . esc_attr( implode( ' ', $classes ) ) . '" data-status="' . esc_attr( $status ) . '">';
        $output .= '<div class="anchor-event-card-header">';
        if ( \has_post_thumbnail( $post_id ) ) {
            $output .= '<div class="anchor-event-thumb">' . \get_the_post_thumbnail( $post_id, 'medium' ) . '</div>';
        }
        $output .= '<h3 class="anchor-event-title"><a href="' . esc_url( \get_permalink( $post_id ) ) . '">' . esc_html( \get_the_title( $post_id ) ) . '</a></h3>';
        $output .= '</div>';
        $output .= '<div class="anchor-event-meta">' . esc_html( $this->format_date_time( $meta ) ) . '</div>';
        if ( $meta['venue'] ) {
            $output .= '<div class="anchor-event-meta">' . esc_html( $meta['venue'] ) . '</div>';
        }
        $excerpt = \get_the_excerpt( $post_id );
        if ( $excerpt ) {
            $output .= '<div class="anchor-event-excerpt">' . esc_html( $excerpt ) . '</div>';
        }
        $output .= '<div class="anchor-event-actions"><a class="anchor-event-button" href="' . esc_url( \get_permalink( $post_id ) ) . '">' . esc_html__( 'View Event', 'anchor-schema' ) . '</a></div>';
        $output .= '</article>';

        \do_action( 'anchor_events_after_render', $post_id, $context );

        return $output;
    }

    public function render_registration_notice() {
        if ( empty( $_GET['event_registration'] ) ) {
            return '';
        }
        $key = sanitize_text_field( $_GET['event_registration'] );
        $messages = [
            'registration_success' => __( 'Registration received. Check your email for confirmation.', 'anchor-schema' ),
            'registration_closed' => __( 'Registration is closed for this event.', 'anchor-schema' ),
            'registration_invalid' => __( 'Please complete all required registration fields.', 'anchor-schema' ),
            'registration_error' => __( 'Registration could not be processed. Please try again.', 'anchor-schema' ),
        ];
        if ( ! isset( $messages[ $key ] ) ) {
            return '';
        }
        return '<div class="anchor-event-notice">' . esc_html( $messages[ $key ] ) . '</div>';
    }

    public function render_single_content( $post_id ) {
        $meta = $this->get_meta( $post_id );
        $status = $this->get_event_status( $post_id, $meta );

        $output = '<section class="anchor-event-detail">';
        $output .= '<div class="anchor-event-detail-meta">';
        $output .= '<div><strong>' . esc_html__( 'Date', 'anchor-schema' ) . ':</strong> ' . esc_html( $this->format_date_time( $meta, true ) ) . '</div>';
        if ( $meta['venue'] ) {
            $output .= '<div><strong>' . esc_html__( 'Venue', 'anchor-schema' ) . ':</strong> ' . esc_html( $meta['venue'] ) . '</div>';
        }
        if ( $meta['virtual'] && $meta['virtual_url'] ) {
            $output .= '<div><strong>' . esc_html__( 'Virtual Event', 'anchor-schema' ) . ':</strong> <a href="' . esc_url( $meta['virtual_url'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'Join here', 'anchor-schema' ) . '</a></div>';
        }
        $output .= '<div><strong>' . esc_html__( 'Status', 'anchor-schema' ) . ':</strong> ' . esc_html( ucfirst( $status ) ) . '</div>';
        $output .= '</div>';

        $address = $this->format_address( $meta );
        if ( $address ) {
            $output .= '<div class="anchor-event-address"><strong>' . esc_html__( 'Address', 'anchor-schema' ) . ':</strong> ' . esc_html( $address ) . '</div>';
        }

        $output .= '</section>';
        return $output;
    }

    public function render_registration_form( $post_id ) {
        $settings = $this->get_settings();
        $meta = $this->get_meta( $post_id );

        if ( ! $meta['registration_enabled'] ) {
            return '';
        }

        if ( $meta['registration_type'] === 'external' ) {
            if ( $meta['registration_url'] ) {
                return '<div class="anchor-event-registration"><a class="anchor-event-button" href="' . esc_url( $meta['registration_url'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'Register', 'anchor-schema' ) . '</a></div>';
            }
            return '<div class="anchor-event-registration anchor-event-registration-closed">' . esc_html__( 'Registration link unavailable.', 'anchor-schema' ) . '</div>';
        }

        if ( empty( $settings['registration_internal'] ) ) {
            return '<div class="anchor-event-registration anchor-event-registration-closed">' . esc_html__( 'Registration is currently disabled.', 'anchor-schema' ) . '</div>';
        }

        $status = $this->get_registration_status( $post_id, $meta );
        if ( $status === 'closed' ) {
            return '<div class="anchor-event-registration anchor-event-registration-closed">' . esc_html__( 'Registration is closed.', 'anchor-schema' ) . '</div>';
        }
        if ( $status === 'full' ) {
            return '<div class="anchor-event-registration anchor-event-registration-closed">' . esc_html__( 'This event is full.', 'anchor-schema' ) . '</div>';
        }
        $notice = '';
        if ( $status === 'waitlist' ) {
            $notice = '<div class="anchor-event-notice">' . esc_html__( 'This event is full. You will be added to the waitlist.', 'anchor-schema' ) . '</div>';
        }

        $fields = $this->get_registration_fields();
        $redirect = \get_permalink( $post_id );

        $output = $notice;
        $output .= '<form class="anchor-event-registration" method="post" action="' . esc_url( \admin_url( 'admin-post.php' ) ) . '">';
        $output .= '<input type="hidden" name="action" value="anchor_event_register" />';
        $output .= '<input type="hidden" name="event_id" value="' . esc_attr( $post_id ) . '" />';
        $output .= '<input type="hidden" name="redirect_to" value="' . esc_url( $redirect ) . '" />';
        $output .= \wp_nonce_field( self::REG_NONCE, self::REG_NONCE, true, false );
        $output .= '<div class="anchor-event-field">';
        $output .= '<label for="anchor_event_name">' . esc_html__( 'Name', 'anchor-schema' ) . '</label>';
        $output .= '<input type="text" id="anchor_event_name" name="anchor_event_name" required />';
        $output .= '</div>';
        $output .= '<div class="anchor-event-field">';
        $output .= '<label for="anchor_event_email">' . esc_html__( 'Email', 'anchor-schema' ) . '</label>';
        $output .= '<input type="email" id="anchor_event_email" name="anchor_event_email" required />';
        $output .= '</div>';

        foreach ( $fields as $field ) {
            $field_id = sanitize_key( $field['id'] );
            $label = $field['label'] ?? $field_id;
            $type = $field['type'] ?? 'text';
            $required = ! empty( $field['required'] );
            $output .= '<div class="anchor-event-field">';
            $output .= '<label for="anchor_event_field_' . esc_attr( $field_id ) . '">' . esc_html( $label ) . '</label>';
            $output .= '<input type="' . esc_attr( $type ) . '" id="anchor_event_field_' . esc_attr( $field_id ) . '" name="anchor_event_field[' . esc_attr( $field_id ) . ']"' . ( $required ? ' required' : '' ) . ' />';
            $output .= '</div>';
        }

        $output .= '<button type="submit" class="anchor-event-button">' . esc_html__( 'Register', 'anchor-schema' ) . '</button>';
        $output .= '</form>';

        return $output;
    }

    public function handle_registration() {
        if ( ! isset( $_POST[ self::REG_NONCE ] ) || ! \wp_verify_nonce( $_POST[ self::REG_NONCE ], self::REG_NONCE ) ) {
            \wp_die( esc_html__( 'Invalid registration request.', 'anchor-schema' ) );
        }

        $event_id = (int) ( $_POST['event_id'] ?? 0 );
        $redirect = esc_url_raw( $_POST['redirect_to'] ?? '' );
        if ( ! $event_id ) {
            \wp_safe_redirect( $redirect ?: \home_url() );
            exit;
        }

        $meta = $this->get_meta( $event_id );
        $settings = $this->get_settings();

        if ( ! $meta['registration_enabled'] || empty( $settings['registration_internal'] ) ) {
            \wp_safe_redirect( $this->with_message( $redirect, 'registration_closed' ) );
            exit;
        }
        if ( $meta['registration_type'] === 'external' ) {
            \wp_safe_redirect( $this->with_message( $redirect, 'registration_closed' ) );
            exit;
        }

        $status = $this->get_registration_status( $event_id, $meta );
        if ( $status === 'closed' || $status === 'full' ) {
            \wp_safe_redirect( $this->with_message( $redirect, 'registration_closed' ) );
            exit;
        }

        $name = sanitize_text_field( $_POST['anchor_event_name'] ?? '' );
        $email = sanitize_email( $_POST['anchor_event_email'] ?? '' );
        if ( ! $name || ! $email ) {
            \wp_safe_redirect( $this->with_message( $redirect, 'registration_invalid' ) );
            exit;
        }

        $extra_fields = [];
        if ( ! empty( $_POST['anchor_event_field'] ) && is_array( $_POST['anchor_event_field'] ) ) {
            foreach ( $_POST['anchor_event_field'] as $key => $value ) {
                $extra_fields[ sanitize_key( $key ) ] = sanitize_text_field( $value );
            }
        }

        $reg_status = 'confirmed';
        if ( $status === 'waitlist' ) {
            $reg_status = 'waitlist';
        }

        $reg_id = \wp_insert_post( [
            'post_type' => self::REG_CPT,
            'post_status' => 'publish',
            'post_title' => $name,
        ] );

        if ( is_wp_error( $reg_id ) ) {
            \wp_safe_redirect( $this->with_message( $redirect, 'registration_error' ) );
            exit;
        }

        \update_post_meta( $reg_id, '_anchor_event_id', $event_id );
        \update_post_meta( $reg_id, '_anchor_event_name', $name );
        \update_post_meta( $reg_id, '_anchor_event_email', $email );
        \update_post_meta( $reg_id, '_anchor_event_reg_status', $reg_status );
        \update_post_meta( $reg_id, '_anchor_event_reg_fields', $extra_fields );

        $this->send_registration_emails( $event_id, $name, $email, $reg_status );

        $this->clear_caches();

        \wp_safe_redirect( $this->with_message( $redirect, 'registration_success' ) );
        exit;
    }

    public function handle_export() {
        if ( ! \current_user_can( 'manage_options' ) ) {
            \wp_die( esc_html__( 'Unauthorized', 'anchor-schema' ) );
        }
        \check_admin_referer( 'anchor_event_export' );

        $event_id = (int) ( $_GET['event_id'] ?? 0 );
        if ( ! $event_id ) {
            \wp_die( esc_html__( 'Missing event.', 'anchor-schema' ) );
        }

        $query = new \WP_Query( [
            'post_type' => self::REG_CPT,
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'meta_query' => [
                [
                    'key' => '_anchor_event_id',
                    'value' => $event_id,
                    'compare' => '=',
                ],
            ],
            'orderby' => 'date',
            'order' => 'DESC',
        ] );

        $rows = [];
        $field_keys = [];
        foreach ( $query->posts as $post ) {
            $fields = \get_post_meta( $post->ID, '_anchor_event_reg_fields', true );
            if ( ! is_array( $fields ) ) {
                $fields = [];
            }
            foreach ( array_keys( $fields ) as $key ) {
                $field_keys[ $key ] = true;
            }
            $rows[] = [
                'name' => \get_post_meta( $post->ID, '_anchor_event_name', true ),
                'email' => \get_post_meta( $post->ID, '_anchor_event_email', true ),
                'status' => \get_post_meta( $post->ID, '_anchor_event_reg_status', true ) ?: 'confirmed',
                'date' => \get_the_date( 'Y-m-d', $post ),
                'fields' => $fields,
            ];
        }

        $field_keys = array_keys( $field_keys );

        header( 'Content-Type: text/csv' );
        header( 'Content-Disposition: attachment; filename="event-registrations-' . $event_id . '.csv"' );

        $out = fopen( 'php://output', 'w' );
        $header = array_merge( [ 'Name', 'Email', 'Status', 'Date' ], $field_keys );
        fputcsv( $out, $header );
        foreach ( $rows as $row ) {
            $data = [ $row['name'], $row['email'], $row['status'], $row['date'] ];
            foreach ( $field_keys as $key ) {
                $data[] = $row['fields'][ $key ] ?? '';
            }
            fputcsv( $out, $data );
        }
        fclose( $out );
        exit;
    }

    public function register_settings_page() {
        \add_options_page(
            __( 'Anchor Events Settings', 'anchor-schema' ),
            __( 'Anchor Events', 'anchor-schema' ),
            'manage_options',
            'anchor-events',
            [ $this, 'render_settings_page' ]
        );
    }

    public function register_settings() {
        \register_setting( 'anchor_events_group', self::OPTION_KEY, [ $this, 'sanitize_settings' ] );

        \add_settings_section( 'anchor_events_main', __( 'Event Defaults', 'anchor-schema' ), function() {
            echo '<p>' . esc_html__( 'Configure default behavior for events and archives.', 'anchor-schema' ) . '</p>';
        }, 'anchor_events_settings' );

        \add_settings_field( 'timezone_mode', __( 'Timezone behavior', 'anchor-schema' ), function() {
            $opts = $this->get_settings();
            $value = $opts['timezone_mode'];
            ?>
            <select name="<?php echo esc_attr( self::OPTION_KEY ); ?>[timezone_mode]">
                <option value="site" <?php selected( $value, 'site' ); ?>><?php echo esc_html__( 'Use site timezone by default', 'anchor-schema' ); ?></option>
                <option value="event" <?php selected( $value, 'event' ); ?>><?php echo esc_html__( 'Respect event timezone field', 'anchor-schema' ); ?></option>
            </select>
            <?php
        }, 'anchor_events_settings', 'anchor_events_main' );

        \add_settings_field( 'archive_hide_past', __( 'Archive past events', 'anchor-schema' ), function() {
            $opts = $this->get_settings();
            ?>
            <label>
                <input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[archive_hide_past]" value="1" <?php checked( $opts['archive_hide_past'] ); ?> />
                <?php echo esc_html__( 'Hide past events from archives by default', 'anchor-schema' ); ?>
            </label>
            <?php
        }, 'anchor_events_settings', 'anchor_events_main' );

        \add_settings_field( 'template_source', __( 'Template source', 'anchor-schema' ), function() {
            $opts = $this->get_settings();
            ?>
            <select name="<?php echo esc_attr( self::OPTION_KEY ); ?>[template_source]">
                <option value="theme" <?php selected( $opts['template_source'], 'theme' ); ?>><?php echo esc_html__( 'Use theme override when available', 'anchor-schema' ); ?></option>
                <option value="plugin" <?php selected( $opts['template_source'], 'plugin' ); ?>><?php echo esc_html__( 'Always use plugin templates', 'anchor-schema' ); ?></option>
            </select>
            <?php
        }, 'anchor_events_settings', 'anchor_events_main' );

        \add_settings_section( 'anchor_events_registration', __( 'Registration Settings', 'anchor-schema' ), function() {
            echo '<p>' . esc_html__( 'Control internal registration and email notifications.', 'anchor-schema' ) . '</p>';
        }, 'anchor_events_settings' );

        \add_settings_field( 'registration_internal', __( 'Internal registration', 'anchor-schema' ), function() {
            $opts = $this->get_settings();
            ?>
            <label>
                <input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[registration_internal]" value="1" <?php checked( $opts['registration_internal'] ); ?> />
                <?php echo esc_html__( 'Enable internal registration forms', 'anchor-schema' ); ?>
            </label>
            <?php
        }, 'anchor_events_settings', 'anchor_events_registration' );

        \add_settings_field( 'admin_email', __( 'Admin notification email', 'anchor-schema' ), function() {
            $opts = $this->get_settings();
            ?>
            <input type="email" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[admin_email]" value="<?php echo esc_attr( $opts['admin_email'] ); ?>" class="regular-text" />
            <p class="description"><?php echo esc_html__( 'Leave blank to use the site admin email.', 'anchor-schema' ); ?></p>
            <?php
        }, 'anchor_events_settings', 'anchor_events_registration' );

        \add_settings_field( 'notify_admin', __( 'Admin notifications', 'anchor-schema' ), function() {
            $opts = $this->get_settings();
            ?>
            <label>
                <input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[notify_admin]" value="1" <?php checked( $opts['notify_admin'] ); ?> />
                <?php echo esc_html__( 'Send admin email when a registration is submitted', 'anchor-schema' ); ?>
            </label>
            <?php
        }, 'anchor_events_settings', 'anchor_events_registration' );

        \add_settings_field( 'notify_user', __( 'User confirmations', 'anchor-schema' ), function() {
            $opts = $this->get_settings();
            ?>
            <label>
                <input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[notify_user]" value="1" <?php checked( $opts['notify_user'] ); ?> />
                <?php echo esc_html__( 'Send confirmation email to registrants', 'anchor-schema' ); ?>
            </label>
            <?php
        }, 'anchor_events_settings', 'anchor_events_registration' );

        \add_settings_section( 'anchor_events_slugs', __( 'Permalinks', 'anchor-schema' ), function() {
            echo '<p>' . esc_html__( 'Customize event URL slugs.', 'anchor-schema' ) . '</p>';
        }, 'anchor_events_settings' );

        \add_settings_field( 'event_slug', __( 'Event slug', 'anchor-schema' ), function() {
            $opts = $this->get_settings();
            ?>
            <input type="text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[event_slug]" value="<?php echo esc_attr( $opts['event_slug'] ); ?>" class="regular-text" />
            <?php
        }, 'anchor_events_settings', 'anchor_events_slugs' );
    }

    public function sanitize_settings( $input ) {
        $defaults = $this->get_settings();
        $output = [
            'timezone_mode' => in_array( $input['timezone_mode'] ?? 'site', [ 'site', 'event' ], true ) ? $input['timezone_mode'] : 'site',
            'archive_hide_past' => ! empty( $input['archive_hide_past'] ),
            'template_source' => in_array( $input['template_source'] ?? 'theme', [ 'theme', 'plugin' ], true ) ? $input['template_source'] : 'theme',
            'registration_internal' => ! empty( $input['registration_internal'] ),
            'admin_email' => sanitize_email( $input['admin_email'] ?? '' ),
            'notify_admin' => ! empty( $input['notify_admin'] ),
            'notify_user' => ! empty( $input['notify_user'] ),
            'event_slug' => sanitize_title( $input['event_slug'] ?? $defaults['event_slug'] ),
        ];
        if ( ! $output['event_slug'] ) {
            $output['event_slug'] = $defaults['event_slug'];
        }

        return $output;
    }

    public function render_settings_page() {
        echo '<div class="wrap"><h1>' . esc_html__( 'Anchor Events Settings', 'anchor-schema' ) . '</h1>';
        echo '<form method="post" action="options.php">';
        \settings_fields( 'anchor_events_group' );
        \do_settings_sections( 'anchor_events_settings' );
        \submit_button();
        echo '</form></div>';
    }

    public function handle_settings_update( $old, $new ) {
        if ( ( $old['event_slug'] ?? '' ) !== ( $new['event_slug'] ?? '' ) ) {
            \flush_rewrite_rules();
        }
    }

    public function filter_archive_query( $query ) {
        if ( \is_admin() || ! $query->is_main_query() ) {
            return;
        }
        if ( ! $query->is_post_type_archive( self::CPT ) ) {
            return;
        }
        $settings = $this->get_settings();
        $meta_query = [ $this->build_hide_clause() ];
        if ( ! empty( $settings['archive_hide_past'] ) ) {
            $meta_query[] = $this->build_visibility_clause();
        }
        $query->set( 'meta_query', $meta_query );
        $query->set( 'meta_key', $this->meta_key( 'start_ts' ) );
        $query->set( 'orderby', 'meta_value_num' );
        $query->set( 'order', 'ASC' );
    }

    public function clear_caches_on_delete( $post_id ) {
        $post_type = \get_post_type( $post_id );
        if ( $post_type === self::CPT || $post_type === self::REG_CPT ) {
            $this->clear_caches();
        }
    }

    private function clear_caches() {
        $keys = \get_option( self::CACHE_OPTION, [] );
        if ( ! is_array( $keys ) ) {
            $keys = [];
        }
        foreach ( $keys as $key ) {
            \delete_transient( $key );
        }
        \update_option( self::CACHE_OPTION, [] );
    }

    private function store_cache_key( $key ) {
        $keys = \get_option( self::CACHE_OPTION, [] );
        if ( ! is_array( $keys ) ) {
            $keys = [];
        }
        if ( ! in_array( $key, $keys, true ) ) {
            $keys[] = $key;
            \update_option( self::CACHE_OPTION, $keys, false );
        }
    }

    private function get_cached_ids( $args ) {
        $key = 'anchor_events_' . md5( wp_json_encode( $args ) );
        $cached = \get_transient( $key );
        if ( $cached !== false ) {
            return $cached;
        }

        // Cache IDs only to keep transient payloads small and fast to rebuild markup.
        $query_args = $args;
        $query_args['fields'] = 'ids';
        $query = new \WP_Query( $query_args );
        $ids = $query->posts;

        \set_transient( $key, $ids, HOUR_IN_SECONDS );
        $this->store_cache_key( $key );

        return $ids;
    }

    private function count_events_by_status( $status ) {
        $query = new \WP_Query( [
            'post_type' => self::CPT,
            'post_status' => 'publish',
            'fields' => 'ids',
            'meta_query' => [
                [
                    'key' => $this->meta_key( 'status' ),
                    'value' => $status,
                    'compare' => '=',
                ],
            ],
        ] );
        return $query->found_posts;
    }

    private function get_meta( $post_id ) {
        $defaults = $this->get_meta_defaults();
        foreach ( $defaults as $key => $value ) {
            $stored = \get_post_meta( $post_id, $this->meta_key( $key ), true );
            if ( $stored === '' ) {
                $stored = $value;
            }
            if ( is_bool( $value ) ) {
                $stored = (bool) $stored;
            }
            if ( is_int( $value ) ) {
                $stored = (int) $stored;
            }
            $defaults[ $key ] = $stored;
        }
        return $defaults;
    }

    private function meta_key( $key ) {
        return '_anchor_event_' . $key;
    }

    private function sanitize_date( $value ) {
        if ( ! $value ) {
            return '';
        }
        $value = sanitize_text_field( $value );
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
            return '';
        }
        return $value;
    }

    private function sanitize_time( $value ) {
        if ( ! $value ) {
            return '';
        }
        $value = sanitize_text_field( $value );
        if ( ! preg_match( '/^\d{2}:\d{2}$/', $value ) ) {
            return '';
        }
        return $value;
    }

    private function get_status_options() {
        return [
            'upcoming' => __( 'Upcoming', 'anchor-schema' ),
            'ongoing' => __( 'Ongoing', 'anchor-schema' ),
            'past' => __( 'Past', 'anchor-schema' ),
            'cancelled' => __( 'Cancelled', 'anchor-schema' ),
            'draft' => __( 'Draft', 'anchor-schema' ),
        ];
    }

    private function calculate_status( $meta ) {
        if ( empty( $meta['start_date'] ) ) {
            return 'draft';
        }

        $timestamps = $this->calculate_timestamps( $meta );
        $now = time();
        if ( $now < $timestamps['start'] ) {
            return 'upcoming';
        }
        if ( $now >= $timestamps['start'] && $now <= $timestamps['end'] ) {
            return 'ongoing';
        }
        return 'past';
    }

    private function calculate_timestamps( $meta ) {
        $settings = $this->get_settings();
        if ( $settings['timezone_mode'] === 'site' ) {
            $timezone = \get_option( 'timezone_string' ) ?: 'UTC';
        } else {
            $timezone = $meta['timezone'] ? $meta['timezone'] : ( \get_option( 'timezone_string' ) ?: 'UTC' );
        }
        $start_time = $meta['all_day'] ? '00:00' : ( $meta['start_time'] ?: '00:00' );
        $end_date = $meta['end_date'] ?: $meta['start_date'];
        $end_time = $meta['all_day'] ? '23:59' : ( $meta['end_time'] ?: $start_time );

        $start = $this->to_timestamp( $meta['start_date'], $start_time, $timezone );
        $end = $this->to_timestamp( $end_date, $end_time, $timezone );

        if ( $end < $start ) {
            $end = $start;
        }

        return [ 'start' => $start, 'end' => $end ];
    }

    private function to_timestamp( $date, $time, $timezone ) {
        if ( ! $date ) {
            return 0;
        }
        try {
            $tz = new \DateTimeZone( $timezone ?: 'UTC' );
        } catch ( \Exception $e ) {
            $tz = new \DateTimeZone( 'UTC' );
        }
        $dt = \DateTime::createFromFormat( 'Y-m-d H:i', $date . ' ' . $time, $tz );
        if ( ! $dt ) {
            return 0;
        }
        return $dt->getTimestamp();
    }

    private function format_date_time( $meta, $include_range = false ) {
        if ( ! $meta['start_date'] ) {
            return '';
        }
        $start = $meta['start_date'];
        $start_time = $meta['all_day'] ? '' : $meta['start_time'];
        $end_date = $meta['end_date'];
        $end_time = $meta['all_day'] ? '' : $meta['end_time'];

        $output = date_i18n( 'M j, Y', strtotime( $start ) );
        if ( $start_time ) {
            $output .= ' ' . $start_time;
        }
        if ( $include_range ) {
            if ( $end_date && $end_date !== $start ) {
                $output .= ' - ' . date_i18n( 'M j, Y', strtotime( $end_date ) );
                if ( $end_time ) {
                    $output .= ' ' . $end_time;
                }
            } elseif ( $end_time ) {
                $output .= ' - ' . $end_time;
            }
        }
        return $output;
    }

    private function format_address( $meta ) {
        $parts = array_filter( [
            $meta['address_street'],
            $meta['address_city'],
            $meta['address_state'],
            $meta['address_zip'],
            $meta['address_country'],
        ] );
        return implode( ', ', $parts );
    }

    private function get_event_status( $post_id, $meta = null ) {
        if ( ! $meta ) {
            $meta = $this->get_meta( $post_id );
        }
        if ( $meta['status_mode'] === 'manual' ) {
            return $meta['status'];
        }
        $status = $this->calculate_status( $meta );
        if ( $status !== $meta['status'] ) {
            \update_post_meta( $post_id, $this->meta_key( 'status' ), $status );
        }
        return $status;
    }

    private function build_visibility_clause() {
        return [
            'key' => $this->meta_key( 'end_ts' ),
            'value' => time(),
            'compare' => '>=',
            'type' => 'NUMERIC',
        ];
    }

    private function build_hide_clause() {
        return [
            'relation' => 'OR',
            [
                'key' => $this->meta_key( 'hide_from_archive' ),
                'compare' => 'NOT EXISTS',
            ],
            [
                'key' => $this->meta_key( 'hide_from_archive' ),
                'value' => '1',
                'compare' => '!=',
            ],
        ];
    }

    private function build_range_clause( $start, $end ) {
        $start = $this->sanitize_date( $start );
        $end = $this->sanitize_date( $end );
        if ( ! $start && ! $end ) {
            return null;
        }
        $start_ts = $start ? strtotime( $start . ' 00:00' ) : 0;
        $end_ts = $end ? strtotime( $end . ' 23:59' ) : strtotime( '+5 years' );
        return [
            'key' => $this->meta_key( 'start_ts' ),
            'value' => [ $start_ts, $end_ts ],
            'compare' => 'BETWEEN',
            'type' => 'NUMERIC',
        ];
    }

    private function get_registration_status( $event_id, $meta ) {
        $now = date( 'Y-m-d' );
        if ( $meta['registration_open'] && $now < $meta['registration_open'] ) {
            return 'closed';
        }
        if ( $meta['registration_close'] && $now > $meta['registration_close'] ) {
            return 'closed';
        }
        if ( $meta['capacity'] ) {
            $count = $this->get_registration_count( $event_id );
            if ( $count >= $meta['capacity'] ) {
                return $meta['waitlist'] ? 'waitlist' : 'full';
            }
        }
        return 'open';
    }

    private function get_registration_fields() {
        $fields = [];
        // Allow developers to extend registration fields with custom inputs.
        return \apply_filters( 'anchor_events_registration_fields', $fields );
    }

    private function get_registrations( $event_id, $limit = 50 ) {
        $args = [
            'post_type' => self::REG_CPT,
            'post_status' => 'publish',
            'posts_per_page' => $limit ? $limit : -1,
            'meta_query' => [
                [
                    'key' => '_anchor_event_id',
                    'value' => $event_id,
                    'compare' => '=',
                ],
            ],
            'orderby' => 'date',
            'order' => 'DESC',
        ];
        $query = new \WP_Query( $args );
        $registrations = [];
        foreach ( $query->posts as $post ) {
            $registrations[] = [
                'name' => \get_post_meta( $post->ID, '_anchor_event_name', true ),
                'email' => \get_post_meta( $post->ID, '_anchor_event_email', true ),
                'status' => \get_post_meta( $post->ID, '_anchor_event_reg_status', true ) ?: 'confirmed',
                'date' => \get_the_date( 'Y-m-d', $post ),
            ];
        }
        return $registrations;
    }

    private function get_registration_count( $event_id, $status = 'confirmed' ) {
        $args = [
            'post_type' => self::REG_CPT,
            'post_status' => 'publish',
            'fields' => 'ids',
            'meta_query' => [
                [
                    'key' => '_anchor_event_id',
                    'value' => $event_id,
                    'compare' => '=',
                ],
            ],
        ];
        if ( $status ) {
            $args['meta_query'][] = [
                'key' => '_anchor_event_reg_status',
                'value' => $status,
                'compare' => '=',
            ];
        }
        $query = new \WP_Query( $args );
        return $query->found_posts;
    }

    private function send_registration_emails( $event_id, $name, $email, $status ) {
        $settings = $this->get_settings();
        $event_title = \get_the_title( $event_id );
        $event_link = \get_permalink( $event_id );

        if ( ! empty( $settings['notify_admin'] ) ) {
            $admin_email = $settings['admin_email'] ?: \get_option( 'admin_email' );
            $subject = sprintf( __( 'New registration for %s', 'anchor-schema' ), $event_title );
            $message = sprintf( __( "Name: %s\nEmail: %s\nStatus: %s\nEvent: %s", 'anchor-schema' ), $name, $email, $status, $event_link );
            \wp_mail( $admin_email, $subject, $message );
        }

        if ( ! empty( $settings['notify_user'] ) ) {
            $subject = sprintf( __( 'You are registered for %s', 'anchor-schema' ), $event_title );
            $message = sprintf( __( "Thanks for registering for %s.\nEvent details: %s", 'anchor-schema' ), $event_title, $event_link );
            \wp_mail( $email, $subject, $message );
        }
    }

    private function with_message( $url, $message ) {
        $url = $url ?: \home_url();
        return \add_query_arg( 'event_registration', $message, $url );
    }

    private function get_settings() {
        $defaults = [
            'timezone_mode' => 'site',
            'archive_hide_past' => true,
            'template_source' => 'theme',
            'registration_internal' => true,
            'admin_email' => '',
            'notify_admin' => true,
            'notify_user' => true,
            'event_slug' => 'event',
        ];
        $settings = \get_option( self::OPTION_KEY, [] );
        if ( ! is_array( $settings ) ) {
            $settings = [];
        }
        return \wp_parse_args( $settings, $defaults );
    }
}
