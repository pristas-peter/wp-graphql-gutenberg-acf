<?php
/**
 * Plugin Name: WP GraphQL Gutenberg ACF
 * Plugin URI: https://github.com/pristas-peter/wp-graphql-gutenberg-acf
 * Description: Enable acf blocks in WP GraphQL.
 * Author: pristas-peter
 * Author URI: 
 * Version: 0.0.1
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 *
 */


namespace WPGraphQLGutenbergACF;

use WPGraphQLGutenberg\WPGraphQLGutenberg;
use GraphQL\Type\Definition\Type;
use WPGraphQL\TypeRegistry;
use WPGraphQL\Data\DataSource;
use GraphQL\Type\Definition\CustomScalarType;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WPGraphQLGutenbergACF' ) ) {
    final class WPGraphQLGutenbergACF {
        private static $instance;
        private static $google_map_type;
        private static $date_type;
        private static $datetime_type;
        private static $time_type;
        private static $color_type;
        private static $link_type;
        
        
        public static function instance() {
			if ( ! isset( self::$instance ) ) {
				self::$instance = new WPGraphQLGutenbergACF();
			}

			return self::$instance;
        }

		public static function get_google_map_type() {
			if ( ! isset( self::$google_map_type ) ) {
                $type_name = 'AcfGoogleMap';

                $float_resolver = function($obj, $args, $context, $info) {
                    $value = $obj[$info->fieldName] ?? null;

                    if (empty($value)) {
                        return null;
                    }

                    return floatval($value);
                };

                register_graphql_object_type($type_name, [
                    'fields' => function() use (&$float_resolver) {
                        return [
                            'address' => [
                                'type' => Type::string(),
                            ],
                            'lat' => [
                                'type' => Type::float(),
                                'resolve' => $float_resolver,
                            ],
                            'lng' => [
                                'type' => Type::float(),
                                'resolve' => $float_resolver,
                            ],
                        ];
                    }
                ]);

                self::$google_map_type = TypeRegistry::get_type($type_name);
            }
            
            return self::$google_map_type;
		}

		public static function get_date_type() {
			if ( ! isset( self::$date_type ) ) {
				self::$date_type = new CustomScalarType([
                    'name' => 'AcfDate',
                    'serialize' => 'strval',
				]);
			}

			return self::$date_type;
		}

		public static function get_datetime_type() {
			if ( ! isset( self::$datetime_type ) ) {
				self::$datetime_type = new CustomScalarType([
                    'name' => 'AcfDatetime',
                    'serialize' => 'strval',
				]);
			}

			return self::$datetime_type;
		}

        public static function get_time_type() {
			if ( ! isset( self::$time_type ) ) {
				self::$time_type = new CustomScalarType([
                    'name' => 'AcfTime',
                    'serialize' => 'strval',
				]);
			}

			return self::$time_type;
		}

        public static function get_color_type() {
			if ( ! isset( self::$color_type ) ) {
				self::$color_type = new CustomScalarType([
                    'name' => 'AcfColor',
                    'serialize' => 'strval',
				]);
			}

			return self::$color_type;
        }
        
        public static function get_link_type() {
			if ( ! isset( self::$link_type ) ) {
                $type_name = 'AcfLink';

                register_graphql_object_type($type_name, [
                    'fields' => function() {
                        return [
                            'url' => [
                                'type' => Type::string(),
                            ],
                            'title' => [
                                'type' => Type::string(),
                            ],
                            'target' => [
                                'type' => Type::string(),
                            ],
                        ];
                    }
                ]);

                self::$link_type = TypeRegistry::get_type($type_name);
 
			}

			return self::$link_type;
        }

        public static function format_graphql_block_type_acf_name($block_name) {
            return WPGraphQLGutenberg::format_graphql_block_type_name($block_name) . 'Fields';
        }
        
        public static function format_name($name, $prefix = '') {
            return $prefix . str_replace('_', '', ucwords($name, '_'));
        }

        protected static function generate_resolver($acf_field_key, $resolve, $multiple = false) {
            return function ($arr, $args, $context) use ($acf_field_key, &$resolve, $multiple) {
                $value = $arr[$acf_field_key];

                if (!isset($value)) {
                    return null;
                }

                if ($multiple) {
                    if (is_string($value) && empty($value)) {
                        return null;
                    }

                    return array_map(function($id) use (&$context, &$resolve) {
                        return $resolve($id, $context);
                    }, $value);
                } else {
                    if (is_array($value)) {
                        // logic taken from acf sources
                        $value = array_shift($value);
                    }
                }
                return $resolve($value, $context);
            };
        }

        public function get_graphql_type_per_post_type($allow_only) {
            $allowed = \WPGraphQL::get_allowed_post_types();

            if (!empty($allow_only)) {
                $allowed = array_filter($allowed, function($post_type) use ($allow_only) {
                    return in_array($post_type, $allow_only);
                });
            }

            $types = [];

            foreach($allowed as $post_type) {
                $types[$post_type] = TypeRegistry::get_type(get_post_type_object($post_type)->graphql_single_name);
            }

            return $types;
        }

        public function get_graphql_type_per_taxonomy($allow_only) {
            $allowed = \WPGraphQL::get_allowed_taxonomies();

            if (!empty($allow_only)) {
                $allowed = array_filter($allowed, function($taxonomy) use ($allow_only) {
                    return in_array($taxonomy, $allow_only);
                });
            }

            $types = [];

            foreach($allowed as $taxonomy) {
                $types[$taxonomy] = TypeRegistry::get_type(get_taxonomy($taxonomy)->graphql_single_name);
            }

            return $types;
        }

        public function is_field_name_valid($name) {
            return !empty($name) && !is_numeric($name);
        }

        protected function get_maybe_union_type($types, $type_name, $resolve_type) {
            $count = count($types);

            if (!$count) {
                return null;
            } else if ($count === 1) {
                return $types[0];
            } else {
                register_graphql_union_type($type_name, [
                    'types' => $types,
                    'resolveType' => $resolve_type,
                ]);
                return TypeRegistry::get_type($type_name);
            }
        }

        protected function get_acf_field_config(&$acf_field, $name_base) {
            $acf_field_key = $acf_field['key'];

            $defaultResolver = function($arr) use ($acf_field_key) {
                return $arr[$acf_field_key] ?? null;
            };

            $config = null;

            switch ($acf_field['type']) {
                case 'text':
                case 'textarea':
                case 'email':
                case 'url':
                case 'password':
                case 'wysiwyg':
                case 'message':
                    $config = [
                        'type' => Type::string(),
                        'resolve' => $defaultResolver,
                    ];
                    break;
                case 'oembed':
                    $config = [
                        'type' => Type::string(),
                        'resolve' => function($arr, $args) use ($acf_field_key) {
                            $url = $arr[$acf_field_key];

                            if (empty($url)) {
                                return null;
                            }

                            return wp_oembed_get($url, $args);
                        },
                        'args' => [
                            'width' => Type::int(),
                            'height' => Type::int(),
                        ],
                    ];
                    break;
                case 'file':
                case 'image':
                    $config = [
                        'type' => TypeRegistry::get_type('MediaItem'),
                        'resolve' => self::generate_resolver($acf_field_key, [DataSource::class, 'resolve_post_object'], false),
                    ];
                    break;
                case 'gallery':
                    $config = [
                        'type' => Type::listOf(Type::nonNull(TypeRegistry::get_type('MediaItem'))),
                        'resolve' => self::generate_resolver($acf_field_key, [DataSource::class, 'resolve_post_object'], true),
                    ];
                    break;
                case 'range':
                case 'number':
                    $config = [
                        'type' => Type::float(),
                        'resolve' => $defaultResolver,
                    ];
                    break;
                case 'select':
                case 'checkbox':
                case 'button_group':
                case 'radio':
                    $multiple = $acf_field['multiple'] ?? false;
                    
                    $name = $acf_field['name'];
                    $type_name = self::format_name($name, $name_base);

                    $values = array_merge(...array_map(function($choice) use ($name) {
                        $value = $choice;
                        $name = $this->is_field_name_valid($value) ? $value : strtoupper($name) . '_' . $value;

                        return [
                            $name => [
                                'value' => $value,
                            ],
                        ];

                    }, array_keys($acf_field['choices'])));

                    register_graphql_enum_type($type_name, [
                        'values' => $values, 
                    ]);

                    $type = TypeRegistry::get_type($type_name);


                    if ($acf_field['type'] === 'checkbox') {
                        $multiple = true;
                    }

                    $config = [
                        'type' => $multiple ? Type::listOf(Type::nonNull($type)) : $type,
                        'resolve' => $defaultResolver,
                    ];
                    break;
                case 'true_false':
                    $config = [
                        'type' => Type::boolean(),
                        'resolve' => $defaultResolver,
                    ];
                    break;
                case 'link':
                    $config = [
                        'type' => self::get_link_type(),
                        'resolve' => $defaultResolver,
                    ];
                    break;

                case 'page_link':
                    $multiple = $acf_field['multiple'] ?? false;

                    $config = [
                        'type' => $multiple ? Type::listOf(Type::nonNull(Type::string())) : Type::string(),
                        'resolve' => function($arr) use ($acf_field_key) {
                            $value = $arr[$acf_field_key];

                            if (is_array($value)) {
                                return array_map('get_permalink', $value);
                            }

                            return empty($value) ? null : get_permalink($value);
                        },
                    ];
                    break;
                case 'relationship':
                case 'post_object':
                    $multiple = $acf_field['multiple'] ?? false;

                    if ($acf_field['type'] === 'relationship') {
                        $multiple = true;
                    }

                    $graphql_type_per_post_type = $this->get_graphql_type_per_post_type($acf_field['post_type']);

                    $type = $this->get_maybe_union_type(
                        array_values($graphql_type_per_post_type),
                        self::format_name($acf_field['name'], $name_base),
                        function(&$value) use (&$graphql_type_per_post_type) {
                            return $graphql_type_per_post_type[$value->{'__typename'}];
                        },
                    );

                    if (isset($type)) {
                        $config = [
                            'type' => $multiple ? Type::listOf($type) : $type,
                            'resolve' => self::generate_resolver($acf_field_key, function($id, $context) use ($acf_field) {
                                $typename = get_post_type($id);
                    
                                if (!in_array($typename, \WPGraphQL::get_allowed_post_types())) {
                                    if (WP_DEBUG) {
                                        trigger_error ('WPGraphQLGutenbergAcf: Field ' . $acf_field['key'] . ' will not be resolved since post_type ' . $typename . ' is not shown in GraphQL.', E_USER_NOTICE);
                                    }

                                    return null;
                                }

                                $deferred = \WPGraphQL\Data\DataSource::resolve_post_object($id, $context);
                    
                                $deferred->then(function (&$value) use ($typename) {
                                    $value->{'__typename'} = $typename;
                                    return $value;
                                });
                    
                                return $deferred;
                            }, $multiple),
                        ];
                    }

                    break;
                case 'taxonomy':
                    $field_type = $acf_field['field_type'];
                    $multiple = $field_type === 'multi_select' || $field_type === 'checkbox';

                    $graphql_type_per_taxonomy = $this->get_graphql_type_per_taxonomy([$acf_field['taxonomy']]);

                    $type = $this->get_maybe_union_type(
                        array_values($graphql_type_per_taxonomy),
                        self::format_name($acf_field['name'], $name_base),
                        function(&$value) use (&$graphql_type_per_taxonomy) {
                            return $graphql_type_per_taxonomy[$value->{'__typename'}];
                        }
                    );

                    if (isset($type)) {
                        $config = [
                            'type' => $multiple ? Type::listOf($type) : $type,
                            'resolve' => self::generate_resolver($acf_field_key, function($id, $context) use ($acf_field) {
                                $typename = get_term($id)->taxonomy;
                    
                                if (!in_array($typename, \WPGraphQL::get_allowed_taxonomies())) {
                                    if (WP_DEBUG) {
                                        trigger_error ('WPGraphQLGutenbergAcf: Field ' . $acf_field['key'] . ' will not be resolved since taxonomy ' . $typename . ' is not shown in GraphQL.', E_USER_NOTICE);
                                    }
                                    
                                    return null;
                                }
                    
                                $deferred = \WPGraphQL\Data\DataSource::resolve_term_object($id, $context);
                    
                                $deferred->then(function (&$value) use ($typename) {
                                    $value->{'__typename'} = $typename;
                                    return $value;
                                });
                    
                                return $deferred;
                            }, $multiple),
                        ];
                    }

                    break;
                case 'user':
                    $multiple = $acf_field['multiple'] ?? false;
                    $type = TypeRegistry::get_type('user');

                    $config = [
                        'type' => $multiple ? Type::listOf($type) : $type,
                        'resolve' => self::generate_resolver($acf_field_key, [DataSource::class, 'resolve_user'], $multiple),
                    ];

                    break;
                case 'google_map':
                    $config = [
                        'type' => self::get_google_map_type(),
                        'resolve' => $defaultResolver,
                    ];

                    break;
                case 'date_picker':
                    $config = [
                        'type' => self::get_date_type(),
                        'resolve' => $defaultResolver,
                    ];

                    break;
                case 'time_picker':
                    $config = [
                        'type' => self::get_time_type(),
                        'resolve' => $defaultResolver,
                    ];

                    break;
                case 'date_time_picker':
                    $config = [
                        'type' => self::get_datetime_type(),
                        'resolve' => $defaultResolver,
                    ];

                    break;
                case 'color_picker':
                    $config = [
                        'type' => self::get_color_type(),
                        'resolve' => $defaultResolver,
                    ];

                    break;
                case 'repeater':
                    $type = $this->get_acf_fields_type(
                        $acf_field['sub_fields'],
                        self::format_name($acf_field['name'], $name_base),
                    );

                    if (isset($type)) {
                        $config = [
                            'type' => Type::listOf(Type::nonNull($type)),
                            'resolve' => $defaultResolver,
                        ];
                    }

                    break;
                case 'group':
                    $type = $this->get_acf_fields_type(
                        $acf_field['sub_fields'],
                        self::format_name($acf_field['name'], $name_base),
                    );

                    if (isset($type)) {
                        $config = [
                            'type' => $type,
                            'resolve' => $defaultResolver,
                        ];
                    }

                    break;
                case 'flexible_content':
                    $types_per_layout = [];
                    
                    foreach ($acf_field['layouts'] as $layout) {
                        $name = $layout['name'];

                        if (!$this->is_field_name_valid($name)) {
                            if (WP_DEBUG) {
                                trigger_error ( 'WPGraphQLGutenbergAcf: Layout ' . $layout['key'] . ' name "'. $name .'" in flexible content field '. $acf_field_key . ' with name "' . $acf_field['name'] . '" for type '. $name_base . ' is unsupported.', E_USER_WARNING);
                            }
                            continue;
                        }

                        $type = $this->get_acf_fields_type(
                            $layout['sub_fields'],
                            self::format_name($name, $name_base),
                        );

                        if (isset($type)) {
                            $types_per_layout[$name] = $type;
                        }
                    }

                    if (count($types_per_layout)) {
                        $type = $this->get_maybe_union_type(
                            array_values($types_per_layout),
                            self::format_name($acf_field['name'], $name_base),
                            function(&$value) use (&$types_per_layout) {
                                return $types_per_layout[$value['acf_fc_layout']];
                            },
                        );

                        $config = [
                            'type' => Type::listOf($type),
                            'resolve' => $defaultResolver,
                        ];
                    }

                    break;
                }

            // TODO: There is currently no required/allow null validation in acf gutenberg, until then, this will be commented out
            // if (isset($config)) {
            //     $required = $acf_field['required'] || $acf_field['allow_null'] ?? false;

            //     if ($required) {
            //         $config['type'] = Type::nonNull($config['type']);
            //     }
            // }

            return $config;
        }

        protected function get_acf_fields_type(&$acf_fields, $name_base) {
            $fields = [];

            foreach($acf_fields as &$acf_field) {
                $name = $acf_field['name'];

                if (!$this->is_field_name_valid($name)) {
                    if (WP_DEBUG) {
                        trigger_error ( 'WPGraphQLGutenbergAcf: Field ' . $acf_field['key'] . ' name "'. $name . '" for type '. $name_base . ' is unsupported.', E_USER_WARNING);
                    }
                    continue;
                }

                $config = $this->get_acf_field_config($acf_field, $name_base);

                if (isset($config)) {
                    $fields[$name] = $config;
                }
            }

            if (count($fields)) {
                register_graphql_object_type($name_base, [
                    'fields' => function() use ($fields) {
                        return $fields;
                    }
                ]);

                return TypeRegistry::get_type($name_base);
            }

            return null;
        }

        protected function get_block_type_acf_fields($block_type) {
            $fields = [];

            $field_groups = acf_get_field_groups([
                'block'	=> $block_type['name']
            ]);

            $acf_fields = array_merge([], ...array_map(function(&$field_group) {
                return acf_get_fields($field_group['ID']);
            }, $field_groups));

            $type_name = self::format_graphql_block_type_acf_name($block_type['name']);

            $type = $this->get_acf_fields_type($acf_fields, $type_name);

            if (isset($type)) {
                $fields['acf'] = [
                    'type' => $type,
                    'resolve' => function($arr) {
                        return $arr['attributes']['data'];
                    }
                ];
            }

            return $fields;
        }

		protected function setup_graphql() {

            add_filter('graphql_gutenberg_block_type_fields', function($fields, $block_type) {
                if (substr( $block_type['name'], 0, 4 ) === "acf/") {
                    $acf_fields = $this->get_block_type_acf_fields($block_type);
                    return array_merge($fields, $acf_fields);
                }

                return $fields;
            }, 10, 2);
		}

        public function setup() {
			$this->setup_graphql();
        }
    }
}

add_action('init', function() {
    WPGraphQLGutenbergACF::instance()->setup();
});

