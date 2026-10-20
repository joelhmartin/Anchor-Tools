<?php
/**
 * Dates inherit their main event's email settings, field by field.
 *
 * A group parent (the "main event") owns the email wording, switches, buttons,
 * template and sender identity. Each of its dates (group children) reads the
 * main event's value for any field it has no non-empty row of its own for, and
 * a date's own non-empty row overrides that one field only.
 *
 * This replaced a copy-down: Occurrences::sync_shared_meta() used to copy every
 * email row from the main event onto each date on save, and DELETE a date's row
 * the main event lacked — so wording typed on a date was invisible on the main
 * event's screen and wiped by the next save of it (Toronto, 2026-10-20). The
 * email keys are no longer synced; this class is the one place they are read.
 *
 * Every read of the `_anchor_event_email_*` override/sender meta goes through
 * resolve() (Module::email_meta() is the thin accessor). The save handlers are
 * the only other code that touches those rows, and for a date they write
 * through write_date_value()/save_date_switch(): an empty input deletes the
 * date's own row, which is what "inherit" means.
 *
 * @package Anchor\Events
 */

namespace Anchor\Events;

defined( 'ABSPATH' ) || exit;

class Email_Inheritance {

    /** Option flag: the one-time cleanup of copied-down rows has run. */
    const MIGRATION_OPTION = 'anchor_events_email_inheritance_v1';

    const PREFIX = '_anchor_event_';

    /**
     * Suffixes whose EMPTY row is a real answer on a main or standalone event:
     * a cleared button field is "deliberately no button" (see
     * Module::get_email_cta()). On a date, empty always means "inherit".
     */
    const EMPTY_IS_AUTHORED = [ 'cta_label', 'cta_url', 'cta2_label', 'cta2_url' ];

    /** @var Module */
    private $module;

    public function __construct( Module $module ) {
        $this->module = $module;
        \add_action( 'admin_init', [ $this, 'maybe_migrate' ] );
    }

    /* ---------------------------------------------------------------- keys */

    /**
     * Every inheritable email meta key, prefixed. Enumerated from the module's
     * type list and the two Occurrences constants, so a new email type or
     * field inherits the day it is added.
     *
     * @return string[]
     */
    public static function keys() {
        $keys = [];
        foreach ( Module::EMAIL_TEMPLATE_TYPES as $type ) {
            foreach ( Occurrences::EMAIL_OVERRIDE_PER_TYPE as $suffix ) {
                $keys[] = self::PREFIX . 'email_' . $suffix . '_' . $type;
            }
        }
        foreach ( Occurrences::EMAIL_SENDER_KEYS as $key ) {
            $keys[] = self::PREFIX . $key;
        }
        return $keys;
    }

    /* ------------------------------------------------------------- resolver */

    /**
     * The main event a date inherits from, or 0 when $event_id is not a date
     * (or is a date whose main event is gone — it then stands alone).
     */
    public function main_of( $event_id ) {
        $occurrences = $this->module->occurrences;
        return $occurrences ? (int) $occurrences->parent_of( (int) $event_id ) : 0;
    }

    /** Is this a date with a live main event to inherit from? */
    public function is_date( $event_id ) {
        return $this->main_of( $event_id ) > 0;
    }

    /**
     * Resolve one email meta key for an event.
     *
     * Own non-empty row → own. Else, for a date, the main event's non-empty
     * row → main. Else none, ''. The one exception is EMPTY_IS_AUTHORED: an
     * empty button row on the main/standalone event that holds it counts as
     * that event's answer (and a date inherits it), because an empty button
     * field there has always meant "no button".
     *
     * @param int    $event_id
     * @param string $meta_key Full key, e.g. `_anchor_event_email_subject_confirmation`.
     * @return array{value:string,source:string} source is 'own'|'main'|'none'.
     */
    public function resolve( $event_id, $meta_key ) {
        $event_id = (int) $event_id;
        $meta_key = (string) $meta_key;
        $main     = $this->main_of( $event_id );

        $own = $this->row( $event_id, $meta_key, $main === 0 );
        if ( $own !== null ) {
            return [ 'value' => $own, 'source' => 'own' ];
        }
        if ( $main > 0 ) {
            $inherited = $this->row( $main, $meta_key, true );
            if ( $inherited !== null ) {
                return [ 'value' => $inherited, 'source' => 'main' ];
            }
        }
        return [ 'value' => '', 'source' => 'none' ];
    }

