<?php
/**
 * More Privacy Options
 *
 * Add more privacy(visibility) options to a WordPress Multisite Network.
 *
 * @package WordPress
 * @subpackage More-privacy-options
 *
 * Plugin Name: More Privacy Options
 * Plugin URI: http://wordpress.org/extend/plugins/more-privacy-options/
 * Version: 4.6
 * Description: Add more privacy (or visibility) options to a WordPress Multisite Network. Settings->Reading->Visibility: Network Users, Blog Members, or Admins Only. Network Settings->Network Visibility Selector: All Blogs Visible to Network Users Only or Visibility managed per blog as default.
 * Author: D. Sader
 * Author URI: http://dsader.snowotherway.org/
 * Text Domain: more-privacy-options
 * Domain Path: /languages
 * Network: true
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

load_plugin_textdomain( 'more-privacy-options', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );

// Load plugin class files.
require_once( 'includes/class-ds-more-privacy-options.php' );
require_once( 'includes/class-ds-more-privacy-hooks.php' );

/**
 * Returns the main instance of Ds_More_Privacy_Options to prevent the need to use globals.
 * There should be only a single instance of the class.
 *
 * @since  1.0.0
 * @return Ds_More_Privacy_Options
 */
function more_privacy_options() {
	$instance = Ds_More_Privacy_Options::instance( __FILE__, '1.0.0' );
	return $instance;
}

more_privacy_options();
