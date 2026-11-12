<?php

/**
 * OpenRegister's own task metrics provider.
 *
 * ObservabilityRegistrar (lib/AppHost) resolves this interface for the per-app
 * IMetricsProvider alias; ContractBindings aliases it to TaskMetricsProvider.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Task
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Task;

use OCA\OpenRegister\AppHost\IMetricsProvider;

/**
 * OpenRegister's own task metrics provider.
 */
interface TaskMetricsProviderInterface extends IMetricsProvider {
}//end interface
