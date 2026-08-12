<?php

/**
 * OpenRegister — Declared Group Reconciler Job
 *
 * An hourly `TimedJob` driving {@see GroupReconciler}: each tick it creates any
 * Nextcloud group that a register, schema, property or stored app declaration
 * names but that does not exist.
 *
 * Hourly rather than per-minute: unlike the schedule reconciler this drives no
 * execution, and the set it diffs only changes when a configuration is imported
 * or an administrator deletes a group. The import path already provisions
 * immediately, so this is the safety net, not the primary route.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use OCA\OpenRegister\Service\Authorization\GroupReconciler;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * TimedJob that provisions declared RBAC groups.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/declared-group-provisioning/specs/rbac-scopes/spec.md
 */
class GroupReconcilerJob extends TimedJob
{
    /**
     * Constructor for GroupReconcilerJob.
     *
     * @param ITimeFactory    $time       Time factory.
     * @param GroupReconciler $reconciler The declared-group reconciler.
     * @param LoggerInterface $logger     Logger.
     */
    public function __construct(
        ITimeFactory $time,
        private readonly GroupReconciler $reconciler,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct(time: $time);
        $this->setInterval(seconds: 3600);
    }//end __construct()

    /**
     * Execute one reconciliation sweep.
     *
     * @param mixed $argument Job argument (unused for TimedJob).
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     *
     * @spec openspec/changes/declared-group-provisioning/specs/rbac-scopes/spec.md
     */
    protected function run($argument): void
    {
        try {
            $this->reconciler->reconcile();
        } catch (Throwable $e) {
            // Defence in depth — reconcile() already never throws.
            $this->logger->error(
                message: '[GroupReconcilerJob] sweep failed: '.$e->getMessage(),
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
        }
    }//end run()
}//end class
