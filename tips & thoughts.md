/*
Tips:
To allow everyone who is on-campus into the blog, while requiring those off-campus to log in. Modify function ds_users_authenticator().

Such as this:

if (     (strncmp('155.47.', $_SERVER['REMOTE_ADDR'], 7 ) == 0)  || (is_user_logged_in())                  ) {
		// user is either logged in or at campus
				}
else {
		// user is either not logged in or at campus

	  if( is_feed() ) {
...
}

Similarily:
A plugin to allow login from only certain ip:
http://w-shadow.com/blog/2008/11/07/restrict-login-by-ip-a-wordpress-plugin/
A plugin to ban certain ip:
http://wordpress.org/extend/plugins/wp-ban/

To protect files/attachments/images uploaded to protected blogs(.htaccess rewrites needed)
Pluginspiration: http://plugins.svn.wordpress.org/private-files/trunk/privatefiles.php

?????????? Notes/Questions about allowing wp-activate.php on a private site ????????????????????

First, but using string matching is dumb and easily bypasses login page. Adding '?wp-activate.php" to any url

// if( strpos($_SERVER['REQUEST_URI'], 'wp-activate.php')) return;

Second, allow activate.php on the main page, but PHP_SELF url string matching may have the same drawbacks as REQUEST_URI.
Could be done to main page with a plugin/function.

Also, the appearance of wp-activate on a subsite depends on which action is hooked: send_headers, or template_redirect.
I prefer the template_redirect so activations can occur on subsites, too.

add_action('send_headers','ds_more_privacy_options_activate',10);
	public function ds_more_privacy_options_activate() {
		global $ds_more_privacy_options;

		if( strpos($_SERVER['PHP_SELF'], 'wp-activate.php') && is_main_site()) {
			remove_action('send_headers', array($ds_more_privacy_options, 'ds_users_authenticator'),10);
			remove_action('send_headers', array($ds_more_privacy_options, 'ds_members_authenticator'),10);
			remove_action('send_headers', array($ds_more_privacy_options, 'ds_admins_authenticator'),10);
		}
	}

Third, you could redirect any url match to the main page wp-activate.php,
but the redirection doesn't take the activation key with it - just not ideal to use any site but the main for activation IMHO.

At any rate using url matching was dumb - adding wp-activate.php to any url bypassed the login auth
- so a redirect to main site may help - provided the main site isn't also private.

// if( strpos($_SERVER['REQUEST_URI'], 'wp-activate.php')  && !is_main_site()) { // DO NOT DO THIS!

So, better may be PHP_SELF since we can wait for script to execute before deciding to auth_redirect it.

// if( strpos($_SERVER['PHP_SELF'], 'wp-activate.php')  && !is_main_site()) {
// $destination = network_home_url('wp-activate.php');
// wp_redirect( $destination );
// exit();
// }

Finally, changing the hook to fire at send_headers rather than template_redirect allows the activation page on every site.
Still shows template pages with headers/sidebars etc - so not ideal either. Hence my preference to redirect to the main site.

// add_action('template_redirect', array($this, 'ds_users_authenticator'));
		add_action('send_headers', array($this, 'ds_users_authenticator'));

So, I have the private functions the way I actually use them on my private sites/networks.
I also do many activations as the SiteAdmin manually using other plugins.
Therefore, the code in this revision may make blogs more private, but somewhat more inconvenient to activate, both features I desire.

We'll see how the feedback trickles in on this issue.