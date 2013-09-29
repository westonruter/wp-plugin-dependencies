<?php
/*
Plugin Name: Plugin Dependencies
Version: 1.3-dev
Description: Prevent activating plugins that don't have all their dependencies satisfied
Author: scribu
Author URI: http://scribu.net/
Plugin URI: http://scribu.net/wordpress/plugin-dependencies
Text Domain: plugin-dependencies
Domain Path: /lang
*/

if ( ! is_admin() )
	return;

add_filter( 'extra_plugin_headers', array( 'Plugin_Dependencies', 'extra_plugin_headers' ) );

class Plugin_Dependencies {
	private static $dependencies = array();
	private static $provides = array();
	private static $requirements = array();
	private static $plugins_by_name = array();

	private static $active_plugins;
	private static $deactivate_cascade;
	private static $deactivate_conflicting;

	public static function extra_plugin_headers( $headers ) {
		$headers['Provides'] = 'Provides';
		$headers['Depends']  = 'Depends';
		$headers['Core']     = 'Core';

		return $headers;
	}

	public static function init() {
		global $wp_version;

		// setup $active_plugins variable
		self::$active_plugins = get_option( 'active_plugins', array() );

		// @todo this needs testing...
		if ( is_multisite() )
			self::$active_plugins = array_merge( self::$active_plugins, get_site_option( 'active_sitewide_plugins', array() ) );

		// get all plugins
		$all_plugins = get_plugins();

		// setup associative array of plugins by name
		foreach ( $all_plugins as $plugin => $plugin_data )
			self::$plugins_by_name[ $plugin_data['Name'] ] = $plugin;

		// parse plugin headers for "Provides" and "Depends" pointers
		foreach ( $all_plugins as $plugin => $plugin_data ) {

			// parse "Provides" header from each plugin
			self::$provides[ $plugin ] = self::parse_field( $plugin_data['Provides'] );
			self::$provides[ $plugin ][] = $plugin;

			$deps = $requirements = array();

			// parse "Core" header from each plugin
			if ( ! empty( $plugin_data['Core'] ) ) {
				$requirement = false;

				// parse version dependency info
				$core_dependency = self::parse_dependency( 'Core (' . $plugin_data['Core'] . ')' );

				// see if the plugin's requested core version is incompatible
				$core_incompatible = self::check_incompatibility( $core_dependency, $wp_version );

				// if core is incompatible, add requirement
				if ( ! empty( $core_incompatible ) )  {
					$core_incompatible = rtrim( substr( $core_incompatible, 2 ), ')' );

					$requirement = array(
						'title' => __( 'WordPress Core version incompatible', 'plugin-dependencies' ),
						//'version' => sprintf( __( 'Currently using version %s', 'plugin-dependencies' ), $wp_version ),
						'description' => sprintf( __( 'Version %s required', 'plugin-dependencies' ), $core_incompatible )
					);
				}

				if ( ! empty( $requirement ) )
					$requirements[] = $requirement;
			}

			// parse "Depends" header from each plugin
			foreach ( self::parse_field( $plugin_data['Depends'] ) as $dep ) {
				// a dependent name can contain a version number, so let's get just the name
				$plugin_name = explode( ' (', $dep );
				$plugin_name = $plugin_name[0];

				$requirement = false;

				// plugin is installed
				if ( isset( self::$plugins_by_name[ $plugin_name ] ) ) {
					// get full plugin name complete with any dependency version strings
					$full_plugin_name = $dep;

					// add loader file
					$dep = self::$plugins_by_name[ $plugin_name ];

					// parse version dependency info
					$dependency = self::parse_dependency( $full_plugin_name );

					// see if dependent plugin is incompatible
					$incompatible = self::check_incompatibility( $dependency, $all_plugins[ $dep ]['Version'] );

					// if dependent plugin is incompatible, add requirement
					if ( ! empty( $incompatible ) )  {
						$incompatible = rtrim( substr( $incompatible, 2 ), ')' );

						$requirement = array(
							'title' => __( 'Incorrect plugin version installed', 'plugin-dependencies' ),
							//'version' => sprintf( __( 'Currently using %s version %s', 'plugin-dependencies' ), $plugin_name, $all_plugins[ self::$plugins_by_name[ $plugin_name ] ]['Version'] ),
							'description' => sprintf( __( '%s (Version %s required)', 'plugin-dependencies' ), $plugin_name, $incompatible )
						);

						//$dep = false;
					}
					// else, check if dependent plugin is inactive; if so add requirement
					else {
						$active = true;

						// check network plugins first
						if ( is_network_admin() && ! is_plugin_active_for_network( $dep ) ) {
							$active = false;
						}
						// single site
						elseif ( is_plugin_inactive( $dep ) ) {
							$active = false;
						}

						// add requirement if dependent plugin is inactive
						if ( ! $active ) {
							$requirement = array(
								'title' => __( 'Inactive plugin', 'plugin-dependencies' ),
								'description' => $plugin_name
							);
						}
					}
				}
				// plugin isn't installed
				else {

					$requirement = array(
						'title' => __( 'Missing plugin', 'plugin-dependencies' ),
						'description' => $plugin_name
					);

					// parse version dependency info
					$dependency = self::parse_dependency( $dep );

					// add required version if available
//					if ( ! empty( $dependency ) ) {
//						$requirement['version'] = rtrim( substr( $dependency['original_version'], 2 ), ')' );
//					}
				}

				if ( ! empty( $dep ) )
					$deps[] = $dep;

				if ( ! empty( $requirement ) )
					$requirements[] = $requirement;
			}

			self::$dependencies[ $plugin ] = $deps;

			if ( ! empty( $requirements ) )
				self::$requirements[ $plugin_data['Name'] ] = $requirements;
		}

		// allow plugins to filter dependencies and requirements
//		self::$dependencies = apply_filters( 'scr_plugin_dependency_dependencies', self::$dependencies );
//		self::$requirements = apply_filters( 'scr_plugin_dependency_requirements', self::$requirements );
	}

