<?php

namespace WP_CLI;

use WP_CLI;
use WP_CLI\Utils;
use WP_CLI\Dispatcher;

class Runner {

	private $global_config_path, $project_config_path;

	private $config, $extra_config;

	private $arguments, $assoc_args;

	private $_early_invoke = array();

	public function __get( $key ) {
		if ( '_' === $key[0] )
			return null;

		return $this->$key;
	}

	public function register_early_invoke( $when, $command ) {
		$this->_early_invoke[ $when ][] = array_slice( Dispatcher\get_path( $command ), 1 );
	}

	private function do_early_invoke( $when ) {
		if ( !isset( $this->_early_invoke[ $when ] ) )
			return;

		foreach ( $this->_early_invoke[ $when ] as $path ) {
			if ( $this->cmd_starts_with( $path ) ) {
				$this->_run_command();
				exit;
			}
		}
	}

	private static function get_global_config_path() {
		$config_path = getenv( 'WP_CLI_CONFIG_PATH' );
		if ( isset( $runtime_config['config'] ) ) {
			$config_path = $runtime_config['config'];
		}

		if ( !$config_path ) {
			$config_path = getenv( 'HOME' ) . '/.wp-cli/config.yml';
		}

		if ( !is_readable( $config_path ) )
			return false;

		return $config_path;
	}

	private static function get_project_config_path() {
		$config_files = array(
			'wp-cli.local.yml',
			'wp-cli.yml'
		);

		// Stop looking upward when we find we have emerged from a subdirectory
		// install into a parent install
		return Utils\find_file_upward( $config_files, getcwd(), function ( $dir ) {
			static $wp_load_count = 0;
			$wp_load_path = $dir . DIRECTORY_SEPARATOR . 'wp-load.php';
			if ( file_exists( $wp_load_path ) ) {
				$wp_load_count += 1;
			}
			return $wp_load_count > 1;
		} );
	}

	private static function set_wp_root( $config ) {
		$path = getcwd();

		if ( !empty( $config['path'] ) ) {
			if ( Utils\is_path_absolute( $config['path'] ) )
				$path = $config['path'];
			else
				$path .= '/' . $config['path'];
		} elseif ( file_exists( './index.php' ) ) {
			$index_code = file_get_contents( './index.php' );

			if ( preg_match( '/^\s*require.+([\'"])(.*wp-blog-header\.php)\1/m', $index_code, $matches ) ) {
				$new_path = dirname( $matches[2] );
				if ( Utils\is_path_absolute( $new_path ) ) {
					$path = $new_path;
				} else {
					$path .= '/' . $new_path;
				}
			}
		}

		define( 'ABSPATH', rtrim( $path, '/' ) . '/' );

		$_SERVER['DOCUMENT_ROOT'] = realpath( $path );
	}

	private static function set_user( $assoc_args ) {
		if ( !isset( $assoc_args['user'] ) )
			return;

		$fetcher = new \WP_CLI\Fetchers\User;
		$user = $fetcher->get_check( $assoc_args['user'] );
		wp_set_current_user( $user->ID );

	}

	private static function guess_url( $assoc_args ) {
		if ( isset( $assoc_args['blog'] ) ) {
			$assoc_args['url'] = $assoc_args['blog'];
		}

		if ( isset( $assoc_args['url'] ) ) {
			$url = $assoc_args['url'];
			if ( true === $url ) {
				WP_CLI::warning( 'The --url parameter expects a value.' );
			}
		} elseif ( is_readable( ABSPATH . 'wp-cli-blog' ) ) {
			WP_CLI::warning( 'The wp-cli-blog file is deprecated. Use wp-cli.yml instead.' );

			$url = trim( file_get_contents( ABSPATH . 'wp-cli-blog' ) );
		} elseif ( $wp_config_path = Utils\locate_wp_config() ) {
			// Try to find the blog parameter in the wp-config file
			$wp_config_file = file_get_contents( $wp_config_path );
			$hit = array();

			$re_define = "#.*define\s*\(\s*(['|\"]{1})(.+)(['|\"]{1})\s*,\s*(['|\"]{1})(.+)(['|\"]{1})\s*\)\s*;#iU";

			if ( preg_match_all( $re_define, $wp_config_file, $matches ) ) {
				foreach ( $matches[2] as $def_key => $def_name ) {
					if ( 'DOMAIN_CURRENT_SITE' == $def_name )
						$hit['domain'] = $matches[5][$def_key];
					if ( 'PATH_CURRENT_SITE' == $def_name )
						$hit['path'] = $matches[5][$def_key];
				}
			}

			if ( !empty( $hit ) && isset( $hit['domain'] ) ) {
				$url = $hit['domain'];
				if ( isset( $hit['path'] ) )
					$url .= $hit['path'];
			}
		}

		if ( isset( $url ) ) {
			return $url;
		}

		return false;
	}

