<?php
declare(strict_types=1);

namespace Anchor\Shipping\Domain;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** The order already has an active label and no "additional package" was requested. */
final class AlreadyLabelled extends \LogicException {}
