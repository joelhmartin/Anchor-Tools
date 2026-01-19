<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

$month_label = date_i18n( 'F Y', strtotime( $calendar_month ) );
$weekdays = [ 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun' ];
?>
<div class="anchor-event-calendar" data-month="<?php echo esc_attr( date( 'Y-m', strtotime( $calendar_month ) ) ); ?>" data-show-past="<?php echo esc_attr( $calendar_show_past ?? 'yes' ); ?>">
    <div class="anchor-event-calendar-header">
        <div class="anchor-event-calendar-nav">
            <?php if ( ! empty( $calendar_prev_link ) ) : ?>
                <a class="anchor-event-calendar-btn" href="<?php echo esc_url( $calendar_prev_link ); ?>" data-month="<?php echo esc_attr( $calendar_prev_month ); ?>">&larr;</a>
            <?php endif; ?>
        </div>
        <div class="anchor-event-calendar-title"><?php echo esc_html( $month_label ); ?></div>
        <div class="anchor-event-calendar-nav">
            <?php if ( ! empty( $calendar_next_link ) ) : ?>
                <a class="anchor-event-calendar-btn" href="<?php echo esc_url( $calendar_next_link ); ?>" data-month="<?php echo esc_attr( $calendar_next_month ); ?>">&rarr;</a>
            <?php endif; ?>
        </div>
    </div>
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
                            <?php
                            $thumb = get_the_post_thumbnail_url( $event_id, 'thumbnail' );
                            $title = get_the_title( $event_id );
                            ?>
                            <li>
                                <a href="<?php echo esc_url( get_permalink( $event_id ) ); ?>"
                                   class="anchor-event-calendar-link"
                                   data-title="<?php echo esc_attr( $title ); ?>"
                                   data-thumb="<?php echo esc_url( $thumb ); ?>"
                                   title="<?php echo esc_attr( $title ); ?>">
                                    <?php echo esc_html( $title ); ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        <?php endfor; ?>
    </div>
</div>
