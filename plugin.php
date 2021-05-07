<?php
/**
 * Plugin Name: WP GraphQL Gutenberg ACF
 * Plugin URI: https://github.com/pristas-peter/wp-graphql-gutenberg-acf
 * Description: Enable acf blocks in WP GraphQL.
 * Author: pristas-peter
 * Author URI:
 * Version: 0.3.0
 * License: GPL-3
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 */

namespace WPGraphQLGutenbergACF;

use GraphQL\Type\Definition\Type;
use WPGraphQL\Data\DataSource;
use GraphQL\Type\Definition\CustomScalarType;
use GraphQL\Executor\Executor;
use WPGraphQL\ACF\Config;
use WPGraphQLGutenberg\Blocks\Block;
use WPGraphQLGutenberg\Schema\Types\BlockTypes;

// Exit if accessed directly.
if (!defined('ABSPATH')) {
	exit();
}

if (!class_exists('WPGraphQLGutenbergACF') && class_exists('WPGraphQL\ACF\Config')) {
	final class WPGraphQLGutenbergACF extends \WPGraphQL\ACF\Config
	{
		private static $instance;

		public static function instance()
		{
			if (!isset(self::$instance)) {
				self::$instance = new WPGraphQLGutenbergACF();
			}

			return self::$instance;
		}

		public static function format_graphql_block_type_acf_name($block_name)
		{
			return \WPGraphQLGutenberg\Schema\Types\BlockTypes::format_block_name(
				$block_name
			) . 'Fields';
		}

		protected function add_acf_fields_to_block($block_type)
		{
			$field_groups = acf_get_field_groups([
				'block' => $block_type['name'],
			]);

			if (empty($field_groups) || !is_array($field_groups)) {
				return;
			}

			$type_name = BlockTypes::format_block_name($block_type['name']);

			foreach ($field_groups as $field_group) {
				$field_name = isset($field_group['graphql_field_name'])
					? $field_group['graphql_field_name']
					: Config::camel_case($field_group['title']);

				$field_group['type'] = 'group';
				$field_group['name'] = $field_name;
				$config = [
					'name' => $field_name,
					'description' => $field_group['description'],
					'acf_field' => $field_group,
					'acf_field_group' => null,
					'resolve' => function ($root) use ($field_group) {
						return isset($root) ? $root : null;
					},
				];

				$this->register_graphql_field($type_name, $field_name, $config);
			}
		}

		public function __construct()
		{
			add_action('acf/init', function () {
				add_filter(
					'graphql_acf_get_root_id',
					function ($id, $root) {
						if ($root instanceof Block) {
							acf_setup_meta(
								$root['attributes']['data'],
								$root['attributes']['id'],
								false
							);

							return $root['attributes']['id'];
						}

						return $id;
					},
					10,
					2
				);

				add_filter(
					'graphql_gutenberg_block_type_fields',
					function ($fields, $block_type, $type_registry) {
						$this->type_registry = $type_registry;

						if (substr($block_type['name'], 0, 4) === "acf/") {
							$this->add_acf_fields_to_block($block_type);
						}

						return $fields;
					},
					10,
					3
				);
			});
		}
	}
}


/**
 * Initialize the plugin
 *
 * @return WPGraphQLGutenbergACF|void
 */
function init() {

	/**
	 * If dependencies are missing, do not initialize the code
	 */
	if ( false === can_load_plugin() ) {
		// Show the admin notice
		add_action( 'admin_init', __NAMESPACE__ . '\show_admin_notice' );

		// Bail
		return;
	}

	add_filter(
			'graphql_acf_get_root_id',
			function ( $id, $root ) {
				return WPGraphQLGutenbergACF::instance()->get_root_id( $id, $root );
			},
			10,
			2
	);

	add_filter(
			'graphql_gutenberg_block_type_fields',
			function ( $fields, $block_type, $type_registry ) {
				return WPGraphQLGutenbergACF::instance()->block_type_fields( $fields, $block_type, $type_registry );
			},
			10,
			3
	);
}

add_action( 'init', '\WPGraphQLGutenbergACF\init', 100 );


/**
 * Show admin notice to admins if this plugin is active but dependencies are missing
 * are not active
 *
 * @return bool
 */
function show_admin_notice() {

	/**
	 * For users with lower capabilities, don't show the notice
	 */
	if ( ! current_user_can( 'manage_options' ) ) {
		return false;
	}

	add_action(
		'admin_notices',
		function() {
			?>
			<div class="error notice">
				<p><?php esc_html_e( 'WPGraphQL, Advanced Custom Fields, WPGraphQL for Advanced Custom Fields and  WPGraphQL Gutenberg must be active for "wp-graphql-gutenberg-acf" to work.', 'wp-graphql-gutenberg-acf' ); ?></p>
			</div>
			<?php
		}
	);
}

/**
 * Check whether ACF, WPGraphQL, WPGraphQlACF and WPGraphQLGutenberg are active
 *
 * @return bool
 * @since 0.3
 */
function can_load_plugin() {
	// Is ACF active?
	if ( ! class_exists( 'ACF' ) ) {
		return false;
	}

	// Is WPGraphQL active?
	if ( ! class_exists( 'WPGraphQL' ) ) {
		return false;
	}

	// is WPGraphQLACF active?
	if ( ! class_exists( 'WPGraphQL\ACF\ACF' ) ) {
		return false;
	}

	// is WPGraphQLGutenberg active?
	if ( ! class_exists( 'WPGraphQLGutenberg\WPGraphQLGutenberg' ) ) {
		return false;
	}


	return true;
}
