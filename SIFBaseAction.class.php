<?php

/**
 * Class SIFBaseAction
 * 
 * Base SIF action for extending in every other action.
 */
class SIFBaseAction {
    public $logEnable = false;
    public $logFile = null;
    
    public $actionName;
    public $actionChain;
    public $config;
    public $userData = array();
    protected $log;

    /**
     * SIF constructor
     */
    public function __construct() {
        
    }

    /**
     * Init logic, called with every action chain. Use start() for logic that should be called just once.
     */
    function init() {
        // Logging
        if ($this->logEnable and $this->logFile !== null) {
            $this->log = @fopen($this->logFile, 'a');

            if (!$this->log) {
                $this->fatalError('Unable to open log file for writing.');
            }
        }
    }

    /**
     * Set a page meta variable so it shows up in the pageData object in javascript.
     * 
     * @param string $name
     * @param mixed $value
     * @return boolean True on success, or false otherwise
     */
    public function setPageVar($name, $value) {
        $this->pageMetaData[$name] = $value;
        return true;
    }


    /**
     * Get a user (template) variable.
     * 
     * @param string $name
     * @return mixed Value if variable exists, or false otherwise.
     */
    function getUserVar($name) {
        if (isset($this->userData[$name])) {
            return $this->userData[$name];
        }

        return false;
    }

    /**
     * Set a user (template) variable.
     * 
     * @param string $name
     * @param mixed $value
     * @return bool True on success or false otherwise
     */
    function setUserVar($name, $value) {
        $this->userData[$name] = $value;
        return true;
    }

    /**
     * Log a message to the SIF log. Deprecated for other loggers like UPLog.
     * 
     * @param string $msg
     * @return bool True on successful write or false otherwise
     */
    function logMessage($msg) {
        if ($this->logEnable) {
            $msg = $_SERVER['REMOTE_ADDR'] . ' [' . date('r') . '] "' .
                   $_SERVER['REQUEST_METHOD'] . ' ' . $_SERVER['REQUEST_URI'] . ' ' .
                   $_SERVER['SERVER_PROTOCOL'] . '" ' . $msg . "\n";
            fwrite($this->log, $msg);
        }

        return true;
    }

    /**
     * Throw an HTTP 503 with the given message.
     * 
     * @param string $msg
     */
    function fatalError($msg) {
        header('HTTP/1.1 503 Service Temporarily Unavailable');
        die($msg);
    }
   
    /**
     * Send a 301 redirect header to the specified URL and exit.
     * 
     * @param string $url
     */
    function redirect($url) {

        // Don't cache redirects
        $this->setCacheHeaders(false);
        
        // Send 301 headers
        header('HTTP/1.1 301 Moved Permanently');
        header('Location: ' . $url);
        
        exit;
    }
        /**
     * Set HTTP response cache headers.
     * 
     * @param bool $cache Whether to enable caching (true) or not (false).
     * @param string $last_mod Last modified time
     * @param int $ttl Cache time to live in seconds
     */
    public function setCacheHeaders($cache = false, $last_mod = '', $ttl = '') {
        $tz_offset = date('Z') * -1;
        $time_now = time();
        $time_def = time();
        $ttl_def = 43200;

        // Default to caching off
        if ($cache != true) {
            $cache = false;
        }

        // If last modified time is empty, use default time
        if ($last_mod == '') {
            $last_mod = $time_def;
        } elseif (!is_numeric($last_mod)) {
            $last_mod = strtotime($last_mod);
        }

        // If last modified time is invalid or before the default, use the default
        if (!preg_match('/^\d{10}$/', $last_mod) or $last_mod < $time_def) {
            $last_mod = $time_def;
        }

        // Set LM and save it for use in the templates
        header('Last-Modified: ' . date('D, d M Y H:i:s', $last_mod + $tz_offset) . ' GMT');
        $this->setUserVar('cache_last_mod', $last_mod);

        // Caching enabled
        if ($cache == true) {

            // If TTL is empty use default
            if ($ttl == '' or !is_numeric($ttl)) {
                $ttl = $ttl_def;
            }

            header_remove('Pragma');
            header('Cache-Control: public, max-age=' . $ttl);
            header('Expires: ' . date('D, d M Y H:i:s', $time_now + $ttl + $tz_offset) . ' GMT');

            // Handle IMS header
            if (isset($_SERVER['HTTP_IF_MODIFIED_SINCE'])) {
                $ims_time = strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']);

                if ($ims_time != -1) {
                    if ($last_mod < $ims_time) {
                        header('HTTP/1.1 304 Not Modified');
                        exit;
                    }
                }
            }

            // Caching disabled
        } else {
            header('Pragma: no-cache');
            header('Expires: Thu, 19 Nov 1981 08:52:00 GMT');
            header('Cache-Control: no-store, no-cache, must-revalidate, post-check=0, pre-check=0');
        }
    }
}