    /**
     * One post's answer for a key, or null when it has none.
     *
     * @param bool $empty_counts Whether an existing empty row of an
     *                           EMPTY_IS_AUTHORED key is an answer here.
     * @return string|null
     */
    private function row( $post_id, $meta_key, $empty_counts ) {
        if ( $post_id <= 0 ) {
            return null;
        }
        $value = \get_post_meta( $post_id, $meta_key, true );
        $value = \is_scalar( $value ) ? (string) $value : '';
        if ( \trim( $value ) !== '' ) {
            return $value;
        }
        if ( $empty_counts && $this->empty_is_authored( $meta_key ) && \metadata_exists( 'post', $post_id, $meta_key ) ) {
            return '';
        }
        return null;
    }

    private function empty_is_authored( $meta_key ) {
        foreach ( self::EMPTY_IS_AUTHORED as $suffix ) {
            if ( \strpos( $meta_key, self::PREFIX . 'email_' . $suffix . '_' ) === 0 ) {
                return true;
            }
        }
        return false;
    }

    /* ---------------------------------------------------------------- saves */

    /**
     * Write one field a date's editor posted: empty deletes the date's own row
     * (inherit the main event), anything else is stored as typed — even when it
     * equals the main event's value, because the person typed it.
     *
     * @param string $value Sanitized, UNSLASHED value.
     */
    public function write_date_value( $post_id, $meta_key, $value ) {
        if ( \trim( (string) $value ) === '' ) {
            \delete_post_meta( (int) $post_id, $meta_key );
            return;
        }
        // wp_slash(): update_post_meta() unslashes what it is given.
        \update_post_meta( (int) $post_id, $meta_key, \wp_slash( (string) $value ) );
    }

    /**
     * A date's on/off switch. A checkbox has no "empty", so a date that agrees
     * with its main event stores nothing (inherits), and one that disagrees
     * stores '1' (off) or '0' (on while the main event is off).
     */
    public function save_date_switch( $post_id, $type, $on ) {
        $key      = self::PREFIX . 'email_off_' . $type;
        $main_off = $this->row( $this->main_of( $post_id ), $key, false ) === '1';
        if ( (bool) $on === ! $main_off ) {
            \delete_post_meta( (int) $post_id, $key );
            return;
        }
        \update_post_meta( (int) $post_id, $key, $on ? '0' : '1' );
    }

    /* --------------------------------------------------------------- editor */

    /**
     * What an editor input shows for one key.
     *
     * Main/standalone event: its own row as the value, the caller's placeholder.
     * Date: its own override as the value (empty when it inherits), and the
     * main event's value as the placeholder so the author sees what it inherits.
     *
     * @return array{value:string,placeholder:string,date:bool,overridden:bool}
     */
    public function field( $event_id, $meta_key, $placeholder = '' ) {
        $resolved = $this->resolve( (int) $event_id, $meta_key );
        $own      = $resolved['source'] === 'own';
        $date     = $this->is_date( $event_id );
        return [
            'value'       => $own ? $resolved['value'] : '',
            'placeholder' => ( $resolved['source'] === 'main' && \trim( $resolved['value'] ) !== '' ) ? $resolved['value'] : (string) $placeholder,
            'date'        => $date,
            'overridden'  => $date && $own,
        ];
    }

