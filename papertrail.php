<?php
/**
 * Plugin Name: Papertrail Logging API
 * Plugin URI:  https://github.com/sc0ttkclark/papertrail
 * Description: Papertrail Logging API for WordPress
 * Version:     0.4
 * Author:      Scott Kingsley Clark
 * Author URI:  http://scottkclark.com/
 */

// See https://papertrailapp.com/account/destinations
// define( 'WP_PAPERTRAIL_DESTINATION', 'logs4.papertrailapp.com:12345' );

class WP_Papertrail_API {

	/**
	 * Socket resource for reuse
	 *
	 * @var resource
	 */
	protected static $socket;

	/**
	 * An array of error codes and their equivalent string value
	 *
	 * @var array
	 */
	protected static $codes = array(
		E_ERROR             => 'E_ERROR',
		E_WARNING           => 'E_WARNING',
		E_PARSE             => 'E_PARSE',
		E_NOTICE            => 'E_NOTICE',
		E_CORE_ERROR        => 'E_CORE_ERROR',
		E_CORE_WARNING      => 'E_CORE_WARNING',
		E_COMPILE_ERROR     => 'E_COMPILE_ERROR',
		E_COMPILE_WARNING   => 'E_COMPILE_WARNING',
		E_USER_ERROR        => 'E_USER_ERROR',
		E_USER_WARNING      => 'E_USER_WARNING',
		E_USER_NOTICE       => 'E_USER_NOTICE',
		E_STRICT            => 'E_STRICT',
		E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
		E_DEPRECATED        => 'E_DEPRECATED',
		E_USER_DEPRECATED   => 'E_USER_DEPRECATED',
	);

	/**
	 * Methods in this class are meant to be called statically
	 */
	private function __construct() {

		// Hulk smash

	}

	public static $excluded_filenames = [];         // List of simple filenames to exclude, such as function.php.  Hint: put leading directories for more specificity, like, 'drivers/functions.php'
    #public static $do_exclude_wordpress = false;    // Set to true to not report on wp-admin stuff
    public static $excluded_plugin_dirs = [];       // By directory name, ignore issues in the following plugins
    public static $excluded_theme_dirs = [];       // By directory name, ignore issues in the following themes


    /**
	 * Log data to Papertrail.
	 *
	 * @author Troy Davis from the Gist located here: https://gist.github.com/troy/2220679
	 *
	 * @param string|array|object $data      Data to log to Papertrail.
	 * @param string              $component Component name to identify log in Papertrail.
	 *
	 * @return bool|WP_Error True if successful or an WP_Error object with the problem.
	 */
	public static function log( $data, $component = '' ) {

		if ( ! defined( 'WP_PAPERTRAIL_DESTINATION' ) || ! WP_PAPERTRAIL_DESTINATION ) {
			return new WP_Error( 'papertrail-no-destination', __( 'No Papertrail destination set.', 'papertrail' ) );
		}

		$destination = array_combine( array( 'hostname', 'port' ), explode( ':', WP_PAPERTRAIL_DESTINATION ) );
		$program     = parse_url( is_multisite() ? network_site_url() : site_url(), PHP_URL_HOST );
		$json        = json_encode( $data );

		if ( empty( $destination ) || 2 != count( $destination ) || empty( $destination['hostname'] ) ) {
			return new WP_Error( 'papertrail-invalid-destination', sprintf( __( 'Invalid Papertrail destination (%s >> %s:%s).', 'papertrail' ), WP_PAPERTRAIL_DESTINATION, $destination['hostname'], $destination['port'] ) );
		}

		if (
			defined( 'WP_PAPERTRAIL_LOG_LEVEL' ) &&
			WP_PAPERTRAIL_LOG_LEVEL &&
			false !== ( $code = self::codify_error_string( $component ) ) &&
			! ( WP_PAPERTRAIL_LOG_LEVEL & $code )
		) {
			return new WP_Error( 'papertrail-log-level-off', esc_html( sprintf(
				__( 'The log level %s has been turned off in this configuration. Current log level: %d', 'papertrail' ),
				self::stringify_error_code( $code ),
				WP_PAPERTRAIL_LOG_LEVEL
			) ) );
		}

		$syslog_message = '<22>' . date_i18n( 'M d H:i:s' );

		if ( $program ) {
			$syslog_message .= ' ' . trim( $program );
		}

		if ( $component ) {
			$syslog_message .= ' ' . trim( $component );
		}

		$syslog_message .= ' ' . $json;

		if ( ! self::$socket ) {
			self::$socket = @socket_create( AF_INET, SOCK_DGRAM, SOL_UDP );

			@socket_connect( self::$socket, $destination['hostname'], $destination['port'] );
		}

		$result = socket_send( self::$socket, $syslog_message, strlen( $syslog_message ), 0 );

		//socket_close( self::$socket );

		$success = false;

		if ( false !== $result ) {
			$success = true;
		}

		return $success;

	}

