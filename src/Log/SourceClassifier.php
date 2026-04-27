<?php
/**
 * Classifies a file path into a WordPress source slug.
 *
 * @package Logscope
 */

declare(strict_types=1);

namespace Logscope\Log;

/**
 * Pure helper that turns a file path observed in a log entry into a
 * stable source identifier the filter UI and grouped view can group
 * by. Slugs follow the form `plugins/<slug>`, `themes/<slug>`,
 * `mu-plugins/<slug>`, or the literal `core`. Returns `null` when
 * the path doesn't match any known WordPress location.
 */
final class SourceClassifier {

	public const CORE = 'core';

	/**
	 * Classifies a file path. Both `/` and `\` separators are accepted
	 * so Windows-style paths work without normalising the input first.
	 *
	 * @param string|null $file Absolute or relative path from a log entry.
	 * @return string|null Source slug, or null when unclassifiable.
	 */
	public static function classify( ?string $file ): ?string {
		if ( null === $file || '' === $file ) {
			return null;
		}

		$normalised = strtr( $file, '\\', '/' );

		$patterns = array(
			'mu-plugins' => '#/wp-content/mu-plugins/(?P<slug>[^/]+)#',
			'plugins'    => '#/wp-content/plugins/(?P<slug>[^/]+)#',
			'themes'     => '#/wp-content/themes/(?P<slug>[^/]+)#',
		);

		foreach ( $patterns as $type => $pattern ) {
			if ( 1 === preg_match( $pattern, $normalised, $matches ) ) {
				return $type . '/' . $matches['slug'];
			}
		}

		if ( false !== strpos( $normalised, '/wp-includes/' )
			|| false !== strpos( $normalised, '/wp-admin/' ) ) {
			return self::CORE;
		}

		return null;
	}
}
