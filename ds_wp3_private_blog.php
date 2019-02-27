<?php
/**
 * Plugin Name: More Privacy Options
 * Plugin URI: http://wordpress.org/extend/plugins/more-privacy-options/
 * Version: 4.6
 * Description: Add more privacy(visibility) options to a WordPress Multisite Network. Settings->Reading->Visibility:Network Users, Blog Members, or Admins Only. Network Settings->Network Visibility Selector: All Blogs Visible to Network Users Only or Visibility managed per blog as default.
 * Author: D. Sader
 * Author URI: http://dsader.snowotherway.org/
 * Network: true
 */

 /**
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

/**
 * @todo: think about WP-API! <- content gets accessible!
 * @todo: sanitize input variables.
 */
class DS_More_Privacy_Options {

	/**
	 * Access via get_privacy_description function.
	 *
	 * @var array
	 */
	public $blog_privacy_description = array();

	/**
	 * The capability that defines admins.
	 *
	 * @var string
	 */
	public $admin_defining_capability = 'add_users';

	/**
	 * -1 : Only registered members of the Network -> there is no public blog.
	 *      Overwrite any priv_id of 1 and 0 with a -1.
	 *      Does not overwrite -2 and -3
	 *
	 * +1 : Visibility is managed per Site.
	 *
	 * @var int
	 */
	public $sitewide_privacy;

	/**
	 * The privacy id of the current blog.
	 *  1 - world
	 *  0 - World, no search
	 * -1 - Network users
	 * -2 - Blog users
	 * -3 - Blog Admins
	 *
	 * @var int
	 */
	public $privacy_id;

	/**
	 * The Constructor
	 */
	public function __construct() {
		if ( ! is_multisite() ) {
			add_action( 'all_admin_notices', array( $this, 'display_not_multisite_notice' ) );
			return false;
		}
		$this->init_vars();
		$this->add_hooks();
	}

	/**
	 * Set some variables for the Class.
	 *
	 * @return void
	 */
	public function init_vars() {

		$this->sitewide_privacy = intval( get_site_option( 'ds_sitewide_privacy' ) );

		$this->blog_privacy_description = array(
			1   => array(
				'long'  => esc_html( __( 'Visible to the World. Allow search engines to index this site.', 'more-privacy-options' ) ),
				'short' => esc_html( __( 'World', 'more-privacy-options' ) ),
				'icon'  => 'dashicons-admin-site',
			),
			0   => array(
				'long'  => esc_html( __( 'Visible to the World. Discourage search engines from indexing this site.', 'more-privacy-options' ) ),
				'short' => esc_html( __( 'World, no search', 'more-privacy-options' ) ),
				'icon'  => 'dashicons-admin-site',
			),
			- 1 => array(
				'long'  => esc_html( __( 'Visible only to registered users of this network.', 'more-privacy-options' ) ),
				'short' => esc_html( __( 'Network users', 'more-privacy-options' ) ),
				'icon'  => 'dashicons-networking',
			),
			- 2 => array(
				'long'  => esc_html( __( 'Visible only to registered users of this blog.', 'more-privacy-options' ) ),
				'short' => esc_html( __( 'Blog users', 'more-privacy-options' ) ),
				'icon'  => 'dashicons-groups',
			),
			- 3 => array(
				'long'  => esc_html( __( 'Visible only to administrators of this blog.', 'more-privacy-options' ) ),
				'short' => esc_html( __( 'Blog Admins', 'more-privacy-options' ) ),
				'icon'  => 'dashicons-businessman',
			),
		);
	}

