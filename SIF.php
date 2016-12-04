<?php

// CVS keyword left for nostalgia purposes, because CVS was the bomb.
// $Id: SIF.class.php,v 1.14 2007/06/06 18:16:08 nate Exp $

/**
 * Class SIF Main routing class
 */
class SIF {
    
    /**
     * Action directory.
     * 
     * @var string
     */
    public $actionDir = '../action/';

    /**
     * Cache directory, for action config cache and such. Requires write access by web user.
     * 
     * @var string
     */
    public $cacheDir = '../cache/sif/';

    /**
     * Whether to enable action config caching. This can improve performance a lot, especially if there are many actions.
     * 
     * @var bool
     */
    public $cacheEnable = true;

    /**
     * Log file. Requires write access by web user.
     * 
     * @var string
     */
    public $logFile = '../log/sif.log';

    /**
     * Whether to enable logging of SIF activity. Useful for development, but not recommended for production.
     * 
     * @var bool
     */
    public $logEnable = false;
    
    /**
     * Name of active action.
     * 
     * @var string
     */
    protected $actionName = '';

    /**
     * Array of actions that we have chained through to get to the active action.
     * 
     * @var array 
     */
    protected $actionChain = [];

    /**
     * Config for the active action.
     * 
     * @var array
     */
    protected $actionConfig = [];

    /**
     * Cache of action configs.
     * 
     * @var array
     */
    protected $actionConfigs = [];

    /**
     * Run SIF. No walking allowed.
     */
    function run() {
        $startCalled = false;

        // Make sure BaseAction is included first so other actions can extend it.
        require_once $this->actionDir . 'BaseAction.class.php';

        // Look for a match
        $args = $this->findActionMatch();

        // Initialize the found action and run its logic and view functions.
        // Keep looping until an action returns nothing, meaning we're done.
        while (true) {
            require_once $this->actionDir . $this->actionName . '.class.php';
            
            // Init and setup action
            $action = new $this->actionName;
            $action->config = $this->actionConfig;
            $action->logEnable = $this->logEnable;
            $action->logFile = $this->logFile;
            $action->actionName = $this->actionName;
            $action->actionChain = $this->actionChain;
            $action->init();
            $action->logMessage('Action ' . $this->actionName . ': intialized');

            // If this is a chained action carry the user vars over
            if (isset($userData)) {
                $action->userData = $userData;
            }

            // Call the start function if it exists
            // This is meant to be implemented in BaseAction for global logic, such as authentication on all pages.
            // This function will only be called once, regardless of how many actions are chained.
            if ($startCalled === false and is_callable([$action, 'start'])) {
                $action->logMessage('Action ' . $this->actionName . ': calling start function');
                $result = $action->start();
                $startCalled = true;

                // If the start function returns a new action we loop and run it.
                if ($result != '') {
                    $this->actionName = $result;
                    $this->actionChain[] = $result;
                    $userData = $action->userData;
                    if (array_key_exists($result, $this->actionConfigs)) {
                        $this->actionConfig = $this->actionConfigs[$result];
                    }
                    continue;
                }
            }

            // Call the logic function if it exists
            if (is_callable([$action, 'logic'])) {
                $action->logMessage('Action ' . $this->actionName . ': calling logic function');
                $result = $action->logic($args);

                // If the logic function returns a new action we loop and run it.
                if ($result != '') {
                    $this->actionName = $result;
                    $this->actionChain[] = $result;
                    $userData = $action->userData;
                    if (array_key_exists($result, $this->actionConfigs)) {
                        $this->actionConfig = $this->actionConfigs[$result];
                    }
                    continue;
                }
            }

            // Call the view function if it exists
            if (is_callable([$action, 'view'])) {
                $action->logMessage('Action ' . $this->actionName . ': calling view function');
                $action->view($args);
            }

            // Call the stop function if it exists
            // This is meant to be implemented only in BaseAction for global teardown logic, such as a db disconnect.
            if (is_callable([$action, 'stop'])) {
                $action->logMessage('Action ' . $this->actionName . ': calling stop function');
                $action->stop($args);
            }

            break;
        }
    }