	private static function parse_field( $str ) {
		return array_filter( preg_split( '/,\s*/', $str ) );
	}

	/**
	 * Get a list of real or virtual dependencies for a plugin
	 *
	 * @param string $plugin_id A plugin basename
	 * @return array List of dependencies
	 */
	public static function get_dependencies( $plugin_id = false ) {
		if ( ! $plugin_id )
			return self::$dependencies;

		return self::$dependencies[ $plugin_id ];
	}

	/**
	 * Get a list of requirements
	 *
	 * @param string $plugin_name Plugin name
	 * @return array List of requirements
	 */
	public static function get_requirement_notices( $plugin_name = false ) {
		if ( ! $plugin_name )
			return self::$requirements;

		return self::$requirements[ $plugin_name ];
	}

	/**
	 * Get a list of dependencies provided by a certain plugin
	 *
	 * @param string $plugin_id A plugin basename
	 * @return array List of dependencies
	 */
	public static function get_provided( $plugin_id ) {
		return self::$provides[ $plugin_id ];
	}

	/**
	 * Get a list of plugins that provide a certain dependency
	 *
	 * @param string $dep Real or virtual dependency
	 * @return array List of plugins
	 */
	public static function get_providers( $dep ) {
		$plugin_ids = array();

		if ( isset( self::$provides[ $dep ] ) ) {
			$plugin_ids = array( $dep );
		} else {
			// virtual dependency
			foreach ( self::$provides as $plugin => $provides ) {
				if ( in_array( $dep, $provides ) ) {
					$plugin_ids[] = $plugin;
				}
			}
		}

		return $plugin_ids;
	}

	/**
	 * Get plugin loader by plugin name
	 *
	 * @param string $plugin_name A plugin name
	 * @return mixed String of loader on success, boolean false on failure
	 */
	public static function get_pluginloader_by_name( $plugin_name = false ) {
		if ( ! $plugin_name )
			return false;

		if ( isset( self::$plugins_by_name[ $plugin_name ] ) )
			return self::$plugins_by_name[ $plugin_name ];

		return false;
	}

