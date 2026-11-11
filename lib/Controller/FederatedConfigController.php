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

use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Controller\Support\ConfigPublishRequest;
use OCA\OpenRegister\Controller\Support\ControllerSession;
use OCA\OpenRegister\Controller\Support\FederatedConfigControllerServices;
use OCA\OpenRegister\Service\Config\ConfigPublishTarget;
use OCA\OpenRegister\Service\Config\FederatedConfigAccessInterface;
use OCA\OpenRegister\Service\Config\FederatedConfigServiceInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUser;
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
	 * Words in an install failure that make it a trust refusal (403) rather than a 500.
	 *
	 * @var list<string>
	 */
	private const FORBIDDEN_INSTALL_MARKERS = ['allowlist', 'trusted', 'signature', 'crossOrganisation'];

	/**
	 * The user session.
	 *
	 * @var IUserSession
	 */
	private readonly IUserSession $userSession;

	/**
	 * The federation engine.
	 *
	 * @var FederatedConfigServiceInterface
	 */
	private readonly FederatedConfigServiceInterface $service;

	/**
	 * Per-org publish/install gating.
	 *
	 * @var FederatedConfigAccessInterface
	 */
	private readonly FederatedConfigAccessInterface $access;

	/**
	 * Reads the user's chosen store credential.
	 *
	 * @var IConfig
	 */
	private readonly IConfig $config;

	/**
	 * The session bundle; the user session and the admin check come from it.
	 *
	 * @var ControllerSession
	 */
	private readonly ControllerSession $session;

	/**
	 * Constructor.
	 *
	 * @param IRequest                        $request The request.
	 * @param FederatedConfigControllerServices $services The controller's collaborators.
	 */
	public function __construct(
		IRequest $request,
		FederatedConfigControllerServices $services,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
		$this->service = $services->service;
		$this->access = $services->access;
		$this->config = $services->config;

		$this->session = $services->session;
		$this->userSession = $services->session->userSession;
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
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
	 */
	#[NoCSRFRequired]
	public function install(): JSONResponse {
		if ($this->access->canInstall(user: $this->userSession->getUser()) === false) {
			return new JSONResponse(['error' => 'You are not allowed to install shared configuration.'], Http::STATUS_FORBIDDEN);
		}

		$type = trim((string)$this->request->getParam('type', ''));
		if ($type === '') {
			return new JSONResponse(['error' => 'An install needs a type.'], Http::STATUS_BAD_REQUEST);
		}

		return $this->runInstall(type: $type);
	}//end install()

	/**
	 * Install the requested bundle of a type and map a failure to its status.
	 *
	 * @param string $type The shareable configuration type id.
	 *
	 * @return JSONResponse The install result, 404 unknown type, 403 trust failure, or 500.
	 */
	private function runInstall(string $type): JSONResponse {
		try {
			$result = $this->service->install(
				typeId: $type,
				bundle: (array)$this->request->getParam('bundle', []),
				source: trim((string)$this->request->getParam('source', ''))
			);
		} catch (UnexpectedValueException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (Throwable $e) {
			$message = $e->getMessage();
			return new JSONResponse(['error' => $message], $this->installFailureStatus(message: $message));
		}

		return new JSONResponse($result);
	}//end runInstall()

	/**
	 * The status of a failed install.
	 *
	 * A blocked source, an untrusted key, a bad signature or a crossing rule
	 * is a 403; anything else a 500.
	 *
	 * @param string $message The failure message.
	 *
	 * @return integer 403 for a trust failure, 500 otherwise.
	 */
	private function installFailureStatus(string $message): int {
		foreach (self::FORBIDDEN_INSTALL_MARKERS as $marker) {
			if (str_contains($message, $marker) === true) {
				return Http::STATUS_FORBIDDEN;
			}
		}

		return Http::STATUS_INTERNAL_SERVER_ERROR;
	}//end installFailureStatus()

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

		$publish = new ConfigPublishRequest(
			type: trim((string)$this->request->getParam('type', '')),
			target: new ConfigPublishTarget(
				repo: trim((string)$this->request->getParam('repo', '')),
				path: trim((string)$this->request->getParam('path', ''))
			)
		);
		if ($publish->isIncomplete() === true) {
			return new JSONResponse(['error' => 'A publish needs a type, repo and path.'], Http::STATUS_BAD_REQUEST);
		}

		return $this->publishWithStoredCredential(user: $user, publish: $publish);
	}//end publish()

	/**
	 * Publish with the store credential the user selected, or refuse when they chose none.
	 *
	 * @param IUser|null           $user    The publishing user.
	 * @param ConfigPublishRequest $publish The type and the repo and path to publish to.
	 *
	 * @return JSONResponse The publish result, or a 4xx/5xx.
	 */
	private function publishWithStoredCredential(?IUser $user, ConfigPublishRequest $publish): JSONResponse {
		$credentialId = $this->config->getUserValue($user->getUID(), self::APP_ID, self::CREDENTIAL_PREF, '');
		if ($credentialId === '') {
			return new JSONResponse(
				['error' => 'No store credential selected. Choose a GitHub credential in the Configuration store settings.'],
				Http::STATUS_BAD_REQUEST
			);
		}

		$target = $this->withRequestedBranchAndVisibility(target: $publish->target);
		return $this->runPublish(publish: new ConfigPublishRequest(type: $publish->type, target: $target), credentialId: $credentialId);
	}//end publishWithStoredCredential()

	/**
	 * The target with the requested branch (main when none) and visibility.
	 *
	 * A newly created store repo is public by default; `visibility: private`
	 * makes it private (the token must have rights to create private repos).
	 *
	 * @param ConfigPublishTarget $target The repo and path.
	 *
	 * @return ConfigPublishTarget The complete target.
	 */
	private function withRequestedBranchAndVisibility(ConfigPublishTarget $target): ConfigPublishTarget {
		$branch = trim((string)$this->request->getParam('branch', ''));
		if ($branch === '') {
			$branch = 'main';
		}

		$private = (trim((string)$this->request->getParam('visibility', 'public')) === 'private');
		return new ConfigPublishTarget(repo: $target->repo, path: $target->path, branch: $branch, private: $private);
	}//end withRequestedBranchAndVisibility()

	/**
	 * Publish the requested selection and map a failure to its status.
	 *
	 * @param ConfigPublishRequest $publish      The type and the complete target.
	 * @param string               $credentialId The user's chosen store credential.
	 *
	 * @return JSONResponse `{published, path, status}`, 404 unknown type, or 500.
	 */
	private function runPublish(ConfigPublishRequest $publish, string $credentialId): JSONResponse {
		try {
			$result = $this->service->publish(
				typeId: $publish->type,
				selection: (array)$this->request->getParam('selection', []),
				target: $publish->target,
				credentialId: $credentialId
			);
		} catch (UnexpectedValueException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (Throwable $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse($result);
	}//end runPublish()

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
	 *
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
	 */
	#[NoCSRFRequired]
	public function setTrust(): JSONResponse {
		if ($this->isAdmin() === false) {
			return new JSONResponse(['error' => 'Only an administrator may change the trust configuration.'], Http::STATUS_FORBIDDEN);
		}

		$trustKey = trim((string)$this->request->getParam('trustKey', ''));
		if ($trustKey !== '') {
			$this->service->trustPublisherKey(publicKey: $trustKey);
			return new JSONResponse($this->service->getTrustConfig());
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

		return new JSONResponse($this->service->getTrustConfig());
	}//end setTrust()

	/**
	 * Whether the current user is a Nextcloud administrator.
	 *
	 * @return boolean Whether the caller is an admin.
	 */
	private function isAdmin(): bool {
		return $this->session->isAdmin();
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
		$credentialId = $this->optionalStoredCredential();

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

		return $this->fetchBundleResponse(repo: $repo, path: $path);
	}//end fetch()

	/**
	 * Fetch the bundle at a repo path, with the user's stored credential when there is one.
	 *
	 * @param string $repo The GitHub repository.
	 * @param string $path The bundle's path in it.
	 *
	 * @return JSONResponse `{bundle}`, or 404 when it cannot be fetched.
	 */
	private function fetchBundleResponse(string $repo, string $path): JSONResponse {
		$credentialId = $this->optionalStoredCredential();

		try {
			$bundle = $this->service->fetchBundle(repo: $repo, path: $path, credentialId: $credentialId);
		} catch (Throwable $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(['bundle' => $bundle]);
	}//end fetchBundleResponse()

	/**
	 * The signed-in user's chosen store credential, or null when anonymous or none is chosen.
	 *
	 * @return string|null The credential id, or null.
	 */
	private function optionalStoredCredential(): ?string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return null;
		}

		$stored = $this->config->getUserValue($user->getUID(), self::APP_ID, self::CREDENTIAL_PREF, '');
		if ($stored === '') {
			return null;
		}

		return $stored;
	}//end optionalStoredCredential()

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
