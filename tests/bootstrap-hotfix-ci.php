<?php

/**
 * PHPUnit bootstrap for the WOO-572 OpenRegister hotfix line (1.1.5-woo-N).
 *
 * The app's own tests/bootstrap.php boots the INSTALLED Nextcloud instance for
 * the OCP\* interfaces and runs inside the docker container. The hotfix CI and
 * a host-side run have no instance, so this bootstrap composer-autoloads the
 * app and serves OCP\* / NCU\* from the `nextcloud/ocp` dev dependency, which
 * ships the interface sources but no composer autoload of its own. Enough for
 * pure unit tests that mock OCP interfaces; anything that resolves a service
 * from \OC::$server is out of scope here (the hotfix's own tests inject their
 * collaborators).
 *
 * Usage (from the app root):
 *   vendor/bin/phpunit --no-coverage --do-not-cache-result \
 *     --bootstrap tests/bootstrap-hotfix-ci.php tests/Unit/...
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 */

declare(strict_types=1);

define('PHPUNIT_RUN', 1);

require_once __DIR__.'/../vendor/autoload.php';

spl_autoload_register(
    static function (string $class): void {
        foreach (['OCP\\', 'NCU\\'] as $prefix) {
            if (str_starts_with($class, $prefix) === false) {
                continue;
            }

            $file = __DIR__.'/../vendor/nextcloud/ocp/'.str_replace('\\', '/', $class).'.php';
            if (is_file($file) === true) {
                require_once $file;
            }

            return;
        }
    }
);

// The OCP interfaces occasionally extend Nextcloud INTERNAL classes (`OC\*`,
// e.g. IRootFolder extends OC\Hooks\Emitter) that nextcloud/ocp does not ship.
// The stubs declare the minimal surface, each guarded by a class_exists() check.
require_once __DIR__.'/stubs/NextcloudInternalStubs.php';

// OCP\DB\QueryBuilder\IQueryBuilder references Doctrine DBAL constants that the
// bare composer install has no package for; same guarded-stub approach.
require_once __DIR__.'/stubs/DoctrineDbalStubs.php';