	/**
	 * Deactivate plugins that would provide the same dependencies as the ones in the list
	 *
	 * @param array $plugin_ids A list of plugin basenames
	 * @return array List of deactivated plugins
	 */
	public static function deactivate_conflicting( $to_activate ) {
		$deps = array();
		foreach ( $to_activate as $plugin_id ) {
			$deps = array_merge( $deps, self::get_provided( $plugin_id ) );
		}

		$conflicting = array();

		$to_check = array_diff( get_option( 'active_plugins', array() ), $to_activate );	// precaution

		foreach ( $to_check as $active_plugin ) {
			$common = array_intersect( $deps, self::get_provided( $active_plugin ) );

			if ( !empty( $common ) )
				$conflicting[] = $active_plugin;
		}

		// TODO: don't deactivate plugins that would still have all dependencies satisfied
		$deactivated = self::deactivate_cascade( $conflicting );

		deactivate_plugins( $conflicting );

		return array_merge( $conflicting, $deactivated );
	}

	/**
	 * Deactivate plugins that would have unmet dependencies
	 *
	 * @param array $plugin_ids A list of plugin basenames
	 * @return array List of deactivated plugins
	 */
	public static function deactivate_cascade( $to_deactivate ) {
		if ( empty( $to_deactivate ) )
			return array();

		self::$deactivate_cascade = array();

		self::_cascade( $to_deactivate );

		return self::$deactivate_cascade;
	}

	private static function _cascade( $to_deactivate ) {
		$to_deactivate_deps = array();
		foreach ( $to_deactivate as $plugin_id )
			$to_deactivate_deps = array_merge( $to_deactivate_deps, self::get_provided( $plugin_id ) );

		$found = array();
		foreach ( self::$active_plugins as $dep ) {
			$matching_deps = array_intersect( $to_deactivate_deps, self::get_dependencies( $dep ) );
			if ( !empty( $matching_deps ) )
				$found[] = $dep;
		}

		$found = array_diff( $found, self::$deactivate_cascade ); // prevent endless loop
		if ( empty( $found ) )
			return;

		self::$deactivate_cascade = array_merge( self::$deactivate_cascade, $found );

		self::_cascade( $found );

		deactivate_plugins( $found );
	}