	/**
	 * Get page info
	 *
	 * @param array $page_info
	 *
	 * @return array
	 */
	public static function get_page_info( $page_info = array() ) {

		// Setup URL
		$page_info['url'] = 'http://';

		if ( is_ssl() ) {
			$page_info['url'] = 'https://';
		}

		$page_info['url'] .= $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];

		$page_info['url'] = explode( '?', $page_info['url'] );
		$page_info['url'] = $page_info['url'][0];
		$page_info['url'] = explode( '#', $page_info['url'] );
		$page_info['url'] = $page_info['url'][0];

		$page_info['$_GET']  = $_GET;
		$page_info['$_POST'] = $_POST;

		$page_info['DOING_AJAX'] = ( defined( 'DOING_AJAX' ) && DOING_AJAX );
		$page_info['DOING_CRON'] = ( defined( 'DOING_CRON' ) && DOING_CRON );

		// Remove potentially sensitive information from page info
		if ( isset( $page_info['$_GET']['password'] ) ) {
			unset( $page_info['$_GET']['password'] );
		}

		if ( isset( $page_info['$_GET']['pwd'] ) ) {
			unset( $page_info['$_GET']['pwd'] );
		}

		if ( isset( $page_info['$_POST']['password'] ) ) {
			unset( $page_info['$_POST']['password'] );
		}

		if ( isset( $page_info['$_POST']['pwd'] ) ) {
			unset( $page_info['$_POST']['pwd'] );
		}

