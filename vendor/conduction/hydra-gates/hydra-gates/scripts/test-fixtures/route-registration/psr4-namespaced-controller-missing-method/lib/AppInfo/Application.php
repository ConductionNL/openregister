<?php
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Fixture\AppInfo;

class Application
{
    public function register($context): void
    {
        $context->registerService(
            'AppHost\\Controller\\GenericHealthController',
            static fn ($c) => new \OCA\Fixture\AppHost\Controller\GenericHealthController()
        );
    }
}
