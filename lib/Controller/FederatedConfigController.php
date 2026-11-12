<?php

/**
 * The federated configuration sharing API.
 *
 * The surface a client (or the builder UI) uses to share configuration over
 * GitHub: list the shareable types every app has contributed, package a
 * selection into a portable bundle, and install a bundle — the last governed by
 * the organisation's source allowlist.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\Config\FederatedConfigAccess;
use OCA\OpenRegister\Service\Config\FederatedConfigService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCA\OpenRegister\Service\Rbac\SettingsChangeAuditor;
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;
use OCP\IRequest;
use OCP\IUserSession;
use Throwable;
use UnexpectedValueException;

/**
 * REST surface for sharing, bundling and installing configuration.
 */
class FederatedConfigController extends Controller {

	/**
	 * The app its user preferences live under.
	 */
	private const APP_ID = 'openregister';

	/**
	 * User-preference key holding the chosen store GitHub credential (written by
	 * the nc-vue Configuration store settings pane via /api/preferences).
	 */
	private const CREDENTIAL_PREF = 'pref_federated-config-credential';

	/**
	 * Constructor.
	 *
	 * @param string $appName The app id.
	 * @param IRequest $request The request.
	 * @param FederatedConfigService $service The federation engine.
	 * @param FederatedConfigAccess $access Per-org publish/install gating.
	 * @param IUserSession $userSession The current user.
	 * @param IConfig $config Reads the user's chosen store credential.
	 * @param IGroupManager $groupManager Gates the trust-governance endpoints to admins.
	 * @param SettingsChangeAuditor|null $settingsAuditor Records trust-configuration changes on the audit trail.
	 * @param LoggerInterface|null       $logger          Logs install refusals and trust changes.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly FederatedConfigService $service,
		private readonly FederatedConfigAccess $access,
		private readonly IUserSession $userSession,
		private readonly IConfig $config,
		private readonly IGroupManager $groupManager,
		private readonly ?SettingsChangeAuditor $settingsAuditor = null,
		private readonly ?LoggerInterface $logger = null,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * The shareable configuration types every app has contributed.
	 *
	 * @return JSONResponse `{types: [{id, name, topic}]}`.
	 *
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
	 */
	#[NoCSRFRequired]
	public function types(): JSONResponse {
		return new JSONResponse(['types' => $this->service->types()]);
	}//end types()