    /**
     * The ↺ button beside an inheritable field on a date: empties the field in
     * place (email-modal.js). Hidden while the field inherits; the script shows
     * it as soon as something is typed. Nothing on a main or standalone event.
     */
    public function reset_button( $event_id, $input_id, $overridden ) {
        if ( ! $this->is_date( $event_id ) ) {
            return '';
        }
        return '<button type="button" class="anchor-event-email-reset" aria-label="' . \esc_attr__( 'Reset to main event', 'anchor-schema' ) . '"'
            . ' title="' . \esc_attr__( 'Reset to main event', 'anchor-schema' ) . '"'
            . ' data-reset-for="' . \esc_attr( $input_id ) . '"' . ( $overridden ? '' : ' hidden' ) . '>&#8634;</button>';
    }

    /**
     * "This is one date of <Main title>. Edit main event" for a date's editor.
     *
     * @param string $edit_url Where "Edit main event" goes (console or wp-admin).
     */
    public function date_notice( $event_id, $edit_url ) {
        $main = $this->main_of( $event_id );
        if ( $main <= 0 ) {
            return '';
        }
        return '<p class="anchor-event-hint anchor-event-email-inherit-notice">'
            . \esc_html( \sprintf(
                /* translators: %s: the main event's title. */
                \__( 'This is one date of %s.', 'anchor-schema' ),
                \html_entity_decode( \get_the_title( $main ), ENT_QUOTES, 'UTF-8' )
            ) )
            . ' <a href="' . \esc_url( $edit_url ) . '">' . \esc_html__( 'Edit main event', 'anchor-schema' ) . '</a> '
            . \esc_html__( 'Empty fields use the main event\'s emails; anything typed here applies to this date only.', 'anchor-schema' )
            . '</p>';
    }

    /**
     * For a main event's editor: which of its dates override which fields.
     *
     * @param callable $url_for fn( int $date_id ): string — the date's editor URL.
     */
    public function overrides_summary( $main_id, callable $url_for ) {
        $occurrences = $this->module->occurrences;
        if ( ! $occurrences || ! $occurrences->is_group_parent( (int) $main_id ) ) {
            return '';
        }
        $lines = [];
        foreach ( $occurrences->children_any_status( (int) $main_id ) as $date_id ) {
            $fields = [];
            foreach ( self::keys() as $key ) {
                if ( $this->row( (int) $date_id, $key, false ) !== null ) {
                    $fields[] = $this->key_label( $key );
                }
            }
            if ( empty( $fields ) ) {
                continue;
            }
            $lines[] = '<li><a href="' . \esc_url( $url_for( (int) $date_id ) ) . '">' . \esc_html( $this->date_label( (int) $date_id ) ) . '</a> '
                . \esc_html( \sprintf(
                    /* translators: %s: comma-separated list of email fields, e.g. "Confirmation subject, Confirmation opening lines". */
                    \__( 'has its own %s', 'anchor-schema' ),
                    \implode( ', ', $fields )
                ) ) . '</li>';
        }
        return $lines ? '<ul class="anchor-event-email-overrides">' . \implode( '', $lines ) . '</ul>' : '';
    }

    /** "Nov 13, 2026" for a date, from its start timestamp (site timezone). */
    public function date_label( $date_id ) {
        $ts = (int) \get_post_meta( (int) $date_id, $this->module->meta_key( 'start_ts' ), true );
        return $ts > 0 ? \wp_date( 'M j, Y', $ts ) : \html_entity_decode( \get_the_title( (int) $date_id ), ENT_QUOTES, 'UTF-8' );
    }

