<?php

/**
 * OpenRegister AppHost — Generic Store Controller
 *
 * Engine-owned store endpoints. A leaf app aliases `/api/store/items` and
 * `/api/store/items/{slug}/install` at this controller and writes no store
 * code of its own: what its store IS gets declared as the `store` block of its
 * `src/manifest.json` (see StoreManifest).
 *
 * ADR-080 SPLIT DISCOVERY FROM INSTALL AND KEPT INSTALL PER APP. Ruben decided
 * on 2026-09-03 that the store should be provided by OpenRegister without a
 * backend per app, which amends Decision 3 of that ADR. Two things make that
 * safe rather than a re-run of what D3 rejected:
 *
 *   - D3's three worked failures are all about a cross-app `extends`, resolved
 *     by the AUTOLOADER rather than the container. This uses none: the leaf app
 *     aliases a route at a controller the engine owns, exactly as it already
 *     does for GenericHealth and GenericMetrics.
 *   - D3's other reason, that install semantics differ per app, is answered by
 *     making the difference DATA. The only thing that actually varied was which
 *     schemas an install may write.
 *
 * Auth posture is owned HERE and a leaf app cannot drift it through its
 * manifest: search is login-required, install is admin-only.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\AppHost\Controller
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

namespace OCA\OpenRegister\AppHost\Controller;

use OCA\OpenRegister\AppHost\Observability\ManifestLoader;
use OCA\OpenRegister\AppHost\Service\GenericStoreService;
use OCA\OpenRegister\AppHost\Service\StoreDescriptor;
use OCA\OpenRegister\AppHost\Store\FederatedStoreCatalog;
use OCA\OpenRegister\AppHost\Store\GenericStoreInstaller;
use OCA\OpenRegister\AppHost\Store\StoreManifest;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Declarative store search and install for AppHost-adopting apps.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/apphost-store-plane/spec.md#requirement-a-leaf-app-must-declare-its-store-rather-than-implement-one
 */
class GenericStoreController extends Controller {
	/**
	 * Kebab-case slug pattern for a remote store item. Bounds what reaches the
	 * registry URL and the install path.
	 */
	private const SLUG_PATTERN = '/^[a-z0-9][a-z0-9-]*[a-z0-9]$/';

	/**
	 * Constructor.
	 *
	 * `$appName` is the CALLING leaf app id, set by that app's alias
	 * registration, which is what lets one controller serve every app.
	 *
	 * @param string                $appName        Calling leaf app id.
	 * @param IRequest              $request        HTTP request.
	 * @param ManifestLoader        $manifestLoader Loads the leaf app's manifest.
	 * @param GenericStoreService   $storeService   Guarded remote discovery.
	 * @param GenericStoreInstaller $installer      Declarative component install.
	 * @param FederatedStoreCatalog $catalog        Configuration browse and install.
	 * @param IUserSession          $userSession    Current session.
	 * @param IGroupManager         $groupManager   Admin check for install.
	 * @param LoggerInterface       $logger         PSR logger.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ManifestLoader $manifestLoader,
		private readonly GenericStoreService $storeService,
		private readonly GenericStoreInstaller $installer,
		private readonly FederatedStoreCatalog $catalog,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Search the remote store.
	 *
	 * Login-required through an in-body guard so an anonymous caller gets an
	 * explicit 401 rather than a login redirect. Returns normalised cards and a
	 * generic outcome, NEVER the registry URL or token.
	 *
	 * With no registry configured the engine answers `not_configured` WITHOUT
	 * making a network call, which is what lets the page fall back to the app's
	 * built-in items (ADR-080 Decision 4).
	 *
	 * @return JSONResponse 200 with `{outcome, cards}`; 401 for anonymous.
	 *
	 * @no-admin-idor-exempt Addresses no object of this app. The query and kind
	 *   filter are forwarded to an EXTERNAL registry, so there is nothing of
	 *   another tenant's to reach by guessing an identifier.
	 *
	 * @spec openspec/specs/apphost-store-plane/spec.md#requirement-a-leaf-app-must-declare-its-store-rather-than-implement-one
	 */
	#[NoAdminRequired]
	public function search(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(
				data: ['outcome' => 'unauthenticated', 'cards' => []],
				statusCode: Http::STATUS_UNAUTHORIZED
			);
		}

		$store = $this->store();
		if ($store->enabled === false) {
			// The app aliased the route but declared no `store` block. Report
			// not_configured rather than 404: the page then renders its
			// (empty) built-in list instead of reading as a broken endpoint.
			return new JSONResponse(
				data: ['outcome' => GenericStoreService::OUTCOME_NOT_CONFIGURED, 'cards' => [], 'kinds' => []],
				statusCode: Http::STATUS_OK
			);
		}

		$query = $this->request->getParam('q');
		if (is_string($query) === false) {
			$query = null;
		}

		$kind = $this->request->getParam('kind');
		if (is_string($kind) === false) {
			$kind = null;
		}

