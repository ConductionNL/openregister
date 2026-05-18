<?php

/**
 * IntegrationsController — read-only discovery surface for the pluggable
 * integration registry.
 *
 * GET /api/integrations            — list every registered integration
 * GET /api/integrations/{id}       — single integration descriptor
 *
 * Read-only by design — the registry advertises what's available; CRUD
 * on a specific provider's linked things happens at
 * /api/objects/{register}/{schema}/{id}/integrations/{integrationId}
 * (added in task 4.2). Mutating the registry itself is not a
 * runtime operation — providers register at app boot via DI.
 *
 * Authentication: every endpoint is `#[NoAdminRequired]` since
 * non-admin users still need to discover which tabs / widgets render
 * on their sidebar / dashboard. Sensitive fields (auth status,
 * requiresPermission, openConnectorSource) are role-redacted per
 * AD-17 — only admins see them.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
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
 * @spec openspec/changes/pluggable-integration-registry/tasks.md#task-18
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Service\Integration\IntegrationProvider;
use OCA\OpenRegister\Service\Integration\IntegrationRegistry;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Public read-only API over the integration registry.
 */
class IntegrationsController extends Controller
{

    /**
     * Constructor.
     *
     * @param string              $appName      App name (injected by NC).
     * @param IRequest            $request      Current request.
     * @param IntegrationRegistry $registry     Integration registry.
     * @param IUserSession        $userSession  Current user session.
     * @param IGroupManager       $groupManager Group manager (admin check).
     * @param LoggerInterface     $logger       Logger.
     *
     * @return void
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private IntegrationRegistry $registry,
        private IUserSession $userSession,
        private IGroupManager $groupManager,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }//end __construct()

    /**
     * GET /api/integrations
     *
     * Optional query params:
     *   - group=<name>     filter by named group
     *   - enabled=true     filter to isEnabled() === true
     *
     * @return JSONResponse JSON list of integration descriptors.
     */
    #[NoAdminRequired]
    public function index(): JSONResponse
    {
        $group   = $this->request->getParam('group');
        $enabled = $this->request->getParam('enabled');

        try {
            $providers = $this->registry->list();
            $isAdmin   = $this->currentUserIsAdmin();

            $rows = [];
            foreach ($providers as $provider) {
                if ($enabled === 'true' && $provider->isEnabled() === false) {
                    continue;
                }

                if (is_string($group) === true && $provider->getGroup() !== $group) {
                    continue;
                }

                $rows[] = $this->describe($provider, $isAdmin);
            }

            return new JSONResponse(['integrations' => $rows], Http::STATUS_OK);
        } catch (\Throwable $e) {
            $this->logger->error('[IntegrationsController] index failed', ['exception' => $e]);
            return new JSONResponse(
                ['message' => 'Failed to list integrations'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end index()

    /**
     * GET /api/integrations/{id}
     *
     * @param string $id Integration id (e.g. 'files', 'xwiki').
     *
     * @return JSONResponse Single descriptor or 404.
     */
    #[NoAdminRequired]
    public function show(string $id): JSONResponse
    {
        $provider = $this->registry->get($id);
        if ($provider === null) {
            return new JSONResponse(
                ['message' => "Integration '$id' not registered"],
                Http::STATUS_NOT_FOUND
            );
        }

        return new JSONResponse(
            $this->describe($provider, $this->currentUserIsAdmin()),
            Http::STATUS_OK
        );
    }//end show()

    /**
     * Build the JSON descriptor for one provider.
     *
     * Public fields (every user): id, label, icon, group, enabled,
     * storageStrategy, surfaces.
     * Admin-only fields: requiresPermission, authStatus,
     * openConnectorSource. Per AD-17 the admin fields are OMITTED
     * (not null-stubbed) when the caller isn't admin so absence
     * matches non-existence.
     *
     * @param IntegrationProvider $provider Provider to describe.
     * @param bool                $isAdmin  Whether to include
     *                                      admin-only fields.
     *
     * @return array<string,mixed>
     */
    private function describe(IntegrationProvider $provider, bool $isAdmin): array
    {
        $row = [
            'id'              => $provider->getId(),
            'label'           => $provider->getLabel(),
            'icon'            => $provider->getIcon(),
            'group'           => $provider->getGroup(),
            'enabled'         => $provider->isEnabled(),
            'storageStrategy' => $provider->getStorageStrategy(),
            // Widget surfaces are declared on the JS side (per AD-6);
            // the PHP descriptor lists the contract-known ones so
            // clients can feature-detect without a second round-trip.
            'surfaces'        => ['user-dashboard', 'app-dashboard', 'detail-page', 'single-entity'],
        ];

        if ($isAdmin === false) {
            return $row;
        }

        // Admin-only operational surface.
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