    /** "Confirmation subject", "From email", … for one key. */
    private function key_label( $meta_key ) {
        $sender = [
            'email_from_name'        => \__( 'From name', 'anchor-schema' ),
            'email_from_address'     => \__( 'From email', 'anchor-schema' ),
            'email_reply_to_name'    => \__( 'Reply-To name', 'anchor-schema' ),
            'email_reply_to_address' => \__( 'Reply-To', 'anchor-schema' ),
            'email_cc'               => \__( 'CC', 'anchor-schema' ),
            'email_bcc'              => \__( 'BCC', 'anchor-schema' ),
        ];
        $bare = \substr( $meta_key, \strlen( self::PREFIX ) );
        if ( isset( $sender[ $bare ] ) ) {
            return $sender[ $bare ];
        }
        $fields = [
            'tpl'        => \__( 'HTML', 'anchor-schema' ),
            'off'        => \__( 'on/off switch', 'anchor-schema' ),
            'subject'    => \__( 'subject', 'anchor-schema' ),
            'preheader'  => \__( 'preview text', 'anchor-schema' ),
            'intro'      => \__( 'opening lines', 'anchor-schema' ),
            'cta_label'  => \__( 'button text', 'anchor-schema' ),
            'cta_url'    => \__( 'button link', 'anchor-schema' ),
            'cta2_label' => \__( 'second button text', 'anchor-schema' ),
            'cta2_url'   => \__( 'second button link', 'anchor-schema' ),
        ];
        $types = $this->module->email_type_labels();
        foreach ( $types as $type => $type_label ) {
            foreach ( $fields as $suffix => $field_label ) {
                if ( $bare === 'email_' . $suffix . '_' . $type ) {
                    return $type_label . ' ' . $field_label;
                }
            }
        }
        return $bare;
    }

    /* ------------------------------------------------------------ migration */

    /** admin_init: run migrate() once per site. */
    public function maybe_migrate() {
        if ( \get_option( self::MIGRATION_OPTION ) || ! \current_user_can( 'edit_posts' ) ) {
            return;
        }
        $result = $this->migrate();
        \update_option( self::MIGRATION_OPTION, [ 'time' => \gmdate( 'Y-m-d H:i:s' ) ] + $result, false );
    }

    /**
     * Operator entry point (WP-CLI). Ignores MIGRATION_OPTION so an operator who
     * pre-set it (to hold off the automatic admin_init run) can dry-run and then
     * run for real. A dry run writes nothing, not even the option; a real run
     * records the option when done.
     *
     * @return array{mains:int,removed:int,lifted:int,kept:int}
     */
    public function run_manual( $dry_run = false ) {
        $result = $this->migrate( $dry_run );
        if ( ! $dry_run ) {
            \update_option( self::MIGRATION_OPTION, [ 'time' => \gmdate( 'Y-m-d H:i:s' ) ] + $result, false );
        }
        return $result;
    }

    /**
     * Undo the copy-down on existing data. For every main event, every date and
     * every inheritable key:
     *
     *  - a date row byte-identical to the main event's row was a sync copy:
     *    deleted (the date now inherits the same value);
     *  - when the main event has no value and EVERY one of its dates holds the
     *    same non-empty value, it is lifted to the main event and removed from
     *    the dates (the Toronto shape: wording authored on the date only);
     *  - anything else is a real override and is kept.
     *
     * Idempotent: a second run finds nothing to remove or lift.
     *
     * @param bool $dry_run Count only; write nothing.
     * @return array{mains:int,removed:int,lifted:int,kept:int}
     */
    public function migrate( $dry_run = false ) {
        $out = [ 'mains' => 0, 'removed' => 0, 'lifted' => 0, 'kept' => 0 ];
        foreach ( $this->main_event_ids() as $main ) {
            $dates = $this->date_ids( $main );
            if ( empty( $dates ) ) {
                continue;
            }
            $out['mains']++;
            foreach ( self::keys() as $key ) {
                $this->migrate_key( $main, $dates, $key, (bool) $dry_run, $out );
            }
        }
        Events_Log::info( 'email_inheritance_migrated', $out + [ 'dry_run' => (bool) $dry_run ] );
        return $out;
    }

