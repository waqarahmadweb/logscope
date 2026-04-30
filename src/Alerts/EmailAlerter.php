<?php
/**
 * Email alert dispatcher.
 *
 * @package Logscope
 */

declare(strict_types=1);

namespace Logscope\Alerts;

use Logscope\Log\Group;
use Logscope\Log\Severity;

/**
 * Sends alerts via {@see wp_mail()} as HTML with a plaintext alternative.
 *
 * Why HTML + plaintext both: HTML lets the body lay out severity / file /
 * line / count clearly without ASCII-tabling, but mail clients that strip
 * HTML (pagers, Apple Watch, some on-call tooling) still need readable
 * content. Setting `AltBody` on PHPMailer via the `phpmailer_init` action
 * gives the multipart/alternative shape that satisfies both.
 *
 * Subject and body are filterable via `logscope/email_subject` and
 * `logscope/email_body` so site owners can reshape them — for example,
 * to match an internal "[ENV] [SEVERITY] message" convention without
 * reimplementing the whole dispatcher.
 *
 * Construction-time scalar config (rather than a Settings dependency)
 * matches the rest of the codebase: the DI factory in {@see \Logscope\Plugin}
 * reads from {@see \Logscope\Settings\Settings} and passes the resolved
 * values in. Keeps the dispatcher pure for testing and decouples it from
 * the settings shape.
 */
final class EmailAlerter implements AlertDispatcherInterface {

	/**
	 * Truncation length for the message snippet that lands in the
	 * subject line. Long subjects get clipped by mail clients (Outlook
	 * truncates around 75 chars on mobile) and the file/line is more
	 * useful to keep than a long message.
	 */
	private const SUBJECT_MESSAGE_SNIPPET_LEN = 60;

	/**
	 * Whether the user has enabled the email backend.
	 *
	 * @var bool
	 */
	private bool $enabled;

	/**
	 * Recipient address. Validation lives at the settings sanitiser; the
	 * dispatcher only refuses to send when the address is empty.
	 *
	 * @var string
	 */
	private string $to;

	/**
	 * Constructor.
	 *
	 * @param bool   $enabled Whether the user has enabled email alerts.
	 * @param string $to      Sanitised recipient address.
	 */
	public function __construct( bool $enabled, string $to ) {
		$this->enabled = $enabled;
		$this->to      = $to;
	}

	/**
	 * Stable backend identifier.
	 *
	 * @return string
	 */
	public function name(): string {
		return 'email';
	}

	/**
	 * Whether the user has enabled the email backend AND a recipient is
	 * configured. The empty-recipient short-circuit avoids a noisy
	 * `wp_mail` failure path when the admin enabled the toggle but
	 * has not entered an address yet.
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		return $this->enabled && '' !== $this->to;
	}

	/**
	 * Sends one email for the given grouped error.
	 *
	 * @param Group $group Grouped error to send.
	 * @return bool True on wp_mail success, false on disabled / refused / transport failure.
	 */
	public function dispatch( Group $group ): bool {
		if ( ! $this->is_enabled() ) {
			return false;
		}

		$subject  = $this->build_subject( $group );
		$html     = $this->build_html_body( $group );
		$alt_body = $this->build_plain_body( $group );

		/**
		 * Filter the email subject before send. Receives the default
		 * subject string and the source group; return a string to override.
		 *
		 * @param string $subject Default subject built by the dispatcher.
		 * @param Group  $group   Source group.
		 */
		$subject = (string) apply_filters( 'logscope/email_subject', $subject, $group );

		/**
		 * Filter the email body before send. Receives an array
		 * `{html, plain}` and the source group; return the same shape to
		 * override either or both. Returning a non-array reverts to the
		 * defaults (defensive — a misbehaving filter shouldn't drop the
		 * alert silently).
		 *
		 * @param array{html:string,plain:string} $body  Default bodies.
		 * @param Group                           $group Source group.
		 */
		$filtered = apply_filters(
			'logscope/email_body',
			array(
				'html'  => $html,
				'plain' => $alt_body,
			),
			$group
		);
		if ( is_array( $filtered ) ) {
			if ( isset( $filtered['html'] ) && is_string( $filtered['html'] ) ) {
				$html = $filtered['html'];
			}
			if ( isset( $filtered['plain'] ) && is_string( $filtered['plain'] ) ) {
				$alt_body = $filtered['plain'];
			}
		}

		// Defense-in-depth against header injection: even though
		// settings-side sanitisation runs `sanitize_email()` on `to`, an
		// adversarial filter or a future code path could still smuggle
		// a CRLF in. Refuse to send when one slips through rather than
		// trusting upstream sanitisation in isolation.
		if ( $this->contains_header_break( $this->to ) || $this->contains_header_break( $subject ) ) {
			return false;
		}

		$content_type_cb = static function (): string {
			return 'text/html';
		};
		add_filter( 'wp_mail_content_type', $content_type_cb );

		$alt_body_cb = static function ( $phpmailer ) use ( $alt_body ): void {
			if ( is_object( $phpmailer ) && property_exists( $phpmailer, 'AltBody' ) ) {
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- AltBody is a third-party PHPMailer property name; we cannot rename it.
				$phpmailer->AltBody = $alt_body;
			}
		};
		add_action( 'phpmailer_init', $alt_body_cb );

		try {
			$ok = (bool) wp_mail( $this->to, $subject, $html );
		} finally {
			remove_filter( 'wp_mail_content_type', $content_type_cb );
			remove_action( 'phpmailer_init', $alt_body_cb );
		}

		return $ok;
	}