    // Step through the available actions and look for a match.
    function findActionMatch() {
        if ($this->cacheEnable) {
            $cacheFile = $this->cacheDir . 'sif/configs.cache';
        }

        if (!is_dir($this->actionDir)) {
            $this->fatalError('Action directory does not exist.');
        }

        // Check cache
        if (isset($cacheFile) and is_readable($cacheFile) and $configs = unserialize(file_get_contents($cacheFile))) {
            // Yep

        // Otherwise build the action config
        } else {
            $configs = [];
            $files = scandir($this->actionDir);

            if (!is_array($files) or count($files) < 1) {
                $this->fatalError('No actions found.');
            }

            foreach ($files as $file) {

                if (is_file($this->actionDir . $file) and is_readable($this->actionDir . $file) and substr($file, 0, 1) != '.') {
                    $config = [];
                    require_once $this->actionDir . $file;

                    // If the config looks valid save it
                    if (isset($config['name'])) {
                        $configs[$config['name']] = $config;
                    }
                }
            }
            
            // Save configs to cache if possible
            if (isset($cacheFile) and is_writable(dirname($cacheFile))) {
                file_put_contents($cacheFile, serialize($configs));
            }
        }
        
        // Save configs for later use when chaining actions
        $this->actionConfigs = $configs;

        // Build commonly used checks once
        // Strip '?' and anything after it in uri
        $checkUri = preg_replace('/\?.*$/', '', $_SERVER['REQUEST_URI']);
        $checkUriDecoded = urldecode($checkUri);
        
        foreach ($configs as $config) {

            // Check if the config has match definitions and if so check each match.
            if (is_array($config['matches']) and count($config['matches']) > 0) {

                // Determine if we should OR or AND the matches
                if (isset($config['match_all']) and $config['match_all'] === true) {
                    $mmode = 'and';
                } else {
                    $mmode = 'or';
                }

                $matches = 0;
                $args = [];

                foreach ($config['matches'] as $type => $data) {
                    $newargs = [];

                    // Truncate type to three chars so we can use names like preg1, preg2, get1, etc.
                    $type = substr($type, 0, 3);

                    switch ($type) {

                    // preg_match() against a server variable
                    case 'pre':
                    case 'hpr':

                        // HTTP_HOST
                        if ($type == 'hpr') {
                            $check = $_SERVER['HTTP_HOST'];

                        // REQUEST_URI
                        } else {
                            if (array_key_exists('matchDecodeUri', $config) and $config['matchDecodeUri'] === true) {
                                $check = $checkUriDecoded;
                            } else {
                                $check = $checkUri;
                            }
                        }

                        if (preg_match($data, $check, $newargs)) {
                            $matches++;
                        }

                        break;

                    // $_GET
                    case 'get':
                        if (isset($_GET[$data])) {
                            $matches++;
                            $newargs = $_GET;
                        }

                        break;

                    // $_POST
                    case 'pos':
                        if (isset($_POST[$data])) {
                            $matches++;
                            $newargs = $_POST;
                        }

                        break;

                    // Default match
                    case 'def':
                        $defaultAction = $config['name'];
                        break;
                    }

                    // If in OR mode, support just one set of arguments
                    if ($mmode == 'or') {
                        $args = $newargs;

                    // If in AND mode, support one set per match
                    } elseif ($mmode == 'and') {
                        $args[$matches] = $newargs;
                    }

                    // If in OR mode, bail out whenever we get a match
                    if ($mmode == 'or' and $matches > 0) {
                        $this->actionName = $config['name'];
                        $this->actionChain[] = $config['name'];
                        $this->actionConfig = $config;
                        return $args;
                    }
                }
                
                // If in AND mode, make sure all matches are successful
                if ($mmode == 'and' and $matches == count($config['matches'])) {
                    $this->actionName = $config['name'];
                    $this->actionChain[] = $config['name'];
                    $this->actionConfig = $config;
                    return $args;
                }
            }
        }

        // If we found a default match and nothing else, use the default
        if (isset($defaultAction)) {
            $this->actionName = $defaultAction;
            $this->actionChain[] = $defaultAction;
            return [];
        }

        $this->fatalError('No actions matched.');
    }

    /**
     * Print a fatal error message and exit.
     * 
     * @param $msg string
     */
    public function fatalError($msg) {
        die($msg);
    }
}


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

