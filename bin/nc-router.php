<?php
// php -S front controller for the sandbox Nextcloud: pretty URLs go to index.php.
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$root = $_SERVER['DOCUMENT_ROOT'];
if (preg_match('#^/[^/]+\.php(/|$)#', $uri) === 1 || preg_match('#^/ocs/v[12]\.php#', $uri) === 1 || preg_match('#^/ocs-provider/#', $uri) === 1) {
	return false;
}
if ($uri !== '/' && is_file($root . $uri) && substr($uri, -4) !== '.php') {
	return false;
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $root . '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';
$_SERVER['front_controller_active'] = 'true';
putenv('front_controller_active=true');
chdir($root);
require $root . '/index.php';