		return $page_info;

	}

	/**
	 * Turn a string representation of an error type into an error code
	 *
	 * If the error code doesn't exist in our array, this will return false. $type will get run through basename, so component strings from error logs will
	 * get handled without any changes necessary to the type value.
	 *
	 * @param string $type
	 *
	 * @return false|int
	 */
	protected static function codify_error_string( $type ) {
		return array_search( basename( $type ), self::$codes );
	}

	protected static function stringify_error_code( $code ) {
		return isset( self::$codes[ $code ] ) ? self::$codes[ $code ] : 'unknown';
	}

    /**
     * This is copied directory from wp-admin/includes/file.php because it isn't loaded yet in some of our cases.
     * @return string path of this wordpress installation
     */
    private static $_home_path_cached;
    static function get_home_path() {
        $home    = set_url_scheme( get_option( 'home' ), 'http' );
        $siteurl = set_url_scheme( get_option( 'siteurl' ), 'http' );
        if ( ! empty( $home ) && 0 !== strcasecmp( $home, $siteurl ) ) {
            $wp_path_rel_to_home = str_ireplace( $home, '', $siteurl ); /* $siteurl - $home */
            $pos = strripos( str_replace( '\\', '/', $_SERVER['SCRIPT_FILENAME'] ), trailingslashit( $wp_path_rel_to_home ) );
            $home_path = substr( $_SERVER['SCRIPT_FILENAME'], 0, $pos );
            $home_path = trailingslashit( $home_path );
        } else {
            $home_path = ABSPATH;
        }

        return str_replace( '\\', '/', $home_path );
    }

	/**
	 * Handle error logging to Papertrail
	 *
	 * @param int    $id      Error number
	 * @param string $message Error message
	 * @param string $file    Error file
	 * @param int    $line    Error line
	 * @param array  $context Error context
	 */
	public static function error_handler( $id, $message, $file, $line, $context ) {

		$type = self::stringify_error_code( $id );


        $directories_to_skip = [];
        #if (!isset(static::$_home_path_cached)) {
        static::$_home_path_cached = realpath(static::get_home_path()); // don't keep calling get_home_path.  It looks expensive.
        #}

        if (defined('WP_PAPERTRAIL_DO_EXCLUDE_WORDPRESS') && WP_PAPERTRAIL_DO_EXCLUDE_WORDPRESS) {
            $directories_to_skip[] = 'wp-admin'; // Future: This dir can move, so this code should account for that, but it doesn't yet.
            $directories_to_skip[] = 'wp-includes'; // Future: This dir can move, so this code should account for that, but it doesn't yet.
            static::$excluded_filenames[] = 'wp-activate.php';
            static::$excluded_filenames[] = 'wp-blog-header.php';
            static::$excluded_filenames[] = 'wp-comments-posts.php';
            static::$excluded_filenames[] = 'wp-config.php';
            static::$excluded_filenames[] = 'wp-cron.php';
            static::$excluded_filenames[] = 'wp-links-opml.php';
            static::$excluded_filenames[] = 'wp-load.php';
            static::$excluded_filenames[] = 'wp-login.php';
            static::$excluded_filenames[] = 'wp-mail.php';
            static::$excluded_filenames[] = 'wp-settings.php';
            static::$excluded_filenames[] = 'wp-trackback.php';
            static::$excluded_filenames[] = 'xmlrpc.php';
        }


        $canonicalized_path_name = realpath($file);

        $position_at_end = strlen(static::$_home_path_cached) -1;
        $start_slot_of_file = $position_at_end + 2;
        $file_off_of_home = substr($canonicalized_path_name,$start_slot_of_file);// note: this removes the starting slash
        $filenameForLog = $file_off_of_home;


        if (count(static::$excluded_filenames) > 0) {
            // Nix if the file ends with this
            foreach (static::$excluded_filenames as $the_ending_filename_maybe_with_some_leading_dirs) {
                $tailOfFileOffHome = substr($file_off_of_home,strlen($file_off_of_home)- strlen($the_ending_filename_maybe_with_some_leading_dirs));
                if ($tailOfFileOffHome  == $the_ending_filename_maybe_with_some_leading_dirs) { // does $file_off_of_home match the end of the item?
                    return;
                }
            }
        }

        $location_of_plugins_dir = 'wp-content'.DIRECTORY_SEPARATOR.'plugins';// Future: make this dynamic
        foreach (static::$excluded_plugin_dirs as $dir_to_exlude) {
            $directories_to_skip[] = $location_of_plugins_dir.DIRECTORY_SEPARATOR.$dir_to_exlude;
        }

        $location_of_themes_dir = 'wp-content'.DIRECTORY_SEPARATOR.'themes';// Future: make this dynamic
        foreach (static::$excluded_theme_dirs as $dir_to_exlude) {
            $directories_to_skip[] = $location_of_themes_dir.DIRECTORY_SEPARATOR.$dir_to_exlude;
        }


        // Nix if matches a directory that starts with this
        foreach ($directories_to_skip as $the_skippable_directory) {
            if (substr($file_off_of_home,0,strlen($the_skippable_directory)) == $the_skippable_directory) {
                return;
            }
        }






        $page_info = array(
			'error' => sprintf( '%s:%s | %s | %s ', $filenameForLog, $line , $type, $message),
		);

		$page_info = self::get_page_info( $page_info );

		if ( 'E_ERROR' != $type ) {
			unset( $page_info['$_POST'] );
			unset( $page_info['$_GET'] );
		}


        self::log( $page_info, 'WP_Papertrail_API/Error/' . $type );

	}

}

// Setup error handler
if ( defined( 'WP_PAPERTRAIL_ERROR_HANDLER' ) && WP_PAPERTRAIL_ERROR_HANDLER ) {
	set_error_handler( array( 'WP_Papertrail_API', 'error_handler' ) );
}

