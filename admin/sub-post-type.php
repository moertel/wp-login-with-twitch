<?php
/**
 * Register post type and add to menu
 */
defined('ABSPATH') or die("Cannot access pages directly.");
function subscriberOnlyPosts()
{

    $labels = array(
        'name' => 'Subscriber Only Posts',
        'singular_name' => 'Subscriber Only Posts',
        'menu_name' => 'Subscriber Only Posts',
        'name_admin_bar' => 'Subscriber Only Posts',
        'archives' => 'Subscriber Only Posts',
    );
    $args = array(
        'label' => 'subscriber',
        'description' => 'subscriber Only Posts',
        'labels' => $labels,
        'supports' => array('title', 'editor', 'thumbnail', 'comments', 'revisions', 'page-attributes'),
        'taxonomies' => array('category', 'post_tag'),
        'hierarchical' => false,
        'public' => true,
        'show_ui' => true,
        'show_in_menu' => false,
        'menu_position' => 5,
        'show_in_admin_bar' => true,
        'show_in_nav_menus' => true,
        'can_export' => true,
        'has_archive' => true,
        'exclude_from_search' => false,
        'publicly_queryable' => true,
        'capability_type' => 'page',
    );
    register_post_type('subscriber-posts', $args);

}
add_action('admin_menu', function(){
    add_submenu_page( 'login-with-twitch-settings.php', 'Subscriber Only Posts', 'Subscriber Only Posts', 'manage_options', 'edit.php?post_type=subscriber-posts', NULL );
}, 100);
