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

use OCA\OpenRegister\AppHost\Controller\Support\GenericStoreControllerServices;
use OCA\OpenRegister\AppHost\Service\GenericStoreService;
use OCA\OpenRegister\AppHost\Service\StoreSearch;
use OCA\OpenRegister\AppHost\Service\StoreDescriptor;
use OCA\OpenRegister\AppHost\Store\StoreActionAuthorizer;
use OCA\OpenRegister\AppHost\Store\StoreManifest;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use Throwable;

/**
 * Declarative store search and install for AppHost-adopting apps.
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) One controller serves every
 * AppHost app across BOTH store paths, so it holds the objects client, the
 * objects installer and the configuration catalogue at once. Splitting it per
 * path would put the route alias in two places, and an alias that resolves to
 * a class the router cannot find is a dispatch-time 500 rather than a test
 * failure. The coupling is the price of one alias.
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
	 * @param string                         $appName  Calling leaf app id.
	 * @param IRequest                       $request  HTTP request.
	 * @param GenericStoreControllerServices $services The discovery, install and platform services.
	 *
	 * @spec openspec/specs/apphost-store-plane/spec.md#requirement-a-leaf-app-must-declare-its-store-rather-than-implement-one
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly GenericStoreControllerServices $services,
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
		if ($this->services->platform->userSession->getUser() === null) {
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
				data: [
					'outcome' => GenericStoreService::OUTCOME_NOT_CONFIGURED,
					'cards' => [],
					'kinds' => [],
					'builtIn' => [],
				],
				statusCode: Http::STATUS_OK
			);
		}

		try {
			$result = $this->searchFor(store: $store);
		} catch (Throwable $e) {
			// Detail to the log, generic outcome to the browser: a registry's
			// internals are not the caller's business.
			$this->services->platform->logger->error(
				message: sprintf('[AppHost\\Store] search failed for %s: %s', $this->appName, $e->getMessage()),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return new JSONResponse(
				data: [
					'outcome' => GenericStoreService::OUTCOME_UNREACHABLE,
					'cards' => [],
					'kinds' => $store->kinds,
					'builtIn' => $store->builtIn,
				],
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
		$kinds = $this->searchKinds(store: $store);

		// 🔴 `builtIn` RIDES BACK FOR THE SAME REASON `kinds` DOES.
		//
		// An app declares its own items ONCE, in the `store` block beside the
		// allowlist. StoreManifest parses them and, until now, nothing ever
		// sent them anywhere: `CnStorePage`'s `builtIn` prop is fed from the
		// PAGE CONFIG, which is a second place to write the same list. An app
		// that declared them only in the `store` block therefore rendered an
		// empty store while its manifest said otherwise.
		//
		// Measured on decidiq, which declares four example sets and showed
		// none of them: the search response carried `outcome`, `cards` and
		// `kinds`, and no `builtIn` key at all. ADR-080 Decision 4 exists to
		// stop precisely that blank surface.
		return new JSONResponse(
			data: [
				'outcome' => $result['outcome'],
				'cards' => $result['cards'],
				'kinds' => $kinds,
				'builtIn' => $store->builtIn,
			],
			statusCode: Http::STATUS_OK
		);
	}//end search()

	/**
	 * A request parameter when it is a string, otherwise null.
	 *
	 * @param string $name The parameter name.
	 *
	 * @return string|null
	 */
	private function stringParam(string $name): ?string {
		$value = $this->request->getParam($name);
		if (is_string($value) === false) {
			return null;
		}

		return $value;
	}//end stringParam()

	/**
	 * The filter kinds a search answers with: the app's declared kinds, or a
	 * federated store's declared type ids when it names no kinds of its own.
	 *
	 * @param StoreManifest $store The declared store block.
	 *
	 * @return array<int, string>
	 */
	private function searchKinds(StoreManifest $store): array {
		if ($store->kinds === [] && $store->isFederated() === true) {
			return $store->declaredTypes();
		}

		return $store->kinds;
	}//end searchKinds()

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
		// The gate is an in-body guard rather than #[AuthorizedAdminSetting],
		// because that attribute names a leaf app's settings CLASS and this
		// controller serves every app. Resolved at request time instead of at
		// annotation time — which is also what lets the APP declare the
		// posture rather than the engine assuming one.
		$user = $this->services->platform->userSession->getUser();

		if (preg_match(self::SLUG_PATTERN, $slug) !== 1) {
			return new JSONResponse(
				data: ['success' => false, 'message' => 'Malformed item slug.'],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		// Every gate runs before anything is resolved or written.
		$store = $this->store();
		$refusal = $this->installRefusal(store: $store, user: $user);
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->installItem(store: $store, slug: $slug);
	}//end install()

	/**
	 * The refusal for an install this caller may not run, or null when every gate passes.
	 *
	 * @param StoreManifest $store The declared store block.
	 * @param IUser|null    $user  The signed-in user, or null.
	 *
	 * @return JSONResponse|null 404 no store; 403 unenforceable posture or not permitted; null when allowed.
	 */
	private function installRefusal(StoreManifest $store, ?IUser $user): ?JSONResponse {
		if ($store->enabled === false) {
			return new JSONResponse(
				data: ['success' => false, 'message' => 'This app declares no store.'],
				statusCode: Http::STATUS_NOT_FOUND
			);
		}

		return $this->postureRefusal(store: $store, user: $user);
	}//end installRefusal()

	/**
	 * The refusal when the declared install posture is unenforceable or does not admit this caller.
	 *
	 * @param StoreManifest $store The declared store block.
	 * @param IUser|null    $user  The signed-in user, or null.
	 *
	 * @return JSONResponse|null 403 when refused; null when the caller may install.
	 */
	private function postureRefusal(StoreManifest $store, ?IUser $user): ?JSONResponse {
		// A posture this engine cannot yet enforce is REFUSED, never
		// downgraded. `action:<name>` needs an ADR-023 resolver that lives in
		// the leaf apps; until that lands, treating it as `authenticated`
		// would quietly install on a weaker gate than the app asked for.
		if ($store->isInstallAuthEnforceable() === false) {
			$this->services->platform->logger->error(
				message: sprintf(
					'[AppHost\\Store] %s declares an installAuth this engine cannot enforce: %s',
					$this->appName,
					$store->installAuth
				),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return new JSONResponse(
				data: ['success' => false, 'message' => 'This store declares an install posture this server cannot enforce.'],
				statusCode: Http::STATUS_FORBIDDEN
			);
		}

		if ($this->mayInstall(store: $store, user: $user) === false) {
			return new JSONResponse(
				data: ['success' => false, 'message' => 'Installing a store item requires an administrator.'],
				statusCode: Http::STATUS_FORBIDDEN
			);
		}

		return null;
	}//end postureRefusal()

	/**
	 * Resolve and install one item through the catalogue that owns it.
	 *
	 * @param StoreManifest $store The declared store block.
	 * @param string        $slug  The remote item slug.
	 *
	 * @return JSONResponse 200 with the install report; 404 when unresolved.
	 */
	private function installItem(StoreManifest $store, string $slug): JSONResponse {
		$descriptor = $this->descriptor(store: $store);

		$item = $this->resolveOrNull(descriptor: $descriptor, slug: $slug);
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
			return new JSONResponse(data: $this->services->sources->catalog->install(ref: $item), statusCode: Http::STATUS_OK);
		}

		return new JSONResponse(
			data: $this->services->install->installer->install(store: $store, item: $item),
			statusCode: Http::STATUS_OK
		);
	}//end installItem()

	/**
	 * Resolve a slug, treating a failing catalogue as unresolved (logged).
	 *
	 * @param StoreDescriptor $descriptor The calling app's store parameters.
	 * @param string          $slug       The item slug.
	 *
	 * @return array<string, mixed>|null The resolved item, or null.
	 */
	private function resolveOrNull(StoreDescriptor $descriptor, string $slug): ?array {
		try {
			return $this->resolveFor(descriptor: $descriptor, slug: $slug);
		} catch (Throwable $e) {
			$this->services->platform->logger->error(
				message: sprintf('[AppHost\\Store] resolve failed for %s: %s', $this->appName, $e->getMessage()),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return null;
		}
	}//end resolveOrNull()

	/**
	 * Whether this caller may install, per the posture the app declared.
	 *
	 * WHO may install is the app's declaration; WHAT may be written stays the
	 * `installable` allowlist, which the installer enforces regardless.
	 * Anonymous is refused whatever the posture: `authenticated` is the weakest
	 * the vocabulary offers and it still means signed in.
	 *
	 * An action posture is resolved against the LEAF app's own ADR-023 matrix.
	 * StoreManifest deliberately answers false for it, because that matrix is
	 * not visible from there and assuming `admin` would turn "the operators who
	 * hold this action" into "instance administrators" — the capability loss
	 * this key exists to prevent.
	 *
	 * @param StoreManifest $store The declared store block.
	 * @param IUser|null    $user  The signed-in user, or null.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/store-plane-action-auth/specs/apphost-store-plane/spec.md#requirement-an-action-posture-must-resolve-against-the-declaring-app
	 */
	private function mayInstall(StoreManifest $store, ?IUser $user): bool {
		$action = $store->installAction();
		if ($action !== null) {
			// StoreManifest answers false for an action posture by design, so
			// this is the only arm that can grant one.
			return ($user !== null
				&& $this->services->install->actionAuthorizer->can(appId: $this->appName, action: $action, user: $user));
		}

		return $store->permitsInstall(
			isSignedIn: ($user !== null),
			isAdmin: ($user !== null && $this->services->platform->groupManager->isAdmin($user->getUID()))
		);
	}//end mayInstall()

	/**
	 * Search whichever catalogue this app declared, for the request's `q` and `kind`.
	 *
	 * An app that declares shareable types exchanges CONFIGURATION, so its
	 * catalogue is what publishers have published, not one remote instance's
	 * rows. Selected by declaration, never by probing: an app that declares
	 * none makes no discovery call at all.
	 *
	 * @param StoreManifest $store The calling app's declared store block.
	 *
	 * @return array{outcome: string, cards: array<int, array<string, mixed>>}
	 *
	 * @spec openspec/changes/store-over-federated-config/specs/apphost-store-plane/spec.md#scenario-an-app-that-declares-no-types-keeps-the-object-store
	 */
	private function searchFor(StoreManifest $store): array {
		$descriptor = $this->descriptor(store: $store);
		$query = $this->stringParam(name: 'q');
		$kind = $this->stringParam(name: 'kind');

		// The declared source is checked FIRST. A github store has no registry
		// URL at all, so falling through to the OpenRegister path would report
		// it as `not_configured` — true of a registry it never declared, and
		// useless to whoever has to act on it.
		if ($store->source === 'github') {
			return $this->services->sources->gitHubSource->search(manifest: $store, query: ($query ?? ''), kind: $kind);
		}

		if ($descriptor->isFederated() === true) {
			return $this->services->sources->catalog->search(descriptor: $descriptor, search: new StoreSearch(query: $query, kind: $kind));
		}

		return $this->services->sources->storeService->search(descriptor: $descriptor, query: $query, kind: $kind);
	}//end searchFor()

	/**
	 * Resolve a slug against whichever catalogue this app declared.
	 *
	 * @param StoreDescriptor $descriptor The calling app's store parameters.
	 * @param string          $slug       The item slug.
	 *
	 * @return array<string, mixed>|null The resolved item, or null when unresolved.
	 *
	 * @spec openspec/changes/store-over-federated-config/specs/apphost-store-plane/spec.md#requirement-a-configuration-install-must-run-through-its-owning-type
	 */
	private function resolveFor(StoreDescriptor $descriptor, string $slug): ?array {
		if ($descriptor->isFederated() === true) {
			return $this->services->sources->catalog->resolve(descriptor: $descriptor, slug: $slug);
		}

		return $this->services->sources->storeService->resolve(descriptor: $descriptor, slug: $slug);
	}//end resolveFor()

	/**
	 * Publish one item of the app's store schema to its configured registry.
	 *
	 * The body is `{item: {...}}`, an object that carries its `slug`. Only a
	 * user the app's publish groups admit may publish
	 * (StoreActionAuthorizer::canPublish); the engine then sends the slug and
	 * the declared publish fields, and nothing else (GenericStoreService).
	 *
	 * @return JSONResponse `{outcome, slug}`; 401 anonymous, 403 not admitted, 400 no item.
	 *
	 * @spec openspec/specs/apphost-store-plane/spec.md
	 */
	#[NoAdminRequired]
	public function publish(): JSONResponse {
		$user = $this->services->platform->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(
				data: ['outcome' => 'unauthenticated', 'slug' => ''],
				statusCode: Http::STATUS_UNAUTHORIZED
			);
		}

		$store = $this->store();
		if ($store->enabled === false) {
			return new JSONResponse(
				data: ['outcome' => GenericStoreService::OUTCOME_NOT_CONFIGURED, 'slug' => ''],
				statusCode: Http::STATUS_OK
			);
		}

		$descriptor = $this->descriptor(store: $store);
		if ($this->services->install->actionAuthorizer->canPublish(descriptor: $descriptor, user: $user) === false) {
			return new JSONResponse(
				data: ['outcome' => 'forbidden', 'slug' => ''],
				statusCode: Http::STATUS_FORBIDDEN
			);
		}

		$item = $this->request->getParam('item');
		if (is_array($item) === false) {
			return new JSONResponse(
				data: ['outcome' => GenericStoreService::OUTCOME_NOT_PUBLISHABLE, 'slug' => ''],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		return new JSONResponse(
			data: $this->services->sources->storeService->publish(descriptor: $descriptor, payload: $item),
			statusCode: Http::STATUS_OK
		);
	}//end publish()

	/**
	 * The calling app's declared store configuration.
	 *
	 * @return StoreManifest
	 */
	private function store(): StoreManifest {
		return $this->services->sources->manifestLoader->loadStore(appId: $this->appName);
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
			types: $store->declaredTypes(),
			publishFields: $store->publishFields,
			publishGroups: $store->publishGroups
		);
	}//end descriptor()
}//end class
