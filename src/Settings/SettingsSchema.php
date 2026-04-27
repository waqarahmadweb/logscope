<?php
/**
 * Declarative schema for Logscope settings.
 *
 * @package Logscope
 */

declare(strict_types=1);

namespace Logscope\Settings;

use InvalidArgumentException;

/**
 * Single source of truth for every persisted setting: the option key, its
 * scalar type, default value, and the sanitizer that coerces incoming
 * values to a safe shape before they hit the database.
 *
 * Keep the field map in sync with {@see \Logscope\Activator::DEFAULT_OPTIONS}.
 * The activator seeds these same keys on plugin activation so a fresh
 * install never has to fall back to the defaults declared here.
 */
final class SettingsSchema {

	/**
	 * Field map keyed by the public setting name. Each entry defines:
	 *
	 *   - option_key: the underlying `wp_options` row name.
	 *   - type:       'string' | 'integer'.
	 *   - default:    value returned when the option is missing or the
	 *                 stored value cannot be coerced into `type`.
	 *   - sanitizer:  callable that accepts the raw input and returns a
	 *                 safe-to-persist value of the declared `type`. The
	 *                 sanitizer is the only place that may reshape input
	 *                 — never trust the caller to pre-sanitize.
	 *
	 * @var array<string, array{option_key:string, type:string, default:mixed, sanitizer:callable}>|null
	 */
	private ?array $fields = null;

	/**
	 * Returns the field map, building it lazily on first access. Closures
	 * capture `$this` only when needed and stay stateless otherwise so the
	 * schema is safe to share across requests within a single PHP process.
	 *
	 * @return array<string, array{option_key:string, type:string, default:mixed, sanitizer:callable}>
	 */
	public function fields(): array {
		if ( null !== $this->fields ) {
			return $this->fields;
		}

		$this->fields = array(
			'log_path'      => array(
				'option_key' => 'logscope_log_path',
				'type'       => 'string',
				'default'    => '',
				'sanitizer'  => static function ( $value ): string {
					if ( ! is_string( $value ) ) {
						return '';
					}

					// Strip null bytes defense-in-depth; PathGuard validates
					// the actual filesystem semantics on use.
					$value = str_replace( "\0", '', $value );

					return trim( $value );
				},
			),
			'tail_interval' => array(
				'option_key' => 'logscope_tail_interval',
				'type'       => 'integer',
				'default'    => 3,
				'sanitizer'  => static function ( $value ): int {
					if ( is_string( $value ) && '' !== $value && ctype_digit( ltrim( $value, '-' ) ) ) {
						$value = (int) $value;
					}

					if ( ! is_int( $value ) ) {
						return 1;
					}

					return $value < 1 ? 1 : $value;
				},
			),
		);

		return $this->fields;
	}

	/**
	 * Returns the public setting names declared by the schema.
	 *
	 * @return string[]
	 */
	public function keys(): array {
		return array_keys( $this->fields() );
	}

	/**
	 * Returns true if the given public setting name is declared.
	 *
	 * @param string $key Public setting name.
	 * @return bool
	 */
	public function has( string $key ): bool {
		return array_key_exists( $key, $this->fields() );
	}

	/**
	 * Returns the field descriptor for the given public setting name.
	 *
	 * @param string $key Public setting name.
	 * @return array{option_key:string, type:string, default:mixed, sanitizer:callable}
	 * @throws InvalidArgumentException If the key is unknown.
	 */
	public function field( string $key ): array {
		$fields = $this->fields();
		if ( ! array_key_exists( $key, $fields ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message is not rendered as HTML; escaping belongs at the render layer.
			throw new InvalidArgumentException( sprintf( 'Unknown Logscope setting "%s".', $key ) );
		}

		return $fields[ $key ];
	}

	/**
	 * Returns the underlying `wp_options` row name for a public setting.
	 *
	 * @param string $key Public setting name.
	 * @return string
	 * @throws InvalidArgumentException If the key is unknown.
	 */
	public function option_key( string $key ): string {
		return $this->field( $key )['option_key'];
	}

	/**
	 * Returns the declared default for a public setting.
	 *
	 * @param string $key Public setting name.
	 * @return mixed
	 * @throws InvalidArgumentException If the key is unknown.
	 */
	public function default_for( string $key ) {
		return $this->field( $key )['default'];
	}

	/**
	 * Sanitizes a raw input value for the given public setting. The
	 * returned value is safe to persist and is guaranteed to match the
	 * declared scalar type for the field.
	 *
	 * @param string $key   Public setting name.
	 * @param mixed  $value Raw input.
	 * @return mixed Sanitized value.
	 * @throws InvalidArgumentException If the key is unknown.
	 */
	public function sanitize( string $key, $value ) {
		$field = $this->field( $key );

		return ( $field['sanitizer'] )( $value );
	}

	/**
	 * Returns true when the given value matches the declared scalar type
	 * for the field. Used to reject corrupted option values stored by an
	 * older version of the plugin and fall back to the default.
	 *
	 * @param string $key   Public setting name.
	 * @param mixed  $value Value to test.
	 * @return bool
	 * @throws InvalidArgumentException If the key is unknown.
	 */
	public function matches_type( string $key, $value ): bool {
		switch ( $this->field( $key )['type'] ) {
			case 'integer':
				return is_int( $value );
			case 'string':
				return is_string( $value );
			default:
				return false;
		}
	}
}
