<?php

/*
 +-----------------------------------------------------------------------+
 | This file is part of the Roundcube Webmail client                     |
 | Copyright (C) 2008-2012, The Roundcube Dev Team                       |
 |                                                                       |
 | Licensed under the GNU General Public License version 3 or            |
 | any later version with exceptions for skins & plugins.                |
 | See the README file for a full license statement.                     |
 |                                                                       |
 | PURPOSE:                                                              |
 |   Class to read configuration settings                                |
 +-----------------------------------------------------------------------+
 | Author: Thomas Bruederli <roundcube@gmail.com>                        |
 +-----------------------------------------------------------------------+
*/

/**
 * Configuration class for Roundcube
 *
 * @package    Framework
 * @subpackage Core
 */
class rcube_config
{
    const DEFAULT_SKIN = 'larry';

    private $env = '';
    private $basedir = 'config/';
    private $prop = array();
    private $errors = array();
    private $userprefs = array();

    /**
     * Renamed options
     *
     * @var array
     */
    private $legacy_props = array(
        // new name => old name
        'default_folders'      => 'default_imap_folders',
        'mail_pagesize'        => 'pagesize',
        'addressbook_pagesize' => 'pagesize',
        'reply_mode'           => 'top_posting',
        'refresh_interval'     => 'keep_alive',
        'min_refresh_interval' => 'min_keep_alive',
        'messages_cache_ttl'   => 'message_cache_lifetime',
        'redundant_attachments_cache_ttl' => 'redundant_attachments_memcache_ttl',
    );


    /**
     * Object constructor
     *
     * @param string Environment suffix for config files to load
     */
    public function __construct($env = '')
    {
        $this->env = $env;
        $this->basedir = RCUBE_CONFIG_DIR;

        $this->load();

        // Defaults, that we do not require you to configure,
        // but contain information that is used in various
        // locations in the code:
        $this->set('contactlist_fields', array('name', 'firstname', 'surname', 'email'));
    }


    /**
     * Load config from local config file
     *
     * @todo Remove global $CONFIG
     */
    private function load()
    {
        // Load default settings
        if (!$this->load_from_file('defaults.inc.php')) {
            $this->errors[] = 'defaults.inc.php was not found.';
        }

        // load main config file
        if (!$this->load_from_file('config.inc.php')) {
            // Old configuration files
            if (!$this->load_from_file('main.inc.php') ||
                !$this->load_from_file('db.inc.php')) {
                $this->errors[] = 'config.inc.php was not found.';
            }
            else if (rand(1,100) == 10) {  // log warning on every 100th request (average)
                trigger_error("config.inc.php was not found. Please migrate your config by running bin/update.sh", E_USER_WARNING);
            }
        }

        // load host-specific configuration
        if (!empty($_SERVER['HTTP_HOST']))
            $this->load_host_config();

        // set skin (with fallback to old 'skin_path' property)
        if (empty($this->prop['skin'])) {
            if (!empty($this->prop['skin_path'])) {
                $this->prop['skin'] = str_replace('skins/', '', unslashify($this->prop['skin_path']));
            }
            else {
                $this->prop['skin'] = self::DEFAULT_SKIN;
            }
        }

        // larry is the new default skin :-)
        if ($this->prop['skin'] == 'default')
            $this->prop['skin'] = self::DEFAULT_SKIN;

        // fix paths
        $this->prop['log_dir'] = $this->prop['log_dir'] ? realpath(unslashify($this->prop['log_dir'])) : RCUBE_INSTALL_PATH . 'logs';
        $this->prop['temp_dir'] = $this->prop['temp_dir'] ? realpath(unslashify($this->prop['temp_dir'])) : RCUBE_INSTALL_PATH . 'temp';

        // fix default imap folders encoding
        foreach (array('drafts_mbox', 'junk_mbox', 'sent_mbox', 'trash_mbox') as $folder)
            $this->prop[$folder] = rcube_charset::convert($this->prop[$folder], RCUBE_CHARSET, 'UTF7-IMAP');

        if (!empty($this->prop['default_folders']))
            foreach ($this->prop['default_folders'] as $n => $folder)
                $this->prop['default_folders'][$n] = rcube_charset::convert($folder, RCUBE_CHARSET, 'UTF7-IMAP');

        // set PHP error logging according to config
        if ($this->prop['debug_level'] & 1) {
            ini_set('log_errors', 1);

            if ($this->prop['log_driver'] == 'syslog') {
                ini_set('error_log', 'syslog');
            }
            else {
                ini_set('error_log', $this->prop['log_dir'].'/errors');
            }
        }

        // enable display_errors in 'show' level, but not for ajax requests
        ini_set('display_errors', intval(empty($_REQUEST['_remote']) && ($this->prop['debug_level'] & 4)));

        // remove deprecated properties
        unset($this->prop['dst_active']);

        // export config data
        $GLOBALS['CONFIG'] = &$this->prop;
    }

