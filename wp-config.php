<?php
/**
 * The base configuration for WordPress
 *
 * This file contains the following configurations:
 * * Database settings
 * * Secret keys
 * * Database table prefix
 * * ABSPATH
 *
 * This wp-config.php uses environment variables for sensitive information
 * to enhance security in different deployment environments.
 * 
 * @link https://wordpress.org/documentation/article/editing-wp-config-php/
 */

// ** Database settings - You can get this info from your web host ** //

// Get database settings from environment variables
define( 'DB_NAME', getenv('WP_DB_NAME') ?: 'wordpress' );
define( 'DB_USER', getenv('WP_DB_USER') ?: 'root' );
define( 'DB_PASSWORD', getenv('WP_DB_PASSWORD') ?: '' );
define( 'DB_HOST', getenv('WP_DB_HOST') ?: 'localhost' );
define( 'DB_CHARSET', getenv('WP_DB_CHARSET') ?: 'utf8' );
define( 'DB_COLLATE', getenv('WP_DB_COLLATE') ?: '' );

// Get site URL settings from environment variables
define( 'WP_HOME', getenv('WP_HOME') ?: 'http://localhost' );
define( 'WP_SITEURL', getenv('WP_SITEURL') ?: 'http://localhost' );

/**#@+
 * Authentication unique keys and salts.
 *
 * Change these to different unique phrases! You can generate these using
 * the {@link https://api.wordpress.org/secret-key/1.1/salt/ WordPress.org secret-key service}.
 *
 * You can change these at any point in time to invalidate all existing cookies.
 * This will force all users to have to log in again.
 */
define( 'AUTH_KEY', 'AsHsNLhCzzldFzOnsKtEqeKaGHjdyngyqLqSYgGpzctsqnYYnkRjFCctSCqhhVvi' );
define( 'SECURE_AUTH_KEY', 'wTjiBNimYdNwjIYLFoKyArrcqkpMwilsLeoLwHfzZzSRDfSHpBIDrCMuPyPZRquk' );
define( 'LOGGED_IN_KEY', 'IBNCgSYpDMwfKttJHXkyWAUAEQBFEYfMwEfgiBgIjSJCqnrcMWgYrwxBjZoajwUN' );
define( 'NONCE_KEY', 'VECUoUFlyhdtNqvWiEnHFPbHJDdTHwcJbluqcumeSpZftZDGOOnIzmDbpYosWRYM' );
define( 'AUTH_SALT', 'whCKzVNUvFeYQpUCkqQlUeXwocAXGplqxpuxRXLnsJAsfAwKwYTGVDqvAqmbPRsr' );
define( 'SECURE_AUTH_SALT', 'auHcxuxjhcvNvzzXnVbnvTpSVfjIQZoUPwyCgwPmdzUJVyKQunHMgLBcqCtYtxUd' );
define( 'LOGGED_IN_SALT', 'yCNqbYjCyViNvkkzWxDDePnnfrvnZRqMdbcvNtytstPrHqNHcVzxlKjAkakqivfV' );
define( 'NONCE_SALT', 'wKErpreWrMHHdSFXyrnMSIqssMYJdXieeupMWeHpOgAmGxYVXvvzLQnZwMaXXnmS' );

/**#@-*/

/**
 * WordPress database table prefix.
 *
 * You can have multiple installations in one database if you give each
 * a unique prefix. Only numbers, letters, and underscores please!
 */
$table_prefix = getenv('WP_TABLE_PREFIX') ?: 'wp_';

/* Add any custom values between this line and the "stop editing" line. */

/* That's all, stop editing! Happy publishing. */

/** Absolute path to the WordPress directory. */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

/** Sets up WordPress vars and included files. */
require_once ABSPATH . 'wp-settings.php';