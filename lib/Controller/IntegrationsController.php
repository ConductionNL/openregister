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

use OCA\OpenRegister\Controller\Support\IntegrationsControllerServices;
use OCA\OpenRegister\Service\Integration\IntegrationAdminView;
use OCA\OpenRegister\Service\Integration\IntegrationProvider;
use OCA\OpenRegister\Service\Integration\IntegrationCatalogueInterface;
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
class IntegrationsController extends Controller {
	/**
	 * Integration registry.
	 *
	 * @var IntegrationCatalogueInterface
	 */
	private IntegrationCatalogueInterface $registry;

	/**
	 * Current user session.
	 *
	 * @var IUserSession
	 */
	private IUserSession $userSession;

	/**
	 * Group manager (admin check).
	 *
	 * @var IGroupManager
	 */
	private IGroupManager $groupManager;

	/**
	 * Logger.
	 *
	 * @var LoggerInterface
	 */
	private LoggerInterface $logger;

	/**
	 * Constructor.
	 *
	 * @param string $appName App name (injected by NC).
	 * @param IRequest $request Current request.
	 * @param IntegrationsControllerServices $services The controller's collaborators.
	 *
	 * @return void
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		IntegrationsControllerServices $services,
	) {
		parent::__construct(appName: $appName, request: $request);
		$this->registry = $services->registry;
		$this->userSession = $services->userSession;
		$this->groupManager = $services->groupManager;
		$this->logger = $services->logger;
	}//end __construct()

	/**
	 * GET /api/integrations
	 *
	 * Optional query params:
	 *   - group=<name>     filter by named group
	 *   - enabled=true     filter to isEnabled() === true
	 *
	 * @return JSONResponse JSON list of integration descriptors.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-11
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		try {
			return new JSONResponse(['integrations' => $this->listedRows()], Http::STATUS_OK);
		} catch (\Throwable $e) {
			$this->logger->error('[IntegrationsController] index failed', ['exception' => $e]);
			return new JSONResponse(
				['message' => 'Failed to list integrations'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}
	}//end index()

	/**
	 * The descriptors of the registered providers the request's filters keep.
	 *
	 * @return array<int, array<string, mixed>> One descriptor per listed provider
	 */
	private function listedRows(): array {
		$providers = $this->registry->list();
		$isAdmin = $this->currentUserIsAdmin();

		$rows = [];
		foreach ($providers as $provider) {
			if ($this->isListed(provider: $provider) === true) {
				$rows[] = $this->describe(provider: $provider, isAdmin: $isAdmin);
			}
		}

		return $rows;
	}//end listedRows()

	/**
	 * Whether a provider passes the `enabled` and `group` filters of the request.
	 *
	 * @param IntegrationProvider $provider The provider
	 *
	 * @return bool True when the provider is listed
	 */
	private function isListed(IntegrationProvider $provider): bool {
		if ($this->request->getParam('enabled') === 'true' && $provider->isEnabled() === false) {
			return false;
		}

		$group = $this->request->getParam('group');

		return is_string($group) === false || $provider->getGroup() === $group;
	}//end isListed()

	/**
	 * GET /api/integrations/{id}
	 *
	 * @param string $id Integration id (e.g. 'files', 'xwiki').
	 *
	 * @return JSONResponse Single descriptor or 404.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-11
	 */
	#[NoAdminRequired]
	public function show(string $id): JSONResponse {
		$provider = $this->registry->get($id);
		if ($provider === null) {
			return new JSONResponse(
				['message' => "Integration '$id' not registered"],
				Http::STATUS_NOT_FOUND
			);
		}

		return new JSONResponse(
			$this->describe(provider: $provider, isAdmin: $this->currentUserIsAdmin()),
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
	 * @param bool $isAdmin Whether to include
	 *                      admin-only fields.
	 *
	 * @return array<string,mixed>
	 */
	private function describe(IntegrationProvider $provider, bool $isAdmin): array {
		$row = [
			'id' => $provider->getId(),
			'label' => $provider->getLabel(),
			'icon' => $provider->getIcon(),
			'group' => $provider->getGroup(),
			'enabled' => $provider->isEnabled(),
			'storageStrategy' => $provider->getStorageStrategy(),
			// Widget surfaces are declared on the JS side (per AD-6);
			// the PHP descriptor lists the contract-known ones so
			// clients can feature-detect without a second round-trip.
			'surfaces' => ['user-dashboard', 'app-dashboard', 'detail-page', 'single-entity'],
		];

		if ($isAdmin === false) {
			return $row;
		}

		return IntegrationAdminView::withAdminFields(row: $row, provider: $provider);
	}//end describe()

	/**
	 * Check whether the current user is in the admin group.
	 *
	 * @return bool
	 */
	private function currentUserIsAdmin(): bool {
		return IntegrationAdminView::callerIsAdmin(userSession: $this->userSession, groupManager: $this->groupManager);
	}//end currentUserIsAdmin()
}//end class