	/**
	 * Adds the necessary WordPress Hooks for the plugin.
	 *
	 * @todo split in settings - handling?
	 *
	 * @return void
	 */
	public function add_hooks() {

		global $current_blog;

		add_action( 'init', array( $this, 'ds_localization_init' ) );
		// Network->Settings.
		add_action( 'update_wpmu_options', array( $this, 'sitewide_privacy_update' ) );
		add_action( 'wpmu_options', array( $this, 'sitewide_privacy_options_page' ) );

		// hooks into Misc Blog Actions in Network->Sites->Edit.
		add_action( 'wpmueditblogaction', array( $this, 'wpmu_blogs_add_privacy_options' ), -999 );

		// hooks into Blog Columns views Network->Sites.
		add_filter( 'manage_sites-network_columns', array( $this, 'add_sites_column' ), 10, 1 );
		add_action( 'manage_sites_custom_column', array( $this, 'manage_sites_custom_column' ), 10, 3 );

		// hook into options-reading.php Dashboard->Settings->Reading.
		add_action( 'blog_privacy_selector', array( $this, 'add_privacy_options' ) );

		add_action( 'template_redirect', array( $this, 'ds_authenticator' ) );
		add_action( 'login_form', array( $this, 'login_message' ) );
		add_filter( 'privacy_on_link_title', array( $this, 'header_title_link' ) );
		add_filter( 'privacy_on_link_text', array( $this, 'header_title_link' ) );

		// fixes robots.txt rules.
		add_action( 'do_robots', array( $this, 'do_robots' ), 1 );

		// fixes noindex meta as well.
		add_action( 'wp_head', array( $this, 'noindex' ), 0 );
		add_action( 'login_head', array( $this, 'noindex' ), 1 );

		// no pings unless public either.
		add_filter( 'option_ping_sites', array( $this, 'privacy_ping_filter' ), 1 );

		// email super-admin when privacy changes.
		add_action( 'update_blog_public', array( $this, 'ds_mail_super_admin' ) );

		// hook into signup form?
		add_action( 'signup_blogform', array( $this, 'add_privacy_options' ) );

		// add_action( 'login_init', array( $this, 'custom_login') );
		add_action( 'login_form_privacy', array( $this, 'custom_login_form' ) );
	}