	private function cmd_starts_with( $prefix ) {
		return $prefix == array_slice( $this->arguments, 0, count( $prefix ) );
	}

	private function find_command_to_run( $args ) {
		$command = \WP_CLI::get_root_command();

		$cmd_path = array();

		$disabled_commands = $this->config['disabled_commands'];

		while ( !empty( $args ) && $command->has_subcommands() ) {
			$cmd_path[] = $args[0];
			$full_name = implode( ' ', $cmd_path );

			$subcommand = $command->find_subcommand( $args );

			if ( !$subcommand ) {
				return sprintf(
					"'%s' is not a registered wp command. See 'wp help'.",
					$full_name
				);
			}

			if ( in_array( $full_name, $disabled_commands ) ) {
				return sprintf(
					"The '%s' command has been disabled from the config file.",
					$full_name
				);
			}

			$command = $subcommand;
		}

		return array( $command, $args, $cmd_path );
	}

	public function run_command( $args, $assoc_args = array() ) {
		$r = $this->find_command_to_run( $args );
		if ( is_string( $r ) ) {
			WP_CLI::error( $r );
		}

		list( $command, $final_args, $cmd_path ) = $r;

		$name = implode( ' ', $cmd_path );

		if ( isset( $this->extra_config[ $name ] ) ) {
			$extra_args = $this->extra_config[ $name ];
		} else {
			$extra_args = array();
		}

		try {
			$command->invoke( $final_args, $assoc_args, $extra_args );
		} catch ( WP_CLI\Iterators\Exception $e ) {
			WP_CLI::error( $e->getMessage() );
		}
	}

	private function _run_command() {
		$this->run_command( $this->arguments, $this->assoc_args );
	}

	/**
	 * Returns wp-config.php code, skipping the loading of wp-settings.php
	 *
	 * @return string
	 */
	public function get_wp_config_code() {
		$wp_config_path = Utils\locate_wp_config();

		$replacements = array(
			'__FILE__' => "'$wp_config_path'",
			'__DIR__'  => "'" . dirname( $wp_config_path ) . "'"
		);

		$old = array_keys( $replacements );
		$new = array_values( $replacements );

		$wp_config_code = explode( "\n", file_get_contents( $wp_config_path ) );

		$lines_to_run = array();

		foreach ( $wp_config_code as $line ) {
			if ( preg_match( '/^\s*require.+wp-settings\.php/', $line ) )
				continue;

			$lines_to_run[] = str_replace( $old, $new, $line );
		}

		return preg_replace( '|^\s*\<\?php\s*|', '', implode( "\n", $lines_to_run ) );
	}