    /** One main event × one key. Mutates $out's counters. */
    private function migrate_key( $main, array $dates, $key, $dry_run, array &$out ) {
        $values = [];
        foreach ( $dates as $date ) {
            if ( \metadata_exists( 'post', $date, $key ) ) {
                $values[ $date ] = \get_post_meta( $date, $key, true );
            }
        }
        if ( empty( $values ) ) {
            return;
        }

        $main_has = \metadata_exists( 'post', $main, $key );
        $main_val = $main_has ? \get_post_meta( $main, $key, true ) : null;
        $first    = \reset( $values );
        $agreed   = \count( $values ) === \count( $dates )
            && \is_string( $first ) && \trim( $first ) !== ''
            && \count( \array_unique( \array_map( 'serialize', $values ) ) ) === 1;

        if ( $this->row( $main, $key, true ) === null && $agreed ) {
            if ( ! $dry_run ) {
                \update_post_meta( $main, $key, \wp_slash( $first ) );
                foreach ( \array_keys( $values ) as $date ) {
                    \delete_post_meta( $date, $key );
                }
            }
            $out['lifted']++;
            return;
        }

        foreach ( $values as $date => $value ) {
            if ( $main_has && $value === $main_val ) {
                if ( ! $dry_run ) {
                    \delete_post_meta( $date, $key );
                }
                $out['removed']++;
            } else {
                $out['kept']++;
            }
        }
    }

    /** @return int[] Every non-trashed group parent. */
    private function main_event_ids() {
        return \array_map( 'intval', \get_posts( [
            'post_type'        => Module::CPT,
            'post_status'      => 'any',
            'fields'           => 'ids',
            'posts_per_page'   => -1,
            'no_found_rows'    => true,
            'suppress_filters' => true,
            'meta_key'         => $this->module->meta_key( 'group_role' ),
            'meta_value'       => 'parent',
        ] ) );
    }

    /**
     * Every non-trashed date of a main event, whatever its status — including
     * a duplicate for an occurrence key, which still carries copied rows.
     *
     * @return int[]
     */
    private function date_ids( $main ) {
        return \array_map( 'intval', \get_posts( [
            'post_type'        => Module::CPT,
            'post_status'      => 'any',
            'fields'           => 'ids',
            'posts_per_page'   => -1,
            'no_found_rows'    => true,
            'suppress_filters' => true,
            'meta_query'       => [
                [ 'key' => $this->module->meta_key( 'group_role' ), 'value' => 'child' ],
                [ 'key' => $this->module->meta_key( 'group_id' ), 'value' => (int) $main, 'type' => 'NUMERIC' ],
            ],
        ] ) );
    }
}

if ( \defined( 'WP_CLI' ) && \WP_CLI ) {

    /**
     * `wp anchor-events email-inheritance` — the date ← main event email cleanup.
     */
    class Email_Inheritance_Command {

        /**
         * Remove email rows copied from main events onto their dates, lift
         * wording every date agrees on to its main event, and keep real
         * per-date overrides. Safe to re-run.
         *
         * ## OPTIONS
         *
         * [--dry-run]
         * : Count what would change; write nothing.
         *
         * ## EXAMPLES
         *
         *     wp anchor-events email-inheritance migrate --dry-run
         *     wp anchor-events email-inheritance migrate
         *
         * @subcommand migrate
         */
        public function migrate( $args, $assoc_args ) {
            $dry    = ! empty( $assoc_args['dry-run'] );
            $result = Module::instance()->email_inheritance->run_manual( $dry );
            \WP_CLI::log( \sprintf(
                'Main events: %d. Copies removed: %d. Lifted to main event: %d. Date overrides kept: %d.',
                $result['mains'],
                $result['removed'],
                $result['lifted'],
                $result['kept']
            ) );
            if ( $dry ) {
                \WP_CLI::log( 'Dry run. Re-run without --dry-run to write.' );
                return;
            }
            \WP_CLI::success( 'Done.' );
        }
    }

    \WP_CLI::add_command( 'anchor-events email-inheritance', __NAMESPACE__ . '\\Email_Inheritance_Command' );
}
