<?php


class Ds_More_Privacy_Hooks {

	/**
	 * The main class of this plugin.
	 *
	 * @var Ds_More_Privacy_Options
	 */
	private $mpo;

	/**
	 * Internationalized String: "Added by MPO-Plugin".
	 *
	 * @var string
	 */
	private $by;

	/**
	 * Names of the options this plugin uses.
	 *
	 * @var array
	 */
	private $options = array(
		'default_privacy' => 'ds_sitewide_privacy',
		'notify_admin'    => 'ds_notify_superadmin_privacy_change',
	);

	public function __construct( $mpo ) {
		$this->mpo = $mpo;
		$this->by  = esc_html( __( "Added by the plugin 'More Privacy Options'.", 'more-privacy-options' ) );
		$this->add_hooks();
	}

	/**
	 * Adds the necessary WordPress Hooks for the plugin.
	 *
	 * @return void
	 */
	private function add_hooks() {

		add_action( 'init', array( $this, 'localization_init' ) );
		add_action( 'init', array( $this, 'maybe_disable_rest' ) );

		// Network->Settings.
		add_action( 'update_wpmu_options', array( $this, 'sitewide_privacy_update' ) );
		add_action( 'wpmu_options', array( $this, 'sitewide_privacy_options_page' ) );
		add_action( 'network_admin_notices', array( $this, 'sitewide_privacy_option_errors' ) );

		// hooks into Misc Blog Actions in Network->Sites->Edit.
		add_action( 'wpmueditblogaction', array( $this, 'wpmu_blogs_add_privacy_options' ), -999 );

		// hooks into Blog Columns views Network->Sites.
		add_filter( 'manage_sites-network_columns', array( $this, 'add_sites_column' ), 10, 1 );
		add_action( 'manage_sites_custom_column', array( $this, 'manage_sites_custom_column' ), 10, 3 );

		add_action( 'send_headers', array( $this, 'maybe_redirect' ) );

		add_action( 'login_form', array( $this, 'login_message' ) );
		add_filter( 'privacy_on_link_title', array( $this, 'header_title_link' ) );
		add_filter( 'privacy_on_link_text', array( $this, 'header_title_link' ) );
		add_filter( 'robots_txt', array( $this, 'filter_robots' ) );

		// fixes noindex meta.
		add_action( 'wp_head', array( $this, 'noindex' ), 0 );
		add_action( 'login_head', array( $this, 'noindex' ), 1 );

		// no pings unless public either.
		add_filter( 'option_ping_sites', array( $this, 'privacy_ping_filter' ), 1 );

		// email super-admin when privacy changes.
		add_action( 'update_blog_public', array( $this, 'mail_super_admin' ), 10, 2 );

		// hook into signup form?
		add_action( 'signup_blogform', array( $this, 'add_privacy_options' ) );
		// hook into options-reading.php Dashboard->Settings->Reading.
		add_action( 'blog_privacy_selector', array( $this, 'add_privacy_options' ) );

		add_action( 'login_form_privacy', array( $this, 'custom_login_form' ) );

	}

	/**
	 * Triggered by the action "init".
	 * Disbale REST-API if a user is not allowed to access a blog.
	 */
	public function maybe_disable_rest() {

		global $wp_version;

		if ( $this->mpo->can_user_access_current_blog() ) {
			return;
		}

		add_filter(
			'rest_authentication_errors',
			function( $result ) {
				$msg = $this->mpo->get_privacy_description( $this->mpo->get_current_privacy_id() );
				return new WP_Error( 'rest_cannot_access', $msg . $this->by, array( 'status' => 401 ) );
			}
		);

		if ( version_compare( $wp_version, '4.7', '<' ) ) { // Legacy support, WP v <= 4.7.
			add_filter( 'json_enabled', '__return_false' ); // Filters for WP-API version 1.x .
			add_filter( 'json_jsonp_enabled', '__return_false' ); // Filters for WP-API version 1.x .

			add_filter( 'rest_enabled', '__return_false' ); // Filters for WP-API version 2.x.
			add_filter( 'rest_jsonp_enabled', '__return_false' ); // Filters for WP-API version 2.x.
		}
	}

