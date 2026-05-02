<?php
/**
 * Schema-driven settings facade.
 *
 * @package Logscope
 */

declare(strict_types=1);

namespace Logscope\Settings;

use InvalidArgumentException;

/**
 * Thin layer over `get_option()` / `update_option()` that funnels every
 * read and write through {@see SettingsSchema}. Callers refer to settings
 * by their public name (`log_path`, `tail_interval`); the schema maps
 * those names to underlying `logscope_*` option keys, declares defaults,
 * and sanitizes writes.
 *
 * Why this exists: the Activator already seeds default options, but a
 * site administrator can manually delete a row, an older version may have
 * stored a corrupted value, and the schema's defaults can drift from
 * what's on disk. Settings::get() papers over all three by re-validating
 * each read against the declared type and falling back to the schema
 * default when the stored value is missing or wrong-shaped.
 */
final class Settings {

	/**
	 * Field declarations and sanitizers.
	 *
	 * @var SettingsSchema
	 */
	private SettingsSchema $schema;

	/**
	 * Constructor.
	 *
	 * @param SettingsSchema $schema Field declarations.
	 */
	public function __construct( SettingsSchema $schema ) {
		$this->schema = $schema;
	}

	/**
	 * Returns the schema. Useful for the REST controller to drive its
	 * args validation and surface field metadata to clients without
	 * re-declaring the field list in two places.
	 *
	 * @return SettingsSchema
	 */
	public function schema(): SettingsSchema {
		return $this->schema;
	}

	/**
	 * Returns the value of a single setting. Falls back to the schema
	 * default when the option is missing or the stored value does not
	 * match the declared scalar type.
	 *
	 * @param string $key Public setting name.
	 * @return mixed
	 * @throws InvalidArgumentException If the key is unknown.
	 */
	public function get( string $key ) {
		$option_key = $this->schema->option_key( $key );
		$default    = $this->schema->default_for( $key );

		$stored = get_option( $option_key, $default );

		if ( ! $this->schema->matches_type( $key, $stored ) ) {
			return $default;
		}

		// WordPress stores wp_options.option_value as LONGTEXT, so an int
		// written via update_option() comes back as a numeric string. The
		// schema's matches_type accepts that shape; cast it back here so
		// callers (and the REST response) see the declared scalar type.
		if ( 'integer' === $this->schema->field( $key )['type'] && ! is_int( $stored ) ) {
			return (int) $stored;
		}

		return $stored;
	}

	/**
	 * Persists a single setting. The value is sanitized through the
	 * schema before being written, so callers may pass raw input from
	 * the REST layer without pre-cleaning it.
	 *
	 * @param string $key   Public setting name.
	 * @param mixed  $value Raw input.
	 * @return mixed The sanitized value that was persisted.
	 * @throws InvalidArgumentException If the key is unknown.
	 */
	public function set( string $key, $value ) {
		$sanitized  = $this->schema->sanitize( $key, $value );
		$option_key = $this->schema->option_key( $key );

		update_option( $option_key, $sanitized );

		return $sanitized;
	}

	/**
	 * Returns the full settings shape keyed by public setting name. This
	 * is what the REST `GET /settings` response exposes, and it always
	 * round-trips through {@see Settings::get()} so corrupted rows are
	 * silently replaced with defaults in the response.
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array {
		$out = array();
		foreach ( $this->schema->keys() as $key ) {
			$out[ $key ] = $this->get( $key );
		}

		return $out;
	}
}
