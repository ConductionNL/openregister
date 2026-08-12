<?php

/**
 * OpenRegister — Declared Group Reconciler
 *
 * Sweeps every declared RBAC group on the instance and creates the ones that do
 * not exist. The temporal peer of import-time provisioning: the importer covers
 * "a configuration was just installed", this covers everything else.
 *
 * It exists because import-time provisioning alone inherits each leaf app's
 * repair-hook wiring, and that wiring is frequently wrong. Nextcloud runs
 * `migrateSchemaOnly()` on a FIRST install — `<post-migration>` repair steps
 * never run and never will, and `<install>` is the only unconditional hook
 * (see \OC\Installer::installAppLastSteps). Six fleet apps declare no
 * `<install>` block at all, so their `InitializeSettings` register import — and
 * with it any group provisioning riding on it — silently does nothing on a fresh
 * instance, which is precisely when the groups do not yet exist.
 *
 * The sweep reads the LIVE registers and schemas rather than the shipped files,
 * so it also covers virtual OpenBuild apps that ship no `register.json`, and
 * restores a group an administrator deleted by hand.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Authorization
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

namespace OCA\OpenRegister\Service\Authorization;

use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reconciles declared RBAC groups into Nextcloud on a schedule.
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
class GroupReconciler
{

    /**
     * App-config key prefix under which each app's declared group list is stored
     * by {@see \OCA\OpenRegister\Service\Configuration\ImportHandler}.
     *
     * @var string
     */
    public const DECLARED_GROUPS_PREFIX = 'declared_groups_';

    /**
     * Constructor.
     *
     * @param RegisterMapper     $registerMapper Live register lookup.
     * @param SchemaMapper       $schemaMapper   Live schema lookup.
     * @param IAppConfig         $appConfig      Stores each app's declared group list.
     * @param RbacGroupCollector $collector      Declared-group extraction.
     * @param GroupProvisioner   $provisioner    Create-only group provisioning.
     * @param LoggerInterface    $logger         PSR logger.
     */
    public function __construct(
        private readonly RegisterMapper $registerMapper,
        private readonly SchemaMapper $schemaMapper,
        private readonly IAppConfig $appConfig,
        private readonly RbacGroupCollector $collector,
        private readonly GroupProvisioner $provisioner,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Run one reconciliation sweep.
     *
     * Never throws — this runs from a background job, where an exception would
     * abort the whole tick and take unrelated work with it.
     *
     * @return array{declared: string[], created: string[]} What was declared and what was created.
     *
     * @spec openspec/specs/rbac-scopes/spec.md
     */
    public function reconcile(): array
    {
        try {
            $declared = $this->collectDeclared();
            if (empty($declared) === true) {
                return [
                    'declared' => [],
                    'created'  => [],
                ];
            }

            $result = $this->provisioner->provision(groups: $declared, declaredBy: 'group-reconciler');

            return [
                'declared' => $declared,
                'created'  => $result['created'],
            ];
        } catch (Throwable $e) {
            $this->logger->error(
                message: '[GroupReconciler] sweep failed: '.$e->getMessage(),
                context: ['exception' => $e]
            );

            return [
                'declared' => [],
                'created'  => [],
            ];
        }//end try
    }//end reconcile()

    /**
     * Collect every declared group id on the instance.
     *
     * Unions three sources: live register authorization, live schema (and
     * property) authorization, and each app's stored declaration — the last of
     * which carries authored scopes for groups no authorization block references
     * yet.
     *
     * @return string[] Provisionable group ids.
     *
     * @spec openspec/specs/rbac-scopes/spec.md
     */
    private function collectDeclared(): array
    {
        $groups = array_merge(
            $this->fromLiveRegisters(),
            $this->fromLiveSchemas(),
            $this->fromStoredDeclarations()
        );

        return $this->collector->provisionable(groups: $groups);
    }//end collectDeclared()

    /**
     * Collect groups from every register's authorization block.
     *
     * RBAC and multi-tenancy filtering are explicitly DISABLED. This runs from
     * cron with no logged-in user and no active organisation, and a filtered
     * `findAll()` would hand back a short list — or an empty one — making the
     * sweep report a clean pass over registers it never read.
     *
     * @return string[] Group ids (unfiltered).
     *
     * @spec openspec/specs/rbac-scopes/spec.md
     */
    private function fromLiveRegisters(): array
    {
        $groups = [];
        foreach ($this->registerMapper->findAll(_rbac: false, _multitenancy: false) as $register) {
            $groups = array_merge(
                $groups,
                $this->collector->fromAuthorizationBlock(authorization: $register->getAuthorization())
            );
        }

        return $groups;
    }//end fromLiveRegisters()

    /**
     * Collect groups from every schema's authorization block and property rules.
     *
     * RBAC and multi-tenancy filtering are disabled for the same reason as
     * {@see self::fromLiveRegisters()}.
     *
     * @return string[] Group ids (unfiltered).
     *
     * @spec openspec/specs/rbac-scopes/spec.md
     */
    private function fromLiveSchemas(): array
    {
        $groups = [];
        foreach ($this->schemaMapper->findAll(_rbac: false, _multitenancy: false) as $schema) {
            $groups = array_merge(
                $groups,
                $this->collector->fromSchemaDefinition(
                    schemaDefinition: [
                        'authorization' => $schema->getAuthorization(),
                        'properties'    => $schema->getProperties(),
                    ]
                )
            );
        }

        return $groups;
    }//end fromLiveSchemas()

    /**
     * Collect groups from each app's stored declaration.
     *
     * Covers authored scopes and virtual OpenBuild apps, whose declaration is
     * never readable from disk.
     *
     * @return string[] Group ids (unfiltered).
     *
     * @spec openspec/specs/rbac-scopes/spec.md
     */
    private function fromStoredDeclarations(): array
    {
        $groups = [];
        foreach ($this->appConfig->getKeys('openregister') as $key) {
            if (str_starts_with($key, self::DECLARED_GROUPS_PREFIX) === false) {
                continue;
            }

            $stored = json_decode($this->appConfig->getValueString('openregister', $key, '[]'), true);
            if (is_array($stored) === false) {
                continue;
            }

            $groups = array_merge($groups, $stored);
        }

        return $groups;
    }//end fromStoredDeclarations()
}//end class