	/**
	 * Triggered by the action "login_form".
	 * Shows text about the privacy on the login form.
	 *
	 * @return void
	 */
	public function login_message() {
		$desc = $this->mpo->get_privacy_description(
			$this->mpo->get_current_privacy_id()
		);
		echo "<p>$desc</p><br/>"; // phpcs:ignore WordPress.Security.EscapeOutput
	}
	/**
	 * Triggered by the action "privacy_on_link_title" and "privacy_on_link_text".
	 *
	 * @return string
	 */
	public function header_title_link() {
		return $this->mpo->get_privacy_description(
			$this->mpo->get_current_privacy_id()
		);
	}

	/**
	 * Triggered by the action "send_headers".
	 * "This action hook executes just before WordPress determines which template page to load.
	 * It is a good hook to use if you need to do a redirect with full knowledge of the content that has been queried."
	 *
	 * The main "entry-point" for checking wether a user can access a blog.
	 * Triggers a redirect if the user is not allowed to access.
	 *
	 * @param WP $wp instance of WP object passed by `send_headers` action hook.
	 * @return void
	 */
	public function maybe_redirect( $wp ) {

		if ( $this->mpo->can_user_access_current_blog() ) {
			return;
		}

		if ( $this->is_activate_request() ) {
			if ( ! is_main_site() ) {
				$query_string     = '?' . http_build_query( $_GET );
				$network_home_url = network_home_url( 'wp-activate.php' ) . $query_string;
				$redirect_url     = apply_filters( 'more_privacy_redirect_activate_request', $network_home_url );
				wp_safe_redirect( $redirect_url );
				exit();
			}
			return;
		}

		if ( ! is_user_logged_in() ) {
			if ( array_key_exists( 'feed', $wp->query_vars ) ) {
				/**
				 * Filter to allow or deny unprotected feeds, defaults to false aka protected.
				 */
				if ( apply_filters( 'more_privacy_allow_feeds', false, $wp->query_vars['feed'] ) ) {
					return;
				} else {
					$this->ds_feed_login();
				}
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
	 * Triggered by the action "login_form_privacy" in wp-login.php (find with "login_form.*")
	 *
	 * Only accessible by logged in users.
	 * Shows when a user can not access a blog (so it's not really a login form but uses the same UI).
	 *
	 * @return void
	 */
	public function custom_login_form() {
		global $current_site;

		$priv_id             = $this->mpo->get_current_privacy_id();
		$privacy_description = $this->mpo->get_privacy_description( $priv_id );

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

		$info = esc_html( __( 'To become a member of this site, contact', 'more-privacy-options' ) );
		if ( '' === $contact_users ) {
			$admin_mail    = get_option( 'admin_email' );
			$contact_users = "$info <a href='mailto:$admin_mail?subject=$site_member_at [$blogname] - $sitename'>$admin_mail</a>";
		} else {
			$contact_users = rtrim( $contact_users, ', ' );
		}

		$message = "$privacy_description <br>$info $contact_users.";

		$message     = apply_filters( 'more_privacy_closed_message', $message, $priv_id, $privacy_description );
		$back        = __( 'Go back' );
		$network_url = untrailingslashit( network_site_url() );
		$network_url = "<a href='$network_url'>" . str_replace( array( 'http://', 'https://' ), '', $network_url ) . '</a>';

		/**
		 * The wp_shake_js triggers the first form (thats why we use a form container here).
		 */
		$container = apply_filters(
			'more_privacy_custom_login_form',
			array(
				"<form id='loginform' class='message'>$message</form>",
				"<p id='backtoblog'><a href='javascript:history.go(-1)'>← $back</a> | $network_url</p>",
			)
		);
		login_header( '', implode( '', $container ), $error );
		wp_shake_js();
		die();
	}


	/**
	 * Triggered by the hook all_admin_notices.
	 *
	 * @return void
	 */
	public function display_not_multisite_notice() {
		$msg = esc_html__( 'More Privacy Options is a plugin just for multisites, please deactivate it.', 'more-privacy-options' );
		echo "
			<div class='error'>
				<p>$msg</p>
			</div>
		";
	}

	/**
	 * Triggered by the "init" action.
	 *
	 * @return void
	 */
	public function localization_init() {
		load_plugin_textdomain( 'more-privacy-options', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
	}

	/**
	 * Triggered by the hook "update_blog_public".
	 * Email Admin about privacy changes.
	 *
	 * @return void
	 */
	public function mail_super_admin( $blog_id, $privacy_id ) {

		if ( ! get_site_option( $this->options['notify_admin'], false ) ) {
			return; // don't send emails if the option is disabled.
		}

		// Bail if the site's database tables do not exist (yet).
		if ( ! wp_is_site_initialized( $blog_id ) ) {
			return; // don't send emails for newly created blogs.
		}

		$to_new   = $this->mpo->get_privacy_description( $privacy_id );
		$blogname = get_blog_option( $blog_id, 'blogname' );
		$blog_url = get_site_url( $blog_id );
		$subject  = esc_html( __( 'Site changed reading visibility settings.', 'more-privacy-options' ) )
			. " $blogname [ID: $blog_id, $blog_url] => $to_new";

		$setting_url  = network_admin_url( 'settings.php' );
		$disable_text = esc_html( __( 'Disable this type of emails in Network admin: ', 'more-privacy-options' ) );
		$message      = "$subject \r\n\r\n" .
				esc_html( __( 'Sent by More Privacy Options plugin.', 'more-privacy-options' ) ) .
				"$disable_text $setting_url";

		$headers = 'Auto-Submitted: auto-generated';
		wp_mail(
			stripslashes( get_site_option( 'admin_email' ) ),
			$subject,
			$message,
			$headers
		);
	}

	/**
	 * Triggered by the "robots_txt" filter.
	 * Disallow robots on privacy-ids < 0.
	 * WordPress handles 0 (no robots) and 1 (public with robots).
	 *
	 * @param string $output The original robots txt.
	 * @param string $public The "blog_public" option for the current requested blog.
	 * @return string The robots txt that might have an prepended "Disallow".
	 */
	public function filter_robots( $output, $public ) {
		if ( intval( $public ) < 0 ) {
			return "Disallow: /\n" . $output;
		}
	}

	/**
	 * Triggered by the actions "wp_head" and "login_head".
	 * If the blog is not public, tell robots to go away.
	 *
	 * @return void
	 */
	public function noindex() {
		remove_action( 'login_head', 'noindex' );
		remove_action( 'wp_head', 'noindex', 1 ); // priority 1.
		if ( 1 !== $this->mpo->get_current_privacy_id() ) {
			if ( function_exists( 'wp_robots_no_robots' ) ) {
				add_filter( 'wp_robots', 'wp_robots_no_robots' );
			} else {
				wp_no_robots();
			}
		}
	}

	/**
	 * Triggered by "option_ping_sites". Things with robots.
	 * "Check whether blog is public before returning sites."
	 *
	 * @param mixed $sites Will return if blog is public, will not return if not public.
	 * @return string|mixed Returns empty string ('') if blog is not public. Returns value in $sites if site is public.
	 */
	public function privacy_ping_filter( $sites ) {
		remove_filter( 'option_ping_sites', 'privacy_ping_filter' );
		if ( 1 === $this->mpo->get_current_privacy_id() ) {
			return $sites;
		} else {
			return '';
		}
	}

	/**
	 * Triggered by the actions "wpmueditblogaction" and "signup_blogform".
	 * Hooks into /wp-admin/network/site-settings.php.
	 *
	 * @return void
	 */
	public function wpmu_blogs_add_privacy_options() {
		global $details, $options;
		$title        = esc_html__( 'More Privacy Options', 'more-privacy-options' );
		$input_fields = '';
		foreach ( $this->mpo->blog_privacy_descriptions as $opt_id => $opt_desc ) {
			$input_fields .= $this->get_input_field( $opt_id, $details->public, 'option[blog_public]' ) . "($opt_id)<br>";
		}
		echo "<tr><th>$title</th><td>$input_fields</td></tr>"; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/**
	 * Helper function. Create an input field for a blog privacy id.
	 *
	 * @param int    $privacy_id The blog privacy id.
	 * @param int    $checked_id if equals $privacy_id the input field is checked.
	 * @param string $name value for the name attribute.
	 * @return string The markup for te input field.
	 */
	private function get_input_field( int $privacy_id, int $checked_id, string $name ) {
		$checked     = ( $privacy_id === $checked_id ) ? 'checked' : '';
		$description = $this->mpo->get_privacy_description( $privacy_id );
		return "<input id='blog-private$privacy_id' type='radio' name='$name' value='$privacy_id' $checked> $description ";
	}

	/**
	 * Triggered by the filter "manage_sites-network_columns".
	 *
	 * @param array $column_details The array which contains all columns of the sites administration (/wp-admin/network/sites.php).
	 * @return array
	 */
	public function add_sites_column( $column_details ) {
		$column_details['blog_visibility'] = esc_html__( 'Visibility', 'more-privacy-options' );
		return $column_details;
	}

	/**
	 * Triggered by the action "manage_sites_custom_column".
	 *
	 * @param string $column_name -.
	 * @param int    $blog_id -.
	 * @return void
	 */
	public function manage_sites_custom_column( $column_name, $blog_id ) {
		if ( 'blog_visibility' !== $column_name ) {
			return;
		}
		$details = get_blog_details( $blog_id );
		$long    = $this->mpo->get_privacy_description( $details->public );
		$icon    = $this->mpo->get_privacy_description( $details->public, 'icon' );
		$short   = $this->mpo->get_privacy_description( $details->public, 'short' );
		$icon    = ( strpos( $icon, 'dashicons' ) !== false ) ? $icon . ' dashicons-before' : $icon;
		echo "<p title='$long'><i class='$icon'></i> $short</p>"; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/**
	 * Triggered by the action "blog_privacy_selector" in options-reading.php.
	 * Adds more privacy settings to the Settings -> Options Reading Page.
	 *
	 * @return void
	 */
	public function add_privacy_options() {
		global $blogname,$current_site;
		$blog_name = get_bloginfo( 'name', 'display' );
		foreach ( $this->mpo->blog_privacy_descriptions as $opt_id => $opt_desc ) {
			if ( $opt_id < 0 ) { // The ids 1 and 0 are WP defaults and already have an interface.
				$input = $this->get_input_field( $opt_id, $this->mpo->get_current_privacy_id(), 'blog_public' ) . '<br>';
				echo "<br><label for='blog-private$opt_id' class='checkbox' >$input</label>"; // phpcs:ignore WordPress.Security.EscapeOutput
			}
		}
	}

	/**
	 * Tries to authenticate a (not logged in) user via $_SERVER['PHP_AUTH_USER'].
	 * Dies if user is not allowed.
	 *
	 * @todo: remove? does anybody use feeds? security: open brute force - option.
	 *
	 * @return void
	 */
	private function ds_feed_login() {
		$user    = wp_signon(
			array(
				'user_login'    => isset( $_SERVER['PHP_AUTH_USER'] ) ? $_SERVER['PHP_AUTH_USER'] : '', // WPCS: sanitization ok. Sanitized by wp_authenticate.
				'user_password' => isset( $_SERVER['PHP_AUTH_PW'] ) ? $_SERVER['PHP_AUTH_PW'] : '', // WPCS: sanitization ok.
				'remember'      => true,
			),
			false
		);
		if ( is_wp_error( $user ) || ! $this->mpo->can_user_access_current_blog() ) {
			$server_name = isset( $_SERVER['SERVER_NAME'] ) ? esc_url_raw( wp_unslash( $_SERVER['SERVER_NAME'] ) ) : '';
			header( 'WWW-Authenticate: Basic realm="' . $server_name . '"' );
			header( 'HTTP/1.0 401 Unauthorized' );
			die();
		}
	}

	/**
	 * Check if the current request is meant for user activation.
	 *
	 * @return boolean
	 */
	private function is_activate_request() {
		$php_self = isset( $_SERVER['PHP_SELF'] ) ? esc_url_raw( wp_unslash( $_SERVER['PHP_SELF'] ) ) : '';
		if ( strpos( $php_self, 'wp-activate.php' ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Triggered by the action "wpmu_options".
	 * Create the settings page. You can decide wether the Visibility is managed per blog or all blogs are private.
	 *
	 * @return void
	 */
	public function sitewide_privacy_options_page() {

		add_settings_error( 'more-privacy-options', 'error', esc_html__( 'Something went wrong saving privacy options.', 'more-privacy-options' ) );
		settings_errors( 'more_privacy_options' );

		$title = esc_html__( 'Network Visibility', 'more-privacy-options' );

		$nonce_action = 'mpo_' . get_current_blog_id();
		$nonce_name   = 'more_privacy_network_setting';
		$nonce        = wp_nonce_field( $nonce_action, $nonce_name, true, false );

		$default_privacy_settings = $this->sitewide_privacy_default_visibility();
		$notify_admin_settings    = $this->sitewide_privacy_option_notify_super_admin();

		$markup = "
			<h3>$title</h3>
			$this->by
			<table class='form-table'>
				$nonce
				$default_privacy_settings
				$notify_admin_settings
			</table>
		";
		echo $markup; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/**
	 * Select between:
	 * - Sites are only visible to registered users of this network
	 * - Visibility is managed per site (default)
	 *
	 * @return string Form Markup
	 */
	public function sitewide_privacy_default_visibility() {
		$network_vis                = esc_html__( 'Default privacy', 'more-privacy-options' );
		$visible_network_users      = esc_html__( 'Sites are only visible to registered users of this network.', 'more-privacy-options' );
		$manage_visibility_per_site = esc_html__( 'Visibility is managed per site (default).', 'more-privacy-options' );

		$option_name           = $this->options['default_privacy'];
		$setting               = intval( get_site_option( $option_name, 1 ) );
		$checked_network_users = ( -1 === $setting ) ? 'checked' : '';
		$checked_per_site      = ( 1 === $setting ) ? 'checked=' : '';

		return "
			<tr valign='top'>
				<th scope='row'>$network_vis</th>
				<td>
					<fieldset>
						<label>
							<input type='radio' name='$option_name' id='$option_name' value='-1' $checked_network_users/>
							$visible_network_users
						</label><br />
						<label>
							<input type='radio' name='$option_name' id='{$option_name}_1' value='1' $checked_per_site/>
							$manage_visibility_per_site
						</label><br />
					</fieldset>
				</td>
			</tr>
		";
	}

	/**
	 * Toggle sending emails about privacy changes.
	 *
	 * @return string Form Markup
	 */
	public function sitewide_privacy_option_notify_super_admin() {

		$option_name = $this->options['notify_admin'];
		$active      = get_site_option( $option_name, true );
		$checked     = checked( $active );
		$notify      = esc_html( __( 'Notify super-admin', 'more-privacy-options' ) );
		$text        = esc_html( __( 'Send emails about blog-privacy changes to super-admin.', 'more-privacy-options' ) );

		return "
			<tr>
				<th scope='row'>$notify</th>
				<td>
					<input name='$option_name' id='$option_name' type='checkbox' $checked>
					<label for='$option_name'>$text</label>
				</td>
			<tr>
		";
	}


	/**
	 * Triggered by the action "update_wpmu_options".
	 *
	 * @return void|WP_Error
	 */
	public function sitewide_privacy_update() {

		if ( ! current_user_can( 'manage_network_options' ) ) {
			wp_safe_redirect( add_query_arg( 'privacy-options-error', 'caps', network_admin_url( 'settings.php' ) ) );
			exit();
		}

		$nonce_name  = 'more_privacy_network_setting';
		$none_action = 'mpo_' . get_current_blog_id();

		$default_privacy_option_name = $this->options['default_privacy'];
		$notify_admin_option_name    = $this->options['notify_admin'];

		if ( isset( $_POST[ $nonce_name ] )
			&& wp_verify_nonce( sanitize_key( $_POST[ $nonce_name ] ), $none_action )
			&& isset( $_POST[ $default_privacy_option_name ] ) ) {
			update_site_option( $default_privacy_option_name, intval( $_POST[ $default_privacy_option_name ] ) );
			update_site_option( $notify_admin_option_name, isset( $_POST[ $notify_admin_option_name ] ) ); // key is only present if 'on'.
		} else {
			wp_safe_redirect( add_query_arg( 'privacy-options-error', 'others', network_admin_url( 'settings.php' ) ) );
			exit();
		}
	}

	/**
	 * Add some errors if saving privacy-options in the network-admin went wrong.
	 *
	 * @return void
	 */
	public function sitewide_privacy_option_errors() {

		// no need to do a nonce-verification as we are just checking for an error.
		if ( ! isset( $_GET['privacy-options-error'] ) ) {
			return;
		}
		$msg = ( 'caps' === $_GET['privacy-options-error'] )
		? esc_html__( 'You are not allowed to manage network options. Network visibility options have not been saved.' )
		: esc_html__( 'Something went wrong saving network visibility options. Please try again.', 'more-privacy-options' );

		add_settings_error( 'more-privacy-options', 'error', $msg );
		settings_errors( 'more-privacy-options' );
	}
}
