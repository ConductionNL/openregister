<?php

/**
 * OpenRegister — Declared Group Provisioner
 *
 * Creates the Nextcloud groups an app declares through its OAS-shaped
 * configuration, so a group named in an `authorization` block always EXISTS and
 * is assignable in Nextcloud's user admin.
 *
 * Provisioning is create-only and idempotent. It never deletes a group and never
 * adds a member: deleting a group destroys its memberships and shares, and
 * seeding members would silently grant access nobody asked for. A provisioned
 * group therefore starts EMPTY — which denies everyone until an administrator
 * populates it. That is deliberate, but it means the empty state has to be
 * visible rather than silent; see {@see self::inventory()}.
 *
 * Why this exists: {@see \OCA\OpenRegister\Service\Object\PermissionHandler::hasGroupPermission()}
 * resolves access by MEMBERSHIP test alone. A group that was never created is
 * indistinguishable from a group nobody belongs to — both deny every caller,
 * silently and with no error anywhere. A typo in an authorization block reads
 * exactly like a working access control.
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

use OCP\IGroupManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Provisions declared RBAC groups into Nextcloud, create-only.
 *
 * @spec openspec/changes/declared-group-provisioning/specs/rbac-scopes/spec.md
 */
class GroupProvisioner
{
    /**
     * Constructor.
     *
     * @param IGroupManager   $groupManager Nextcloud group existence + creation.
     * @param LoggerInterface $logger       PSR logger.
     */
    public function __construct(
        private readonly IGroupManager $groupManager,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Create every declared group that does not yet exist.
     *
     * Each group is handled independently: one failure (a backend that refuses
     * creation, an LDAP-backed read-only group backend) is logged and skipped so
     * the remaining declarations still land. Never throws — provisioning runs
     * inside imports and background jobs where a hard failure would take down
     * work that is otherwise complete.
     *
     * @param string[] $groups     Provisionable group ids (already filtered by RbacGroupCollector).
     * @param string   $declaredBy The declaring app id, for log attribution.
     *
     * @return array{created: string[], existing: string[], failed: string[]} What happened, per group.
     *
     * @spec openspec/changes/declared-group-provisioning/specs/rbac-scopes/spec.md
     */
    public function provision(array $groups, string $declaredBy): array
    {
        $result = [
            'created'  => [],
            'existing' => [],
            'failed'   => [],
        ];

        foreach ($groups as $group) {
            try {
                if ($this->groupManager->groupExists($group) === true) {
                    $result['existing'][] = $group;
                    continue;
                }

                $this->groupManager->createGroup($group);
                $result['created'][] = $group;
            } catch (Throwable $e) {
                $result['failed'][] = $group;
                $this->logger->error(
                    message: sprintf(
                        '[GroupProvisioner] could not create group "%s" declared by "%s": %s',
                        $group,
                        $declaredBy,
                        $e->getMessage()
                    ),
                    context: ['exception' => $e]
                );
            }//end try
        }//end foreach

        if (empty($result['created']) === false) {
            $this->logger->info(
                message: sprintf(
                    '[GroupProvisioner] created %d declared group(s) for "%s": %s — each starts EMPTY and '
                    .'therefore denies every caller until an administrator adds members',
                    count($result['created']),
                    $declaredBy,
                    implode(', ', $result['created'])
                )
            );
        }

        return $result;
    }//end provision()

    /**
     * Report the live membership state of a set of declared groups.
     *
     * Feeds the admin surface that makes a declared-but-unpopulated group
     * visible. A zero count is the actionable case: the group exists, RBAC
     * consults it, and it grants nobody anything.
     *
     * `members` is null, NOT zero, when the count is UNKNOWN — {@see \OCP\IGroup::count()}
     * returns `int|bool` and hands back `false` on a backend that cannot count
     * (some LDAP configurations). Collapsing that to 0 would report a fully
     * populated group as empty and raise exactly the false alarm this surface
     * exists to prevent.
     *
     * @param string[] $groups Declared group ids.
     *
     * @return array<string, array{exists: bool, members: int|null}> Keyed by group id; members null = unknown.
     *
     * @spec openspec/changes/declared-group-provisioning/specs/rbac-scopes/spec.md
     */
    public function inventory(array $groups): array
    {
        $inventory = [];
        foreach ($groups as $group) {
            $resolved = $this->groupManager->get($group);
            if ($resolved === null) {
                $inventory[$group] = [
                    'exists'  => false,
                    'members' => null,
                ];
                continue;
            }

            $count   = $resolved->count();
            $members = null;
            if (is_int($count) === true) {
                $members = $count;
            }

            $inventory[$group] = [
                'exists'  => true,
                'members' => $members,
            ];
        }//end foreach

        return $inventory;
    }//end inventory()
}//end class
