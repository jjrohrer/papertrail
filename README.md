# Papertrail Logging API for WordPress

## Setup

1. Get your destination from your account at https://papertrailapp.com/account/destinations
2. Install as a plugin or load it up in mu-plugins
3. Define your constant in wp-config.php `WP_PAPERTRAIL_DESTINATION`

## Make JSON pretty

You can use this Greasemonkey script to make the JSON logging look much better (and works with Tampermonkey with a small tweak, see comments).

https://gist.github.com/troy/55442ad0d2502f9ac0a7

## Usage

```php
// Log data
$success = WP_Papertrail_API::log( $some_string_array_or_object, 'Some optional identifier' );

if ( ! is_wp_error( $success ) ) {
    // Successfully logged to Papertrail
}
```

## Options

### Destination

You will need to define the destination to log to in wp-config.php (see https://papertrailapp.com/account/destinations)

`define( 'WP_PAPERTRAIL_DESTINATION', 'logs1.papertrailapp.com:12345' );`

### Error Logging

You can log PHP errors to Papertrail too, using this easy to set constant:

`define( 'WP_PAPERTRAIL_ERROR_HANDLER', true );`

Please be aware, some codebases produce a large amount of notices, warnings, or other messages even when they aren't displayed on the screen. Be careful with this handler enabled and watch your Papertrail plan as you might approach your limit quickly.

### Log Levels

You can specify the error reporting levels you would like to log to Papertrail by defining the log level constant in `wp-config.php`:

```php
// log all errors except deprecated errors
define( 'WP_PAPERTRAIL_LOG_LEVEL', E_ALL & ~E_DEPRECATED );
```

See [the PHP documentation](https://php.net/manual/en/function.error-reporting.php) for more information on this configuration option.

### Exclusions
 You can tell WP_Papertrail_API to ignore errors that happen in Wordpress, or in certain plugins or themes.  This is useful
 when somebody is spewing messages that are beyond your control, or that you can't do anything about, such as when 
 Wordpress throws 'Notice' messages. 
 
```php
// Don't report certain errors (TODO: 2018-12-6 - This assumes standard directory locations, which is a bad assumption)
define('WP_PAPERTRAIL_DO_EXCLUDE_WORDPRESS',true); // Ignore errors coming from wordpress

WP_Papertrail_API::$excluded_plugin_dirs[] = 'et-appzoo-schoolchase'; // Ignore any errors coming from this plugin directory

WP_Papertrail_API::$excluded_plugin_dirs[] = 'et-appzoo-schoolchase/et-appchase-xaddon-xevents/html/Lib/adodb/drivers';  // Ignore stuff in 'drivers' dir of the plugin
WP_Papertrail_API::$excluded_themes_dirs[] = 'genesis';  // Ignore anything from the genesis theme

WP_Papertrail_API::$excluded_filenames[] = 'functions.php'; // Ignore messages from _All_ files called functions.php
WP_Papertrail_API::$excluded_filenames[] = 'jdrivers/functions.php'; // Ignore messages from _All_ files called functions.php that are in a 'drivers' directory.

```
 
 

## Props

Props have to go to Troy Davis (@troy on GitHub, @troyd on Twitter) who came up with the PHP interface to communicate with the Papertrail API.

See the original gist code here: https://gist.github.com/troy/2220679

I also referenced the Stream to Papertrail plugin (https://github.com/Japh/stream-to-papertrail) initially.
