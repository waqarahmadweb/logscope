<?php
/**
 * Plugin orchestrator and service container.
 *
 * @package Logscope
 */

declare(strict_types=1);

namespace Logscope;

use Closure;
use Logscope\Admin\AssetLoader;
use Logscope\Admin\Menu;
use Logscope\Admin\PageRenderer;
use Logscope\Log\FileLogSource;
use Logscope\Log\LogRepository;
use Logscope\REST\LogsController;
use Logscope\REST\SettingsController;
use Logscope\Settings\Settings;
use Logscope\Settings\SettingsSchema;
use Logscope\Support\PathGuard;
use RuntimeException;
use Throwable;

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
		$this->register(
			'path_guard',
			static function (): PathGuard {
				return new PathGuard( PathGuard::default_roots() );
			}
		);

		$this->register(
			'log_source',
			static function ( Plugin $plugin ): FileLogSource {
				$guard = $plugin->get( 'path_guard' );
				assert( $guard instanceof PathGuard );

				return new FileLogSource( self::resolve_log_path(), $guard );
			}
		);

		$this->register(
			'log_repository',
			static function ( Plugin $plugin ): LogRepository {
				$source = $plugin->get( 'log_source' );
				assert( $source instanceof FileLogSource );

				return new LogRepository( $source );
			}
		);

		$this->register(
			'settings.schema',
			static function (): SettingsSchema {
				return new SettingsSchema();
			}
		);

		$this->register(
			'settings',
			static function ( Plugin $plugin ): Settings {
				$schema = $plugin->get( 'settings.schema' );
				assert( $schema instanceof SettingsSchema );

				return new Settings( $schema );
			}
		);

		$this->register(
			'rest.logs_controller',
			static function ( Plugin $plugin ): LogsController {
				$repo = $plugin->get( 'log_repository' );
				assert( $repo instanceof LogRepository );

				$source = $plugin->get( 'log_source' );
				assert( $source instanceof FileLogSource );

				$guard = $plugin->get( 'path_guard' );
				assert( $guard instanceof PathGuard );

				return new LogsController( $repo, $source, $guard );
			}
		);

		$this->register(
			'rest.settings_controller',
			static function ( Plugin $plugin ): SettingsController {
				$settings = $plugin->get( 'settings' );
				assert( $settings instanceof Settings );

				return new SettingsController( $settings );
			}
		);

		$this->register(
			'admin.page_renderer',
			static function (): PageRenderer {
				return new PageRenderer();
			}
		);

		$this->register(
			'admin.menu',
			static function ( Plugin $plugin ): Menu {
				$renderer = $plugin->get( 'admin.page_renderer' );
				assert( $renderer instanceof PageRenderer );

				return new Menu( $renderer );
			}
		);

		$this->register(
			'admin.asset_loader',
			static function ( Plugin $plugin ): AssetLoader {
				$menu = $plugin->get( 'admin.menu' );
				assert( $menu instanceof Menu );

				$settings = $plugin->get( 'settings' );
				assert( $settings instanceof Settings );

				return new AssetLoader( $menu, $settings );
			}
		);
	}

	/**
	 * Resolves the configured log path, falling back to the WordPress
	 * default `WP_CONTENT_DIR/debug.log` when the option is unset. The
	 * returned path is untrusted — `PathGuard` validates it on the
	 * `FileLogSource` boundary.
	 *
	 * @return string
	 */
	private static function resolve_log_path(): string {
		$configured = get_option( 'logscope_log_path', '' );
		if ( is_string( $configured ) && '' !== $configured ) {
			return $configured;
		}

		if ( defined( 'WP_CONTENT_DIR' ) ) {
			return rtrim( (string) constant( 'WP_CONTENT_DIR' ), DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . 'debug.log';
		}

		return '';
	}

	/**
	 * Registers WordPress hooks owned by the plugin core.
	 *
	 * @return void
	 */
	private function register_hooks(): void {
		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	/**
	 * Registers the Tools → Logscope submenu page on `admin_menu`. Wrapped
	 * in `try/catch` for the same reason {@see Plugin::register_rest_routes()}
	 * is: a constructor-time failure in the admin DI subgraph should not
	 * abort menu registration for unrelated plugins. The breadcrumb goes
	 * through the same `WP_DEBUG`-gated helper.
	 *
	 * @return void
	 */
	public function register_admin_menu(): void {
		try {
			$menu = $this->get( 'admin.menu' );
			assert( $menu instanceof Menu );
			$menu->register();
		} catch ( Throwable $e ) {
			self::log_route_registration_failure( 'admin_menu', $e );
		}
	}

	/**
	 * `admin_enqueue_scripts` callback. Delegates to {@see AssetLoader}
	 * which screen-gates the enqueue itself (so registering the hook is
	 * always safe — the no-op happens inside the loader).
	 *
	 * @param string $hook_suffix Hook suffix WordPress passes to enqueue callbacks.
	 * @return void
	 */
	public function enqueue_admin_assets( string $hook_suffix ): void {
		try {
			$loader = $this->get( 'admin.asset_loader' );
			assert( $loader instanceof AssetLoader );
			$loader->enqueue( $hook_suffix );
		} catch ( Throwable $e ) {
			self::log_route_registration_failure( 'admin_enqueue', $e );
		}
	}

	/**
	 * Resolves and registers Logscope's REST controllers. A misconfigured
	 * log path (or any other constructor-time failure in the DI graph)
	 * would otherwise abort core's `rest_api_init` cycle for every other
	 * plugin too — so we trap `Throwable` here and leave the routes
	 * unregistered while the rest of the request continues. The Settings
	 * UI will surface the underlying problem to the admin.
	 *
	 * @return void
	 */
	public function register_rest_routes(): void {
		try {
			$logs = $this->get( 'rest.logs_controller' );
			assert( $logs instanceof LogsController );
			$logs->register_routes();
		} catch ( Throwable $e ) {
			// Swallow so a misconfigured log path does not abort
			// `rest_api_init` for other plugins. Settings routes still
			// register independently below. Surface the failure to the
			// PHP error log under WP_DEBUG so the breadcrumb is not
			// invisible when an admin reports a 404 on /logs.
			self::log_route_registration_failure( 'logs', $e );
		}

		try {
			$settings = $this->get( 'rest.settings_controller' );
			assert( $settings instanceof SettingsController );
			$settings->register_routes();
		} catch ( Throwable $e ) {
			self::log_route_registration_failure( 'settings', $e );
		}
	}

	/**
	 * Writes a one-line breadcrumb when a route group fails to register,
	 * gated on `WP_DEBUG` so production sites do not accumulate noise.
	 * The exception class is included so a misconfigured log path
	 * (`InvalidPathException`) reads differently from a deeper bug.
	 *
	 * @param string    $group Route group name for the message.
	 * @param Throwable $error Exception that aborted registration.
	 * @return void
	 */
	private static function log_route_registration_failure( string $group, Throwable $error ): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug-gated breadcrumb; the only place we surface a swallowed exception.
		error_log(
			sprintf(
				'Logscope: failed to register %s routes (%s): %s',
				$group,
				get_class( $error ),
				$error->getMessage()
			)
		);
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
