<?php

/**
 * Class which manages privacy-options (interfaces and rules).
 */
class Ds_More_Privacy_Options {

	/**
	 * Access via get_privacy_description function.
	 *
	 * @var array
	 */
	public $blog_privacy_descriptions = array();

	/**
	 * The capability that defines admins (more flexible than checking for role names).
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
	 * The single instance of DS_More_Privacy_Options.
	 *
	 * @var     object
	 * @access  private
	 * @since   1.0.0
	 */
	private static $instance = null;

	/**
	 * The main plugin object.
	 *
	 * @var     object
	 * @access  public
	 * @since   1.0.0
	 */
	public $parent = null;

	/**
	 * The Constructor
	 */
	public function __construct() {
		if ( ! is_multisite() ) {
			add_action( 'all_admin_notices', array( $this, 'display_not_multisite_notice' ) );
			return false;
		}
		$this->init_vars();
		$hooks = new Ds_More_Privacy_Hooks( $this );
	}

	/**
	 * Set some variables for the Class.
	 *
	 * @return void
	 */
	public function init_vars() {

		$this->sitewide_privacy = intval( get_site_option( 'ds_sitewide_privacy' ) );

		/**
		 * If you want to change the output here use the privacy_description - filter (documented below).
		 */
		$this->blog_privacy_descriptions = array(
			1   => array( // this is WP-default. Just here for completeness.
				'long'  => esc_html__( 'Visible to the World. Allow search engines to index this site.', 'more-privacy-options' ),
				'short' => esc_html__( 'World', 'more-privacy-options' ),
				'icon'  => 'dashicons-admin-site',
			),
			0   => array( // this is WP-default. Just here for completeness.
				'long'  => esc_html__( 'Visible to the World. Discourage search engines from indexing this site.', 'more-privacy-options' ),
				'short' => esc_html__( 'World, discourage search engines', 'more-privacy-options' ),
				'icon'  => 'dashicons-admin-site',
			),
			- 1 => array(
				'long'  => esc_html__( 'Visible only to registered users of this network.', 'more-privacy-options' ),
				'short' => esc_html__( 'Network users', 'more-privacy-options' ),
				'icon'  => 'dashicons-networking',
			),
			- 2 => array(
				'long'  => esc_html__( 'Visible only to registered users of this site.', 'more-privacy-options' ),
				'short' => esc_html__( 'Blog users', 'more-privacy-options' ),
				'icon'  => 'dashicons-groups',
			),
			- 3 => array(
				'long'  => esc_html__( 'Visible only to administrators of this site.', 'more-privacy-options' ),
				'short' => esc_html__( 'Blog Admins', 'more-privacy-options' ),
				'icon'  => 'dashicons-businessman',
			),
		);
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
		if ( isset( $this->blog_privacy_descriptions[ $id ] ) ) {
			return apply_filters(
				'privacy_description',
				esc_html( $this->blog_privacy_descriptions[ $id ][ $type ] ),
				$id,
				$type
			);
		}
		return new WP_Error( 'broke', __( "We don't have a description for the given privacy id.", 'more-privacy-options' ) );
	}

	/**
	 * Get long, short and icon for a privacy level ( lvl 1 would be 'World').
	 *
	 * @return array with keys long, short and icon
	 */
	public function get_privacy_level( int $id ) {
		foreach ( $this->blog_privacy_descriptions[ $id ] as $type => $value ) {
			$this->blog_privacy_descriptions[ $id ][ $type ] = $this->get_privacy_description( $id, $type );
		}
		return $this->blog_privacy_descriptions[ $id ];
	}

	/**
	 * The the privacy id of the current blog.
	 * It is the 'blog_public' value stored in wp_blogs.
	 *
	 * @return int
	 */
	public function get_current_privacy_id() {
		global $current_blog;

		if ( is_null( $this->privacy_id ) ) { // only init once. change if you support others than current blog.

			if ( isset( $current_blog->public ) ) {
				$priv_id = $current_blog->public;
			} else {
				$priv_id = get_blog_option( get_current_blog_id(), 'blog_public' );
			}
			/**
			 * If sitewide privacy is only for registered Members (-1) we overwrite any public privacy ( 1 and 0 ) with a -1.
			 * sitewide privacy is 1 by default.
			 */
			$this->privacy_id = min( intval( $priv_id ), intval( get_site_option( 'ds_sitewide_privacy', 1 ) ) );

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

		$priv_id = $this->get_current_privacy_id();

		/**
		 * Blog is public and privacy is managed per blog
		 * or sitewide privacy is public (this plugin doesn't make sense in this case :).
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
			return true;
		}

		/**
		 * Member only blog.
		 */
		if ( -2 === $priv_id ) {
			return is_user_member_of_blog( $user_id, $blog_id );
		}

		/**
		 * Admin only blog.
		 */
		if ( -3 === $priv_id ) {
			return user_can( $user_id, $this->admin_defining_capability );
		}

		return false;
	}


	/**
	 * Main this class Instance
	 *
	 * Ensures only one instance of this class is loaded or can be loaded.
	 *
	 * @since 1.0.0
	 * @static
	 * @see Spaces_Setup()
	 * @return Main DS_More_Privacy_Options instance
	 */
	public static function instance( $parent ) {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self( $parent );
		}
		return self::$instance;
	}

	/**
	 * Cloning is forbidden.
	 *
	 * @since 1.0.0
	 */
	public function __clone() {
		_doing_it_wrong( __FUNCTION__, __( 'Cheatin&#8217; huh?' ), $this->parent->_version );
	}

	/**
	 * Unserializing instances of this class is forbidden.
	 *
	 * @since 1.0.0
	 */
	public function __wakeup() {
		_doing_it_wrong( __FUNCTION__, __( 'Cheatin&#8217; huh?' ), $this->parent->_version );
	}

}
