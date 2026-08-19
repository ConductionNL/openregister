<?php
// SPDX-License-Identifier: EUPL-1.2

namespace OCA\Fixture\AppInfo;

use OCA\OpenRegister\AppHost\Bootstrap;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap
{
    public const APP_ID = 'fixture';

    public function register(IRegistrationContext $context): void
    {
        // ADR-040: adopt the OpenRegister AppHost. One call wires the generic
        // SPA/settings/preferences/health/metrics controllers.
        Bootstrap::register(
            $context,
            self::APP_ID,
            ['namespace' => 'OCA\\Fixture']
        );
    }
}
