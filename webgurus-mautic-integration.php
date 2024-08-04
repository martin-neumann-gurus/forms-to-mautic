<?php
/**
 * Plugin Name: Webgurus Forms to Mautic Integration
 * Plugin URI:  https://www.webgurus.net/wordpress/plugins/forms-to-mautic
 * Description: Send all your WordPress sign ups directly to Mautic
 * Author: Martin Neumann - Webgurus
 * Author URI:  https://www.webgurus.net
 * Version: 1.1.0
 * Text Domain: webgurus-mautic
 */

/**
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 *
 * Copyright 2024 Martin Neumann. Adapted from FluentForms for Mautic plugin. 
 */


defined('ABSPATH') or die;
require_once __DIR__ . '/vendor/autoload.php';
include_once __DIR__ . '/crud.php';
include_once __DIR__ . '/core.php';


register_activation_hook(__FILE__, function () {
    global $wpdb;

    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
    dbDelta( \Webgurus\Mautic\Record::create_table_sql() );
});

register_deactivation_hook( __FILE__, function() {
    $timestamp = wp_next_scheduled( 'wg_mautic_five_minute_error_handler' );
    wp_unschedule_event( $timestamp, 'wg_mautic_five_minute_error_handler' );

    $timestamp = wp_next_scheduled( 'wg_mautic_hourly_error_handler' );
    wp_unschedule_event( $timestamp, 'wg_mautic_hourly_error_handler' );
});

add_action('plugins_loaded', function () {
    \Carbon_Fields\Carbon_Fields::boot();

    if (function_exists('wpFluentForm')) {
        include_once __DIR__.'/Integrations/FluentForms.php';
        new \Webgurus\Mautic\Bootstrap(wpFluentForm());
    }

    if ( class_exists( 'WooCommerce' ) ) {
        include_once __DIR__.'/Integrations/WooCommerce.php';
    }

}, 1);

add_action('carbon_fields_register_fields', function () {
    load_plugin_textdomain('webgurus-mautic', false, basename(__DIR__) . '/languages');

    if ( is_admin()) {
        include_once __DIR__ . '/admin.php';
        \Webgurus\Admin\MauticAdmin::Boot();
    }
}, 100);