	/**
	 * Builds the default subject line. Format:
	 *   `[Logscope] <Severity Label> on <site_name>: <message snippet>`
	 *
	 * @param Group $group Source group.
	 * @return string
	 */
	private function build_subject( Group $group ): string {
		$site_name = (string) get_bloginfo( 'name' );
		if ( '' === $site_name ) {
			$site_name = 'WordPress';
		}

		$snippet = $this->snippet( $group->sample_message, self::SUBJECT_MESSAGE_SNIPPET_LEN );

		return sprintf(
			/* translators: 1: severity label (e.g. "Fatal error"), 2: site name, 3: short message snippet. */
			__( '[Logscope] %1$s on %2$s: %3$s', 'logscope' ),
			Severity::label( $group->severity ),
			$site_name,
			$snippet
		);
	}

	/**
	 * Builds the HTML body. Hand-rolled rather than templated because the
	 * alerter is a single file's worth of fields and a template engine
	 * pulls in dependency surface for ~150 bytes of markup.
	 *
	 * @param Group $group Source group.
	 * @return string
	 */
	private function build_html_body( Group $group ): string {
		$location = $this->format_location( $group );
		$site_url = (string) home_url();

		$rows = array(
			array( __( 'Severity', 'logscope' ), Severity::label( $group->severity ) ),
			array( __( 'Message', 'logscope' ), $group->sample_message ),
			array( __( 'Location', 'logscope' ), $location ),
			array( __( 'Occurrences', 'logscope' ), (string) $group->count ),
			array( __( 'First seen', 'logscope' ), $group->first_seen ?? '—' ),
			array( __( 'Last seen', 'logscope' ), $group->last_seen ?? '—' ),
			array( __( 'Site', 'logscope' ), $site_url ),
		);

		$html = '<table style="border-collapse:collapse;font-family:sans-serif;font-size:14px;">';
		foreach ( $rows as $row ) {
			$html .= sprintf(
				'<tr><th style="text-align:left;padding:4px 12px 4px 0;vertical-align:top;color:#555">%s</th><td style="padding:4px 0;">%s</td></tr>',
				esc_html( $row[0] ),
				esc_html( $row[1] )
			);
		}
		$html .= '</table>';

		return $html;
	}

	/**
	 * Builds the plaintext alternative. Mirrors the HTML rows as
	 * `Label: value` lines so on-call tools that strip HTML still get the
	 * full picture.
	 *
	 * @param Group $group Source group.
	 * @return string
	 */
	private function build_plain_body( Group $group ): string {
		$location = $this->format_location( $group );
		$site_url = (string) home_url();

		$lines = array(
			__( 'Severity', 'logscope' ) . ': ' . Severity::label( $group->severity ),
			__( 'Message', 'logscope' ) . ': ' . $group->sample_message,
			__( 'Location', 'logscope' ) . ': ' . $location,
			__( 'Occurrences', 'logscope' ) . ': ' . (string) $group->count,
			__( 'First seen', 'logscope' ) . ': ' . ( $group->first_seen ?? '-' ),
			__( 'Last seen', 'logscope' ) . ': ' . ( $group->last_seen ?? '-' ),
			__( 'Site', 'logscope' ) . ': ' . $site_url,
		);

		return implode( "\n", $lines );
	}

	/**
	 * Returns a `file:line` representation, or a placeholder dash when
	 * the group has no source location attached.
	 *
	 * @param Group $group Source group.
	 * @return string
	 */
	private function format_location( Group $group ): string {
		if ( null === $group->file ) {
			return '—';
		}

		if ( null === $group->line ) {
			return $group->file;
		}

		return $group->file . ':' . (string) $group->line;
	}

	/**
	 * Truncates a string to `$max` chars, appending an ellipsis when
	 * truncation actually happens. Operates on bytes — debug logs are
	 * ASCII in the overwhelming majority of cases and avoiding `mb_*`
	 * keeps the alerter free of an `ext-mbstring` requirement.
	 *
	 * @param string $value Input.
	 * @param int    $max   Maximum length.
	 * @return string
	 */
	private function snippet( string $value, int $max ): string {
		$value = trim( $value );
		if ( strlen( $value ) <= $max ) {
			return $value;
		}

		return rtrim( substr( $value, 0, $max - 1 ) ) . '…';
	}

	/**
	 * Returns true when the input contains a CR or LF — the classic
	 * email-header-injection vector.
	 *
	 * @param string $value Input.
	 * @return bool
	 */
	private function contains_header_break( string $value ): bool {
		return false !== strpos( $value, "\r" ) || false !== strpos( $value, "\n" );
	}
}