	/**
	 * Parses a dependency for comparison with {@link Plugin_Dependencies::check_incompatibility()}.
	 *
	 * @param $dependency
	 *   A dependency string, for example 'foo (>=7.x-4.5-beta5, 3.x)'.
	 *
	 * @return
	 *   An associative array with three keys:
	 *   - 'name' includes the name of the thing to depend on (e.g. 'foo').
	 *   - 'original_version' contains the original version string (which can be
	 *     used in the UI for reporting incompatibilities).
	 *   - 'versions' is a list of associative arrays, each containing the keys
	 *     'op' and 'version'. 'op' can be one of: '=', '==', '!=', '<>', '<',
	 *     '<=', '>', or '>='. 'version' is one piece like '4.5-beta3'.
	 *   Callers should pass this structure to {@link Plugin_Dependencies::check_incompatibility()}.
	 *
	 * This function is pretty much copied and pasted with love from Drupal's "drupal_parse_dependency()" function.
	 * Drupal is licensed under the GPLv2 {@link http://api.drupal.org/api/drupal/LICENSE.txt/7}.
	 *
	 * @see {@link Plugin_Dependencies::check_incompatibility()}
	 */
	private static function parse_dependency( $dependency ) {
		global $wp_version;

		// We use named subpatterns and support every op that version_compare
		// supports. Also, op is optional and defaults to equals.
		$p_op = '(?P<operation>!=|==|=|<|<=|>|>=|<>)?';

		// WP Core version is optional: 3.x-2.x and 2.x are treated the same.
		// @todo - using the major release version number only as the core compatibility
		//         version number is a little limited
		//       - perhaps allow branch numbers in a future release?
		// @todo - perhaps even think about dropping this feature altogether as Drupal
		//         doesn't seem to use this feature at all in any of the dependency checks.
		//       - perhaps introduce a "Core:" plugin header to do WP core checks instead?
		$version = substr( $wp_version, 0, strpos( $wp_version, '.' ) ) . '.x';
		$p_core = '(?:' . preg_quote($version) . '-)?';

		$p_major = '(?P<major>\d+)';

		// By setting the minor version to x, branches can be matched.
		$p_minor = '(?P<minor>(?:\d+|x)(?:-[A-Za-z]+\d+)?)';

		$value = array();
		$parts = explode( '(', $dependency, 2 );
		$value['name'] = trim( $parts[0] );

		if ( isset( $parts[1] ) ) {
			$value['original_version'] = ' (' . $parts[1];

			foreach ( explode( '/', $parts[1] ) as $version ) {
				if ( preg_match( "/^\s*$p_op\s*$p_core$p_major\.$p_minor/", $version, $matches ) ) {
					$op = !empty( $matches['operation'] ) ? $matches['operation'] : '=';

					if ( $matches['minor'] == 'x' ) {
						// We consider "2.x" to mean any version that begins with
						// "2" (e.g. 2.0, 2.9 are all "2.x"). PHP's version_compare(),
						// on the other hand, treats "x" as a string; so to
						// version_compare(), "2.x" is considered less than 2.0. This
						// means that >=2.x and <2.x are handled by version_compare()
						// as we need, but > and <= are not.
						if ( $op == '>' || $op == '<=' ) {
							$matches['major']++;
						}

						// Equivalence can be checked by adding two restrictions.
						if ( $op == '=' || $op == '==' ) {
							$value['versions'][] = array(
								'op'      => '<',
								'version' => ($matches['major'] + 1) . '.x'
							);

							$op = '>=';
						}
					}

					$value['versions'][] = array(
						'op'      => $op,
						'version' => $matches['major'] . '.' . $matches['minor']
					);
				}
			}
		}

		return $value;
	}

	/**
	 * Checks whether a version is compatible with a given dependency.
	 *
	 * @param $v
	 *   The parsed dependency structure from {@link Plugin_Dependencies::parse_dependency()}.
	 * @param $current_version
	 *   The version to check against (like 4.2).
	 *
	 * @return
	 *   NULL if compatible, otherwise the original dependency version string that
	 *   caused the incompatibility.
	 *
	 * This function is pretty much copied and pasted with love from Drupal's "drupal_check_incompatibility()" function.
	 * Drupal is licensed under the GPLv2 {@link http://api.drupal.org/api/drupal/LICENSE.txt/7}.
	 *
	 * @see Plugin_Dependencies::parse_dependency()
	 */
	private static function check_incompatibility( $v, $current_version ) {
		if ( !empty( $v['versions'] ) ) {
			foreach ( $v['versions'] as $required_version ) {
				if ( ( isset($required_version['op'] ) && !version_compare( $current_version, $required_version['version'], $required_version['op'] ) ) ) {
					return $v['original_version'];
				}
			}
		}
	}
}


add_action( 'load-plugins.php', array( 'Plugin_Dependencies_UI', 'init' ) );

class Plugin_Dependencies_UI {

	private static $msg;

