<?php
// Sandbox router for php -S: pretty URLs (/apps/x/) go to index.php like the
// .htaccess front controller does; real files and *.php entry points as is.
$uri = (string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$root = $_SERVER['DOCUMENT_ROOT'];
if (preg_match('#^/[^/]+\.php(/|$)#', $uri) === 1 || preg_match('#\.php(/|$)#', $uri) === 1) {
	return false;
}
if ($uri !== '/' && is_file($root . $uri)) {
	return false;
}
if ($uri === '/') {
	return false;
}
putenv('front_controller_active=true');
$_SERVER['front_controller_active'] = 'true';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $root . '/index.php';
$_SERVER['PHP_SELF'] = '/index.php' . $uri;
$_SERVER['PATH_INFO'] = $uri;
chdir($root);
require $root . '/index.php';