	/**
	 * Triggered by the action "login_form".
	 * Shows text about the privacy on the login form.
	 *
	 * @return void
	 */
	public function login_message() {
		$desc = $this->get_privacy_description(
			$this->get_privacy_id()
		);
		echo "<p>$desc</p><br/>"; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/**
	 * Triggered by the action "template_redirect".
	 * The main "enty-point" for checking weather a user can access a blog.
	 * Triggers a redirect if the user is not allowed to access.
	 *
	 * @return void
	 */
	public function ds_authenticator() {
		global $current_blog;
		if ( ! $this->can_user_access_current_blog() ) {
			$this->no_access_redirect();
		}
	}

	/**
	 * Triggered by the action "privacy_on_link_title" and "privacy_on_link_text".
	 *
	 * @return string
	 */
	public function header_title_link() {
		return $this->get_privacy_description(
			$this->get_privacy_id()
		);
	}

	/**
	 * The the privacy id of the current blog.
	 * It is the 'blog_public' value stored in wp_blogs.
	 *
	 * @return int
	 */
	public function get_privacy_id() {
		global $current_blog;

		if ( is_null( $this->privacy_id ) ) {

			if ( isset( $current_blog->public ) ) {
				$priv_id = $current_blog->public;
			} else {
				$priv_id = get_blog_option( get_current_blog_id(), 'blog_public' );
			}
			/**
			 * If sitewide privacy is only for registered Members (-1) we overwrite any public privacy ( 1 and 0 ) with a -1.
			 * sitewide privacy is 1 by default.
			 */
			if ( -1 === intval( get_site_option( 'ds_sitewide_privacy', 1 ) ) ) {
				$this->privacy_id = max( intval( $priv_id ), -1 );
			} else {
				$this->privacy_id = intval( $priv_id );
			}
		}
		return $this->privacy_id;
	}

	/**
	 * Check if a user (specified by id, current user by default) can access a blog.
	 *
	 * @param int $user_id current user by default.
	 * @return boolean
	 */
	public function can_user_access_current_blog( int $user_id = null ) {

		$priv_id = $this->get_privacy_id();

		/**
		 * Blog is public and privacy is managed per blog.
		 */
		if ( $priv_id > -1 ) {
			return true;
		}

		/**
		 * Blog is not public and user is not logged in.
		 */
		if ( ! is_user_logged_in() ) {
			return false;
		}

		/**
		 * Blog or network are visible for all network users.
		 */
		if ( -1 === $priv_id ) {
			return true;
		}

		$user_id = ( $user_id ) ? $user_id : get_current_user_id();
		$blog_id = get_current_blog_id();

		if ( is_super_admin( $user_id ) ) {
			error_log( 'super!' );
			return true;
		}

		/**
		 * Member only blog.
		 */
		if ( -2 == $priv_id ) {
			return is_user_member_of_blog( $user_id, $blog_id );
		}

		/**
		 * Admin only blog.
		 */
		if ( -3 == $priv_id ) {
			return user_can( $user_id, $this->admin_defining_capability );
		}

		return false;
	}

	public function no_access_redirect() {

		if ( $this->is_activate_request() ) {
			return;
		}

		if ( ! is_user_logged_in() ) {
			if ( is_feed() ) {
				$this->ds_feed_login();
			} else {
				auth_redirect();
			}
		}

		/**
		 * If we are here privacy can only be < -1 .
		 */
		wp_safe_redirect( add_query_arg( 'action', 'privacy', wp_login_url() ) );
		exit;
	}

	/**
	 * Triggered by the action "login_form_privacy" in wp-login.php.
	 * Check login_form.*.
	 * Only accessible by logged in users.
	 *
	 * @return void
	 */
	public function custom_login_form() {
		global $current_site;

		$priv_id             = $this->get_privacy_id();
		$privacy_description = $this->get_privacy_description( $priv_id );

		$error = new WP_Error();

		$contact_users  = '';
		$blogname       = get_bloginfo( 'name' );
		$sitename       = $current_site->site_name;
		$site_member_at = esc_html( __( 'Site membership at', 'more-privacy-options' ) );

		$users = get_users();
		foreach ( $users as $user ) {
			if ( user_can( $user->ID, 'add_users' ) ) {
				$contact_users .= "<a href='mailto:$user->user_email?subject=$site_member_at [$blogname] - $sitename'>$user->display_name</a>, ";
			}
		}
		if ( '' == $contact_users ) {
			$admin_mail    = get_option( 'admin_email' );
			$contact_users = "$info <a href='mailto:$admin_mail?subject=$site_member_at [$blogname] - $sitename'>$admin_mail</a>";
		} else {
			$contact_users = rtrim( $contact_users, ', ' );
		}

		$info    = esc_html( __( 'To become a member of this site, contact', 'more-privacy-options' ) );
		$message = "$privacy_description <br>$info<br> $contact_users.";

		$message     = apply_filters( 'more_privacy_closed_message', $message, $priv_id, $privacy_description );
		$back        = __( 'Go back' );
		$network_url = untrailingslashit( network_site_url() );
		$network_url = "<a href='$network_url'>" . str_replace( array( 'http://', 'https://' ), '', $network_url ) . '</a>';

		/**
		 * The wp_shake_js triggers the first form (thats why we use a form container here).
		 */
		$container = "
			<form id='loginform' class='message'>$message</form>
			<p id='backtoblog'>
				<a href='javascript:history.go(-1)'>← $back</a> | $network_url
			</p>
		";

		add_action( 'login_head', 'wp_shake_js', 12 );
		login_header( '', $container, $error );

		die();
	}

	/**
	 * Blog privacy is stored as an id. Get a description for the corresponding ID.
	 * Get id for blog via. get_blog_option( $blog_id, 'blog_public').
	 *
	 * @param integer $id The id of the Privacy status (of a blog).
	 * @param string  $type long|short|icon.
	 * @return string|WP_Error
	 */
	public function get_privacy_description( int $id, $type = 'long' ) {
		if ( isset( $this->blog_privacy_description[ $id ] ) ) {
			return apply_filters(
				'privacy_description',
				$this->blog_privacy_description[ $id ][ $type ],
				$id,
				$type
			);
		}
		return new WP_Error( 'broke', __( "We don't have a description for the given privacy id.", 'more-privacy-options' ) );
	}

	/**
	 * Triggered by the hook all_admin_notices.
	 *
	 * @return void
	 */
	public function display_not_multisite_notice() {
		$msg = esc_html( __( 'More Privacy Options is a plugin just for multisites, please deactivate it.', 'more-privacy-options' ) );
		echo "
			<div class='error'>
				<p>$msg</p>
			</div>
		"; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/**
	 * Triggered by the "init" action.
	 *
	 * @return void
	 */
	public function ds_localization_init() {
		load_plugin_textdomain( 'more-privacy-options', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
	}

	/**
	 * Triggered by the hook "update_blog_public".
	 *
	 * @return void
	 */
	public function ds_mail_super_admin() {

		$blog_id    = get_current_blog_id();
		$privacy_id = $this->get_privacy_id();

		$to_new   = $this->get_privacy_description( $privacy_id );
		$blogname = get_blog_option( $blog_id, 'blogname' );
		$email    = stripslashes( get_site_option( 'admin_email' ) );
		$url      = get_site_url( $blog_id );
		$subject  = __( 'Site changed reading visibility settings.', 'more-privacy-options' )
			. " $blogname [ID: $blog_id, $url] => $to_new";
		$message  = $subject;
		$message .= __( " \r\n\r\nSent by More Privacy Options plugin.", 'more-privacy-options' );

		$headers = 'Auto-Submitted: auto-generated';
		wp_mail( $email, $subject, $message, $headers );
	}

	public function do_robots() {
		// https://wordpress.org/support/topic/robotstxt-too-restrictive-for-allow-search-engines/
		remove_action( 'do_robots', 'do_robots' );
		header( 'Content-Type: text/plain; charset=utf-8' );
		do_action( 'do_robotstxt' );

		$output     = "User-agent: *\n";
		$privacy_id = $this->get_privacy_id();
		if ( '1' != $privacy_id ) {
			$output .= "Disallow: /\n";
		} else {
			$site_url = parse_url( site_url() );
			$path     = ( ! empty( $site_url['path'] ) ) ? $site_url['path'] : '';
			$output  .= "Disallow: $path/wp-admin/\n";
		}

		echo apply_filters( 'robots_txt', $output, $privacy_id );
	}

	public function noindex() {
		remove_action( 'login_head', 'noindex' );
		remove_action( 'wp_head', 'noindex', 1 ); // priority 1.

		// If the blog is not public, tell robots to go away.
		if ( 1 !== $this->get_privacy_id() ) {
			// wp_no_robots();
			echo "<meta name='robots' content='noindex,nofollow' />\n";
		}
	}

	public function privacy_ping_filter( $sites ) {
		remove_filter( 'option_ping_sites', 'privacy_ping_filter' );
		if ( 1 === $this->get_privacy_id() ) {
			return $sites;
		} else {
			return '';
		}
	}

	/**
	 * Triggered by the action "wpmueditblogaction".
	 * Hookes into site_settings.php.
	 *
	 * @return void
	 */
	public function wpmu_blogs_add_privacy_options() {

		global $details, $options;
		$title        = esc_html( __( 'More Privacy Options', 'more-privacy-options' ) );
		$input_fields = '';
		foreach ( $this->blog_privacy_description as $opt_id => $opt_desc ) {
			$input_fields .= $this->get_input_field( $opt_id, $details->public, 'option[blog_public]' ) . "($opt_id)<br>";
		}
		echo "<tr><th>$title</th><td>$input_fields</td></tr>"; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/**
	 * Create an input field for a blog privacy id.
	 *
	 * @param integer $privacy_id The blog privacy id.
	 * @param integer $checked_id if equals $privacy_id the input field is checked.
	 * @param string  $name value for the name attribute.
	 * @return string The markup for te input field.
	 */
	private function get_input_field( int $privacy_id, int $checked_id, string $name ) {
		$checked     = ( $privacy_id == $checked_id ) ? 'checked' : '';
		$description = $this->get_privacy_description( $privacy_id );
		return "<input id='blog-private$privacy_id' type='radio' name='$name' value='$privacy_id' $checked> $description ";
	}

	/**
	 * Triggered by the filter "manage_sites-network_columns".
	 *
	 * @param [type] $column_details
	 * @return void
	 */
	public function add_sites_column( $column_details ) {
		$column_details['blog_visibility'] = _x( '<nobr>Visibility</nobr>', 'column name' );
		return $column_details;
	}

	public function manage_sites_custom_column( $column_name, $blog_id ) {
		if ( 'blog_visibility' != $column_name ) {
			return;
		}
		$details = get_blog_details( $blog_id );
		$long    = $this->get_privacy_description( $details->public );
		$icon    = $this->get_privacy_description( $details->public, 'icon' );
		$short   = $this->get_privacy_description( $details->public, 'short' );
		echo "<p class='$icon dashicons-before' title='$long'> $short</p>"; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	public function wpmu_blogs_add_privacy_options_messages() {
		global $blog;
		echo $this->get_privacy_description( $blog['public'] ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<br class="clear" />';
	}

	/**
	 * Triggered by the action "blog_privacy_selector" in options-reading.php.
	 * Adds more privacy settings to the Settings -> Options Reading Page.
	 *
	 * @param [type] $options
	 * @return void
	 */
	public function add_privacy_options( $options ) {
		global $blogname,$current_site;
		$blog_name = get_bloginfo( 'name', 'display' );
		foreach ( $this->blog_privacy_description as $opt_id => $opt_desc ) {
			if ( $opt_id < 0 ) { // The ids 1 and 0 are WP defaults and already have an interface.
				$input = $this->get_input_field( $opt_id, $this->get_privacy_id(), 'blog_public' ) . '<br>';
				echo "<br><label for='blog-private$opt_id' class='checkbox' >$input</label>"; // phpcs:ignore WordPress.Security.EscapeOutput
			}
		}
	}

	/**
	 * Tries to authenticate a (not logged in) user via $_SERVER['PHP_AUTH_USER'].
	 * Dies if user is not allowed.
	 *
	 * @return void
	 */
	public function ds_feed_login() {
		$user    = wp_signon(
			array(
				'user_login'    => isset( $_SERVER['PHP_AUTH_USER'] ) ? $_SERVER['PHP_AUTH_USER'] : '',
				'user_password' => isset( $_SERVER['PHP_AUTH_PW'] ) ? $_SERVER['PHP_AUTH_PW'] : '',
				'remember'      => true,
			),
			false
		);
		$user_id = is_wp_error( $user ) ? get_user_by( 'user_login', $user->user_login ) : null;
		if ( is_wp_error( $user ) || ! $this->can_user_access_current_blog() ) {
			header( 'WWW-Authenticate: Basic realm="' . $_SERVER['SERVER_NAME'] . '"' );
			header( 'HTTP/1.0 401 Unauthorized' );
			die();
		}
	}

	/**
	 * Check if the current request is ment for user actication. -> return true.
	 * Might redirect and exit.
	 *
	 * @return boolean
	 */
	public function is_activate_request() {
		if ( strpos( $_SERVER['PHP_SELF'], 'wp-activate.php' ) && is_main_site() ) {
			return true;
		}
		if ( strpos( $_SERVER['PHP_SELF'], 'wp-activate.php' ) && ! is_main_site() ) {
			$destination = network_home_url( 'wp-activate.php' );
			wp_safe_redirect( $destination );
			exit();
		}
		return false;
	}


	// -----------------------------------------------------------------------//
	// ---Functions for SiteAdmins Options--------------------------------------//
	// ---WARNING: member users, if they exist, still see the backend---------//


	/**
	 * Triggered by the action "wpmu_options".
	 *
	 * @return void
	 */
	public function sitewide_privacy_options_page() {

		$setting                    = intval( get_site_option( 'ds_sitewide_privacy', 1 ) );
		$title                      = __( 'Network Visibility Selector', 'more-privacy-options' );
		$network_vis                = __( 'Network Visibility', 'more-privacy-options' );
		$visible_network_users      = __( 'Visible only to registered users of this network', 'more-privacy-options' );
		$checked_network_users      = ( -1 == $setting ) ? 'checked' : '';
		$manage_visibility_per_site = __( 'Default: visibility managed per site.', 'more-privacy-options' );
		$checked_per_site           = ( 1 == $setting ) ? 'checked=' : '';
		echo "
			<h3>$title</h3>
			<table class='form-table'>
				<tr valign='top'>
					<th scope='row'>$network_vis</th>
					<td>
						<label>
							<input type='radio' name='ds_sitewide_privacy' id='ds_sitewide_privacy' value='-1' $checked_network_users/>
							$visible_network_users
						</label><br />
						<label>
							<input type='radio' name='ds_sitewide_privacy' id='ds_sitewide_privacy_1' value='1' $checked_per_site/>
							$manage_visibility_per_site
						</label><br />
					</td>
				</tr>
			</table>
		";
	}

	/**
	 * Triggered by the action "update_wpmu_options".
	 *
	 * @return void
	 */
	public function sitewide_privacy_update() {
		update_site_option( 'ds_sitewide_privacy', $_POST['ds_sitewide_privacy'] );
	}
}
new DS_More_Privacy_Options();
