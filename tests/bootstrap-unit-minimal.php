<?php

declare(strict_types=1);

define('PHPUNIT_RUN', 1);

// App vendor autoloader.
require_once __DIR__ . '/../vendor/autoload.php';

// Nextcloud core autoloader — maps OCP\, OCA\, OC\ without booting the framework.
if (file_exists('/srv/nextcloud/lib/composer/autoload.php')) {
    require_once '/srv/nextcloud/lib/composer/autoload.php';
} elseif (file_exists('/var/www/html/lib/composer/autoload.php')) {
    require_once '/var/www/html/lib/composer/autoload.php';
}

// 3rdparty autoloader — maps Doctrine\, GuzzleHttp\, etc.
if (file_exists('/srv/nextcloud/3rdparty/autoload.php')) {
    require_once '/srv/nextcloud/3rdparty/autoload.php';
} elseif (file_exists('/var/www/html/3rdparty/autoload.php')) {
    require_once '/var/www/html/3rdparty/autoload.php';
}

// Minimal OC stub so Response::getHeaders() resolves services without the full NC container.
if (!class_exists('OC')) {
    class OC
    {
        public static object $server;
    }

    OC::$server = new class {
        public function get(string $service): mixed
        {
            if ($service === \OCP\IRequest::class) {
                return new class {
                    public function getId(): string { return 'unit-test-request-id'; }
                };
            }
            if ($service === \OCP\IUserSession::class) {
                return new class {
                    public function getUser(): ?object { return null; }
                };
            }
            return null;
        }
    };
}
