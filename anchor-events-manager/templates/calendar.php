<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

$month_label = date_i18n( 'F Y', strtotime( $calendar_month ) );
$weekdays = [ 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun' ];
?>
<div class="anchor-event-calendar">
    <div class="anchor-event-calendar-header"><?php echo esc_html( $month_label ); ?></div>
    <div class="anchor-event-calendar-grid">
        <?php foreach ( $weekdays as $day ) : ?>
            <div class="anchor-event-calendar-cell anchor-event-calendar-weekday"><?php echo esc_html( $day ); ?></div>
        <?php endforeach; ?>

        <?php for ( $i = 1; $i < $calendar_start_weekday; $i++ ) : ?>
            <div class="anchor-event-calendar-cell is-empty"></div>
        <?php endfor; ?>

        <?php for ( $day = 1; $day <= $calendar_days; $day++ ) : ?>
            <?php $date = date( 'Y-m-d', strtotime( $calendar_month . ' +' . ( $day - 1 ) . ' days' ) ); ?>
            <div class="anchor-event-calendar-cell">
                <div class="anchor-event-calendar-date"><?php echo esc_html( $day ); ?></div>
                <?php if ( ! empty( $calendar_events[ $date ] ) ) : ?>
                    <ul class="anchor-event-calendar-list">
                        <?php foreach ( $calendar_events[ $date ] as $event_id ) : ?>
                            <li><a href="<?php echo esc_url( get_permalink( $event_id ) ); ?>"><?php echo esc_html( get_the_title( $event_id ) ); ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        <?php endfor; ?>
    </div>
</div>