		try {
			$descriptor = $this->descriptor(store: $store);

			// An app that declares shareable types exchanges CONFIGURATION, so
			// its catalogue is what publishers have published, not one remote
			// instance's rows. Selected by declaration, never by probing: an
			// app that declares none makes no discovery call at all.
			if ($descriptor->isFederated() === true) {
				$result = $this->catalog->search(descriptor: $descriptor, query: $query, kind: $kind);
			} else {
				$result = $this->storeService->search(descriptor: $descriptor, query: $query, kind: $kind);
			}
		} catch (Throwable $e) {
			// Detail to the log, generic outcome to the browser: a registry's
			// internals are not the caller's business.
			$this->logger->error(
				message: sprintf('[AppHost\\Store] search failed for %s: %s', $this->appName, $e->getMessage()),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return new JSONResponse(
				data: ['outcome' => GenericStoreService::OUTCOME_UNREACHABLE, 'cards' => [], 'kinds' => $store->kinds],
				statusCode: Http::STATUS_OK
			);
		}

		// `kinds` rides back with the cards so the page can offer the filters the
		// APP declared, rather than a copy kept in its page config. Empty when
		// the app names none, and the page falls back to the shared vocabulary.
		// A federated store's cards are discriminated by TYPE, so the declared
		// type ids are the honest filter set when the app names no kinds of its
		// own. Falling through to the shared kind vocabulary would offer chips
		// (`adapter`, `agent-template`) that match nothing on this page.
		$kinds = $store->kinds;
		if ($kinds === [] && $store->isFederated() === true) {
			$kinds = $store->declaredTypes();
		}

		return new JSONResponse(
			data: [
				'outcome' => $result['outcome'],
				'cards' => $result['cards'],
				'kinds' => $kinds,
			],
			statusCode: Http::STATUS_OK
		);
	}//end search()

	/**
	 * Install one store item into this instance.
	 *
	 * Administrative: the components written are the shape of the work every
	 * handler then operates against.
	 *
	 * The write runs as the calling administrator and NOT through
	 * `runAsSystem()`. That elevation is for operations whose inputs originate
	 * from code or the app's own seed data; a store payload is neither, it
	 * comes off the network.
	 *
	 * @param string $slug The remote item slug.
	 *
	 * @return JSONResponse 200 with a per-component report; 400 bad slug; 403 non-admin; 404 unresolved.
	 *
	 * @spec openspec/specs/apphost-store-plane/spec.md#requirement-an-install-must-refuse-every-schema-the-manifest-does-not-allow
	 */
	#[NoAdminRequired]
	public function install(string $slug): JSONResponse {
		// The admin check is an in-body guard rather than
		// #[AuthorizedAdminSetting], because that attribute names a leaf app's
		// settings CLASS and this controller serves every app. Same posture,
		// resolved at request time instead of at annotation time.
		$user = $this->userSession->getUser();
		if ($user === null || $this->groupManager->isAdmin($user->getUID()) === false) {
			return new JSONResponse(
				data: ['success' => false, 'message' => 'Installing a store item requires an administrator.'],
				statusCode: Http::STATUS_FORBIDDEN
			);
		}

		if (preg_match(self::SLUG_PATTERN, $slug) !== 1) {
			return new JSONResponse(
				data: ['success' => false, 'message' => 'Malformed item slug.'],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		$store = $this->store();
		if ($store->enabled === false) {
			return new JSONResponse(
				data: ['success' => false, 'message' => 'This app declares no store.'],
				statusCode: Http::STATUS_NOT_FOUND
			);
		}

		$descriptor = $this->descriptor(store: $store);

		try {
			if ($descriptor->isFederated() === true) {
				$item = $this->catalog->resolve(descriptor: $descriptor, slug: $slug);
			} else {
				$item = $this->storeService->resolve(descriptor: $descriptor, slug: $slug);
			}
		} catch (Throwable $e) {
			$this->logger->error(
				message: sprintf('[AppHost\\Store] resolve failed for %s: %s', $this->appName, $e->getMessage()),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			$item = null;
		}

		if ($item === null) {
			return new JSONResponse(
				data: ['success' => false, 'message' => 'The store item could not be resolved.'],
				statusCode: Http::STATUS_NOT_FOUND
			);
		}

		// A configuration bundle is applied by the type that owns it, so that a
		// set arrives as registers, schemas, flows and objects rather than as
		// rows of whichever schema the plane happened to allow.
		if ($descriptor->isFederated() === true) {
			return new JSONResponse(data: $this->catalog->install(ref: $item), statusCode: Http::STATUS_OK);
		}

		return new JSONResponse(
			data: $this->installer->install(store: $store, item: $item),
			statusCode: Http::STATUS_OK
		);
	}//end install()

	/**
	 * The calling app's declared store configuration.
	 *
	 * @return StoreManifest
	 */
	private function store(): StoreManifest {
		return $this->manifestLoader->loadStore(appId: $this->appName);
	}//end store()

	/**
	 * Build the discovery descriptor from the declared block.
	 *
	 * The registry URL and token stay in the CALLING app's IAppConfig, so each
	 * app connects its own registry and one app's token never reaches another.
	 *
	 * @param StoreManifest $store The declared store config.
	 *
	 * @return StoreDescriptor
	 */
	private function descriptor(StoreManifest $store): StoreDescriptor {
		return new StoreDescriptor(
			appId: $this->appName,
			schema: $store->schema,
			defaultRegister: $store->register,
			cardFields: $store->cardFields,
			types: $store->declaredTypes()
		);
	}//end descriptor()
}//end class