	/**
	 * Package a selection of a type's configuration into a portable bundle.
	 *
	 * Gated on `canPublish`, exactly like `publish()`. A bundle IS the payload
	 * `publish()` pushes out — the same `serialise()` call, the same bytes,
	 * minus the GitHub round-trip. Gating one and not the other made the
	 * publish gate decorative: anyone refused by `canPublish` could call
	 * `bundle` and receive the identical export to do what they liked with.
	 *
	 * @return JSONResponse The bundle, 403 when the caller may not publish, or a 4xx.
	 *
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
	 */
	#[NoCSRFRequired]
	public function bundle(): JSONResponse {
		if ($this->access->canPublish(user: $this->userSession->getUser()) === false) {
			return new JSONResponse(['error' => 'You are not allowed to package configuration for sharing.'], Http::STATUS_FORBIDDEN);
		}

		$type = trim((string)$this->request->getParam('type', ''));
		if ($type === '') {
			return new JSONResponse(['error' => 'A bundle needs a type.'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$bundle = $this->service->bundle(typeId: $type, selection: (array)$this->request->getParam('selection', []));
		} catch (UnexpectedValueException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (Throwable $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse($bundle);
	}//end bundle()

	/**
	 * Install a bundle, subject to the organisation's source allowlist.
	 *
	 * @return JSONResponse The install result, 404 unknown type, or 403 when the
	 *                      source is not allowlisted.
	 *
	 * CSRF is required: an install writes registers, schemas, objects or flows
	 * as the system. No frontend calls this route; an API client sends the
	 * `OCS-APIRequest: true` header (or a request token) to pass the check.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
	 */
	public function install(): JSONResponse {
		if ($this->access->canInstall(user: $this->userSession->getUser()) === false) {
			$this->logger?->warning(
				'[FederatedConfig] Refused a bundle install: caller is not in the install groups',
				['actor' => $this->actor(), 'type' => (string)$this->request->getParam('type', '')]
			);
			return new JSONResponse(['error' => 'You are not allowed to install shared configuration.'], Http::STATUS_FORBIDDEN);
		}

		$type = trim((string)$this->request->getParam('type', ''));
		if ($type === '') {
			return new JSONResponse(['error' => 'An install needs a type.'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$result = $this->service->install(
				typeId: $type,
				bundle: (array)$this->request->getParam('bundle', []),
				source: trim((string)$this->request->getParam('source', ''))
			);
		} catch (UnexpectedValueException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (NotAuthorizedException $e) {
			return new JSONResponse(['error' => 'This bundle would change a register or schema you may not manage.'], Http::STATUS_FORBIDDEN);
		} catch (Throwable $e) {
			// A blocked source, an untrusted key, or a bad signature is a 403;
			// anything else a 500.
			$status = Http::STATUS_INTERNAL_SERVER_ERROR;
			$message = $e->getMessage();
			if (str_contains($message, 'allowlist') === true
				|| str_contains($message, 'trusted') === true
				|| str_contains($message, 'signature') === true
			) {
				$status = Http::STATUS_FORBIDDEN;
			}

			return new JSONResponse(['error' => $message], $status);
		}//end try

		return new JSONResponse($result);
	}//end install()

	/**
	 * Publish a selection to a GitHub repository, using the user's chosen store
	 * credential and signing the bundle.
	 *
	 * The credential is NOT taken from the request — it is the one the user
	 * selected in the Configuration store settings pane — so a caller can never
	 * publish with a credential they did not choose.
	 *
	 * @return JSONResponse `{published, path, status}`, or a 4xx.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
	 */
	#[NoCSRFRequired]
	public function publish(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($this->access->canPublish(user: $user) === false) {
			return new JSONResponse(['error' => 'You are not allowed to publish configuration.'], Http::STATUS_FORBIDDEN);
		}

		$type = trim((string)$this->request->getParam('type', ''));
		$repo = trim((string)$this->request->getParam('repo', ''));
		$path = trim((string)$this->request->getParam('path', ''));
		if ($type === '' || $repo === '' || $path === '') {
			return new JSONResponse(['error' => 'A publish needs a type, repo and path.'], Http::STATUS_BAD_REQUEST);
		}

		$credentialId = $this->config->getUserValue($user->getUID(), self::APP_ID, self::CREDENTIAL_PREF, '');
		if ($credentialId === '') {
			return new JSONResponse(
				['error' => 'No store credential selected. Choose a GitHub credential in the Configuration store settings.'],
				Http::STATUS_BAD_REQUEST
			);
		}

		$branch = trim((string)$this->request->getParam('branch', ''));
		if ($branch === '') {
			$branch = 'main';
		}

		// A newly created store repo is public by default; `visibility: private`
		// makes it private (the token must have rights to create private repos).
		$private = (trim((string)$this->request->getParam('visibility', 'public')) === 'private');

		try {
			$result = $this->service->publish(
				typeId: $type,
				selection: (array)$this->request->getParam('selection', []),
				repo: $repo,
				path: $path,
				credentialId: $credentialId,
				branch: $branch,
				private: $private
			);
		} catch (UnexpectedValueException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (Throwable $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse($result);
	}//end publish()

	/**
	 * The organisation's trust configuration (admin only).
	 *
	 * @return JSONResponse `{sourceAllowlist, trustedKeys, publishGroups, installGroups}` or 403.
	 *
	 * @auth admin-only Returns the instance's federation trust configuration — the source
	 *       allowlist and the trusted publisher keys. The body rejects non-admins, and #2342
	 *       removed the #[NoAdminRequired] that used to contradict it; this states the posture so
	 *       "admin-only by Nextcloud default" stays distinguishable from a forgotten attribute.
	 *
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
	 */
	#[NoCSRFRequired]
	public function trust(): JSONResponse {
		if ($this->isAdmin() === false) {
			return new JSONResponse(['error' => 'Only an administrator may read the trust configuration.'], Http::STATUS_FORBIDDEN);
		}

		return new JSONResponse($this->service->getTrustConfig());
	}//end trust()

	/**
	 * Set one trust-configuration value, or trust a publisher key (admin only).
	 *
	 * Accepts either `{field, value}` (field ∈ sourceAllowlist|trustedKeys|
	 * publishGroups|installGroups) or `{trustKey}` to append a public key to the
	 * trusted-keys list.
	 *
	 * @return JSONResponse The updated trust configuration, or a 4xx.
	 *
	 * @auth admin-only Writes the federation trust configuration: it can append a public key to the
	 *       trusted-keys list, which decides whose published configuration this instance will
	 *       accept. The body rejects non-admins, and #2342 removed the #[NoAdminRequired] that used
	 *       to contradict it; this states the posture rather than leaving it to a default.
	 *       CSRF is required: a trusted key or `allowUnverifiedBundles` outlives any credential
	 *       rotation, so a forged browser request must not set one. No frontend calls this route;
	 *       an API client (Basic auth or an app password included) must send the
	 *       `OCS-APIRequest: true` header, or Nextcloud refuses the request with 412.
	 *
	 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
	 */
	public function setTrust(): JSONResponse {
		if ($this->isAdmin() === false) {
			$this->logger?->warning('[FederatedConfig] Refused a trust-configuration change: not an administrator', ['actor' => $this->actor()]);
			return new JSONResponse(['error' => 'Only an administrator may change the trust configuration.'], Http::STATUS_FORBIDDEN);
		}

		$before = $this->service->getTrustConfig();

		$trustKey = trim((string)$this->request->getParam('trustKey', ''));
		if ($trustKey !== '') {
			$this->service->trustPublisherKey(publicKey: $trustKey);
			return new JSONResponse($this->recordTrustChange(before: $before));
		}

		$field = trim((string)$this->request->getParam('field', ''));
		if ($field === '') {
			return new JSONResponse(['error' => 'A trust update needs a field or a trustKey.'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$this->service->setTrustValue(field: $field, value: (string)$this->request->getParam('value', ''));
		} catch (Throwable $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse($this->recordTrustChange(before: $before));
	}//end setTrust()

	/**
	 * Put a trust-configuration change on the audit trail and in the log.
	 *
	 * Trusted keys and `allowUnverifiedBundles` decide whose bundles this
	 * instance installs, and outlive any credential rotation, so who changed
	 * them has to be on record. Public keys only; no secret is stored here.
	 *
	 * @param array<string, string> $before The trust configuration before the write.
	 *
	 * @return array<string, string> The trust configuration after it.
	 */
	private function recordTrustChange(array $before): array {
		$after = $this->service->getTrustConfig();
		$changed = [];
		foreach (array_keys($before + $after) as $field) {
			if (($before[$field] ?? null) !== ($after[$field] ?? null)) {
				$changed[] = $field;
			}
		}

		$this->logger?->info(
			'[FederatedConfig] Trust configuration changed',
			['actor' => $this->actor(), 'fields' => $changed]
		);
		try {
			$this->settingsAuditor?->recordUpdate(app: 'openregister.federated-config', before: $before, after: $after);
		} catch (Throwable $e) {
			$this->logger?->error('[FederatedConfig] Could not record a trust change on the audit trail: ' . $e->getMessage());
		}

		return $after;
	}//end recordTrustChange()

	/**
	 * The signed-in user's id for a log line.
	 *
	 * @return string The uid, or "(no user)".
	 */
	private function actor(): string {
		return ($this->userSession->getUser()?->getUID() ?? '(no user)');
	}//end actor()

	/**
	 * Whether the current user is a Nextcloud administrator.
	 *
	 * @return boolean Whether the caller is an admin.
	 */
	private function isAdmin(): bool {
		$user = $this->userSession->getUser();
		return $user !== null && $this->groupManager->isAdmin($user->getUID());
	}//end isAdmin()

	/**
	 * Discover published bundles across GitHub by a type's discovery topic.
	 *
	 * Gated on `canInstall`, exactly like `install()` — for the reason `bundle()`
	 * is gated like `publish()`. Discovery and fetch are the two steps that
	 * PRECEDE an install, and both drive this server's GitHub client with the
	 * caller's chosen store credential. That credential is held by reference: the
	 * broker signs the request and the caller never sees the secret. Leaving
	 * these two ungated therefore handed anyone who may NOT install a
	 * credentialed GitHub proxy, which is more than the install they were
	 * refused. Orgs that have not curated `federated_config_install_groups` are
	 * unaffected — an empty list still means "any signed-in user".
	 *
	 * @return JSONResponse `{results: [...]}`, or 403 when the caller may not install.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function discover(): JSONResponse {
		if ($this->access->canInstall(user: $this->userSession->getUser()) === false) {
			return new JSONResponse(['error' => 'You are not allowed to browse shared configuration.'], Http::STATUS_FORBIDDEN);
		}

		$topic = trim((string)$this->request->getParam('topic', ''));
		if ($topic === '') {
			return new JSONResponse(['error' => 'Discovery needs a topic.'], Http::STATUS_BAD_REQUEST);
		}

		// An authenticated search (higher rate limit) uses the user's chosen
		// store credential when they have one; otherwise it is anonymous.
		$credentialId = null;
		$user = $this->userSession->getUser();
		if ($user !== null) {
			$stored = $this->config->getUserValue($user->getUID(), self::APP_ID, self::CREDENTIAL_PREF, '');
			if ($stored !== '') {
				$credentialId = $stored;
			}
		}

		return new JSONResponse(['results' => $this->service->discover(topic: $topic, credentialId: $credentialId)]);
	}//end discover()

	/**
	 * Fetch a published bundle file from a GitHub repo (the bridge from discover
	 * to install).
	 *
	 * Gated on `canInstall` for the reason given on `discover()`: this method
	 * takes a caller-supplied `repo` and `path` and has the broker sign the
	 * request with the caller's store credential, so ungated it is an arbitrary
	 * credentialed GitHub read for anyone the install gate would have refused.
	 *
	 * @return JSONResponse The decoded bundle, 403 when the caller may not install, or a 4xx.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function fetch(): JSONResponse {
		if ($this->access->canInstall(user: $this->userSession->getUser()) === false) {
			return new JSONResponse(['error' => 'You are not allowed to fetch shared configuration.'], Http::STATUS_FORBIDDEN);
		}

		$repo = trim((string)$this->request->getParam('repo', ''));
		$path = trim((string)$this->request->getParam('path', ''));
		if ($repo === '' || $path === '') {
			return new JSONResponse(['error' => 'A fetch needs a repo and a path.'], Http::STATUS_BAD_REQUEST);
		}

		$credentialId = null;
		$user = $this->userSession->getUser();
		if ($user !== null) {
			$stored = $this->config->getUserValue($user->getUID(), self::APP_ID, self::CREDENTIAL_PREF, '');
			if ($stored !== '') {
				$credentialId = $stored;
			}
		}

		try {
			$bundle = $this->service->fetchBundle(repo: $repo, path: $path, credentialId: $credentialId);
		} catch (Throwable $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(['bundle' => $bundle]);
	}//end fetch()

	/**
	 * This instance's signing public key, for other orgs to add to their trust list.
	 *
	 * @return JSONResponse `{publicKey}`.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function publicKey(): JSONResponse {
		return new JSONResponse(['publicKey' => $this->service->publicKey()]);
	}//end publicKey()
}//end class
