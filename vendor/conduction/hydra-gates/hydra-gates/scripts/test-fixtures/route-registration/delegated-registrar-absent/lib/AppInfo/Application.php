<?php
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Fixture\AppInfo;

/**
 * Byte-identical to `delegated-registrar/` except that NOTHING in this app
 * calls \OCA\OpenRegister\AppHost\Bootstrap::register(). The four absent
 * controllers are therefore genuinely absent, and every one of them must be
 * reported. This is the control that stops #237's fix from becoming
 * "an absent controller is fine".
 */
class Application
{
    public function register($context): void
    {
    }
}