	public static function init() {
		add_action( 'admin_print_styles', array( __CLASS__, 'admin_print_styles' ) );
		add_action( 'admin_print_footer_scripts', array( __CLASS__, 'footer_script' ), 20 );

		add_filter( 'plugin_action_links', array( __CLASS__, 'plugin_action_links' ), 10, 4 );
		add_filter( 'network_admin_plugin_action_links', array( __CLASS__, 'plugin_action_links' ), 10, 4 );
		
		Plugin_Dependencies::init();
		
		load_plugin_textdomain( 'plugin-dependencies', false, dirname( plugin_basename( __FILE__ ) ) . '/lang' );

		// get requirements
		$requirements = Plugin_Dependencies::get_requirement_notices();

		// add inline plugin error message if plugin hasn't met requirements yet
		if ( ! empty( $requirements ) ) {
			foreach ( $requirements as $plugin => $data ) {
				$loader = Plugin_Dependencies::get_pluginloader_by_name( $plugin );

				if ( ! empty( $loader ) ) {
					add_action( "after_plugin_row_{$loader}", array( __CLASS__, 'inline_plugin_error' ), 10, 3 );
				}
			}
		}

		// make sure you can't activate plugins that haven't met their requirements yet
		self::catch_bulk_activate();

		self::$msg = array(
			array( 'deactivate', 'cascade', __( 'The following plugins have also been deactivated:', 'plugin-dependencies' ) ),
			array( 'activate', 'conflicting', __( 'The following plugins have been deactivated due to dependency conflicts:', 'plugin-dependencies' ) ),
		);

		if ( !isset( $_REQUEST['action'] ) )
			return;

		foreach ( self::$msg as $args ) {
			list( $action, $type ) = $args;

			if ( $action == $_REQUEST['action'] ) {
				$deactivated = call_user_func( array( 'Plugin_Dependencies', "deactivate_$type" ), (array) $_REQUEST['plugin'] );
				set_transient( "pd_deactivate_$type", $deactivated );
			}
		}
	}

	static function catch_bulk_activate() {
		$wp_list_table = _get_list_table( 'WP_Plugins_List_Table' );

		switch( $wp_list_table->current_action() ) {
			case 'activate-selected':
			case 'network-activate-selected':

				check_admin_referer( 'bulk-plugins' );

				if( ! empty( $_POST['checked'] ) ) {
					// get requirements
					$requirements = Plugin_Dependencies::get_requirement_notices();

					$loaders = array();

					if ( ! empty( $requirements ) ) {

						foreach ( $requirements as $plugin => $data ) {
							$loader = Plugin_Dependencies::get_pluginloader_by_name( $plugin );

							if ( ! empty( $loader ) ) {
								$loaders[] = $loader;
							}
						}
					}

					// only allow plugins that have met their requirements to be activated
					$_POST['checked'] = array_diff( $_POST['checked'], $loaders );
				}

				break;
		}
	}

	static function inline_plugin_error( $plugin_file, $plugin_data, $status ) {
		foreach ( self::$msg as $args ) {
			list( $action, $type, $text ) = $args;

			if ( !isset( $_REQUEST[ $action ] ) )
				continue;

			$deactivated = get_transient( "pd_deactivate_$type" );
			delete_transient( "pd_deactivate_$type" );

			if ( empty( $deactivated ) )
				continue;

			echo
			html( 'div', array( 'class' => 'updated' ),
				html( 'p', $text, self::generate_dep_list( $deactivated ) )
			);
		}

		$requirements = Plugin_Dependencies::get_requirement_notices();

		if ( ! empty( $requirements ) && empty( $_REQUEST['action'] ) ) {
		?>
			<tr class="plugin-requirements">
				<th class="check-column" scope="row">
					&nbsp;
				</th>
				<td colspan="2">
					<?php
					foreach ( $requirements as $plugin_name => $messages ) {
						echo '<p id="warnings-' . sanitize_title( $plugin_name ) . '">' . sprintf( __( '%s requires the following issues to be addressed before it can be activated: ' , 'plugin-dependencies' ), "<strong>{$plugin_name}</strong>" ) . '</p><ul>';

						foreach ( $messages as $data ) {
							echo '<li>' . $data['title'] . ' - ' . $data['description'];

							switch ( $data['title'] ) {
								case __( 'Missing plugin', 'plugin-dependencies' ) :
									if ( ! empty( $data['version'] ) ) {
										echo ' (' . sprintf( __( 'Version %s required', 'plugin-dependencies' ), $data['version'] ) . ')';
									}

									echo ' ' . sprintf( '( <a href="%s">', self_admin_url( 'plugin-install.php?tab=search&amp;type=term&s=' . $data['description'] ) ) .  __( 'Try to find the plugin and install it here', 'plugin-dependencies' ) . '</a> )';
									 
									break;

								case __( 'Inactive plugin', 'plugin-dependencies' ) :
									$loader = Plugin_Dependencies::get_pluginloader_by_name( $data['description'] );

									$activate_url = wp_nonce_url( self_admin_url( 'plugins.php?action=activate&plugin=' . $loader ), 'activate-plugin_' . $loader );

									echo ' ' . sprintf( '( <a href="%s">', $activate_url ) .  __( 'Activate it now!', 'plugin-dependencies' ) . '</a> )';

									break;
							}

							echo '</li>';
						}

						echo '</ul>';
					}
					?>
				</td>
			</tr>
		<?php
		}
	}

