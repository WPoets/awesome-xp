<?php
namespace aw2\elementor_widgets;

\add_action( 'init', 'aw2\elementor_widgets\aw_register_elements_post_type', 0 );
\add_action('init','aw2\elementor_widgets\setup_elementor_widgets',1);
\add_action( 'elementor/widgets/register', 'aw2\elementor_widgets\register_generic_widgets' );

function _register_generic_widgets( $widgets_manager ) {
    
    require_once( __DIR__ . '/class-generic-widget.php' );
    // Path to the directory containing JSON widget definitions
    $json_files_path = __DIR__ . '/widget-definitions/';
    
    // Find all .json files in the directory
    $json_files = glob( $json_files_path . '*.json' );

    foreach ( $json_files as $file ) {
        $widget_config = json_decode( file_get_contents( $file ), true );

        if ( !empty($widget_config) && !empty($widget_config['name']) ) {
            // 1. Add the config to our static cache in the generic class
            \Elementor_Generic_Widget::add_config($widget_config);

            // 2. Register a new instance, passing the unique name in the $args array
            $widgets_manager->register( new \Elementor_Generic_Widget([], [
                'widget_name' => $widget_config['name']
            ]));
        }
    }
}

function register_generic_widgets( $widgets_manager ) {
    require_once( __DIR__ . '/class-generic-widget.php' );
    $elementor_widgets=&\aw2_library::get_array_ref('elementor_widgets');

    foreach ( $elementor_widgets as $widget ) {
       
        if ( !empty($widget) && !empty($widget['name']) ) {
            // 1. Add the config to our static cache in the generic class
            \Elementor_Generic_Widget::add_config($widget);

            // 2. Register a new instance, passing the unique name in the $args array
            $widgets_manager->register( new \Elementor_Generic_Widget([], [
                'widget_name' => $widget['name']
            ]));
        }
    }
}

/**
 * Registers the Custom Post Type for storing Elementor widget JSON.
 */
function aw_register_elements_post_type() {

    $labels = [
        'name'                  => _x( 'Awesome Elementor Widgets', 'Post Type General Name', 'text_domain' ),
        'singular_name'         => _x( 'Awesome Elementor Widget', 'Post Type Singular Name', 'text_domain' ),
        'menu_name'             => __( 'Awesome Elementor Widgets', 'text_domain' ),
        'name_admin_bar'        => __( 'Awesome Elementor Widget', 'text_domain' ),
        'archives'              => __( 'Awesome Elementor Widget Archives', 'text_domain' ),
        'attributes'            => __( 'Awesome Elementor Widget Attributes', 'text_domain' ),
        'parent_item_colon'     => __( 'Parent Awesome Elementor Widget:', 'text_domain' ),
        'all_items'             => __( 'All Awesome Elementor Widget', 'text_domain' ),
        'add_new_item'          => __( 'Add New Awesome Elementor Widget', 'text_domain' ),
        'add_new'               => __( 'Add New', 'text_domain' ),
        'new_item'              => __( 'New Awesome Elementor Widget', 'text_domain' ),
        'edit_item'             => __( 'Edit Awesome Elementor Widget', 'text_domain' ),
        'update_item'           => __( 'Update Awesome Elementor Widget', 'text_domain' ),
        'view_item'             => __( 'View Awesome Elementor Widget', 'text_domain' ),
        'view_items'            => __( 'View Awesome Elementor Widgets', 'text_domain' ),
        'search_items'          => __( 'Search Awesome Elementor Widget', 'text_domain' ),
        'not_found'             => __( 'Not found', 'text_domain' ),
        'not_found_in_trash'    => __( 'Not found in Trash', 'text_domain' ),
        'featured_image'        => __( 'Featured Image', 'text_domain' ),
        'set_featured_image'    => __( 'Set featured image', 'text_domain' ),
        'remove_featured_image' => __( 'Remove featured image', 'text_domain' ),
        'use_featured_image'    => __( 'Use as featured image', 'text_domain' ),
        'insert_into_item'      => __( 'Insert into element', 'text_domain' ),
        'uploaded_to_this_item' => __( 'Uploaded to this element', 'text_domain' ),
        'items_list'            => __( 'Awesome Elementor Widgets list', 'text_domain' ),
        'items_list_navigation' => __( 'Awesome Elementor Widgets list navigation', 'text_domain' ),
        'filter_items_list'     => __( 'Filter Awesome Elementor Widgets list', 'text_domain' ),
    ];

    $capabilities = array(
        "edit_post"=>"develop_for_awesomeui",
        "read_post"=>"develop_for_awesomeui",
        "delete_post"=>"develop_for_awesomeui",
        "edit_posts"=>"develop_for_awesomeui",
        "edit_others_posts"=>"develop_for_awesomeui",
        "publish_posts"=>"develop_for_awesomeui",
        "read_private_posts"=>"develop_for_awesomeui",
        "delete_posts"=>"develop_for_awesomeui"
        
    );

    $args = [
        'label'               => __( 'Awesome Elementor Widgets', 'text_domain' ),
        'description'         => __( 'Post type for storing Elementor widget configurations.', 'text_domain' ),
        'labels'              => $labels,
        'supports'            => [ 'title', 'editor', 'revisions'],
        'hierarchical'        => false,
        'public'              => false,  // This is key: not publicly accessible
        'show_ui'             => true,   // But we want to see it in the admin dashboard
        'show_in_menu'        => true,
        'menu_position'       => 20,     // Position below "Pages"
        'menu_icon'           => 'dashicons-layout',
        'show_in_admin_bar'   => true,
        'show_in_nav_menus'   => false,
        'can_export'          => true,
        'has_archive'         => false,
        'exclude_from_search' => true,
        'publicly_queryable'  => false,
        'capability_type'     => 'post',
        'capabilities'        => $capabilities,
        'rewrite'             => false,
        'delete_with_user'    => false,
        'show_in_rest'        => false, // Important for Gutenberg or REST API access if needed later
    ];

    register_post_type( 'aw_elements', $args );
  
    \aw2_library::add_service('aw_elements','aw_elements service refers to aw_elements posts for rendering etc.',['post_type'=>'aw_elements']);

}





function setup_elementor_widgets(){
	//loop through the module and run the main module.
    $widget_posts= \aw2_library::get_collection(["post_type"=>'aw_elements']);
   
	foreach($widget_posts as $widget_post){
        // run the post main service?
        \aw2_library::service_run('aw_elements.'.$widget_post['module'].'.register',null,null,'service');
    }
   
}

				