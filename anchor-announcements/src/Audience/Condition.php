<?php
declare(strict_types=1);

namespace Anchor\Announcements\Audience;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * One audience filter ("bought product X between these dates"). match() returns the
 * people it matches; the Resolver does AND, OR and NOT. Integrations add their own
 * through the `anchor_announcements_conditions` filter.
 */
interface Condition {
	public function key(): string;
	public function label(): string;
	/** Picker heading, e.g. "Site users", "WooCommerce". */
	public function group(): string;
	public function available(): bool;
	/** @return list<array{key:string,type:string,label:string,options?:array,search?:string,default?:mixed}> */
	public function fields(): array;
	public function match( array $params ): RecipientSet;
}
