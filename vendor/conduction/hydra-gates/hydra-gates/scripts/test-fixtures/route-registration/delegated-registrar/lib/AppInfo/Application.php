<?php
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Fixture\AppInfo;

use OCA\Fixture\AppInfo\Registrar\AppHostRegistrar;

/**
 * Application.php contains NO Bootstrap::register() call. phpmd complains
 * about a long register(), the app decomposes it into per-concern
 * registrars, and the AppHost call moves one file down — procest#717.
 */
class Application
{
    public function register($context): void
    {
        (new AppHostRegistrar())->register(context: $context);
    }
}
