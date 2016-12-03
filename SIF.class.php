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
