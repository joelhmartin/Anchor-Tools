<?php
/**
 * Anchor_Questions_Editor: the question-row editor shared by every module that
 * lets an admin author questions (Events attendee questions, Checkout Questions).
 *
 * Markup is a table repeater. The JS binds to `.anchor-questions-editor`
 * wrappers and reads the field-name prefix from `data-prefix`, so several
 * editors can live on one screen. Posted shape per row:
 * `<prefix>[n][label|key|type|options|required|admin_email]`, plus a hidden
 * `<prefix>_present` marker so a save can tell "all rows removed" from "this
 * form never showed the editor".
 *
 * @package Anchor_Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Anchor_Questions_Editor {

    const HANDLE = 'anchor-questions-editor';

    /**
     * One editable row. $template renders the __INDEX__ placeholder the JS fills in.
     *
     * @param string     $name_prefix Field-name prefix, e.g. "anchor_event_questions".
     * @param int        $index       Row position.
     * @param array|null $row         Question row (key,label,type,options,required,admin_email).
     * @param bool       $template    True for the blank clone-me row.
     * @return string Escaped HTML.
     */
    public static function row_html( $name_prefix, $index, $row = null, $template = false ) {
        $idx  = $template ? '__INDEX__' : (string) (int) $index;
        $base = $name_prefix . '[' . $idx . ']';

        $label    = $row['label'] ?? '';
        $key      = $row['key'] ?? '';
        $type     = $row['type'] ?? 'text';
        $options  = isset( $row['options'] ) && is_array( $row['options'] ) ? implode( "\n", $row['options'] ) : '';
        $required = ! empty( $row['required'] );
        $in_admin = ! empty( $row['admin_email'] );

        ob_start();
        ?>
        <tr class="anchor-question-row">
            <td>
                <input type="text" name="<?php echo esc_attr( $base . '[label]' ); ?>" value="<?php echo esc_attr( $label ); ?>" placeholder="<?php echo esc_attr__( 'e.g. Company or organization', 'anchor-schema' ); ?>" />
                <input type="hidden" name="<?php echo esc_attr( $base . '[key]' ); ?>" value="<?php echo esc_attr( $key ); ?>" />
            </td>
            <td>
                <select name="<?php echo esc_attr( $base . '[type]' ); ?>" class="anchor-question-type">
                    <option value="text" <?php selected( $type, 'text' ); ?>><?php echo esc_html__( 'Short text', 'anchor-schema' ); ?></option>
                    <option value="textarea" <?php selected( $type, 'textarea' ); ?>><?php echo esc_html__( 'Long text', 'anchor-schema' ); ?></option>
                    <option value="select" <?php selected( $type, 'select' ); ?>><?php echo esc_html__( 'Choose one', 'anchor-schema' ); ?></option>
                    <option value="multiselect" <?php selected( $type, 'multiselect' ); ?>><?php echo esc_html__( 'Choose any', 'anchor-schema' ); ?></option>
                    <option value="checkbox" <?php selected( $type, 'checkbox' ); ?>><?php echo esc_html__( 'Yes / no', 'anchor-schema' ); ?></option>
                </select>
            </td>
            <td>
                <textarea name="<?php echo esc_attr( $base . '[options]' ); ?>" rows="2" placeholder="<?php echo esc_attr__( 'One choice per line', 'anchor-schema' ); ?>"><?php echo esc_textarea( $options ); ?></textarea>
            </td>
            <td>
                <label><input type="checkbox" name="<?php echo esc_attr( $base . '[required]' ); ?>" value="1" <?php checked( $required ); ?> /> <?php echo esc_html__( 'Required', 'anchor-schema' ); ?></label>
                <label><input type="checkbox" name="<?php echo esc_attr( $base . '[admin_email]' ); ?>" value="1" <?php checked( $in_admin ); ?> /> <?php echo esc_html__( 'Include in admin email', 'anchor-schema' ); ?></label>
                <span class="anchor-question-help"><?php echo esc_html__( "Answers are sent by plain email. Don't tick this for health information.", 'anchor-schema' ); ?></span>
            </td>
            <td>
                <button type="button" class="button-link-delete anchor-question-remove" aria-label="<?php echo esc_attr__( 'Remove question', 'anchor-schema' ); ?>">&times;</button>
            </td>
        </tr>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * The whole repeater: wrapper, present-marker, header, rows, add button, row template.
     *
     * @param string $name_prefix Field-name prefix for every row.
     * @param array  $rows        Existing question rows.
     * @return string Escaped HTML.
     */
    public static function repeater_html( $name_prefix, array $rows ) {
        ob_start();
        ?>
                <div class="anchor-questions-editor" data-prefix="<?php echo esc_attr( $name_prefix ); ?>">
                    <?php // Tells the save handler the repeater was on screen, so removing the last
                          // row CLEARS the questions instead of reading as "this form did not edit them". ?>
                    <input type="hidden" name="<?php echo esc_attr( $name_prefix . '_present' ); ?>" value="1" />
                    <table class="widefat anchor-questions-table">
                        <thead>
                            <tr>
                                <th><?php echo esc_html__( 'Question', 'anchor-schema' ); ?></th>
                                <th><?php echo esc_html__( 'Answer type', 'anchor-schema' ); ?></th>
                                <th><?php echo esc_html__( 'Choices', 'anchor-schema' ); ?></th>
                                <th><?php echo esc_html__( 'Required', 'anchor-schema' ); ?></th>
                                <th aria-hidden="true"></th>
                            </tr>
                        </thead>
                        <tbody class="anchor-questions-rows">
                            <?php foreach ( $rows as $i => $row ) : ?>
                                <?php echo self::row_html( $name_prefix, (int) $i, $row ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in row_html ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p>
                        <button type="button" class="anchor-event-button-secondary anchor-question-add"><?php echo esc_html__( 'Add question', 'anchor-schema' ); ?></button>
                    </p>
                    <script type="text/template" class="anchor-question-template">
                        <?php echo self::row_html( $name_prefix, 0, null, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in row_html ?>
                    </script>
                </div>
        <?php
        return (string) ob_get_clean();
    }

    /** Enqueue the shared editor script + style. Safe to call more than once. */
    public static function enqueue() {
        $js  = 'includes/questions/assets/questions-editor.js';
        $css = 'includes/questions/assets/questions-editor.css';
        wp_enqueue_style( self::HANDLE, Anchor_Asset_Loader::url( $css ), [], self::version( $css ) );
        wp_enqueue_script( self::HANDLE, Anchor_Asset_Loader::url( $js ), [ 'jquery' ], self::version( $js ), true );
    }

    /** File mtime, so an edited asset busts caches without a manual bump. */
    private static function version( $relative ) {
        $path = Anchor_Asset_Loader::path( $relative );
        $time = is_readable( $path ) ? filemtime( $path ) : false;
        return $time ? (string) $time : ( defined( 'ANCHOR_TOOLS_VERSION' ) ? ANCHOR_TOOLS_VERSION : '1' );
    }
}
