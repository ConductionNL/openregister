<?php
/**
 * TaskGaugeInterface: the task gauge OpenRegister's metrics provider joins.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Integrations
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Integrations;

use OCA\OpenRegister\AppHost\IMetricsProvider;

/**
 * The task samples OpenRegister's metrics provider puts first in its answer.
 *
 * Integrations joins the gauges under the app's provider alias; Processes keeps
 * the tasks and binds its task metrics provider in its own wiring.
 *
 * @spec openspec/changes/production-observability
 */
interface TaskGaugeInterface extends IMetricsProvider {
}//end interface
