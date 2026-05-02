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
class SettingsSchema {

	/**
	 * Closed vocabulary of `type` values a field may declare. Kept as a
	 * const so {@see SettingsSchema::matches_type()} can be exhaustive
	 * without a defensive default branch.
	 */
	public const TYPES = array( 'string', 'integer' );

	/**
	 * Field map keyed by the public setting name. Each entry defines:
	 *
	 *   - option_key: the underlying `wp_options` row name.
	 *   - type:       one of {@see SettingsSchema::TYPES}.
	 *   - default:    value returned when the option is missing or the
	 *                 stored value cannot be coerced into `type`.
	 *   - sanitizer:  callable that accepts the raw input and returns a
	 *                 safe-to-persist value of the declared `type`. The
	 *                 sanitizer is the only place that may reshape input
	 *                 — never trust the caller to pre-sanitize.
	 *
	 * @var array<string, array{option_key:string, type:string, default:mixed, sanitizer:callable}>
	 */
	private array $fields;

	/**
	 * Builds the schema. The field map is constant per process so it is
	 * assigned once in the constructor; closures stay `static` to avoid
	 * binding `$this`.
	 */
	public function __construct() {
		$this->fields = array(
			'log_path'                   => array(
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
			'tail_interval'              => array(
				'option_key' => 'logscope_tail_interval',
				'type'       => 'integer',
				'default'    => 3,
				'sanitizer'  => static function ( $value ): int {
					if ( ! is_numeric( $value ) ) {
						return 1;
					}

					$coerced = (int) $value;

					return $coerced < 1 ? 1 : $coerced;
				},
			),
			'alert_email_enabled'        => array(
				'option_key' => 'logscope_alert_email_enabled',
				'type'       => 'integer',
				'default'    => 0,
				'sanitizer'  => static function ( $value ): int {
					return self::coerce_bool_to_int( $value );
				},
			),
			'alert_email_to'             => array(
				'option_key' => 'logscope_alert_email_to',
				'type'       => 'string',
				'default'    => '',
				'sanitizer'  => static function ( $value ): string {
					if ( ! is_string( $value ) ) {
						return '';
					}
					$trimmed = trim( str_replace( "\0", '', $value ) );
					if ( '' === $trimmed ) {
						return '';
					}

					$email = sanitize_email( $trimmed );

					return is_string( $email ) ? $email : '';
				},
			),
			'alert_webhook_enabled'      => array(
				'option_key' => 'logscope_alert_webhook_enabled',
				'type'       => 'integer',
				'default'    => 0,
				'sanitizer'  => static function ( $value ): int {
					return self::coerce_bool_to_int( $value );
				},
			),
			'alert_webhook_url'          => array(
				'option_key' => 'logscope_alert_webhook_url',
				'type'       => 'string',
				'default'    => '',
				'sanitizer'  => static function ( $value ): string {
					if ( ! is_string( $value ) ) {
						return '';
					}
					$trimmed = trim( str_replace( "\0", '', $value ) );
					if ( '' === $trimmed ) {
						return '';
					}

					$url = esc_url_raw( $trimmed );
					if ( ! is_string( $url ) || '' === $url ) {
						return '';
					}

					// Scheme allowlist: webhook URLs must be http(s) so the
					// dispatcher cannot be tricked into reading local files
					// or hitting non-network protocols. Mirrors the runtime
					// check in WebhookAlerter as defense-in-depth at the
					// settings boundary.
					$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
					if ( ! is_string( $scheme ) ) {
						return '';
					}
					$scheme = strtolower( $scheme );
					if ( 'http' !== $scheme && 'https' !== $scheme ) {
						return '';
					}

					return $url;
				},
			),
			'alert_dedup_window'         => array(
				'option_key' => 'logscope_alert_dedup_window',
				'type'       => 'integer',
				'default'    => 1800,
				'sanitizer'  => static function ( $value ): int {
					if ( ! is_numeric( $value ) ) {
						return 1800;
					}
					$coerced = (int) $value;

					// Mirrors the floor in AlertDeduplicator; anything
					// shorter than 60s defeats dedup on a noisy site.
					return $coerced < 60 ? 60 : $coerced;
				},
			),
			'cron_scan_enabled'          => array(
				'option_key' => 'logscope_cron_scan_enabled',
				'type'       => 'integer',
				'default'    => 0,
				'sanitizer'  => static function ( $value ): int {
					return self::coerce_bool_to_int( $value );
				},
			),
			'cron_scan_interval_minutes' => array(
				'option_key' => 'logscope_cron_scan_interval_minutes',
				'type'       => 'integer',
				'default'    => 5,
				'sanitizer'  => static function ( $value ): int {
					if ( ! is_numeric( $value ) ) {
						return 5;
					}
					$coerced = (int) $value;

					// 1 minute floor: the WP-Cron resolution is itself
					// "every page load that lands on a hook flush", so
					// anything sub-minute is fictional. 1440 ceiling
					// (one day) so a typo cannot suspend scanning for
					// arbitrary stretches without re-saving.
					if ( $coerced < 1 ) {
						return 1;
					}
					if ( $coerced > 1440 ) {
						return 1440;
					}
					return $coerced;
				},
			),
			'retention_enabled'          => array(
				'option_key' => 'logscope_retention_enabled',
				'type'       => 'integer',
				'default'    => 0,
				'sanitizer'  => static function ( $value ): int {
					return self::coerce_bool_to_int( $value );
				},
			),
			'retention_max_size_mb'      => array(
				'option_key' => 'logscope_retention_max_size_mb',
				'type'       => 'integer',
				'default'    => 50,
				'sanitizer'  => static function ( $value ): int {
					if ( ! is_numeric( $value ) ) {
						return 50;
					}
					$coerced = (int) $value;

					// 1 MB floor mirrors the cron-interval shape: a
					// sub-1 MB threshold rotates after one fatal stack
					// trace and produces noise rather than retention.
					// 1024 MB (1 GiB) ceiling so a typo cannot suspend
					// rotation for an unreasonable span.
					if ( $coerced < 1 ) {
						return 1;
					}
					if ( $coerced > 1024 ) {
						return 1024;
					}
					return $coerced;
				},
			),
			'default_per_page'           => array(
				'option_key' => 'logscope_default_per_page',
				'type'       => 'integer',
				'default'    => 50,
				'sanitizer'  => static function ( $value ): int {
					if ( ! is_numeric( $value ) ) {
						return 50;
					}
					$coerced = (int) $value;

					// 10 floor / 500 ceiling: below 10 the user is paging
					// constantly; above 500 the React virtualised list still
					// renders, but the JSON payload bloats and the perceived
					// fetch latency suffers.
					if ( $coerced < 10 ) {
						return 10;
					}
					if ( $coerced > 500 ) {
						return 500;
					}
					return $coerced;
				},
			),
			'default_severity_filter'    => array(
				'option_key' => 'logscope_default_severity_filter',
				'type'       => 'string',
				'default'    => '',
				'sanitizer'  => static function ( $value ): string {
					// Stored as a CSV of severity tokens so the schema's closed
					// type vocabulary (string|integer) doesn't have to grow an
					// `array` branch. Empty string means "no preset filter".
					if ( ! is_string( $value ) ) {
						return '';
					}
					$value = str_replace( "\0", '', $value );
					$canonical = array( 'fatal', 'parse', 'warning', 'notice', 'deprecated', 'strict', 'unknown' );
					$incoming  = array_filter(
						array_map( 'trim', explode( ',', $value ) ),
						static function ( string $t ) use ( $canonical ): bool {
							return in_array( $t, $canonical, true );
						}
					);
					// Dedupe + reorder by canonical severity order so the
					// stored value is stable regardless of how the UI
					// submitted it. Iterating the canonical list (rather
					// than the incoming) is what produces the reorder.
					$incoming_set = array_flip( $incoming );
					$ordered      = array_values(
						array_filter(
							$canonical,
							static function ( string $t ) use ( $incoming_set ): bool {
								return isset( $incoming_set[ $t ] );
							}
						)
					);
					return implode( ',', $ordered );
				},
			),
			'timestamp_tz'               => array(
				'option_key' => 'logscope_timestamp_tz',
				'type'       => 'string',
				'default'    => 'site',
				'sanitizer'  => static function ( $value ): string {
					if ( ! is_string( $value ) ) {
						return 'site';
					}
					$value = strtolower( trim( $value ) );
					return 'utc' === $value ? 'utc' : 'site';
				},
			),
			'retention_max_archives'     => array(
				'option_key' => 'logscope_retention_max_archives',
				'type'       => 'integer',
				'default'    => 5,
				'sanitizer'  => static function ( $value ): int {
					if ( ! is_numeric( $value ) ) {
						return 5;
					}
					$coerced = (int) $value;

					// 1 archive floor: a 0-cap effectively disables
					// retention via a side door already covered by
					// `retention_enabled`. 50 ceiling because more
					// archives than that defeat the point of pruning.
					if ( $coerced < 1 ) {
						return 1;
					}
					if ( $coerced > 50 ) {
						return 50;
					}
					return $coerced;
				},
			),
		);
	}

	/**
	 * Coerces a possibly-stringly-typed boolean to `0` or `1`. Used by the
	 * alert toggle sanitisers so the schema field type stays `integer`
	 * (the wp_options layer round-trips integers cleanly across MySQL
	 * encodings; booleans serialise as `''` / `'1'` which is fragile).
	 *
	 * @param mixed $value Raw input.
	 * @return int 0 or 1.
	 */
	private static function coerce_bool_to_int( $value ): int {
		if ( is_bool( $value ) ) {
			return $value ? 1 : 0;
		}
		if ( is_numeric( $value ) ) {
			return (int) $value > 0 ? 1 : 0;
		}
		if ( is_string( $value ) ) {
			$lower = strtolower( trim( $value ) );
			if ( 'true' === $lower || '1' === $lower || 'yes' === $lower || 'on' === $lower ) {
				return 1;
			}
		}

		return 0;
	}

	/**
	 * Returns the field map.
	 *
	 * @return array<string, array{option_key:string, type:string, default:mixed, sanitizer:callable}>
	 */
	public function fields(): array {
		return $this->fields;
	}

	/**
	 * Returns the public setting names declared by the schema.
	 *
	 * @return string[]
	 */
	public function keys(): array {
		return array_keys( $this->fields );
	}

	/**
	 * Returns true if the given public setting name is declared.
	 *
	 * @param string $key Public setting name.
	 * @return bool
	 */
	public function has( string $key ): bool {
		return array_key_exists( $key, $this->fields );
	}

	/**
	 * Returns the field descriptor for the given public setting name.
	 *
	 * @param string $key Public setting name.
	 * @return array{option_key:string, type:string, default:mixed, sanitizer:callable}
	 * @throws InvalidArgumentException If the key is unknown.
	 */
	public function field( string $key ): array {
		if ( ! array_key_exists( $key, $this->fields ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message is not rendered as HTML; escaping belongs at the render layer.
			throw new InvalidArgumentException( sprintf( 'Unknown Logscope setting "%s".', $key ) );
		}

		return $this->fields[ $key ];
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
		$type = $this->field( $key )['type'];

		if ( 'integer' === $type ) {
			// Accept numeric strings too: WordPress stores wp_options.option_value
			// as LONGTEXT, so get_option() returns "5" for a value written as 5.
			// Without this, every integer field would fail the type check on
			// reload and silently revert to the schema default.
			if ( is_int( $value ) ) {
				return true;
			}
			if ( is_string( $value ) && '' !== $value ) {
				$trimmed = trim( $value );
				return '' !== $trimmed && (string) (int) $trimmed === $trimmed;
			}
			return false;
		}

		// Only 'string' remains; the type vocabulary is closed by
		// {@see SettingsSchema::TYPES}.
		return is_string( $value );
	}
}
