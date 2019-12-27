<?php

namespace PHPServerless;

class RoboFile extends \Robo\Tasks {

    private $dirCwd = null;
    private $dirPhpSls = null;
    private $dirConfig = null;
    private $dirPhpSlsDeploy = null;

    function __construct() {
        $this->init();
    }

    function init() {
        $this->dirCwd = getcwd();
        $this->dirConfig = $this->dirCwd . '/config';
        $this->dirPhpSls = $this->dirCwd . '/.phpsls';
        $this->dirPhpSlsDeploy = $this->dirPhpSls . '/deploy';


        if (is_dir($this->dirPhpSls) == true) {
            return true;
        }
        $isSuccessful = $this->taskExec('mkdir')
                ->arg($this->dirPhpSls)
                ->run()
                ->wasSuccessful();
        if ($isSuccessful == false) {
            return $this->say('Failed.');
        }
    }

    public function deploy($environment) {
        // 1. Does the configuration file exists? No => Exit
        $this->say('1. Checking configuration...');
        $envConfigFile = $this->dirConfig . '/' . $environment . '.php';

        if (file_exists($envConfigFile) == false) {
            return $this->say('Configuration file for environment "' . $environment . '" missing at: ' . $envConfigFile);
        }

        // 2. Load the configuration file for the enviroment
        \Sinevia\Registry::set("ENVIRONMENT", $environment);
        $this->loadEnvConf(\Sinevia\Registry::get("ENVIRONMENT"));

        var_dump(\Sinevia\Registry::toArray());

        // 3. Check if serverless function name is set
        $functionName = \Sinevia\Registry::get('SERVERLESS_FUNCTION_NAME', '');

        if ($functionName == "") {
            return $this->say('SERVERLESS_FUNCTION_NAME not set for environment "' . $environment . '"');
        }

        if ($functionName == "{YOUR_LIVE_SERVERLESS_FUNCTION_NAME}") {
            return $this->say('SERVERLESS_FUNCTION_NAME not set for environment "' . $environment . '"');
        }

        $this->say('5. Creating deployment directory...');
        if (file_exists($this->dirPhpSlsDeploy) == false) {
            $isSuccessful = $this->taskExec('mkdir')
                    ->arg($this->dirPhpSlsDeploy)
                    ->run()
                    ->wasSuccessful();
            if ($isSuccessful == false) {
                return $this->say('Failed.');
            }
        }

        $this->taskCleanDir([$this->dirPhpSlsDeploy])->run();

        $serverlessFileContents = file_get_contents(__DIR__ . '/stubs/serverless.yaml');
        $serverlessFileContents = str_replace('{YOURFUNCTION}', $functionName, $serverlessFileContents);
        file_put_contents($this->dirPhpSlsDeploy . '/serverless.yaml', $serverlessFileContents);

        $this->say('5. Copying files...');
        $this->taskCopyDir([getcwd() => $this->dirPhpSlsDeploy])
                ->exclude([
                    $this->dirPhpSls,
                    $this->dirCwd . '/composer.lock',
                    $this->dirCwd . '/nbproject',
                    $this->dirCwd . '/node_modules',
                    //$this->dirCwd . '/phpsls',
                    $this->dirCwd . '/vendor',
                ])
                // ->option('function', $functionName) // Not working since Serverless v.1.5.1
                ->run();

        // 4. Run tests
        $this->say('2. Running tests...');
        //$isSuccessful = $this->test();
        //if ($isSuccessful == false) {
        //    return $this->say('Failed');
        //}
        // 5. Run composer (no-dev)
        $this->say('3. Updating composer dependencies...');
        $isSuccessful = $this->taskExec('composer')
                        ->arg('update')
                        ->option('prefer-dist')
                        ->option('optimize-autoloader')
                        ->dir($this->dirPhpSlsDeploy)
                        ->run()->wasSuccessful();
        if ($isSuccessful == false) {
            return $this->say('Failed.');
        }

        exit('HERE');
        // 6. Prepare for deployment
//        $this->say('4. Prepare for deployment...');
//        $this->taskReplaceInFile('env.php')
//                ->from('"ENVIRONMENT", isLocal() ? "local" : "unrecognized"')
//                ->to('"ENVIRONMENT", isLoca()()l() ? "local" : "' . $environment . '"')
//                ->run();
        // 7. Deploy
        try {
            $this->say('5. Deploying...');
            $this->taskExec('sls')
                    ->arg('deploy')
                    // ->option('function', $functionName) // Not working since Serverless v.1.5.1
                    ->run();
        } catch (\Exception $e) {
            $this->say('There was an exception: ' . $e->getMessage());
        }

        // 8. Cleanup after deployment
        $this->say('6. Cleaning up...');
        $this->taskReplaceInFile('env.php')
                ->from('"ENVIRONMENT", isLocal() ? "local" : "' . $environment . '"')
                ->to('"ENVIRONMENT", isLocal() ? "local" : "unrecognized"')
                ->run();
        $this->taskReplaceInFile('serverless.yaml')
                ->from($functionName)
                ->to('{YOURFUNCTION}')
                ->run();
    }

    /**
     * Serves the application locally using the PHP built-in server
     * @return void
     */
    public function serve() {
        $this->init();

        /* START: Reload enviroment */
//        \Sinevia\Registry::set("ENVIRONMENT", 'local');
//        loadEnvConf(\Sinevia\Registry::get("ENVIRONMENT"));
        /* END: Reload enviroment */

//        $url = \Sinevia\Registry::get('URL_BASE', '');
//        if ($url == "") {
//            return $this->say('URL_BASE not set for local');
//        }
//
//        $domain = str_replace('http://', '', $url);

        $domain = 'localhost:35555';
        $serverFileContents = file_get_contents(__DIR__ . '/stubs/index.php');
        file_put_contents($this->dirPhpSls . '/index.php', $serverFileContents);

        $isSuccessful = $this->taskExec('php')
                ->arg('-S')
                ->arg($domain)
                ->arg($this->dirPhpSls . '/index.php')
                ->run();
    }

    /**
     * Loads the environment configuration variables
     * @param string $environment
     * @return void
     */
    function loadEnvConf($environment) {
        $envConfigFile = $this->dirConfig . '/' . $environment . '.php';

        if (file_exists($envConfigFile)) {
            $envConfigVars = include($envConfigFile);

            if (is_array($envConfigVars)) {
                foreach ($envConfigVars as $key => $value) {
                    \Sinevia\Registry::setIfNotExists($key, $value);
                }
            }
        }
    }

}

/**
 * Checks whether the script runs on localhost
 * @return boolean
 */
function isLocal() {
    if (isset($_SERVER['REMOTE_ADDR']) == false) {
        return false;
    }

    $whitelist = array(
        '127.0.0.1',
        '::1'
    );

    if (in_array($_SERVER['REMOTE_ADDR'], $whitelist)) {
        return true;
    }

    false;
}
