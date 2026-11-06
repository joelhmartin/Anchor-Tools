<?php
/**
 * The shared question model: normalising authored rows, sanitising posted
 * answers, rendering one control and mapping stored answers back onto
 * questions. Pure functions on plain arrays; it knows nothing about events,
 * seats, products or orders, so any module that asks questions can use it.
 *
 * A question row is `key, label, type, options, required, admin_email`, with
 * type one of text, textarea, select, multiselect, checkbox.
 *
 * @package Anchor_Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Anchor_Questions {

    /**
     * Normalize question rows. Drops rows with no label, derives a stable key from
     * the label when one is not supplied, and guarantees key uniqueness — the key
     * identifies the answer on every seat, so a duplicate would merge two
     * questions into one column.
     *
     * @param array $rows
     * @return array
     */
    public static function normalize( $rows ) {
        $clean = [];
        $seen  = [];
        foreach ( (array) $rows as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $label = sanitize_text_field( (string) ( $row['label'] ?? '' ) );
            if ( $label === '' ) {
                continue;
            }
            $key = sanitize_key( (string) ( $row['key'] ?? '' ) );
            if ( $key === '' ) {
                $key = sanitize_key( substr( sanitize_title( $label ), 0, 32 ) );
            }
            if ( $key === '' ) {
                $key = 'question';
            }
            if ( isset( $seen[ $key ] ) ) {
                $n = 2;
                while ( isset( $seen[ $key . '_' . $n ] ) ) {
                    $n++;
                }
                $key = $key . '_' . $n;
            }
            $seen[ $key ] = true;

            $type = sanitize_key( (string) ( $row['type'] ?? 'text' ) );
            if ( ! in_array( $type, [ 'text', 'textarea', 'select', 'multiselect', 'checkbox' ], true ) ) {
                $type = 'text';
            }

            $options = [];
            if ( $type === 'select' || $type === 'multiselect' ) {
                $raw_options = $row['options'] ?? '';
                if ( ! is_array( $raw_options ) ) {
                    $raw_options = preg_split( '/\r\n|\r|\n/', (string) $raw_options );
                }
                foreach ( (array) $raw_options as $opt ) {
                    $opt = sanitize_text_field( (string) $opt );
                    if ( $type === 'multiselect' ) {
                        // A stored "Choose any" answer is ", "-joined, so a comma inside a
                        // choice would make it ambiguous: "Peanuts, tree nuts" -> "Peanuts / tree nuts".
                        $opt = trim( (string) preg_replace( '/\s*,\s*/', ' / ', $opt ) );
                    }
                    if ( $opt !== '' ) {
                        $options[] = $opt;
                    }
                }
                if ( empty( $options ) ) {
                    $type = 'text'; // a select with nothing to select is a text box
                }
            }

            $clean[] = [
                'key'         => $key,
                'label'       => $label,
                'type'        => $type,
                'options'     => $options,
                'required'    => ! empty( $row['required'] ),
                // "Include in admin email": off unless the author ticked it, so
                // every question saved before this setting existed stays off.
                'admin_email' => ! empty( $row['admin_email'] ),
            ];
        }
        return $clean;
    }

    /**
     * Sanitize one posted answer set against an event's OWN question model —
     * the single validator every write path uses (REG-D9/D39).
     *
     * There used to be three copies of this loop: the free handler, the
     * WooCommerce checkout validator and the WooCommerce item writer. Copies
     * that have to AGREE to be correct drift, and these had: two of them ran
     * every answer through sanitize_text_field(), which strips newlines, so a
     * textarea answer ("no nuts, and I use a wheelchair") arrived on the roster
     * as one run-on line while the third path kept it intact. One
     * implementation, three call sites.
     *
     * Answers come back keyed by the question's stable key — never its label
     * (REG-D10) — with select values constrained to the offered options and a
     * checkbox normalized to 'yes' | ''. `missing` lists the required questions
     * that were left blank, so each caller can phrase its own refusal.
     *
     * @param array $questions Normalized question rows.
     * @param mixed $posted    Raw posted answers, keyed by question key.
     * @return array{answers:array<string,string>,missing:array<int,array>}
     */
    public static function sanitize_answers( array $questions, $posted ) {
        $posted    = is_array( $posted ) ? $posted : [];

        $answers = [];
        $missing = [];
        foreach ( $questions as $q ) {
            $raw    = $posted[ $q['key'] ] ?? '';
            $answer = '';
            if ( $q['type'] === 'multiselect' ) {
                // "Choose any": the posted value is the list of ticked choices (or the
                // stored string on redisplay); only offered choices survive, in the
                // question's own order. An "Other" choice carries the write-in posted as
                // <key>__other, stored as "Other: <text>". The answer is ONE string
                // ("Gluten-free, Other: shellfish"), so the roster, CSV and emails read it
                // like any other answer.
                $answer = self::multiselect_answer( $q, $raw, $posted[ $q['key'] . '__other' ] ?? '' );
                if ( ! empty( $q['required'] ) && $answer === '' ) {
                    $missing[] = $q;
                }
                $answers[ $q['key'] ] = $answer;
                continue;
            }
            if ( is_scalar( $raw ) ) {
                // A textarea is the one type whose newlines are part of the
                // answer; sanitize_text_field() would flatten them.
                $answer = ( $q['type'] === 'textarea' )
                    ? sanitize_textarea_field( (string) $raw )
                    : sanitize_text_field( (string) $raw );
            }
            if ( $q['type'] === 'checkbox' ) {
                $answer = ( $answer !== '' && $answer !== '0' ) ? 'yes' : '';
            } elseif ( $q['type'] === 'select' && $answer !== '' && ! in_array( $answer, $q['options'], true ) ) {
                $answer = ''; // not one of the offered choices
            }
            if ( ! empty( $q['required'] ) && trim( $answer ) === '' ) {
                $missing[] = $q;
            }
            $answers[ $q['key'] ] = $answer;
        }

        return [
            'answers' => $answers,
            'missing' => $missing,
        ];
    }

    /** Whether a choice is the write-in "Other" option (case-insensitive, "Other" or "Other (please specify)"). */
    public static function is_other_choice( $opt ) {
        return 1 === preg_match( '/^\s*other\b/i', (string) $opt );
    }

    /**
     * The write-in field's name for a multiselect control named $name: the question's
     * key with "__other" appended, as a sibling inside the same answers array
     * ("x[fields][diet]" -> "x[fields][diet__other]").
     */
    public static function other_field_name( $name ) {
        $name = (string) $name;
        return substr( $name, -1 ) === ']' ? substr( $name, 0, -1 ) . '__other]' : $name . '__other';
    }

    /**
     * Normalise a "Choose any" answer to its stored string. $raw is the ticked choices
     * (array) on a submit, or the stored string on redisplay; $other is the write-in.
     *
     * @return string Offered choices in question order, ", "-joined; "Other: <text>" for a filled write-in.
     */
    public static function multiselect_answer( array $q, $raw, $other = '' ) {
        $parsed = is_array( $raw ) ? null : self::multiselect_parse( $q, (string) $raw );
        $ticked = is_array( $raw ) ? array_map( 'strval', array_filter( $raw, 'is_scalar' ) ) : $parsed['chosen'];
        $other  = is_scalar( $other ) ? sanitize_text_field( (string) $other ) : '';
        if ( $other === '' && $parsed ) {
            $other = $parsed['other'];
        }
        $out  = [];
        $tail = '';
        foreach ( (array) ( $q['options'] ?? [] ) as $opt ) {
            if ( ! in_array( $opt, $ticked, true ) ) {
                continue;
            }
            if ( self::is_other_choice( $opt ) ) {
                // Always LAST, whatever its position among the options: the write-in may
                // contain commas, and multiselect_parse() reads "Other: ..." to the end.
                $tail = $other !== '' ? $opt . ': ' . $other : $opt;
                continue;
            }
            $out[] = $opt;
        }
        if ( $tail !== '' ) {
            $out[] = $tail;
        }
        return implode( ', ', $out );
    }

    /**
     * Read a stored "Choose any" string back into ticked choices + write-in text.
     *
     * @return array{chosen:string[],labels:string[],other:string}
     */
    public static function multiselect_parse( array $q, $value ) {
        $chosen = [];
        $labels = [];
        $other  = '';
        $value  = (string) $value;
        foreach ( (array) ( $q['options'] ?? [] ) as $opt ) {
            if ( self::is_other_choice( $opt ) && preg_match( '/(?:^|,\s*)' . preg_quote( $opt, '/' ) . ':\s*(.*)$/u', $value, $m ) ) {
                $chosen[] = $opt;
                $other    = trim( $m[1] );
                $labels[] = $opt . ': ' . $other;
                continue;
            }
            if ( preg_match( '/(?:^|,\s*)' . preg_quote( $opt, '/' ) . '(?=\s*(?:,|$))/u', $value ) ) {
                $chosen[] = $opt;
                $labels[] = $opt;
            }
        }
        return [ 'chosen' => $chosen, 'labels' => $labels, 'other' => $other ];
    }

    /**
     * The form control for ONE attendee question — the single renderer behind
     * the free registration form, the WooCommerce checkout attendee block and
     * the roster's manual add form (REG-D39).
     *
     * Only the control is rendered, never the wrapper or the label: the three
     * surfaces put their fields in a `.anchor-event-field` div, a Woo
     * `.form-row` paragraph and a `.form-table` row respectively, and that is
     * their business. What must not vary is which types exist, what a select
     * offers, and what a checkbox posts — those are the question model, and
     * they now have one implementation.
     *
     * @param array $q    Normalized question row.
     * @param array $args {
     *     @type string $name           Input name attribute (required).
     *     @type string $id             Input id, omitted when ''.
     *     @type string $value          Current value (redisplay).
     *     @type string $class          class attribute, omitted when ''.
     *     @type bool   $required       Defaults to the question's own flag.
     *     @type string $checkbox_label Text beside a checkbox; when non-empty the
     *                                  input is wrapped in a <label>.
     *     @type string $checkbox_class class for that wrapping <label>.
     * }
     * @return string
     */
    public static function render_control( array $q, array $args = [] ) {
        $args = array_merge( [
            'name'           => '',
            'id'             => '',
            'value'          => '',
            'class'          => '',
            'required'       => ! empty( $q['required'] ),
            'checkbox_label' => '',
            'checkbox_class' => '',
        ], $args );

        $id    = $args['id'] !== '' ? ' id="' . esc_attr( $args['id'] ) . '"' : '';
        $class = $args['class'] !== '' ? ' class="' . esc_attr( $args['class'] ) . '"' : '';
        $name  = ' name="' . esc_attr( $args['name'] ) . '"';
        $req   = $args['required'] ? ' required' : '';
        $value = (string) $args['value'];
        $type  = isset( $q['type'] ) ? (string) $q['type'] : 'text';

        if ( $type === 'textarea' ) {
            return '<textarea' . $id . $class . $name . ' rows="3"' . $req . '>' . esc_textarea( $value ) . '</textarea>';
        }

        if ( $type === 'select' ) {
            $out = '<select' . $id . $class . $name . $req . '>';
            $out .= '<option value="">' . esc_html__( '— Select —', 'anchor-schema' ) . '</option>';
            foreach ( (array) ( $q['options'] ?? [] ) as $opt ) {
                $out .= '<option value="' . esc_attr( $opt ) . '"' . selected( $value, $opt, false ) . '>' . esc_html( $opt ) . '</option>';
            }
            return $out . '</select>';
        }

        if ( $type === 'multiselect' ) {
            // A compact dropdown of checkboxes (no script needed: <details>), with a
            // write-in beside an "Other" choice. No `required` on the boxes - HTML would
            // then demand every one; the validator enforces "at least one".
            $parsed  = self::multiselect_parse( $q, $value );
            $summary = $parsed['chosen'] ? implode( ', ', $parsed['labels'] ) : __( 'Select all that apply', 'anchor-schema' );
            $out     = '<details class="anchor-event-multi"' . $id . ' role="group" aria-label="' . esc_attr( (string) ( $q['label'] ?? '' ) ) . '"><summary class="anchor-event-multi__summary">' . esc_html( $summary ) . '</summary><div class="anchor-event-multi__list">';
            foreach ( (array) ( $q['options'] ?? [] ) as $opt ) {
                $on   = in_array( $opt, $parsed['chosen'], true );
                $out .= '<label class="anchor-event-multi__opt"><input type="checkbox" name="' . esc_attr( $args['name'] . '[]' ) . '" value="' . esc_attr( $opt ) . '"' . ( $on ? ' checked' : '' ) . ' /> ' . esc_html( $opt ) . '</label>';
                if ( self::is_other_choice( $opt ) ) {
                    $out .= '<input type="text" class="anchor-event-multi__other' . ( $args['class'] !== '' ? ' ' . esc_attr( $args['class'] ) : '' ) . '" name="' . esc_attr( self::other_field_name( $args['name'] ) ) . '" value="' . esc_attr( $parsed['other'] ) . '" placeholder="' . esc_attr__( 'Please specify', 'anchor-schema' ) . '" aria-label="' . esc_attr( $opt ) . '" />';
                }
            }
            return $out . '</div></details>';
        }

        if ( $type === 'checkbox' ) {
            $input = '<input type="checkbox"' . $id . $class . $name . ' value="yes"' . checked( $value, 'yes', false ) . $req . ' />';
            if ( $args['checkbox_label'] === '' ) {
                return $input;
            }
            $wrap = $args['checkbox_class'] !== '' ? ' class="' . esc_attr( $args['checkbox_class'] ) . '"' : '';
            return '<label' . $wrap . '>' . $input . ' ' . esc_html( $args['checkbox_label'] ) . '</label>';
        }

        return '<input type="text"' . $id . $class . $name . ' value="' . esc_attr( $value ) . '"' . $req . ' />';
    }

    /**
     * Map a seat's stored answers onto the event's CURRENT question set
     * (REG-D10 / REG-D11).
     *
     * Answers are keyed by the question's stable key on every write path. Seats
     * booked before that was true — every WooCommerce seat up to it — hold
     * LABEL-keyed answers, so a stored key that matches a current question's
     * label is migrated onto that question's key HERE, on read: no data
     * rewrite, and a rename can never orphan an answer because the label is
     * only ever a display value.
     *
     * An answer whose question no longer exists keeps the key it was stored
     * under, so it still reaches the roster and the CSV instead of vanishing.
     *
     * @param array $questions Normalized question rows.
     * @param mixed $stored    Stored answers.
     * @return array<string,mixed> Answers keyed by question key.
     */
    public static function resolve_answers( array $questions, $stored ) {
        if ( ! is_array( $stored ) || empty( $stored ) ) {
            return [];
        }
        if ( empty( $questions ) ) {
            return $stored;
        }

        $keys     = [];
        $by_label = [];
        foreach ( $questions as $q ) {
            $keys[ $q['key'] ] = true;
            $by_label[ self::answer_index( $q['label'] ) ] = $q['key'];
        }

        $out = [];
        foreach ( $stored as $stored_key => $value ) {
            $key = (string) $stored_key;
            if ( ! isset( $keys[ $key ] ) ) {
                $index = self::answer_index( $key );
                // Only migrate when the question does not ALSO have an id-keyed
                // answer on this seat — that one is the current spelling and wins.
                if ( isset( $by_label[ $index ] ) && ! array_key_exists( $by_label[ $index ], $stored ) ) {
                    $key = $by_label[ $index ];
                }
            }
            $out[ $key ] = $value;
        }
        return $out;
    }

    /**
     * Display label for a stored answer key: the current question's label, or
     * the stored key itself once that question is gone (REG-D10). The one place
     * the roster table, the roster list table, the CSV header and the privacy
     * export ask, so a heading cannot mean two things in two readers.
     *
     * @param array  $questions Normalized question rows.
     * @param string $key
     * @return string
     */
    public static function answer_label( array $questions, $key ) {
        foreach ( $questions as $q ) {
            if ( $q['key'] === (string) $key ) {
                return $q['label'];
            }
        }
        return (string) $key;
    }

    /** Comparison form for matching a stored key against a question label. */
    private static function answer_index( $value ) {
        return strtolower( trim( (string) $value ) );
    }
}