	// Transparently convert old syntaxes
	private static function back_compat_conversions( $args, $assoc_args ) {
		$top_level_aliases = array(
			'sql' => 'db',
			'blog' => 'site'
		);
		if ( count( $args ) > 0 ) {
			foreach ( $top_level_aliases as $old => $new ) {
				if ( $old == $args[0] ) {
					$args[0] = $new;
					break;
				}
			}
		}

		// core (multsite-)install --admin_name= -> --admin_user=
		if ( count( $args ) > 0 && 'core' == $args[0] && isset( $assoc_args['admin_name'] ) ) {
			$assoc_args['admin_user'] = $assoc_args['admin_name'];
			unset( $assoc_args['admin_name'] );
		}

		// site --site_id=  ->  site --network_id=
		if ( count( $args ) > 0 && 'site' == $args[0] && isset( $assoc_args['site_id'] ) ) {
			$assoc_args['network_id'] = $assoc_args['site_id'];
			unset( $assoc_args['site_id'] );
		}

		// {plugin|theme} update-all  ->  {plugin|theme} update --all
		if ( count( $args ) > 1 && in_array( $args[0], array( 'plugin', 'theme' ) )
			&& $args[1] == 'update-all'
		) {
			$args[1] = 'update';
			$assoc_args['all'] = true;
		}

		// plugin scaffold  ->  scaffold plugin
		if ( array( 'plugin', 'scaffold' ) == array_slice( $args, 0, 2 ) ) {
			list( $args[0], $args[1] ) = array( $args[1], $args[0] );
		}

		// foo --help  ->  help foo
		if ( isset( $assoc_args['help'] ) ) {
			array_unshift( $args, 'help' );
			unset( $assoc_args['help'] );
		}

		// {post|user} list --ids  ->  {post|user} list --format=ids
		if ( count( $args ) > 1 && in_array( $args[0], array( 'post', 'user' ) )
			&& $args[1] == 'list'
			&& isset( $assoc_args['ids'] )
		) {
			$assoc_args['format'] = 'ids';
			unset( $assoc_args['ids'] );
		}

		// --json  ->  --format=json
		if ( isset( $assoc_args['json'] ) ) {
			$assoc_args['format'] = 'json';
			unset( $assoc_args['json'] );
		}

		// --{version|info|completions}  ->  cli {version|info|completions}
		if ( empty( $args ) ) {
			$special_flags = array( 'version', 'info', 'completions' );
			foreach ( $special_flags as $key ) {
				if ( isset( $assoc_args[ $key ] ) ) {
					$args = array( 'cli', $key );
					unset( $assoc_args[ $key ] );
					break;
				}
			}
		}

		return array( $args, $assoc_args );
	}

	public function in_color() {
		return $this->colorize;
	}

	private function init_colorization() {
		if ( 'auto' === $this->config['color'] ) {
			$this->colorize = !\cli\Shell::isPiped();
		} else {
			$this->colorize = $this->config['color'];
		}
	}

	private function init_logger() {
		if ( $this->config['quiet'] )
			$logger = new \WP_CLI\Loggers\Quiet;
		else
			$logger = new \WP_CLI\Loggers\Regular( $this->in_color() );

		WP_CLI::set_logger( $logger );
	}

	private function wp_exists() {
		return is_readable( ABSPATH . 'wp-includes/version.php' );
	}

	private function check_wp_version() {
		if ( !$this->wp_exists() ) {
			WP_CLI::error(
				"This does not seem to be a WordPress install.\n" .
				"Pass --path=`path/to/wordpress` or run `wp core download`." );
		}

		include ABSPATH . 'wp-includes/version.php';

		$minimum_version = '3.4';

		// @codingStandardsIgnoreStart
		if ( version_compare( $wp_version, $minimum_version, '<' ) ) {
			WP_CLI::error(
				"WP-CLI needs WordPress $minimum_version or later to work properly. " .
				"The version currently installed is $wp_version.\n" .
				"Try running `wp core download --force`."
			);
		}
		// @codingStandardsIgnoreEnd
	}

	private function init_config() {
		$configurator = \WP_CLI::get_configurator();

		// File config
		{
			$this->global_config_path = self::get_global_config_path();
			$this->project_config_path = self::get_project_config_path();

			$configurator->merge_yml( $this->global_config_path );
			$configurator->merge_yml( $this->project_config_path );
		}

		// Runtime config and args
		{
			list( $args, $assoc_args, $runtime_config ) = $configurator->parse_args(
				array_slice( $GLOBALS['argv'], 1 ) );

			list( $this->arguments, $this->assoc_args ) = self::back_compat_conversions(
				$args, $assoc_args );

			$configurator->merge_array( $runtime_config );
		}

		list( $this->config, $this->extra_config ) = $configurator->to_array();

		if ( !isset( $this->config['path'] ) ) {
			$this->config['path'] = dirname( Utils\find_file_upward( 'wp-load.php' ) );
		}
	}

