<?php

/**
 * SeedTablesVirtualSchemas — reconciles the `tables` virtual register on
 * install/upgrade so every Nextcloud Tables table gets a read-only virtual
 * schema.
 *
 * Follows the {@see SeedDirectoryVirtualSchemas} / {@see SeedAppVirtualSchemas}
 * convention (runs during `occ upgrade`, before peer autoloaders). It enumerates
 * the tables visible to the instance's admin users via {@see TablesTableReader}
 * and hands them to {@see TablesSchemaSyncService::reconcile()}, which seeds one
 * schema per table (deterministic slug), refreshes managed schemas, and retires
 * schemas whose table is gone. Idempotent, and a no-op when the Tables app is
 * absent (the reader fails closed to an empty descriptor set). Never throws — a
 * seed failure logs a warning and leaves the instance otherwise healthy.
 *
 * @category Repair
 * @package  OCA\OpenRegister\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/tables-virtual-register/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Repair;

use OCA\OpenRegister\Service\ObjectSource\TablesSchemaSyncService;
use OCA\OpenRegister\Service\ObjectSource\TablesTableReader;
use OCP\IGroupManager;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Seeds/reconciles the `tables` virtual register on install/upgrade.
 */
class SeedTablesVirtualSchemas implements IRepairStep
{
    /**
     * Constructor.
     *
     * @param TablesTableReader       $reader       Guarded gateway to Tables services.
     * @param TablesSchemaSyncService $syncService  Schema reconcile logic.
     * @param IGroupManager           $groupManager Admin-user enumeration.
     * @param LoggerInterface         $logger       Logger for seed diagnostics.
     *
     * @return void
     */
    public function __construct(
        private readonly TablesTableReader $reader,
        private readonly TablesSchemaSyncService $syncService,
        private readonly IGroupManager $groupManager,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Get the name of this repair step.
     *
     * @return string The step name.
     *
     * @spec openspec/specs/tables-virtual-register/spec.md
     */
    public function getName(): string
    {
        return 'Seed OpenRegister Tables virtual schemas (one per Nextcloud Tables table)';
    }//end getName()

    /**
     * Run the repair step, reconciling the `tables` register.
     *
     * @param IOutput $output Output interface for status messages.
     *
     * @return void
     *
     * @spec openspec/specs/tables-virtual-register/spec.md
     */
    public function run(IOutput $output): void
    {
        try {
            if ($this->reader->isAvailable() === false) {
                $output->info('Tables app not enabled — skipping Tables virtual schema seed');
                return;
            }

            $adminIds = $this->adminUserIds();
            $tables   = $this->reader->collectTableDescriptors(userIds: $adminIds);
            $stats    = $this->syncService->reconcile(tables: $tables);

            $output->info(
                sprintf(
                    'Tables virtual schemas reconciled: seeded=%d, retired=%d, skipped=%d',
                    $stats['seeded'],
                    $stats['retired'],
                    $stats['skipped']
                )
            );
        } catch (Throwable $e) {
            $this->logger->warning('[SeedTablesVirtualSchemas] seed failed: '.$e->getMessage());
            $output->warning('Tables virtual schema seed skipped: '.$e->getMessage());
        }//end try
    }//end run()

    /**
     * Resolve the instance's admin user ids for table enumeration.
     *
     * @return array<int, string> The admin user ids (may be empty).
     *
     * @spec openspec/specs/tables-virtual-register/spec.md
     */
    private function adminUserIds(): array
    {
        $admins = $this->groupManager->get('admin');
        if ($admins === null) {
            return [];
        }

        $ids = [];
        foreach ($admins->getUsers() as $user) {
            $ids[] = $user->getUID();
        }

        return $ids;
    }//end adminUserIds()
}//end class
