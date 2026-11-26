<?php

/**
 * A controller with a recognisable short name, for the request metrics middleware test.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Middleware\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Middleware\Support;

use OCP\AppFramework\Controller;

/**
 * A controller whose route key falls back to `fakeMetrics.<method>`.
 */
final class FakeMetricsController extends Controller {
}//end class
