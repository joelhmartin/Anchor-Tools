<?php
declare(strict_types=1);

namespace Anchor\Shipping\Domain;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** The carrier label was bought but could not be saved locally; the order has already been flagged. */
final class LabelNotSaved extends \RuntimeException {}