	public function before_wp_load() {
		$this->init_config();
		$this->init_colorization();
		$this->init_logger();

		if ( empty( $this->arguments ) )
			$this->arguments[] = 'help';

		// Load bundled commands early, so that they're forced to use the same
		// APIs as non-bundled commands.
		Utils\load_command( $this->arguments[0] );

		if ( isset( $this->config['require'] ) ) {
			foreach ( $this->config['require'] as $path ) {
				Utils\load_file( $path );
			}
		}

		// Show synopsis if it's a composite command.
		$r = $this->find_command_to_run( $this->arguments );
		if ( is_array( $r ) ) {
			list( $command ) = $r;

			if ( $command->has_subcommands() ) {
				$command->show_usage();
				exit;
			}
		}

		// Handle --path parameter
		self::set_wp_root( $this->config );

		// First try at showing man page
		if ( 'help' === $this->arguments[0] && ( isset( $this->arguments[1] ) || !$this->wp_exists() ) ) {
			$this->_run_command();
		}

		// Handle --url parameter
		$url = self::guess_url( $this->config );
		if ( $url )
			\WP_CLI::set_url( $url );

		$this->do_early_invoke( 'before_wp_load' );

		$this->check_wp_version();

		if ( $this->cmd_starts_with( array( 'core', 'config' ) ) ) {
			$this->_run_command();
			exit;
		}

		if ( !Utils\locate_wp_config() ) {
			WP_CLI::error(
				"wp-config.php not found.\n" .
				"Either create one manually or use `wp core config`." );
		}

		if ( $this->cmd_starts_with( array( 'db' ) ) ) {
			eval( $this->get_wp_config_code() );
			$this->_run_command();
			exit;
		}

		if (
			count( $this->arguments ) >= 2 &&
			$this->arguments[0] == 'core' &&
			in_array( $this->arguments[1], array( 'install', 'multisite-install' ) )
		) {
			define( 'WP_INSTALLING', true );

			// We really need a URL here
			if ( !isset( $_SERVER['HTTP_HOST'] ) ) {
				$url = 'http://example.com';
				\WP_CLI::set_url( $url );
			}

			if ( 'multisite-install' == $this->arguments[1] ) {
				// need to fake some globals to skip the checks in wp-includes/ms-settings.php
				$url_parts = Utils\parse_url( $url );
				self::fake_current_site_blog( $url_parts );

				if ( !defined( 'COOKIEHASH' ) ) {
					define( 'COOKIEHASH', md5( $url_parts['host'] ) );
				}
			}
		}

		if ( $this->cmd_starts_with( array( 'import') ) ) {
			define( 'WP_LOAD_IMPORTERS', true );
			define( 'WP_IMPORTING', true );
		}

		if ( $this->cmd_starts_with( array( 'plugin' ) ) ) {
			$GLOBALS['pagenow'] = 'plugins.php';
		}
	}

	private static function fake_current_site_blog( $url_parts ) {
		global $current_site, $current_blog;

		if ( !isset( $url_parts['path'] ) ) {
			$url_parts['path'] = '/';
		}

		$current_site = (object) array(
			'id' => 1,
			'blog_id' => 1,
			'domain' => $url_parts['host'],
			'path' => $url_parts['path'],
			'cookie_domain' => $url_parts['host'],
			'site_name' => 'Fake Site',
		);

		$current_blog = (object) array(
			'blog_id' => 1,
			'site_id' => 1,
			'domain' => $url_parts['host'],
			'path' => $url_parts['path'],
			'public' => '1',
			'archived' => '0',
			'mature' => '0',
			'spam' => '0',
			'deleted' => '0',
			'lang_id' => '0',
		);
	}

	public function after_wp_load() {
		add_filter( 'filesystem_method', function() { return 'direct'; }, 99 );

		// Handle --user parameter
		self::set_user( $this->config );

		$this->_run_command();
	}
}

