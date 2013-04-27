<?php

namespace FM\ElFinderPHP\Connector;

use FM\ElFinderPHP\Connector\ElFinderConnector;
use FM\ElFinderPHP\ElFinder;
use FM\ElFinderPHP\Driver\ElFinderVolumeLocalFileSystem;


class MinimalConnector
{

    public function index()
    {

        $opts = array(
            // 'debug' => true,
            'roots' => array(
                array(
                    'driver'        => 'LocalFileSystem',   // driver for accessing file system (REQUIRED)
                    'path'          => '../files/',         // path to files (REQUIRED)
                    'URL'           => dirname($_SERVER['PHP_SELF']) . '/../files/', // URL to files (REQUIRED)
                    'accessControl' => 'access'             // disable and hide dot starting files (OPTIONAL)
                )
            )
        );

        $connector = new elFinderConnector(new ElFinder($opts));
        $connector->run();
    }

}