<?php

/**
 * IntegrationsCapability — surface the integration registry through
 * Nextcloud's `/ocs/v2.php/cloud/capabilities` endpoint.
 *
 * Per AD-17 the capabilities block is role-redacted: every
 * authenticated user gets the public surface (id, label, group,
 * enabled, surfaces); admins additionally receive operational
 * fields (requiresPermission, authStatus, openConnectorSource).
 * Absence of an admin field for a non-admin caller is
 * indistinguishable from "not configured".
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Capabilities
 * @package  OCA\OpenRegister\Capabilities
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/pluggable-integration-registry/tasks.md#task-21
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Capabilities;

use OCA\OpenRegister\Service\Integration\IntegrationProvider;
use OCA\OpenRegister\Service\Integration\IntegrationRegistry;
use OCP\Capabilities\ICapability;
use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * OCS capability provider for the integration registry.
 */
class IntegrationsCapability implements ICapability
{

    /**
     * Constructor.
     *
     * @param IntegrationRegistry $registry     Integration registry.
     * @param IUserSession        $userSession  Current user session.
     * @param IGroupManager       $groupManager Group manager (admin check).
     *
     * @return void
     */
    public function __construct(
        private IntegrationRegistry $registry,
        private IUserSession $userSession,
        private IGroupManager $groupManager,
    ) {
    }//end __construct()

    /**
     * @inheritDoc
     *
     * @return array<string,mixed>
     */
    public function getCapabilities(): array
    {
        $isAdmin = $this->currentUserIsAdmin();
        $rows    = [];

        foreach ($this->registry->list() as $provider) {
            $rows[] = $this->describe($provider, $isAdmin);
        }

        return [
            'openregister' => [
                'integrations' => [
                    'version'      => 1,
                    'providers'    => $rows,
                ],
            ],
        ];
    }//end getCapabilities()

    /**
     * Build the role-redacted descriptor for one provider.
     *
     * @param IntegrationProvider $provider Provider.
     * @param bool                $isAdmin  Whether the caller is admin.
     *
     * @return array<string,mixed>
     */
    private function describe(IntegrationProvider $provider, bool $isAdmin): array
    {
        $row = [
            'id'              => $provider->getId(),
            'label'           => $provider->getLabel(),
            'group'           => $provider->getGroup(),
            'enabled'         => $provider->isEnabled(),
            'storageStrategy' => $provider->getStorageStrategy(),
            'surfaces'        => ['user-dashboard', 'app-dashboard', 'detail-page', 'single-entity'],
        ];

        if ($isAdmin === false) {
            return $row;
        }

        $row['requiresPermission']  = $provider->requiresPermission();
        $row['openConnectorSource'] = $provider->getOpenConnectorSource();

        try {
            $row['authStatus'] = $provider->health();
        } catch (\Throwable $e) {
            $row['authStatus'] = [
                'status'     => 'unavailable',
                'authStatus' => 'unknown',
                'message'    => 'Provider health check threw',
            ];
        }

        return $row;
    }//end describe()

    /**
     * Check whether the current user is in the admin group.
     *
     * @return bool
     */
    private function currentUserIsAdmin(): bool
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return false;
        }

        return $this->groupManager->isAdmin($user->getUID());
    }//end currentUserIsAdmin()

}//end class