    /**
     * Load a host-specific config file if configured
     * This will merge the host specific configuration with the given one
     */
    private function load_host_config()
    {
        $fname = null;

        if (is_array($this->prop['include_host_config'])) {
            $fname = $this->prop['include_host_config'][$_SERVER['HTTP_HOST']];
        }
        else if (!empty($this->prop['include_host_config'])) {
            $fname = preg_replace('/[^a-z0-9\.\-_]/i', '', $_SERVER['HTTP_HOST']) . '.inc.php';
        }

        if ($fname) {
            $this->load_from_file($fname);
        }
    }


    /**
     * Read configuration from a file
     * and merge with the already stored config values
     *
     * @param string $file Name of the config file to be loaded
     * @return booelan True on success, false on failure
     */
    public function load_from_file($file)
    {
        $fpath = $this->resolve_path($file);
        if ($fpath && is_file($fpath) && is_readable($fpath)) {
            // use output buffering, we don't need any output here 
            ob_start();
            include($fpath);
            ob_end_clean();

            if (is_array($config)) {
                $this->merge($config);
                return true;
            }
            // deprecated name of config variable
            else if (is_array($rcmail_config)) {
                $this->merge($rcmail_config);
                return true;
            }
        }

        return false;
    }

    /**
     * Helper method to resolve the absolute path to the given config file.
     * This also takes the 'env' property into account.
     */
    public function resolve_path($file, $use_env = true)
    {
        if (strpos($file, '/') === false) {
            $file = realpath($this->basedir . '/' . $file);
        }

        // check if <file>-env.ini exists
        if ($file && $use_env && !empty($this->env)) {
            $envfile = preg_replace('/\.(inc.php)$/', '-' . $this->env . '.\\1', $file);
            if (is_file($envfile))
                return $envfile;
        }

        return $file;
    }


    /**
     * Getter for a specific config parameter
     *
     * @param  string $name Parameter name
     * @param  mixed  $def  Default value if not set
     * @return mixed  The requested config value
     */
    public function get($name, $def = null)
    {
        if (isset($this->prop[$name])) {
            $result = $this->prop[$name];
        }
        else {
            $result = $def;
        }

        $rcube = rcube::get_instance();

        if ($name == 'timezone') {
            if (empty($result) || $result == 'auto') {
                $result = $this->client_timezone();
            }
        }
        else if ($name == 'client_mimetypes') {
            if ($result == null && $def == null)
                $result = 'text/plain,text/html,text/xml,image/jpeg,image/gif,image/png,image/bmp,image/tiff,application/x-javascript,application/pdf,application/x-shockwave-flash';
            if ($result && is_string($result))
                $result = explode(',', $result);
        }

        $plugin = $rcube->plugins->exec_hook('config_get', array(
            'name' => $name, 'default' => $def, 'result' => $result));

        return $plugin['result'];
    }


    /**
     * Setter for a config parameter
     *
     * @param string $name  Parameter name
     * @param mixed  $value Parameter value
     */
    public function set($name, $value)
    {
        $this->prop[$name] = $value;
    }


    /**
     * Override config options with the given values (eg. user prefs)
     *
     * @param array $prefs Hash array with config props to merge over
     */
    public function merge($prefs)
    {
        $prefs = $this->fix_legacy_props($prefs);
        $this->prop = array_merge($this->prop, $prefs, $this->userprefs);
    }


    /**
     * Merge the given prefs over the current config
     * and make sure that they survive further merging.
     *
     * @param array $prefs Hash array with user prefs
     */
    public function set_user_prefs($prefs)
    {
        $prefs = $this->fix_legacy_props($prefs);

        // Honor the dont_override setting for any existing user preferences
        $dont_override = $this->get('dont_override');
        if (is_array($dont_override) && !empty($dont_override)) {
            foreach ($dont_override as $key) {
                unset($prefs[$key]);
            }
        }

        // larry is the new default skin :-)
        if ($prefs['skin'] == 'default') {
            $prefs['skin'] = self::DEFAULT_SKIN;
        }

        $this->userprefs = $prefs;
        $this->prop      = array_merge($this->prop, $prefs);
    }