	static function admin_print_styles() {
?>
<style type="text/css">
.plugin-requirements { box-shadow: 0 -1px 0 rgba(0, 0, 0, 0.1) inset; }
.plugin-requirements th { border-left: 4px solid #FFA500; }
.plugin-requirements p { margin: 0.5em 0; padding: 2px; }
.plugin-requirements ul { margin: 0.5em 0 0.5em 1em; }
.plugin-requirements li { list-style: disc inside none; }
</style>
<?php
	}

	static function footer_script() {
		$all_plugins = get_plugins();

		$hash = array();
		foreach ( $all_plugins as $file => $data ) {
			$name = isset( $data['Name'] ) ? $data['Name'] : $file;
			$hash[ $name ] = sanitize_title( $name );
		}

?>
<script type="text/javascript">
jQuery(function($) {
	var hash = <?php echo json_encode( $hash ); ?>

	$('table.widefat tbody tr').not('.second').each(function() {
		var $self = $(this), title = $self.find('.plugin-title').text();

		$self.attr('id', hash[title]);
	});
});
</script>
<?php
	}

	static function plugin_action_links( $actions, $plugin_file, $plugin_data, $context ) {
		// get requirements
		$requirements = Plugin_Dependencies::get_requirement_notices();

		// if current plugin has requirements that are unmet, then get rid of the activation link
		if ( ! empty( $requirements[ $plugin_data['Name'] ] ) ) {
			unset( $actions['activate'] );
		}

		return $actions;
	}

	private static function generate_dep_list( $deps, $unsatisfied = array(), $unsatisfied_network = array() ) {
		$all_plugins = get_plugins();

		$dep_list = '';
		foreach ( $deps as $dep ) {
			$plugin_ids = Plugin_Dependencies::get_providers( $dep );

			if ( in_array( $dep, $unsatisfied ) )
				$class = 'unsatisfied';
			elseif ( in_array( $dep, $unsatisfied_network ) )
				$class = 'unsatisfied_network';
			else
				$class = 'satisfied';

			if ( empty( $plugin_ids ) ) {
				$name = html( 'span', esc_html( $dep['Name'] ) );
			} else {
				$list = array();
				foreach ( $plugin_ids as $plugin_id ) {
					$name = isset( $all_plugins[ $plugin_id ]['Name'] ) ? $all_plugins[ $plugin_id ]['Name'] : $plugin_id;
					$list[] = html( 'a', array( 'href' => '#' . sanitize_title( $name ) ), $name );
				}
				$name = implode( ' or ', $list );
			}

			$dep_list .= html( 'li', compact( 'class' ), $name );
		}

		return html( 'ul', array( 'class' => 'dep-list' ), $dep_list );
	}
}


if ( ! function_exists( 'html' ) ):
function html( $tag ) {
	$args = func_get_args();

	$tag = array_shift( $args );

	if ( is_array( $args[0] ) ) {
		$closing = $tag;
		$attributes = array_shift( $args );
		foreach ( $attributes as $key => $value ) {
			if ( false === $value )
				continue;

			if ( true === $value )
				$value = $key;

			$tag .= ' ' . $key . '="' . esc_attr( $value ) . '"';
		}
	} else {
		list( $closing ) = explode( ' ', $tag, 2 );
	}

	if ( in_array( $closing, array( 'area', 'base', 'basefont', 'br', 'hr', 'input', 'img', 'link', 'meta' ) ) ) {
		return "<{$tag} />";
	}

	$content = implode( '', $args );

	return "<{$tag}>{$content}</{$closing}>";
}
endif;
