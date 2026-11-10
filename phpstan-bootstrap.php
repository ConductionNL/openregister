<?php

/**
 * PHPStan bootstrap file - registers OCP autoloader for static analysis.
 */

$autoloader = require __DIR__ . '/vendor/autoload.php';
$autoloader->addPsr4('OCP\\', __DIR__ . '/vendor/nextcloud/ocp/OCP/');
$autoloader->addPsr4('NCU\\', __DIR__ . '/vendor/nextcloud/ocp/NCU/');