    /**
     * Getter for all config options
     *
     * @return array  Hash array containing all config properties
     */
    public function all()
    {
        return $this->prop;
    }

    /**
     * Special getter for user's timezone offset including DST
     *
     * @return float  Timezone offset (in hours)
     * @deprecated
     */
    public function get_timezone()
    {
      if ($tz = $this->get('timezone')) {
        try {
          $tz = new DateTimeZone($tz);
          return $tz->getOffset(new DateTime('now')) / 3600;
        }
        catch (Exception $e) {
        }
      }

      return 0;
    }

    /**
     * Return requested DES crypto key.
     *
     * @param string $key Crypto key name
     * @return string Crypto key
     */
    public function get_crypto_key($key)
    {
        // Bomb out if the requested key does not exist
        if (!array_key_exists($key, $this->prop)) {
            rcube::raise_error(array(
                'code' => 500, 'type' => 'php',
                'file' => __FILE__, 'line' => __LINE__,
                'message' => "Request for unconfigured crypto key \"$key\""
            ), true, true);
        }

        $key = $this->prop[$key];

        // Bomb out if the configured key is not exactly 24 bytes long
        if (strlen($key) != 24) {
            rcube::raise_error(array(
                'code' => 500, 'type' => 'php',
                'file' => __FILE__, 'line' => __LINE__,
                'message' => "Configured crypto key '$key' is not exactly 24 bytes long"
            ), true, true);
        }

        return $key;
    }


    /**
     * Try to autodetect operating system and find the correct line endings
     *
     * @return string The appropriate mail header delimiter
     */
    public function header_delimiter()
    {
        // use the configured delimiter for headers
        if (!empty($this->prop['mail_header_delimiter'])) {
            $delim = $this->prop['mail_header_delimiter'];
            if ($delim == "\n" || $delim == "\r\n")
                return $delim;
            else
                rcube::raise_error(array(
                    'code' => 500, 'type' => 'php',
                    'file' => __FILE__, 'line' => __LINE__,
                    'message' => "Invalid mail_header_delimiter setting"
                ), true, false);
        }

        $php_os = strtolower(substr(PHP_OS, 0, 3));

        if ($php_os == 'win')
            return "\r\n";

        if ($php_os == 'mac')
            return "\r\n";

        return "\n";
    }


    /**
     * Return the mail domain configured for the given host
     *
     * @param string  $host   IMAP host
     * @param boolean $encode If true, domain name will be converted to IDN ASCII
     * @return string Resolved SMTP host
     */
    public function mail_domain($host, $encode=true)
    {
        $domain = $host;

        if (is_array($this->prop['mail_domain'])) {
            if (isset($this->prop['mail_domain'][$host]))
                $domain = $this->prop['mail_domain'][$host];
        }
        else if (!empty($this->prop['mail_domain'])) {
            $domain = rcube_utils::parse_host($this->prop['mail_domain']);
        }

        if ($encode) {
            $domain = rcube_utils::idn_to_ascii($domain);
        }

        return $domain;
    }


    /**
     * Getter for error state
     *
     * @return mixed Error message on error, False if no errors
     */
    public function get_error()
    {
        return empty($this->errors) ? false : join("\n", $this->errors);
    }


    /**
     * Internal getter for client's (browser) timezone identifier
     */
    private function client_timezone()
    {
        // @TODO: remove this legacy timezone handling in the future
        $props = $this->fix_legacy_props(array('timezone' => $_SESSION['timezone']));

        if (!empty($props['timezone'])) {
            try {
                $tz = new DateTimeZone($props['timezone']);
                return $tz->getName();
            }
            catch (Exception $e) { /* gracefully ignore */ }
        }

        // fallback to server's timezone
        return date_default_timezone_get();
    }

    /**
     * Convert legacy options into new ones
     *
     * @param array $props Hash array with config props
     *
     * @return array Converted config props
     */
    private function fix_legacy_props($props)
    {
        foreach ($this->legacy_props as $new => $old) {
            if (isset($props[$old])) {
                if (!isset($props[$new])) {
                    $props[$new] = $props[$old];
                }
                unset($props[$old]);
            }
        }

        // convert deprecated numeric timezone value
        if (isset($props['timezone']) && is_numeric($props['timezone'])) {
            if ($tz = timezone_name_from_abbr("", $props['timezone'] * 3600, 0)) {
                $props['timezone'] = $tz;
            }
            else {
                unset($props['timezone']);
            }
        }

        return $props;
    }
}
