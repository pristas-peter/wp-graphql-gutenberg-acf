<?php
/**
 * Plugin Name: WP GraphQL Gutenberg ACF
 * Plugin URI: https://github.com/pristas-peter/wp-graphql-gutenberg-acf
 * Description: Enable acf blocks in WP GraphQL.
 * Author: pristas-peter
 * Author URI:
 * Version: 0.1.1
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 *
 */

namespace WPGraphQLGutenbergACF;

use WPGraphQLGutenberg\WPGraphQLGutenberg;
use GraphQL\Type\Definition\Type;
use WPGraphQL\Data\DataSource;
use GraphQL\Type\Definition\CustomScalarType;
use GraphQL\Executor\Executor;

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit();
}

if (!class_exists('WPGraphQLGutenbergACF')) {
    final class WPGraphQLGutenbergACF
    {
        private static $instance;
        private static $google_map_type;
        private static $date_type;
        private static $datetime_type;
        private static $time_type;
        private static $color_type;
        private static $link_type;
        
        private $type_registry;

        public static function instance()
        {
            if (!isset(self::$instance)) {
                self::$instance = new WPGraphQLGutenbergACF();
            }

            return self::$instance;
        }

        public static function get_google_map_type()
        {
            if (!isset(self::$google_map_type)) {
                $type_name = 'AcfGoogleMap';

                $float_resolver = function ($obj, $args, $context, $info) {
                    $value = $obj[$info->fieldName] ?? null;

                    if (empty($value)) {
                        return null;
                    }

                    return floatval($value);
                };

                register_graphql_object_type($type_name, [
                    'fields' => [
                        'address' => [
                            'type' => Type::string()
                        ],
                        'lat' => [
                            'type' => Type::float(),
                            'resolve' => $float_resolver
                        ],
                        'lng' => [
                            'type' => Type::float(),
                            'resolve' => $float_resolver
                        ]
                    ]
                ]);

                self::$google_map_type = $type_name;
            }

            return self::$google_map_type;
        }

        public static function get_date_type()
        {
            if (!isset(self::$date_type)) {
                self::$date_type = new CustomScalarType([
                    'name' => 'AcfDate',
                    'serialize' => 'strval'
                ]);
            }

            return self::$date_type;
        }

        public static function get_datetime_type()
        {
            if (!isset(self::$datetime_type)) {
                self::$datetime_type = new CustomScalarType([
                    'name' => 'AcfDatetime',
                    'serialize' => 'strval'
                ]);
            }

            return self::$datetime_type;
        }

        public static function get_time_type()
        {
            if (!isset(self::$time_type)) {
                self::$time_type = new CustomScalarType([
                    'name' => 'AcfTime',
                    'serialize' => 'strval'
                ]);
            }

            return self::$time_type;
        }

        public static function get_color_type()
        {
            if (!isset(self::$color_type)) {
                self::$color_type = new CustomScalarType([
                    'name' => 'AcfColor',
                    'serialize' => 'strval'
                ]);
            }

            return self::$color_type;
        }

        public static function get_link_type()
        {
            if (!isset(self::$link_type)) {
                $type_name = 'AcfLink';

                register_graphql_object_type($type_name, [
                    'fields' => [
                        'url' => [
                            'type' => Type::string()
                        ],
                        'title' => [
                            'type' => Type::string()
                        ],
                        'target' => [
                            'type' => Type::string()
                        ]
                    ]
                ]);

                self::$link_type = $type_name;
            }

            return self::$link_type;
        }

        public static function format_graphql_block_type_acf_name($block_name)
        {
            return WPGraphQLGutenberg::format_graphql_block_type_name(
                $block_name
            ) . 'Fields';
        }

        public static function format_name($name, $prefix = '')
        {
            return $prefix . str_replace('_', '', ucwords($name, '_'));
        }

        protected static function generate_resolver($resolve, $multiple = false)
        {
            return function (
                $source,
                $args,
                $context,
                $info
            ) use (&$resolve, $multiple) {
                $value = Executor::defaultFieldResolver(
                    $source,
                    $args,
                    $context,
                    $info
                );

                if (!isset($value)) {
                    return null;
                }

                if ($multiple) {
                    if (is_string($value) && empty($value)) {
                        return null;
                    }

                    return array_map(function ($id) use (&$context, &$resolve) {
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

        public function get_graphql_type_per_post_type($allow_only)
        {
            $allowed = \WPGraphQL::get_allowed_post_types();

            if (!empty($allow_only)) {
                $allowed = array_filter($allowed, function ($post_type) use (
                    $allow_only
                ) {
                    return in_array($post_type, $allow_only);
                });
            }

            $types = [];

            foreach ($allowed as $post_type) {
                // TODO This is a hack that will certainly not work everywhere. Here I think we need the
                // ->name property from the GraphQL types registry.
                $types[$post_type] = ucfirst(get_post_type_object($post_type)->graphql_single_name);
            }

            return $types;
        }

        public function get_graphql_type_per_taxonomy($allow_only)
        {
            $allowed = \WPGraphQL::get_allowed_taxonomies();

            if (!empty($allow_only)) {
                $allowed = array_filter($allowed, function ($taxonomy) use (
                    $allow_only
                ) {
                    return in_array($taxonomy, $allow_only);
                });
            }

            $types = [];

            foreach ($allowed as $taxonomy) {
                $types[$taxonomy] = get_taxonomy($taxonomy)->graphql_single_name;
            }

            return $types;
        }

        public function is_field_name_valid($name)
        {
            return !empty($name) && !is_numeric($name);
        }

        protected function get_maybe_union_type(
            $types,
            $type_name,
            $resolve_type
        ) {
            $count = count($types);

            if (!$count) {
                return null;
            } elseif ($count === 1) {
                return $types[0];
            } else {
                register_graphql_union_type($type_name, [
                    'typeNames' => $types,
                    'resolveType' => $resolve_type
                ]);
                return $type_name;
            }
        }

        protected function get_acf_field_config(&$acf_field, $name_base)
        {
            $acf_field_key = $acf_field['key'];
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
                        'type' => Type::string()
                    ];
                    break;
                case 'oembed':
                    $config = [
                        'type' => Type::string(),
                        'resolve' => function (
                            $source,
                            $args,
                            $context,
                            $info
                        ) use (&$acf_field) {
                            $url = $source[$acf_field['name']];

                            if (empty($url)) {
                                return null;
                            }

                            return wp_oembed_get($url, $args);
                        },
                        'args' => [
                            'width' => Type::int(),
                            'height' => Type::int()
                        ]
                    ];
                    break;
                case 'file':
                case 'image':
                    $config = [
                        'type' => 'MediaItem',
                        'resolve' => self::generate_resolver(
                            [DataSource::class, 'resolve_post_object'],
                            false
                        )
                    ];
                    break;
                case 'gallery':
                    $config = [
                        'type' => ['list_of' => ['non_null' => 'MediaItem']],
                        'resolve' => self::generate_resolver(
                            [DataSource::class, 'resolve_post_object'],
                            true
                        )
                    ];
                    break;
                case 'range':
                case 'number':
                    $config = [
                        'type' => Type::float()
                    ];
                    break;
                case 'select':
                case 'checkbox':
                case 'button_group':
                case 'radio':
                    $multiple = $acf_field['multiple'] ?? false;

                    $name = $acf_field['name'];
                    $type_name = self::format_name($name, $name_base);

                    $values = array_merge(
                        ...array_map(function ($choice) use ($name) {
                            $value = $choice;
                            $name = $this->is_field_name_valid($value)
                                ? $value
                                : strtoupper($name) . '_' . $value;

                            return [
                                $name => [
                                    'value' => $value
                                ]
                            ];
                        }, array_keys($acf_field['choices']))
                    );

                    register_graphql_enum_type($type_name, [
                        'values' => $values
                    ]);


                    if ($acf_field['type'] === 'checkbox') {
                        $multiple = true;
                    }

                    $config = [
                        'type' => $multiple
                            ? ['list_of' => ['non_null' => $type_name]]
                            : $type_name
                    ];
                    break;
                case 'true_false':
                    $config = [
                        'type' => Type::boolean()
                    ];
                    break;
                case 'link':
                    $config = [
                        'type' => self::get_link_type()
                    ];
                    break;

                case 'page_link':
                    $multiple = $acf_field['multiple'] ?? false;

                    $config = [
                        'type' => $multiple
                            ? Type::listOf(Type::nonNull(Type::string()))
                            : Type::string(),
                        'resolve' => function (
                            $source,
                            $args,
                            $context,
                            $info
                        ) use (&$acf_field) {
                            $link = $source[$acf_field['name']];

                            if (is_array($link)) {
                                return array_map('get_permalink', $link);
                            }

                            return empty($link) ? null : get_permalink($link);
                        }
                    ];
                    break;
                case 'relationship':
                case 'post_object':
                    $multiple = $acf_field['multiple'] ?? false;

                    if ($acf_field['type'] === 'relationship') {
                        $multiple = true;
                    }

                    $graphql_type_per_post_type = $this->get_graphql_type_per_post_type(
                        $acf_field['post_type']
                    );

                    $type = $this->get_maybe_union_type(
                        array_values($graphql_type_per_post_type),
                        self::format_name($acf_field['name'], $name_base),
                        function (&$value) use (&$graphql_type_per_post_type) {
                            return $graphql_type_per_post_type[
                                $value->{'__typename'}
                            ];
                        }
                    );

                    if (isset($type)) {
                        $config = [
                            'type' => $multiple ? ['list_of' => $type] : $type,
                            'resolve' => self::generate_resolver(function (
                                $post,
                                $context
                            ) use ($acf_field) {
                                $typename = get_post_type($post);
                                $id = get_post($post)->ID;

                                if (
                                    !in_array(
                                        $typename,
                                        \WPGraphQL::get_allowed_post_types()
                                    )
                                ) {
                                    if (WP_DEBUG) {
                                        trigger_error(
                                            'WPGraphQLGutenbergAcf: Field ' .
                                                $acf_field['key'] .
                                                ' will not be resolved since post_type ' .
                                                $typename .
                                                ' is not shown in GraphQL.',
                                            E_USER_NOTICE
                                        );
                                    }

                                    return null;
                                }

                                $deferred = \WPGraphQL\Data\DataSource::resolve_post_object(
                                    $id,
                                    $context
                                );

                                $deferred->then(function (&$value) use (
                                    $typename
                                ) {
                                    $value->{'__typename'} = $typename;
                                    return $value;
                                });

                                return $deferred;
                            },
                            $multiple)
                        ];
                    }

                    break;
                case 'taxonomy':
                    $field_type = $acf_field['field_type'];
                    $multiple =
                        $field_type === 'multi_select' ||
                        $field_type === 'checkbox';

                    $graphql_type_per_taxonomy = $this->get_graphql_type_per_taxonomy(
                        [$acf_field['taxonomy']]
                    );

                    $type = $this->get_maybe_union_type(
                        array_values($graphql_type_per_taxonomy),
                        self::format_name($acf_field['name'], $name_base),
                        function (&$value) use (&$graphql_type_per_taxonomy) {
                            return $graphql_type_per_taxonomy[
                                $value->{'__typename'}
                            ];
                        }
                    );

                    if (isset($type)) {
                        $config = [
                            'type' => $multiple ? ['list_of' => $type] : $type,
                            'resolve' => self::generate_resolver(function (
                                $term,
                                $context
                            ) use ($acf_field) {
                                $term = get_term($term);
                                $typename = $term->taxonomy;

                                if (
                                    !in_array(
                                        $typename,
                                        \WPGraphQL::get_allowed_taxonomies()
                                    )
                                ) {
                                    if (WP_DEBUG) {
                                        trigger_error(
                                            'WPGraphQLGutenbergAcf: Field ' .
                                                $acf_field['key'] .
                                                ' will not be resolved since taxonomy ' .
                                                $typename .
                                                ' is not shown in GraphQL.',
                                            E_USER_NOTICE
                                        );
                                    }

                                    return null;
                                }

                                $deferred = \WPGraphQL\Data\DataSource::resolve_term_object(
                                    $term->term_id,
                                    $context
                                );

                                $deferred->then(function (&$value) use (
                                    $typename
                                ) {
                                    $value->{'__typename'} = $typename;
                                    return $value;
                                });

                                return $deferred;
                            },
                            $multiple)
                        ];
                    }

                    break;
                case 'user':
                    $multiple = $acf_field['multiple'] ?? false;
                    $type = 'user';

                    $config = [
                        'type' => $multiple ? ['list_of' => $type] : $type,
                        'resolve' => self::generate_resolver(
                            [DataSource::class, 'resolve_user'],
                            $multiple
                        )
                    ];

                    break;
                case 'google_map':
                    $config = [
                        'type' => self::get_google_map_type()
                    ];

                    break;
                case 'date_picker':
                    $config = [
                        'type' => self::get_date_type()
                    ];

                    break;
                case 'time_picker':
                    $config = [
                        'type' => self::get_time_type()
                    ];

                    break;
                case 'date_time_picker':
                    $config = [
                        'type' => self::get_datetime_type()
                    ];

                    break;
                case 'color_picker':
                    $config = [
                        'type' => self::get_color_type()
                    ];

                    break;
                case 'repeater':
                    $type = $this->get_acf_fields_type(
                        $acf_field['sub_fields'],
                        self::format_name($acf_field['name'], $name_base)
                    );

                    if (isset($type)) {
                        $config = [
                            'type' => ['list_of' => ['non_null' => $type]]
                        ];
                    }

                    break;
                case 'group':
                    $type = $this->get_acf_fields_type(
                        $acf_field['sub_fields'],
                        self::format_name($acf_field['name'], $name_base)
                    );

                    if (isset($type)) {
                        $config = [
                            'type' => $type
                        ];
                    }

                    break;
                case 'flexible_content':
                    $types_per_layout = [];

                    foreach ($acf_field['layouts'] as $layout) {
                        $name = $layout['name'];

                        if (!$this->is_field_name_valid($name)) {
                            if (WP_DEBUG) {
                                trigger_error(
                                    'WPGraphQLGutenbergAcf: Layout ' .
                                        $layout['key'] .
                                        ' name "' .
                                        $name .
                                        '" in flexible content field ' .
                                        $acf_field_key .
                                        ' with name "' .
                                        $acf_field['name'] .
                                        '" for type ' .
                                        $name_base .
                                        ' is unsupported.',
                                    E_USER_WARNING
                                );
                            }
                            continue;
                        }

                        $type = $this->get_acf_fields_type(
                            $layout['sub_fields'],
                            self::format_name($name, $name_base)
                        );

                        if (isset($type)) {
                            $types_per_layout[$name] = $type;
                        }
                    }

                    if (count($types_per_layout)) {
                        $type = $this->get_maybe_union_type(
                            array_values($types_per_layout),
                            self::format_name($acf_field['name'], $name_base),
                            function (&$value) use (&$types_per_layout) {
                                return $types_per_layout[
                                    $this->type_registry->get_type($value['acf_fc_layout'])
                                ];
                            }
                        );

                        $config = [
                            'type' => ['list_of' => $type]
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

        protected function get_acf_fields_type(&$acf_fields, $name_base)
        {
            $fields = [];

            foreach ($acf_fields as &$acf_field) {
                $name = $acf_field['name'];

                if (!$this->is_field_name_valid($name)) {
                    if (WP_DEBUG) {
                        trigger_error(
                            'WPGraphQLGutenbergAcf: Field ' .
                                $acf_field['key'] .
                                ' name "' .
                                $name .
                                '" for type ' .
                                $name_base .
                                ' is unsupported.',
                            E_USER_WARNING
                        );
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
                    'fields' => $fields
                ]);

                return $name_base;
            }

            return null;
        }

        protected function get_block_type_acf_fields($block_type)
        {
            $fields = [];

            $field_groups = acf_get_field_groups([
                'block' => $block_type['name']
            ]);

            $acf_fields = array_merge(
                [],
                ...array_map(function (&$field_group) {
                    return acf_get_fields($field_group['ID']);
                }, $field_groups)
            );

            $type_name = self::format_graphql_block_type_acf_name(
                $block_type['name']
            );

            $type = $this->get_acf_fields_type($acf_fields, $type_name);

            if (isset($type)) {
                $fields['acf'] = [
                    'type' => $type,
                    'resolve' => function ($arr) use ($acf_fields, $type_name) {
                        acf_setup_meta(
                            $arr['attributes']['data'],
                            $arr['attributes']['id'],
                            true
                        );

                        $data = array_merge(
                            [],
                            ...array_map(function ($field) use ($type_name) {
                                           /**
                                * graphql_gutenberg_acf_field_value
                                * Filters acf field value.
                                *
                                * @param mixed     $value             		Value.
                                * @param array     $field                	Acf field.
                                * @param string    $type_name               GraphQL type name.
                                */
                                $value = apply_filters(
                                   'graphql_gutenberg_acf_field_value',
                                   get_field($field['key']),
                                   $field,
                                   $type_name
                               );

                                return [
                                    $field['name'] => $value
                                ];
                            }, $acf_fields)
                        );

                        return $data;
                    }
                ];
            }

            return $fields;
        }

        protected function setup_graphql()
        {
            add_filter(
                'graphql_gutenberg_block_type_fields',
                function ($fields, $block_type, $type_registry) {
                    $this->type_registry = $type_registry;

                    if (substr($block_type['name'], 0, 4) === "acf/") {
                        $acf_fields = $this->get_block_type_acf_fields(
                            $block_type
                        );
                        return array_merge($fields, $acf_fields);
                    }

                    return $fields;
                },
                10,
                3
            );
        }

        public function setup()
        {
            $this->setup_graphql();
        }
    }
}

add_action('acf/init', function () {
    WPGraphQLGutenbergACF::instance()->setup();
}, 100);
