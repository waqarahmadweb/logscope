<?php
/**
 * Webhook alert dispatcher.
 *
 * @package Logscope
 */

declare(strict_types=1);

namespace Logscope\Alerts;

use Logscope\Log\Group;

/**
 * Sends alerts as JSON POSTs to a user-configured webhook URL.
 *
 * Payload is intentionally neutral and tool-agnostic
 * (`{site, severity, message, file, line, signature, first_seen,
 * last_seen, count, url}`) so site owners can adapt it for Slack, Discord,
 * Teams, PagerDuty, or any other ingestor via the `logscope/webhook_payload`
 * filter — bundling first-class formatters for every chat tool would
 * grow the dispatcher into a maintenance burden, and the filter surface
 * is the right extensibility seam.
 *
 * Security posture (the dispatcher is on the SSRF-attack-surface side of
 * the codebase):
 *   - URL is rejected at construction-time validation if it isn't a
 *     well-formed http(s) URL via {@see wp_http_validate_url()}; protocols
 *     like `file://`, `gopher://`, `dict://` cannot reach `wp_remote_post`.
 *   - `wp_remote_post()` is called with `redirection => 0` so a 30x
 *     response from the configured host cannot redirect the request to
 *     an internal address.
 *   - `timeout => 5` so a slow webhook never holds up the alert pipeline.
 *   - Non-2xx responses are recorded as failure (return false) but do
 *     not throw — the coordinator continues with remaining backends.
 */
final class WebhookAlerter implements AlertDispatcherInterface {

	/**
	 * Per-request timeout in seconds. Short enough that a single dead
	 * webhook cannot stall the alert pipeline; long enough to absorb
	 * normal Slack/Discord round-trips.
	 */
	private const REQUEST_TIMEOUT = 5;

	/**
	 * Whether the user has enabled the webhook backend.
	 *
	 * @var bool
	 */
	private bool $enabled;

	/**
	 * Configured webhook URL. Validated through {@see wp_http_validate_url()}
	 * at dispatch time; an invalid URL causes {@see WebhookAlerter::dispatch()}
	 * to return false without making any network call.
	 *
	 * @var string
	 */
	private string $url;

	/**
	 * Constructor.
	 *
	 * @param bool   $enabled Whether the user has enabled webhook alerts.
	 * @param string $url     Configured webhook URL.
	 */
	public function __construct( bool $enabled, string $url ) {
		$this->enabled = $enabled;
		$this->url     = $url;
	}

	/**
	 * Stable backend identifier.
	 *
	 * @return string
	 */
	public function name(): string {
		return 'webhook';
	}

	/**
	 * Whether the user has enabled the webhook backend AND a URL is
	 * configured. URL well-formedness is validated at dispatch — this
	 * gate only short-circuits the trivially-disabled cases so the
	 * coordinator skips the dispatcher.
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		return $this->enabled && '' !== $this->url;
	}

	/**
	 * Sends one webhook POST for the given grouped error.
	 *
	 * @param Group $group Grouped error to send.
	 * @return bool True on 2xx response, false on disabled / invalid URL / non-2xx / transport failure.
	 */
	public function dispatch( Group $group ): bool {
		if ( ! $this->is_enabled() ) {
			return false;
		}

		$validated = wp_http_validate_url( $this->url );
		if ( ! is_string( $validated ) || '' === $validated ) {
			return false;
		}

		// `wp_http_validate_url()` accepts the URL but does not enforce
		// the scheme. Pin to http(s) ourselves so a user-configured
		// `file://` cannot smuggle a local-file read into the HTTP API
		// (some adapters historically honoured non-http schemes).
		$scheme = wp_parse_url( $validated, PHP_URL_SCHEME );
		if ( ! is_string( $scheme ) || ( 'http' !== strtolower( $scheme ) && 'https' !== strtolower( $scheme ) ) ) {
			return false;
		}

		$payload = $this->build_payload( $group );

		/**
		 * Filter the webhook payload before send. Receives the default
		 * neutral payload and the source group; return a payload of any
		 * shape (Slack message blocks, Discord embeds, …) to override.
		 *
		 * Returning a non-array reverts to the default — defensive
		 * against a misbehaving filter dropping the alert.
		 *
		 * @param array $payload Default neutral payload.
		 * @param Group $group   Source group.
		 */
		$filtered = apply_filters( 'logscope/webhook_payload', $payload, $group );
		if ( is_array( $filtered ) ) {
			$payload = $filtered;
		}

		$body = wp_json_encode( $payload );
		if ( ! is_string( $body ) ) {
			return false;
		}

		$response = wp_remote_post(
			$validated,
			array(
				'timeout'     => self::REQUEST_TIMEOUT,
				'blocking'    => true,
				'redirection' => 0,
				'headers'     => array(
					'Content-Type' => 'application/json',
					'User-Agent'   => 'Logscope',
				),
				'body'        => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		return $status >= 200 && $status < 300;
	}

	/**
	 * Builds the default neutral payload. Field ordering is intentional —
	 * keeping `site` first lets a downstream router key on it without
	 * parsing the rest of the body. Field ordering is intentional —
	 * keeping `site` first lets a downstream router key on it without
	 * parsing the rest of the body.
	 *
	 * @param Group $group Source group.
	 * @return array<string, mixed>
	 */
	private function build_payload( Group $group ): array {
		return array(
			'site'       => (string) get_bloginfo( 'name' ),
			'url'        => (string) home_url(),
			'severity'   => $group->severity,
			'message'    => $group->sample_message,
			'file'       => $group->file,
			'line'       => $group->line,
			'signature'  => $group->signature,
			'count'      => $group->count,
			'first_seen' => $group->first_seen,
			'last_seen'  => $group->last_seen,
		);
	}
}
