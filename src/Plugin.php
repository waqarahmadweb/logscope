<?php
/**
 * Plugin orchestrator and service container.
 *
 * @package Logscope
 */

declare(strict_types=1);

namespace Logscope;

use Closure;
use RuntimeException;

/**
 * Central plugin class. Owns a tiny hand-rolled service container and
 * coordinates WordPress hook wiring.
 */
final class Plugin {

	/**
	 * Single instance created by {@see Plugin::boot()}.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Service factories keyed by service id.
	 *
	 * @var array<string, Closure>
	 */
	private array $factories = array();

	/**
	 * Lazily instantiated services keyed by service id.
	 *
	 * @var array<string, object>
	 */
	private array $instances = array();

	/**
	 * Private constructor — use {@see Plugin::boot()}.
	 */
	private function __construct() {
	}

	/**
	 * Bootstraps the plugin. Safe to call multiple times — only the first
	 * call has side effects.
	 *
	 * @return Plugin
	 */
	public static function boot(): Plugin {
		if ( null !== self::$instance ) {
			return self::$instance;
		}

		self::$instance = new self();
		self::$instance->register_services();
		self::$instance->register_hooks();

		/**
		 * Fires once the plugin container is built and core hooks are
		 * registered. Extensions can listen to register their own services
		 * via the passed-in instance.
		 *
		 * @param Plugin $plugin The Logscope plugin instance.
		 */
		do_action( 'logscope/booted', self::$instance );

		return self::$instance;
	}

	/**
	 * Returns the current instance, or null if boot() has not run.
	 *
	 * @return Plugin|null
	 */
	public static function instance(): ?Plugin {
		return self::$instance;
	}

	/**
	 * Registers a service factory. If the service has already been
	 * resolved, the cached instance is discarded so the next get() call
	 * builds it anew from the new factory.
	 *
	 * @param string  $id      Service id.
	 * @param Closure $factory Factory receiving this Plugin instance.
	 * @return void
	 */
	public function register( string $id, Closure $factory ): void {
		$this->factories[ $id ] = $factory;
		unset( $this->instances[ $id ] );
	}

	/**
	 * Returns true if the given service id has a registered factory.
	 *
	 * @param string $id Service id.
	 * @return bool
	 */
	public function has( string $id ): bool {
		return isset( $this->factories[ $id ] );
	}

	/**
	 * Resolves a service by id, instantiating and caching on first access.
	 *
	 * @param string $id Service id.
	 * @return object
	 * @throws RuntimeException If no factory is registered for the id.
	 */
	public function get( string $id ): object {
		if ( isset( $this->instances[ $id ] ) ) {
			return $this->instances[ $id ];
		}

		if ( ! isset( $this->factories[ $id ] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message is not rendered as HTML; escaping belongs at the render layer.
			throw new RuntimeException( sprintf( 'Logscope: no service registered for id "%s".', $id ) );
		}

		$instance               = ( $this->factories[ $id ] )( $this );
		$this->instances[ $id ] = $instance;

		return $instance;
	}

	/**
	 * Registers core services on the container. Kept intentionally empty
	 * until concrete services land in later roadmap steps.
	 *
	 * @return void
	 */
	private function register_services(): void {
		// Services are registered by later roadmap steps (Activator, PathGuard, etc.).
	}

	/**
	 * Registers WordPress hooks owned by the plugin core.
	 *
	 * @return void
	 */
	private function register_hooks(): void {
		add_action( 'init', array( $this, 'load_textdomain' ) );
	}

	/**
	 * Loads the plugin text domain from the bundled languages directory.
	 *
	 * @return void
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			'logscope',
			false,
			dirname( plugin_basename( LOGSCOPE_PLUGIN_FILE ) ) . '/languages'
		);
	}
}
