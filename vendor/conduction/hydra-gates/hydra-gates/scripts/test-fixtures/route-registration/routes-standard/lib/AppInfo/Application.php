<?php
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Fixture\AppInfo;

use OCA\OpenRegister\AppHost\Bootstrap;

class Application
{
    public function register($context): void
    {
        Bootstrap::register($context, 'fixture', ['namespace' => 'OCA\\Fixture']);
    }
}
