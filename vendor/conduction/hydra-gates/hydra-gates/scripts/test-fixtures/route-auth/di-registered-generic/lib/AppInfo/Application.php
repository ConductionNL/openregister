<?php
// SPDX-License-Identifier: EUPL-1.2

namespace OCA\Fixture\AppInfo;

use OCA\OpenRegister\AppHost\Controller\GenericPreferencesController;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;

/**
 * NOTE: no `Bootstrap::register()` call. This app adopts ONE AppHost generic
 * and wires it by hand, because it needs `appName` injected so the `pref_`
 * user-value namespace stays scoped to this app rather than to OpenRegister.
 *
 * The service key MUST be the standard `OCA\Fixture\Controller\…` namespace:
 * that is the class name NC's App::main synthesises from the plain
 * `genericPreferences#…` route slug. A key in any other namespace is never
 * looked up by the router and every request 503s.
 */
class Application extends App implements IBootstrap
{
    public const APP_ID = 'fixture';

    public function register(IRegistrationContext $context): void
    {
        $context->registerService(
            'OCA\\Fixture\\Controller\\GenericPreferencesController',
            static function (ContainerInterface $c) {
                return new GenericPreferencesController(
                    appName: self::APP_ID,
                    request: $c->get(IRequest::class),
                    config: $c->get(IConfig::class),
                    userSession: $c->get(IUserSession::class)
                );
            }
        );
    }

    public function boot(\OCP\AppFramework\Bootstrap\IBootContext $context): void
    {
    }
}
