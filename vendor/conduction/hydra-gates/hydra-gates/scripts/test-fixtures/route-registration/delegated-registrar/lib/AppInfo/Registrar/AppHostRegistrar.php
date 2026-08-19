<?php
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Fixture\AppInfo\Registrar;

use OCA\OpenRegister\AppHost\Bootstrap;

/**
 * THE ONE FILE THIS FIXTURE PAIR DIFFERS BY. Its sibling
 * `delegated-registrar-absent/` is byte-identical apart from this file not
 * existing, and there gate-14 must report all four routes.
 */
class AppHostRegistrar
{
    public function register($context): void
    {
        Bootstrap::register($context, 'fixture', ['namespace' => 'OCA\\Fixture']);
    }
}
