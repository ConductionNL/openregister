<?php
/**
 * MetricsRetentionInterface: cutting operational metrics to their retention.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

/**
 * Removes operational metrics past their retention.
 *
 * Implemented by \OCA\OpenRegister\Search\Service\MetricsService.
 */
interface MetricsRetentionInterface
{
    /**
     * Delete metrics older than the retention.
     *
     * @param int $retentionDays Days a metric is kept.
     *
     * @return int The number of rows deleted.
     */
    public function cleanOldMetrics(int $retentionDays = 90): int;
}//end interface
