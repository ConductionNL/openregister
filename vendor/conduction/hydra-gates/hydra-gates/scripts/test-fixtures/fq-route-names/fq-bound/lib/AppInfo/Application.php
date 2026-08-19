<?php
// SPDX-License-Identifier: EUPL-1.2

namespace OCA\Fixture\AppInfo;

use OCA\OpenRegister\AppHost\Controller\GenericDashboardController;
use OCA\OpenRegister\AppHost\Controller\GenericHealthController;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IRequest;
use Psr\Container\ContainerInterface;

/**
 * NOTE: deliberately NO `Bootstrap::register()` call, so `_apphost_serves()`
 * is switched off for this fixture. The only thing that can exempt the
 * fully-qualified routes is the binding evidence below — which is exactly what
 * this suite is here to measure. If the suite passed with Bootstrap adoption
 * present it would be measuring the five-slug list instead.
 */
class Application extends App implements IBootstrap
{
    public const APP_ID = 'fixture';

    public function register(IRegistrationContext $context): void
    {
        // The service key MUST be the route name verbatim + `Controller`:
        // that is the literal App::main looks up, and for a name containing
        // `\Controller\` there is no fallback to rewrite it.
        $context->registerService(
            'OCA\\Fixture\\AppHost\\Controller\\GenericDashboardController',
            static function (ContainerInterface $c) {
                return new GenericDashboardController(
                    appName: self::APP_ID,
                    request: $c->get(IRequest::class)
                );
            }
        );
        $context->registerService(
            'OCA\\Fixture\\AppHost\\Controller\\GenericHealthController',
            static function (ContainerInterface $c) {
                return new GenericHealthController(
                    appName: self::APP_ID,
                    request: $c->get(IRequest::class)
                );
            }
        );
    }

    public function boot(IBootContext $context): void
    {
    }
}
