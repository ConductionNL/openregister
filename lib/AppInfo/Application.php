<?php

/**
 * OpenConnector Consumers Controller
 *
 * This file contains the controller for handling consumer related operations
 * in the OpenRegister application.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category AppInfo
 * @package  OCA\OpenRegister\AppInfo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @psalm-suppress UnusedClass
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppInfo;

use OCA\OpenRegister\AppHost\ObservabilityRegistrar;
use OCA\OpenRegister\AppHost\Service\FeatureToggleService;
use OCA\OpenRegister\Capabilities\IntegrationsCapability;
use OCA\OpenRegister\Capabilities\UrnCapability;
use OCA\OpenRegister\Contract\EmailLinkReaderInterface;
use OCA\OpenRegister\Contract\RegisterSlugResolverInterface;
use OCA\OpenRegister\Controller\IntegrationsController;
use OCA\OpenRegister\Controller\ObjectIntegrationsController;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\WebhookMapper;
use OCA\OpenRegister\Federation\OpenRegisterCloudFederationProvider;
use OCA\OpenRegister\Listener\ListenerRegistrar;
use OCA\OpenRegister\Mcp\IMcpToolProvider;
use OCA\OpenRegister\Mcp\McpProviderDiscovery;
use OCA\OpenRegister\Middleware\LanguageMiddleware;
use OCA\OpenRegister\Notification\AnnotationNotifier;
use OCA\OpenRegister\Notification\Notifier;
use OCA\OpenRegister\Repair\LogDanglingLinkedTypes;
use OCA\OpenRegister\Search\ObjectsProvider;
use OCA\OpenRegister\Service\Configuration\ConfigurationServiceRegistrar;
use OCA\OpenRegister\Service\ContractBindings;
use OCA\OpenRegister\Service\DeepLinkRegistryService;
use OCA\OpenRegister\Service\EmailLinkService;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\File\FolderManagementHandler;
use OCA\OpenRegister\Service\File\Pdf\Fallback\NullNcOfficeConverter;
use OCA\OpenRegister\Service\Flow\FlowRunAuthorization;
use OCA\OpenRegister\Service\Flow\FlowRunnableGuard;
use OCA\OpenRegister\Service\Flow\FlowRunContext;
use OCA\OpenRegister\Service\Flow\RegistryStepDispatcher;
use OCA\OpenRegister\Service\Gdpr\Evidence\EvidenceSourceRegistry;
use OCA\OpenRegister\Service\Gdpr\Export\UnsignedPadesSigner;
use OCA\OpenRegister\Service\Gdpr\Identity\IdentityVerifyRegistry;
use OCA\OpenRegister\Service\Gdpr\Identity\NullIdentityVerifyProvider;
use OCA\OpenRegister\Service\Gdpr\Regulator\NullRegulatorEscalateProvider;
use OCA\OpenRegister\Service\Gdpr\Regulator\RegulatorEscalateRegistry;
use OCA\OpenRegister\Service\Integration\BuiltinProviders\AuditTrailProvider;
use OCA\OpenRegister\Service\Integration\BuiltinProviders\FilesProvider;
use OCA\OpenRegister\Service\Integration\BuiltinProviders\NotesProvider;
use OCA\OpenRegister\Service\Integration\BuiltinProviders\TagsProvider;
use OCA\OpenRegister\Service\Integration\BuiltinProviders\TasksProvider;
use OCA\OpenRegister\Service\Integration\ExternalIntegrationRouter;
use OCA\OpenRegister\Service\Integration\IntegrationRegistry;
use OCA\OpenRegister\Service\Integration\LeafRegistry;
use OCA\OpenRegister\Service\Integration\PropertyReferenceTypeValidator;
use OCA\OpenRegister\Service\Integration\PropertySemanticReferenceValidator;
use OCA\OpenRegister\Service\Integration\Providers\BookmarksProvider;
use OCA\OpenRegister\Service\Integration\Providers\CalendarProvider;
use OCA\OpenRegister\Service\Integration\Providers\ContactsProvider;
use OCA\OpenRegister\Service\Integration\Providers\DeckProvider;
use OCA\OpenRegister\Service\Integration\Providers\EmailProvider;
use OCA\OpenRegister\Service\Integration\Providers\KvkProvider;
use OCA\OpenRegister\Service\Integration\Providers\OpenCorporatesProvider;
use OCA\OpenRegister\Service\Integration\Providers\OpenProjectProvider;
use OCA\OpenRegister\Service\Integration\Providers\PollsProvider;
use OCA\OpenRegister\Service\Integration\Providers\SharesProvider;
use OCA\OpenRegister\Service\Integration\Providers\TalkProvider;
use OCA\OpenRegister\Service\Integration\Providers\XwikiProvider;
use OCA\OpenRegister\Service\Integration\TimeProvider as IntegrationTimeProvider;
use OCA\OpenRegister\Service\LanguageService;
use OCA\OpenRegister\Service\Lifecycle\AutoTransitionPass;
use OCA\OpenRegister\Service\Lifecycle\AutoTransitionRunner;
use OCA\OpenRegister\Service\Lifecycle\LifecycleActionContext;
use OCA\OpenRegister\Service\Mcp\McpToolsService;
use OCA\OpenRegister\Service\NoteService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\ObjectSource\ObjectSourceRegistrar;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\Outbound\OutboundClientFactory;
use OCA\OpenRegister\Service\Portal\PortalPartyResolver;
use OCA\OpenRegister\Service\Rbac\HierarchyDescender;
use OCA\OpenRegister\Service\Rbac\HierarchyGrantExpander;
use OCA\OpenRegister\Service\Rbac\ObjectGrantResolver;
use OCA\OpenRegister\Service\Rbac\ExplicitReveal;
use OCA\OpenRegister\Service\Rbac\RevealCollector;
use OCA\OpenRegister\Service\Rbac\TokenGrantNarrower;
use OCA\OpenRegister\Service\Rbac\TokenGrantSource;
use OCA\OpenRegister\Service\RegisterSlugResolver;
use OCA\OpenRegister\Service\SchemaImport\DialectDetector;
use OCA\OpenRegister\Service\SchemaImport\SchemaImportService;
use OCA\OpenRegister\Service\SchemaImport\ThreeWayMerge;
use OCA\OpenRegister\Service\Schemas\PropertyValidatorHandler;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\Sync\SourceFetcherRegistry;
use OCA\OpenRegister\Service\TaskService;
use OCA\OpenRegister\Service\Task\TaskInboxService;
use OCA\OpenRegister\Service\Translation\IdentityTranslationProvider;
use OCA\OpenRegister\Service\Translation\TranslationProviderInterface;
use OCA\OpenRegister\Service\UserService;
use OCA\OpenRegister\Service\VectorizationService;
use OCA\OpenRegister\Service\Vectorization\Strategies\FileVectorizationStrategy;
use OCA\OpenRegister\Service\Vectorization\Strategies\ObjectVectorizationStrategy;
use OCA\OpenRegister\Service\Vectorization\VectorEmbeddings;
use OCA\OpenRegister\Service\WebPush\HexIconService;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Federation\ICloudFederationProviderManager;
use OCP\IAppConfig;
use OCP\Security\IContentSecurityPolicyManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Class Application
 *
 * Application class for the OpenRegister app that handles bootstrapping.
 *
 * @category AppInfo
 * @package  OCA\OpenRegister\AppInfo
 *
 * @author  Nextcloud Dev Team
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/nextcloud/server/blob/master/apps-extra/openregister
 *
 * Public services exposed for consumer apps via Nextcloud DI:
 * - {@see \OCA\OpenRegister\Service\RegisterService}            CRUD + helpers on Register entities.
 * - {@see \OCA\OpenRegister\Service\SchemaService}              CRUD + helpers on Schema entities.
 * - {@see \OCA\OpenRegister\Service\ObjectService}              CRUD + search on registered objects.
 * - {@see \OCA\OpenRegister\Service\RegisterResolverService}    Resolve `<context>_register` / `<context>_schema`
 *   IAppConfig keys to slug/UUID strings or hydrated entities; request-scoped cache + tenant aware.
 * - {@see \OCA\OpenRegister\Service\Serializer\RegisterSerializer} Serialize Register entities with `_extend`
 *   expansion (schemas, @self.stats) — single source of truth shared between HTTP and DI callers.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Bootstrap class must reference every
 *   service, mapper, event listener, and middleware registered for the entire app; the
 *   coupling is structural and cannot be reduced without breaking the NC bootstrap contract.
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)  Service registration methods enumerate
 *   all DI bindings in one place per NC bootstrap pattern; splitting them would spread
 *   the wire-up across unrelated files and obscure the dependency graph.
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)   Single IBootstrap implementation per NC
 *   app-info convention; all register/boot logic must live in this one class.
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)  IBootstrap::boot(IBootContext) signature
 *   is fixed by the NC framework contract; the $context parameter may not be used in
 *   every version of the boot method.
 * @SuppressWarnings(PHPMD.TooManyMethods)         Bootstrap class groups its register/boot
 *   wire-up into per-concern helper methods (services, listeners, middleware, capabilities);
 *   inlining them to satisfy the method-count threshold would recreate one unreadable
 *   mega-method that the ExcessiveMethodLength suppression already guards against.
 */
class Application extends App implements IBootstrap {
	/**
	 * Application ID for the OpenRegister app
	 *
	 * @var string
	 */
	public const APP_ID = 'openregister';

	/**
	 * Constructor for the Application class
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-b2b-crossrefs-2026-04-28/tasks.md
	 */
	public function __construct() {
		parent::__construct(appName: self::APP_ID);
	}//end __construct()

	/**
	 * Register application components
	 *
	 * @param IRegistrationContext $context The registration context
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-b2b-crossrefs-2026-04-28/tasks.md
	 * @spec openspec/specs/credential-broker/spec.md#requirement-credential-store-backend-resolution
	 */
	public function register(IRegistrationContext $context): void {
		include_once __DIR__ . '/../../vendor/autoload.php';
		\OCA\OpenRegister\Service\WritePhaseProbe::stamp('or.register.in');

		// Credential broker (credential-broker-service + credential-doriath-leaf):
		// bind the CredentialStore abstraction through the CredentialStoreResolver
		// (design D-A) — the Doriath application-vault leaf when the doriath app is
		// eligible (enabled + service classes + application-scoped seam methods +
		// OR self-registration), else the NC encrypted per-user vault leaf. The
		// broker and controller keep depending only on the CredentialStore
		// interface; this factory is the ONLY place the selection happens.
		$context->registerService(
			\OCA\OpenRegister\Service\Credential\CredentialStore::class,
			static function ($c) {
				return $c->get(\OCA\OpenRegister\Service\Credential\CredentialStoreResolver::class)->resolve();
			}
		);

		// The feature-toggle reader MUST be shared, for the same reason the
		// hierarchy descent below it must: it memoises the merged toggle map
		// FOR THE LIFETIME OF ONE REQUEST, and a container that built it fresh
		// at each injection point would turn a per-request memo into a
		// per-injection one — every `isEnabled()` in a loop paying a config
		// read. Registered explicitly rather than autowired so that a wiring
		// failure is loud here rather than showing up as every toggle reading
		// off (ledger row 11.15).
		$context->registerService(
			FeatureToggleService::class,
			static function ($c) {
				return new FeatureToggleService(
					appConfig: $c->get(\OCP\IAppConfig::class),
					container: $c,
					logger: $c->get(\Psr\Log\LoggerInterface::class),
				);
			}
		);

		// 🔴 THE RUN AUTHORIZATION IS REGISTERED EXPLICITLY, because its
		// failure mode is total. `FlowService` takes it as a NULLABLE argument
		// and an absent one is UNDECIDABLE, which refuses every run — correct
		// for a security control, and an outage if the container quietly
		// declined to build it. A named registration turns that into a loud
		// container error instead of a fleet of refusals nobody can explain
		// (change `flow-runs-honour-their-declaration`).
		$context->registerService(
			FlowRunAuthorization::class,
			static function ($c) {
				return new FlowRunAuthorization(
					access: $c->get(\OCA\OpenRegister\Service\Flow\FlowAccess::class),
				);
			}
		);

		// 🔴 THE RUN AND EDIT GUARD IS REGISTERED EXPLICITLY, for the reason
		// directly above. Both of its collaborators are nullable and both
		// absences fail CLOSED, so a container that quietly declined to build
		// one of them would refuse every run and every test run with no error
		// anywhere saying why. A named registration turns that into a loud
		// container error instead.
		$context->registerService(
			FlowRunnableGuard::class,
			static function ($c) {
				return new FlowRunnableGuard(
					flows: $c->get(\OCA\OpenRegister\Service\Flow\FlowService::class),
					access: $c->get(\OCA\OpenRegister\Service\Flow\FlowAccess::class),
				);
			}
		);

		// 🔴 THE TOKEN GRANT SOURCE MUST BE SHARED, and this is not a
		// performance argument. It is BOUND in the authentication path, where a
		// Consumer is resolved, and READ in the permission handler, where the
		// decision is made. An unshared registration would give those two
		// different objects: the bind would land on one and the read would find
		// an empty other, so every scoped token would silently evaluate as
		// unscoped — a widening, arriving in total silence (row Q13.20).
		$context->registerService(
			TokenGrantSource::class,
			static function () {
				return new TokenGrantSource();
			}
		);

		$context->registerService(
			TokenGrantNarrower::class,
			static function () {
				return new TokenGrantNarrower();
			}
		);

		// The object-hierarchy descent MUST be shared, for the reason the three
		// registrations below it give and one that is sharper here: both of
		// these memoise FOR THE LIFETIME OF ONE REQUEST, and a container that
		// builds an auto-wired class fresh at every injection point would turn
		// a per-request memo into a per-injection one. That is not merely slow.
		// `HierarchyDescender` reads every schema and every register to find
		// the declarations, so an unshared instance pays that on each of the
		// several paths that consult a grant, on every request that holds one.
		//
		// Registered EXPLICITLY rather than left to autowiring for a second
		// reason: `ObjectGrantResolver` takes the expander as a NULLABLE
		// argument, so a wiring failure there would not raise, it would simply
		// stop inheriting grants and say nothing. A named registration is what
		// makes that failure loud (ledger row Q13.23).
		$context->registerService(
			HierarchyDescender::class,
			static function ($c) {
				return new HierarchyDescender(
					db: $c->get(\OCP\IDBConnection::class),
					schemaMapper: $c->get(\OCA\OpenRegister\Db\SchemaMapper::class),
					registerMapper: $c->get(\OCA\OpenRegister\Db\RegisterMapper::class),
					logger: $c->get(\Psr\Log\LoggerInterface::class),
				);
			}
		);

		$context->registerService(
			HierarchyGrantExpander::class,
			static function ($c) {
				return new HierarchyGrantExpander(
					descender: $c->get(HierarchyDescender::class),
					logger: $c->get(\Psr\Log\LoggerInterface::class),
				);
			}
		);

		$context->registerService(
			ObjectGrantResolver::class,
			static function ($c) {
				return new ObjectGrantResolver(
					logger: $c->get(\Psr\Log\LoggerInterface::class),
					container: $c,
					hierarchy: $c->get(HierarchyGrantExpander::class),
				);
			}
		);

		// The reveal collector MUST be shared, and for the sharpest reason on
		// this list: it collects during rendering and is flushed ONCE at the
		// end of the request (ledger row 5.6, D-2). A container that built an
		// auto-wired class fresh at every injection point would give the
		// renderer one instance and the flush another, so the flush would find
		// nothing and every reveal of a BSN would go unrecorded — with no
		// error, and with an audit page that looks like a quiet day.
		$context->registerService(
			RevealCollector::class,
			static function () {
				return new RevealCollector();
			}
		);

		// Shared for the same reason: the grant ObjectsController::show makes
		// must be the one the render path reads (property-explicit-reveal).
		$context->registerService(
			ExplicitReveal::class,
			static function () {
				return new ExplicitReveal();
			}
		);

		// Register request-scoped LanguageService as a singleton (shared per request).
		$context->registerService(
			LanguageService::class,
			function () {
				return new LanguageService();
			}
		);

		// The ambient flow-attribution stack MUST be shared. Nextcloud's
		// container builds an auto-wired class fresh at every injection point,
		// so without this registration the engine would push frames onto one
		// instance and the audit mapper would read an empty stack on another —
		// attributing nothing at all, silently and with no error anywhere.
		$context->registerService(
			FlowRunContext::class,
			function () {
				return new FlowRunContext();
			}
		);

		// The named-transition context MUST be shared for the same reason:
		// TransitionEngine declares the action on one instance and the
		// lifecycle listeners read it on another. Unshared, every named call
		// would silently fall back to matching by from/to value.
		$context->registerService(
			LifecycleActionContext::class,
			function () {
				return new LifecycleActionContext();
			}
		);

		// The automatic-transition pass MUST be shared, and for a sharper reason
		// than the two above: it IS the request's state. The listener records
		// into it, ObjectService and TransitionEngine open and close boundaries
		// on it, and the drain reads the lineage the boundary counted. Auto-
		// wired fresh at each injection point, every one of those would hold its
		// own counter: nothing would ever reach depth zero with a record in it,
		// so no automatic transition would ever fire, silently and with no
		// error anywhere.
		$context->registerService(
			AutoTransitionPass::class,
			function ($c) {
				return new AutoTransitionPass(
					runner: $c->get(AutoTransitionRunner::class),
					logger: $c->get(LoggerInterface::class)
				);
			}
		);

		// The register-slug resolver MUST be shared. It memoises one indexed
		// read per candidate list for the life of the request, and a sweep asks
		// the same question at every call site of the same tick. Nextcloud
		// auto-wires a fresh instance at every injection point, so without this
		// registration the memo would be empty every time and the probe would
		// re-read the register table once per call site.
		$context->registerService(
			RegisterSlugResolver::class,
			function ($c) {
				return new RegisterSlugResolver(
					registerMapper: $c->get(RegisterMapper::class),
					logger: $c->get(LoggerInterface::class)
				);
			}
		);

		// The published contract (ADR-084) consuming apps type-hint. Bound to
		// the same shared instance, so an app that injects the interface and one
		// that injects the class share a memo rather than probing twice.
		$context->registerService(
			RegisterSlugResolverInterface::class,
			function ($c) {
				return $c->get(RegisterSlugResolver::class);
			}
		);

		// The published read of an object's linked emails, for consuming apps.
		$context->registerService(
			EmailLinkReaderInterface::class,
			function ($c) {
				return $c->get(EmailLinkService::class);
			}
		);

		// The contracts other components type-hint, bound to their services.
		ContractBindings::register(context: $context);

		// Register the LanguageMiddleware for Accept-Language header parsing.
		$context->registerMiddleware(LanguageMiddleware::class);

		// Register the default no-op TranslationProvider. Operators replace
		// this binding with a real provider (LibreTranslate / DeepL / etc.)
		// by overriding it in their own app's registration.
		$context->registerService(
			TranslationProviderInterface::class,
			function () {
				return new IdentityTranslationProvider();
			}
		);

		// Register the TenantQuotaMiddleware for tenant quota enforcement and status checks.
		$context->registerMiddleware(\OCA\OpenRegister\Middleware\TenantQuotaMiddleware::class);

		// Register the OasValidationMiddleware for opt-in request-body
		// validation against per-operation OAS schemas. Activates only on
		// POST/PUT/PATCH with `?_validate=true`; pass-through otherwise.
		$context->registerMiddleware(\OCA\OpenRegister\Middleware\OasValidationMiddleware::class);

		// Writes the request's reveals of audited properties, once, after the
		// controller has answered (ledger row 5.6, D-2). Registered LAST of the
		// middlewares so it runs closest to the response: everything the read
		// path was going to collect has been collected by then, and a
		// middleware that flushed earlier would write a shorter trail than the
		// request actually produced.
		$context->registerMiddleware(\OCA\OpenRegister\Middleware\RevealAuditMiddleware::class);

		// Register the RateLimitMiddleware to wire SecurityService brute-force
		// protection into the inbound API auth path (issue #1834). Records
		// failed Basic/Bearer/session auth on protected endpoints (keyed on
		// identity+trusted-IP) and pre-emptively blocks locked-out callers.
		// Fail-OPEN: any limiter error allows the request through.
		$context->registerMiddleware(\OCA\OpenRegister\Middleware\RateLimitMiddleware::class);

		// Register the PublicApiCorsMiddleware: reflects the request Origin onto
		// `@PublicPage` responses so browsers on other origins (e.g. a marketing
		// site posting a lead into a public-create schema) can read the result.
		// Scoped to public endpoints only and never sets allow-credentials.
		$context->registerMiddleware(\OCA\OpenRegister\Middleware\PublicApiCorsMiddleware::class);

		// Register the ChatCompatMiddleware (or-chat-proxy-deprecation): adds
		// Deprecation/Sunset/Link response headers to every chat/agents/
		// conversations controller response and forwards those requests
		// server-side to hermiq. ON by default since
		// or-chat-engine-decommission; operators opt out via the
		// `openregister.chat.proxyTo` appconfig value (any value != 'hermiq').
		// Any failure falls back to serving the request locally unchanged.
		$context->registerMiddleware(\OCA\OpenRegister\Middleware\ChatCompatMiddleware::class);

		// Register the ObjectSourceErrorMiddleware (dbal-virtual-registers D8):
		// maps DbalObjectSourceException thrown during reads against an
		// external-database-backed schema onto its declared 502/503 status —
		// an unreachable or failing external database must never surface as a
		// bare 500. All other exceptions are rethrown untouched.
		$context->registerMiddleware(\OCA\OpenRegister\Middleware\ObjectSourceErrorMiddleware::class);

		// Register the EncryptedFieldFilterMiddleware (field-level-object-encryption):
		// maps EncryptedFieldFilterException — thrown when a caller filters/
		// searches/facets on a field flagged `x-openregister-encrypted` — onto a
		// clear HTTP 400 instead of a silent zero-row result or a bare 500.
		$context->registerMiddleware(\OCA\OpenRegister\Middleware\EncryptedFieldFilterMiddleware::class);

		// Register the UnknownMetadataFieldMiddleware: maps
		// UnknownMetadataFieldException — thrown when a `@self` filter names a
		// field that no metadata column corresponds to — onto an HTTP 400 that
		// names the field and lists the filterable ones, instead of the opaque
		// driver-level 500 an unresolvable column name used to produce.
		$context->registerMiddleware(\OCA\OpenRegister\Middleware\UnknownMetadataFieldMiddleware::class);

		// Register the RelatedRowFilterMiddleware: maps
		// RelatedRowFilterNotSupportedException — a `_related`/`_referenced`
		// filter on a multi-schema facet route that cannot honour it — onto an
		// HTTP 400 with the exception's message, not an uncaught 500.
		$context->registerMiddleware(\OCA\OpenRegister\Middleware\RelatedRowFilterMiddleware::class);

		// Register the MaintenanceModeMiddleware (admin-operations-console
		// D-6): while maintenance mode holds, every controller but the
		// operations console is refused with the administered message, so the
		// instance can be closed without locking out the administrator who
		// closed it and has to open it again.
		$context->registerMiddleware(\OCA\OpenRegister\Middleware\MaintenanceModeMiddleware::class);

		// Register the ApiVersionMiddleware (api-as-a-versioned-surface): names
		// the contract version that answered on every API response, carries the
		// RFC 8594 end date when that version is deprecated, and refuses a call
		// naming a withdrawn version with 410 or an undeclared one with 400.
		// It decorates and refuses; it never changes which controller runs, so a
		// deprecated version keeps exactly the behaviour it had before.
		$context->registerMiddleware(\OCA\OpenRegister\Middleware\ApiVersionMiddleware::class);

		// Register the ApiCallerMiddleware (api-as-a-versioned-surface): binds a
		// caller to its administered source addresses, bounds it to its
		// administered ceiling, and records which route and version it called.
		// Registered AFTER ApiVersionMiddleware so the version this call speaks
		// is already resolvable when the record is written.
		//
		// An instance that has administered neither a ceiling nor a binding
		// behaves exactly as it did before: the limiter fails open, the binding
		// only refuses a caller an administrator actually bound, and the record
		// swallows its own failures.
		$context->registerMiddleware(\OCA\OpenRegister\Middleware\ApiCallerMiddleware::class);

		// Every outbound call this app makes goes through the administered
		// proxy (api-as-a-versioned-surface, design D-6). Most gemeenten have
		// no direct egress, so a call that bypasses the proxy does not fail
		// visibly: it works on a developer's laptop and times out in
		// production.
		//
		// 🔑 BOUND UNDER `IClientService` RATHER THAN MIGRATING CALL SITES.
		// This app creates clients from fourteen files across twenty call
		// sites. Editing all twenty would leave the guarantee resting on
		// nobody ever adding a twenty-first, which is exactly the failure D-6
		// names. Overriding the binding means every existing call site is
		// untouched and every future one is covered by construction.
		//
		// The override is scoped to this app's container: nothing resolved
		// outside OpenRegister sees it.
		$context->registerService(
			\OCP\Http\Client\IClientService::class,
			static function ($container): \OCP\Http\Client\IClientService {
				// 🔴 The inner client comes from the SERVER container, not from
				// `$container`. Asking the app container for `IClientService`
				// here would resolve to this very closure and recurse until the
				// stack runs out. `OCP\Server::get()` is the public accessor
				// for the server's own binding, and it is what reaches past
				// the override we are installing.
				return new OutboundClientFactory(
					clientService: \OCP\Server::get(\OCP\Http\Client\IClientService::class),
					proxy: $container->get(\OCA\OpenRegister\Service\Outbound\ProxySettings::class),
				);
			}
		);

		// Bind the dormant Path B PDF anonymisation fallback bridge to its
		// null implementation. Tenants enabling Path B replace this binding
		// with a concrete NcOfficeConverterInterface implementation that
		// talks to Collabora Online / Code (per the
		// `pdf-anonymisation-odt-fallback` scaffold).
		$context->registerService(
			\OCA\OpenRegister\Service\File\Pdf\Fallback\NcOfficeConverterInterface::class,
			function () {
				return new NullNcOfficeConverter();
			}
		);

		// The loop node's step dispatcher. `IterateNode` resolves the INTERFACE
		// — correctly, it should not know which dispatcher runs its body — but
		// nothing ever bound it, and an interface cannot be instantiated. So
		// every repeat step failed the moment it executed:
		//
		// > Could not resolve …\FlowStepDispatcher! Class can not be instantiated
		//
		// The node validated, appeared in the palette and drew on the canvas
		// throughout, which is why this survived: nothing short of RUNNING a
		// flow that contains one can see it, and no flow on the instance did.
		// Registered as an explicit FACTORY, not an alias. An alias makes the
		// container autowire RegistryStepDispatcher, and its second parameter is
		// a FlowRunGuard whose own constructor takes a `string $runUuid` — which
		// the container cannot invent. Autowiring it therefore died with
		// "Could not resolve runUuid!" on the first repeat step that ran, and a
		// nullable default does not help: the container resolves a typed
		// parameter before it considers the default.
		//
		// The guard is per-RUN state and cannot come from the container at all.
		// A container-built dispatcher gets none, and picks one up from the run
		// context at dispatch time instead.
		$context->registerService(
			\OCA\OpenRegister\Service\Flow\FlowStepDispatcher::class,
			static function ($c) {
				return new RegistryStepDispatcher(
					registry: $c->get(\OCA\OpenRegister\Service\Flow\FlowNodeRegistry::class),
					// The acting-identity scope IS container state, unlike the
					// guard: a loop's body steps must scope a contributed node
					// to the run's runAs exactly as top-level steps do.
					scope: $c->get(\OCA\OpenRegister\Service\Flow\FlowRunAsScope::class)
				);
			}
		);

		// DSAR case-engine (dsar-case-engine): bind the swappable PAdES signing
		// seam to its default SHA-256-only stub. The real PAdES-LTV signer drops
		// in here later by replacing this binding — the ExportBundleService
		// depends only on the PadesSigner interface, so nothing else changes.
		// TODO(ADR-047 Phase-1b, DEFERRED): PAdES-LTV signing deferred — the
		// 2026-07-04 tc-lib-pdf spike was No-Go (8.65 stubs the B-T timestamp).
		// Interim = SHA-256 hash-only. When resumed, bind a real PadesSigner
		// against pyHanko (MIT sidecar) or a matured tc-lib-pdf; TSA URL config.
		$context->registerService(
			\OCA\OpenRegister\Service\Gdpr\Export\PadesSigner::class,
			function () {
				return new UnsignedPadesSigner();
			}
		);

		// DSAR evidence-source registry (shared, so leaf-app addProvider()
		// registrations during boot() persist for the whole request). The
		// harvest service auto-wires it; OR core enumerates only registered
		// providers (ADR-019 — an unregistered source contributes nothing).
		$context->registerService(
			\OCA\OpenRegister\Service\Gdpr\Evidence\EvidenceSourceRegistry::class,
			function (ContainerInterface $container) {
				return new EvidenceSourceRegistry(
					logger: $container->get('Psr\Log\LoggerInterface')
				);
			}
		);

		// DSAR integration seams (dsar-integration-seams): two pluggable seams —
		// identity-verify and regulator-escalate — as shared per-request
		// registries (so a leaf app's addProvider() during boot() persists for
		// the whole request, ADR-019) each pre-seeded with the OR fail-closed
		// default in its constructor. Resolution is pack-selector-driven and
		// fail-closed: an unset/unknown selector resolves the default that
		// refuses (never null, never "verified"/"escalated" — ADR-005/CWE-863).
		$context->registerService(
			\OCA\OpenRegister\Service\Gdpr\Identity\NullIdentityVerifyProvider::class,
			function () {
				return new NullIdentityVerifyProvider();
			}
		);
		$context->registerService(
			\OCA\OpenRegister\Service\Gdpr\Identity\IdentityVerifyRegistry::class,
			function (ContainerInterface $container) {
				return new IdentityVerifyRegistry(
					logger: $container->get('Psr\Log\LoggerInterface'),
					default: $container->get(\OCA\OpenRegister\Service\Gdpr\Identity\NullIdentityVerifyProvider::class)
				);
			}
		);
		$context->registerService(
			\OCA\OpenRegister\Service\Gdpr\Regulator\NullRegulatorEscalateProvider::class,
			function () {
				return new NullRegulatorEscalateProvider();
			}
		);
		$context->registerService(
			\OCA\OpenRegister\Service\Gdpr\Regulator\RegulatorEscalateRegistry::class,
			function (ContainerInterface $container) {
				return new RegulatorEscalateRegistry(
					logger: $container->get('Psr\Log\LoggerInterface'),
					default: $container->get(\OCA\OpenRegister\Service\Gdpr\Regulator\NullRegulatorEscalateProvider::class)
				);
			}
		);

		// Web Push hex-icon service (openregister-web-push-engine): needs an
		// app-scoped IAppData for its rendered-PNG cache, which is not
		// auto-wirable, so resolve it via IAppDataFactory here.
		$context->registerService(
			\OCA\OpenRegister\Service\WebPush\HexIconService::class,
			function (\Psr\Container\ContainerInterface $c): \OCA\OpenRegister\Service\WebPush\HexIconService {
				return new HexIconService(
					appManager: $c->get(\OCP\App\IAppManager::class),
					appData: $c->get(\OCP\Files\AppData\IAppDataFactory::class)->get('openregister'),
					logger: $c->get(\Psr\Log\LoggerInterface::class)
				);
			}
		);

		// Register the data-sync source-fetcher registry pre-loaded with the
		// built-in REST/OpenRegister fetcher. Apps can register additional
		// fetchers (OData, SOAP, CSV) by resolving and extending this service.
		$context->registerService(
			\OCA\OpenRegister\Service\Sync\SourceFetcherRegistry::class,
			function ($c) {
				$registry = new SourceFetcherRegistry();
				$registry->register($c->get(\OCA\OpenRegister\Service\Sync\RestApiSourceFetcher::class));
				return $registry;
			}
		);

		// Register the standards schema-import service with the bundled,
		// versioned snapshot resources (schema-import-standards). Built
		// explicitly so the resource root is deterministic; the dialect
		// registry inside it makes DCAT/SKOS/ZGW importers follow-ups.
		$context->registerService(
			\OCA\OpenRegister\Service\SchemaImport\SchemaImportService::class,
			function () {
				return new SchemaImportService(
					detector: new DialectDetector(),
					merge: new ThreeWayMerge()
				);
			}
		);

		// Register all services in phases to resolve circular dependencies.
		$this->registerMappersWithCircularDependencies(context: $context);
		$this->registerCacheAndFileHandlers(context: $context);
		ConfigurationServiceRegistrar::register(context: $context);

		$context->registerSearchProvider(ObjectsProvider::class);
		// The entries beside the objects. Two providers, because a Woo
		// request asks for every mention of a subject and the answer is a
		// list of entries, each naming the case it sits on.
		$context->registerSearchProvider(\OCA\OpenRegister\Search\TimelineEntriesProvider::class);
		$context->registerReferenceProvider(\OCA\OpenRegister\Reference\ObjectReferenceProvider::class);
		$context->registerCalendarProvider(\OCA\OpenRegister\Calendar\RegisterCalendarProvider::class);

		$this->registerVectorizationService(context: $context);
		$this->registerObjectInteractionServices(context: $context);
		$this->registerIntegrationRegistry(context: $context);
		ObjectSourceRegistrar::register(context: $context);
		ListenerRegistrar::register(context: $context);
		(new McpProviderDiscovery())->register(context: $context);
		ObservabilityRegistrar::register(context: $context, appId: self::APP_ID);

		// Register the annotation-driven INotifier so notifications fired by
		// AnnotationNotificationDispatcher get a parsed subject — without
		// this Nextcloud silently drops the notification.
		$context->registerNotifierService(AnnotationNotifier::class);

		// 🔴 AND the hand-written one, which was NEVER REGISTERED.
		//
		// `AnnotationNotifier::prepare()` deliberately re-throws
		// UnknownNotificationException for the subjects it does not own, and its
		// comment says they are "rendered by Notifier" — but nothing ever
		// registered Notifier, so nothing rendered them. Nextcloud drops a
		// notification no notifier claims, silently. Every
		// `configuration_update_available`, `handoff_drain_failed`,
		// `scheduled_report_delivered` and `scheduled_report_failed` this app has
		// ever dispatched was written to the store and then discarded at parse
		// time, and the class rendering them was written, complete, and
		// unreachable — the same orphaned-capability shape as
		// TriggerScheduleNode::validateConfig().
		$context->registerNotifierService(Notifier::class);

		// Surface URN identifier surface via Nextcloud capabilities API so
		// clients can discover URN endpoints + the instance slug without
		// probing routes.
		$context->registerCapability(UrnCapability::class);

		// Pluggable-integration-registry task 4.5 (tasks.md#task-22):
		// advertise the integration registry through the OCS
		// capabilities endpoint.
		$context->registerCapability(IntegrationsCapability::class);

		// ADR-019 Phase E (Option B): single umbrella widget hosted on
		// the Nextcloud user-dashboard. Iterates the integration
		// registry client-side and mounts each leaf's `user-dashboard`
		// widget — locked in over the 24-tile alternative because per-
		// integration NC widgets would clutter the global dashboard.
		$context->registerDashboardWidget(
			\OCA\OpenRegister\Dashboard\IntegrationDashboardWidget::class
		);
		\OCA\OpenRegister\Service\WritePhaseProbe::stamp('or.register.out');
	}//end register()

	/**
	 * Register mappers with circular dependencies.
	 *
	 * These must be registered in the correct order to resolve dependencies:
	 * 1. OrganisationService (breaks circular dependency with SettingsService)
	 * 2. SchemaMapper (depends on OrganisationMapper)
	 * 3. RegisterMapper (depends on SchemaMapper)
	 * 4. MagicMapper and MagicMapper (depend on the above)
	 *
	 * @param IRegistrationContext $context The registration context
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Manual DI factory closures for four
	 *   interdependent mappers/services each require 10-20 lines of constructor wiring;
	 *   the length is structural to the NC DI pattern and cannot be shortened without
	 *   obscuring the circular-dependency resolution order.
	 *
	 * @spec openspec/archive/retrofit-b2b-crossrefs-2026-04-28/tasks.md
	 */
	private function registerMappersWithCircularDependencies(IRegistrationContext $context): void {
		// Register OrganisationService without SettingsService to break circular dependency.
		$context->registerService(
			OrganisationService::class,
			function (ContainerInterface $container) {
				return new OrganisationService(
					organisationMapper: $container->get(OrganisationMapper::class),
					userSession: $container->get('OCP\IUserSession'),
					session: $container->get('OCP\ISession'),
					config: $container->get('OCP\IConfig'),
					appConfig: $container->get('OCP\IAppConfig'),
					groupManager: $container->get('OCP\IGroupManager'),
					userManager: $container->get('OCP\IUserManager'),
					logger: $container->get('Psr\Log\LoggerInterface'),
					settingsService: null
				);
			}
		);

		// Register UserService for UserController (after OrganisationService which it depends on).
		$context->registerService(
			\OCA\OpenRegister\Service\UserService::class,
			function (ContainerInterface $container) {
				return new UserService(
					userManager: $container->get('OCP\IUserManager'),
					userSession: $container->get('OCP\IUserSession'),
					config: $container->get('OCP\IConfig'),
					groupManager: $container->get('OCP\IGroupManager'),
					accountManager: $container->get('OCP\Accounts\IAccountManager'),
					logger: $container->get('Psr\Log\LoggerInterface'),
					organisationService: $container->get(OrganisationService::class),
					eventDispatcher: $container->get('OCP\EventDispatcher\IEventDispatcher'),
					avatarManager: $container->get('OCP\IAvatarManager'),
					auditTrailMapper: $container->get(\OCA\OpenRegister\Db\AuditTrailMapper::class),
					secureRandom: $container->get('OCP\Security\ISecureRandom'),
					db: $container->get('OCP\IDBConnection'),
					l10nFactory: $container->get('OCP\L10N\IFactory')
				);
			}
		);

		$context->registerService(
			SchemaMapper::class,
			function (ContainerInterface $container) {
				return new SchemaMapper(
					db: $container->get('OCP\IDBConnection'),
					eventDispatcher: $container->get('OCP\EventDispatcher\IEventDispatcher'),
					validator: $container->get(PropertyValidatorHandler::class),
					organisationMapper: $container->get(OrganisationMapper::class),
					userSession: $container->get('OCP\IUserSession'),
					groupManager: $container->get('OCP\IGroupManager'),
					appConfig: $container->get('OCP\IAppConfig'),
					logger: $container->get('Psr\Log\LoggerInterface')
				);
			}
		);

		$context->registerService(
			RegisterMapper::class,
			function (ContainerInterface $container) {
				return new RegisterMapper(
					db: $container->get('OCP\IDBConnection'),
					schemaMapper: $container->get(SchemaMapper::class),
					eventDispatcher: $container->get('OCP\EventDispatcher\IEventDispatcher'),
					container: $container,
					organisationMapper: $container->get(OrganisationMapper::class),
					userSession: $container->get('OCP\IUserSession'),
					groupManager: $container->get('OCP\IGroupManager'),
					appConfig: $container->get('OCP\IAppConfig'),
					// Explicitly wired: this factory overrides autowiring, so an
					// argument omitted here is never supplied at all. That is how
					// RegisterMapper ran with four dead logging branches
					// (openregister#2820).
					logger: $container->get('Psr\Log\LoggerInterface')
				);
			}
		);

		$context->registerService(
			WebhookMapper::class,
			function (ContainerInterface $container) {
				return new WebhookMapper(
					db: $container->get('OCP\IDBConnection'),
					organisationMapper: $container->get(OrganisationMapper::class),
					userSession: $container->get('OCP\IUserSession'),
					groupManager: $container->get('OCP\IGroupManager'),
					appConfig: $container->get('OCP\IAppConfig')
				);
			}
		);

		$context->registerService(
			MagicMapper::class,
			function (ContainerInterface $container) {
				return new MagicMapper(
					db: $container->get('OCP\IDBConnection'),
					schemaMapper: $container->get(SchemaMapper::class),
					registerMapper: $container->get(RegisterMapper::class),
					config: $container->get('OCP\IConfig'),
					eventDispatcher: $container->get('OCP\EventDispatcher\IEventDispatcher'),
					userSession: $container->get('OCP\IUserSession'),
					groupManager: $container->get('OCP\IGroupManager'),
					userManager: $container->get('OCP\IUserManager'),
					appConfig: $container->get('OCP\IAppConfig'),
					logger: $container->get('Psr\Log\LoggerInterface'),
					settingsService: $container->get(SettingsService::class),
					container: $container
				);
			}
		);

		// The task inbox and the portal party resolver read the object store
		// through AbstractObjectMapper, which the autowirer cannot build (it is
		// abstract) and would silently default to null: an inbox row without
		// subject context, and a portal task that can match nobody. Both are
		// wired to the MagicMapper explicitly for that reason.
		$context->registerService(
			TaskInboxService::class,
			function (ContainerInterface $container) {
				return new TaskInboxService(
					tasks: $container->get(\OCA\OpenRegister\Db\TaskMapper::class),
					temporal: $container->get(\OCA\OpenRegister\Service\Task\TaskTemporalProjection::class),
					logger: $container->get('Psr\Log\LoggerInterface'),
					objects: $container->get(MagicMapper::class),
					deliveries: $container->get(\OCA\OpenRegister\Db\PortalTaskDeliveryMapper::class),
					// Reads a located task's subject from its own table instead
					// of searching every magic table for it.
					locator: $container->get(\OCA\OpenRegister\Service\Task\TaskSubjectLocator::class)
				);
			}
		);

		$context->registerService(
			PortalPartyResolver::class,
			function (ContainerInterface $container) {
				return new PortalPartyResolver(
					objects: $container->get(MagicMapper::class)
				);
			}
		);

		// EntityRelationMapper is registered explicitly because it constructor-injects
		// `IEventDispatcher` to dispatch `EntityRelationDecisionUpdatedEvent`. Every
		// other event-dispatcher-dependent mapper in this method (SchemaMapper,
		// RegisterMapper, MagicMapper, WebhookMapper) is wired explicitly to avoid
		// depending on NC's autowirer resolution of framework-interface keys, which
		// has historically been version-sensitive.
		$context->registerService(
			EntityRelationMapper::class,
			function (ContainerInterface $container) {
				return new EntityRelationMapper(
					db: $container->get('OCP\IDBConnection'),
					auditTrailMapper: $container->get(\OCA\OpenRegister\Db\AuditTrailMapper::class),
					userSession: $container->get('OCP\IUserSession'),
					eventDispatcher: $container->get('OCP\EventDispatcher\IEventDispatcher'),
					logger: $container->get('Psr\Log\LoggerInterface')
				);
			}
		);

	}//end registerMappersWithCircularDependencies()

	/**
	 * Register cache and file handling services.
	 *
	 * @param IRegistrationContext $context The registration context
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-b2b-crossrefs-2026-04-28/tasks.md
	 */
	private function registerCacheAndFileHandlers(IRegistrationContext $context): void {
		$context->registerService(
			CacheHandler::class,
			function (ContainerInterface $container) {
				return new CacheHandler(
					organisationMapper: $container->get(OrganisationMapper::class),
					logger: $container->get('Psr\Log\LoggerInterface'),
					cacheFactory: $container->get('OCP\ICacheFactory'),
					userSession: $container->get('OCP\IUserSession'),
					container: $container,
					registerMapper: $container->get(RegisterMapper::class),
					schemaMapper: $container->get(SchemaMapper::class),
					db: $container->get('OCP\IDBConnection')
				);
			}
		);

		// FolderManagementHandler without FileService to break circular dependency.
		$context->registerService(
			FolderManagementHandler::class,
			function (ContainerInterface $container) {
				return new FolderManagementHandler(
					rootFolder: $container->get('OCP\Files\IRootFolder'),
					objectEntityMapper: $container->get(MagicMapper::class),
					registerMapper: $container->get(RegisterMapper::class),
					userSession: $container->get('OCP\IUserSession'),
					groupManager: $container->get('OCP\IGroupManager'),
					logger: $container->get('Psr\Log\LoggerInterface'),
					auditTrailMapper: $container->get(\OCA\OpenRegister\Db\AuditTrailMapper::class),
					mountCache: $container->get('OCP\Files\Config\IUserMountCache'),
					folderRecorder: $container->get(\OCA\OpenRegister\Db\RegisterFolderRecorder::class),
					fileService: null
				);
			}
		);
	}//end registerCacheAndFileHandlers()

	/**
	 * Register vectorization service with strategies.
	 *
	 * @param IRegistrationContext $context The registration context
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-b2b-crossrefs-2026-04-28/tasks.md
	 */
	private function registerVectorizationService(IRegistrationContext $context): void {
		$context->registerService(
			VectorizationService::class,
			function (ContainerInterface $container) {
				$service = new VectorizationService(
					vectorService: $container->get(VectorEmbeddings::class),
					logger: $container->get('Psr\Log\LoggerInterface')
				);

				$fileStrategy = $container->get(FileVectorizationStrategy::class);
				$objectStrategy = $container->get(ObjectVectorizationStrategy::class);
				$service->registerStrategy('file', $fileStrategy);
				$service->registerStrategy('object', $objectStrategy);

				return $service;
			}
		);
	}//end registerVectorizationService()

	/**
	 * Register task and note services for object interactions.
	 *
	 * TaskService wraps CalDAV VTODO operations, NoteService wraps Nextcloud Comments.
	 *
	 * @param IRegistrationContext $context The registration context
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-b2b-crossrefs-2026-04-28/tasks.md
	 */
	private function registerObjectInteractionServices(IRegistrationContext $context): void {
		$context->registerService(
			TaskService::class,
			function (ContainerInterface $container) {
				// The write-back gate is resolved lazily and best-effort: without
				// it a PROJECTED VTODO's update is refused (fail closed), and
				// standalone VTODOs are unaffected either way.
				$gate = null;
				try {
					$gate = $container->get(\OCA\OpenRegister\Service\Task\TaskVtodoWriteBackGate::class);
				} catch (\Throwable $e) {
					$container->get('Psr\Log\LoggerInterface')->debug(
						'[Application] TaskVtodoWriteBackGate unavailable for TaskService: ' . $e->getMessage()
					);
				}

				return new TaskService(
					calDavBackend: $container->get('OCA\DAV\CalDAV\CalDavBackend'),
					userSession: $container->get('OCP\IUserSession'),
					logger: $container->get('Psr\Log\LoggerInterface'),
					urlGenerator: $container->get('OCP\IURLGenerator'),
					gate: $gate
				);
			}
		);

		$context->registerService(
			NoteService::class,
			function (ContainerInterface $container) {
				return new NoteService(
					commentsManager: $container->get('OCP\Comments\ICommentsManager'),
					userSession: $container->get('OCP\IUserSession'),
					userManager: $container->get('OCP\IUserManager'),
					logger: $container->get('Psr\Log\LoggerInterface'),
					visibility: $container->get(\OCA\OpenRegister\Service\TimelineVisibilityService::class),
					versions: $container->get(\OCA\OpenRegister\Service\NoteVersionService::class)
				);
			}
		);

		$context->registerService(
			\OCA\OpenRegister\Service\Integration\TimeProvider::class,
			function (ContainerInterface $container) {
				return new IntegrationTimeProvider(
					appConfig: $container->get('OCP\IAppConfig')
				);
			}
		);
	}//end registerObjectInteractionServices()

	/**
	 * Register the IntegrationRegistry + ExternalIntegrationRouter
	 * services used by the pluggable integration registry.
	 *
	 * Both are shared per-request singletons. Apps that ship their own
	 * IntegrationProvider implementations register them via
	 * `$this->container->get(IntegrationRegistry::class)->addProvider(...)`
	 * from their own Application::register() hook — see
	 * `openspec/changes/pluggable-integration-registry/proposal.md` (AD-1).
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/integration-registry/spec.md
	 */
	private function registerIntegrationRegistry(IRegistrationContext $context): void {
		$context->registerService(
			IntegrationRegistry::class,
			function (ContainerInterface $container) {
				return new IntegrationRegistry(
					logger: $container->get('Psr\Log\LoggerInterface')
				);
			}
		);

		$context->registerService(
			ExternalIntegrationRouter::class,
			function (ContainerInterface $container) {
				return new ExternalIntegrationRouter(
					appManager: $container->get('OCP\App\IAppManager'),
					container: $container,
					logger: $container->get('Psr\Log\LoggerInterface')
				);
			}
		);

		// PropertyReferenceTypeValidator — consumed by schema-property
		// validation paths that opt in to the new referenceType marker
		// (AD-18). Stays separate from the entity-level
		// validateLinkedTypesValue path so existing schemas keep
		// validating exactly as before.
		$context->registerService(
			PropertyReferenceTypeValidator::class,
			function (ContainerInterface $container) {
				return new PropertyReferenceTypeValidator(
					registry: $container->get(IntegrationRegistry::class),
				);
			}
		);

		// PropertySemanticReferenceValidator — validates the opt-in
		// `referenceSemanticType` property marker (ADR-048, cross-app
		// semantic references) as a well-formed absolute IRI. Standalone,
		// mirroring PropertyReferenceTypeValidator; wire into schema-save
		// when write-time enforcement is desired.
		$context->registerService(
			PropertySemanticReferenceValidator::class,
			function (ContainerInterface $container) {
				return new PropertySemanticReferenceValidator(
					jsonLd: $container->get(\OCA\OpenRegister\Service\JsonLd\JsonLdContextService::class),
				);
			}
		);

		// Repair step LogDanglingLinkedTypes — runs on install +
		// post-migration to surface schemas whose linkedTypes contain
		// ids that the registry can no longer resolve. Strictly
		// informational; never throws, never modifies data.
		$context->registerService(
			LogDanglingLinkedTypes::class,
			function (ContainerInterface $container) {
				return new LogDanglingLinkedTypes(
					registry: $container->get(IntegrationRegistry::class),
					container: $container,
					logger: $container->get('Psr\Log\LoggerInterface')
				);
			}
		);

		// LeafRegistry — the cross-app leaf catalogue (ADR-066). Collects the
		// leaves sibling apps contribute through RegisterLeafProvidersEvent,
		// lazily on first read, and lands their data providers on the shared
		// IntegrationRegistry so existing per-object routing reaches them.
		$context->registerService(
			LeafRegistry::class,
			function (ContainerInterface $container) {
				return new LeafRegistry(
					eventDispatcher: $container->get(\OCP\EventDispatcher\IEventDispatcher::class),
					integrationRegistry: $container->get(IntegrationRegistry::class),
					appManager: $container->get('OCP\App\IAppManager'),
					logger: $container->get('Psr\Log\LoggerInterface')
				);
			}
		);

		$this->registerBuiltinIntegrationProviders(context: $context);

		// IntegrationsController — read-only API over the registry.
		$context->registerService(
			IntegrationsController::class,
			function (ContainerInterface $container) {
				return new IntegrationsController(
					appName: 'openregister',
					request: $container->get('OCP\IRequest'),
					registry: $container->get(IntegrationRegistry::class),
					userSession: $container->get('OCP\IUserSession'),
					groupManager: $container->get('OCP\IGroupManager'),
					logger: $container->get('Psr\Log\LoggerInterface')
				);
			}
		);

		// ObjectIntegrationsController — object-scoped sub-resource
		// dispatch through the registry.
		$context->registerService(
			ObjectIntegrationsController::class,
			function (ContainerInterface $container) {
				return new ObjectIntegrationsController(
					appName: 'openregister',
					request: $container->get('OCP\IRequest'),
					registry: $container->get(IntegrationRegistry::class),
					logger: $container->get('Psr\Log\LoggerInterface'),
					objectService: $container->get(\OCA\OpenRegister\Service\ObjectService::class),
					schemaMapper: $container->get(\OCA\OpenRegister\Db\SchemaMapper::class),
					visibility: $container->get(\OCA\OpenRegister\Service\TimelineVisibilityService::class)
				);
			}
		);

		// IntegrationsAdminSettings is declared in info.xml <admin> and
		// resolved by Nextcloud's container — IntegrationRegistry +
		// ExternalIntegrationRouter are already registered above and the
		// remaining constructor deps (IAppManager / IURLGenerator /
		// IL10N) are framework services NC autowires. No explicit
		// registerService needed, mirroring OpenRegisterAdmin.
		// IntegrationsCapability — surfaces the registry through the
		// Nextcloud OCS capabilities endpoint, role-redacted per AD-17.
		$context->registerService(
			IntegrationsCapability::class,
			function (ContainerInterface $container) {
				return new IntegrationsCapability(
					registry: $container->get(IntegrationRegistry::class),
					userSession: $container->get('OCP\IUserSession'),
					groupManager: $container->get('OCP\IGroupManager'),
					appManager: $container->get('OCP\App\IAppManager'),
					leafRegistry: $container->get(LeafRegistry::class)
				);
			}
		);

	}//end registerIntegrationRegistry()

	/**
	 * Register the integration providers and link services listed in
	 * BuiltinIntegrationServices so they resolve lazily from the container.
	 *
	 * The providers self-register with the IntegrationRegistry during
	 * `boot()` through `bootBuiltinIntegrationProviders()`.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/integration-registry/spec.md
	 */
	private function registerBuiltinIntegrationProviders(IRegistrationContext $context): void {
		foreach (array_keys(BuiltinIntegrationServices::FACTORIES) as $class) {
			$context->registerService(
				$class,
				static function (ContainerInterface $container) use ($class) {
					return BuiltinIntegrationServices::build(container: $container, class: $class);
				}
			);
		}
	}//end registerBuiltinIntegrationProviders()

	/**
	 * Boot application components
	 *
	 * @param IBootContext $context The boot context
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-b2b-crossrefs-2026-04-28/tasks.md
	 */
	public function boot(IBootContext $context): void {
		\OCA\OpenRegister\Service\WritePhaseProbe::stamp('or.boot.in');
		// Dispatch the deep link registration event so consuming apps
		// (Procest, Pipelinq, etc.) can register their URL patterns.
		// DeepLinkRegistryService uses ContainerInterface for lazy mapper
		// resolution, so no circular DI issues during registration.
		$server = $context->getServerContainer();
		$dispatcher = $server->get(IEventDispatcher::class);
		// Through the registry, so a later read in the same process does not
		// ask a second time.
		$registry = $server->get(DeepLinkRegistryService::class);
		$registry->requestRegistrations(dispatcher: $dispatcher);

		// Register the built-in IntegrationProvider implementations
		// with the IntegrationRegistry. The 5 wrap existing services
		// (FileService / NoteService / TaskService / system tag manager /
		// AuditTrailMapper) and surface them through the unified
		// registry contract. Each provider is constructed lazily — the
		// registry never touches a provider's wrapped service unless a
		// caller actually invokes that provider's CRUD path.
		$this->bootBuiltinIntegrationProviders(server: $server);
		$this->bootLeafRegistry(server: $server);
		ObjectSourceRegistrar::boot(server: $server);
		$this->bootFederation(server: $server);

		// Allow GitHub-hosted avatars in img-src so the CnRoadmapItem
		// component can render submitter faces alongside the issues
		// surfaced by the github-issue-proxy. The browser refuses
		// `<img src="https://avatars.githubusercontent.com/...">` under
		// Nextcloud's default img-src ('self' data: blob:), leaving a
		// broken-image glyph next to every roadmap card. Allowlisting
		// the host here lifts the block instance-wide for any app that
		// proxies GitHub issues through OpenRegister.
		$this->relaxCspForGithubAvatars(server: $server);

		// Allow registering the Web Push Service Worker (openregister-web-push-engine).
		// Nextcloud's default CSP uses a nonce-based script-src, which a Service
		// Worker script cannot carry, so navigator.serviceWorker.register() is
		// rejected with "violates the Content Security Policy". Adding
		// `worker-src 'self'` lets the same-origin SW register.
		$this->relaxCspForWebPushWorker(server: $server);
		\OCA\OpenRegister\Service\WritePhaseProbe::stamp('or.boot.out');
	}//end boot()

	/**
	 * Push an `img-src https://avatars.githubusercontent.com` allowlist
	 * onto Nextcloud's default Content-Security-Policy via
	 * `IContentSecurityPolicyManager::addDefaultPolicy()`. Idempotent —
	 * NC merges policies additively, never narrowing.
	 *
	 * @param mixed $server Server container (passed in from boot()).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/features-roadmap-menu/spec.md
	 */
	private function relaxCspForGithubAvatars($server): void {
		try {
			$cspManager = $server->get(IContentSecurityPolicyManager::class);
			$policy = new ContentSecurityPolicy();
			$policy->addAllowedImageDomain('https://avatars.githubusercontent.com');
			$cspManager->addDefaultPolicy($policy);
		} catch (\Throwable $e) {
			// CSP manager unavailable (rare — would break far more than
			// avatars). Stay silent rather than fail the boot.
		}
	}//end relaxCspForGithubAvatars()

	/**
	 * Allow the same-origin Web Push Service Worker to register by adding
	 * `worker-src 'self'` to Nextcloud's default Content-Security-Policy via
	 * `IContentSecurityPolicyManager::addDefaultPolicy()`. NC's nonce-based
	 * script-src otherwise rejects `navigator.serviceWorker.register()` with a
	 * CSP violation (a SW script cannot carry a request nonce). Idempotent —
	 * NC merges policies additively, never narrowing.
	 *
	 * @param mixed $server Server container (passed in from boot()).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/web-push-delivery/spec.md
	 */
	private function relaxCspForWebPushWorker($server): void {
		try {
			$cspManager = $server->get(IContentSecurityPolicyManager::class);
			$policy = new ContentSecurityPolicy();
			$policy->addAllowedWorkerSrcDomain("'self'");
			$cspManager->addDefaultPolicy($policy);
		} catch (\Throwable $e) {
			// CSP manager unavailable (rare). Stay silent rather than fail boot.
		}
	}//end relaxCspForWebPushWorker()

	/**
	 * Resolve every BuiltinProviders/* class and register it with the
	 * shared IntegrationRegistry.
	 *
	 * Kept separate from the DI registration in
	 * `registerIntegrationRegistry()` because addProvider() needs the
	 * registry instance — i.e. it has to run after the container has
	 * fully bootstrapped. `boot()` is the canonical post-registration
	 * hook for that.
	 *
	 * @param mixed $server Server container (passed in from boot()).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/integration-registry/spec.md
	 */
	private function bootBuiltinIntegrationProviders($server): void {
		try {
			$integrationRegistry = $server->get(
				IntegrationRegistry::class
			);
		} catch (\Throwable $e) {
			// Registry binding not available — skip silently; the
			// service would log its own warning at use-time anyway.
			return;
		}

		$providerClasses = [
			FilesProvider::class,
			NotesProvider::class,
			TasksProvider::class,
			TagsProvider::class,
			AuditTrailProvider::class,
			// Leaves: external (OpenConnector-backed).
			// @spec openspec/specs/integration-xwiki/spec.md (canonical spec).
			XwikiProvider::class,
			// @spec openspec/specs/integration-openproject/spec.md (canonical spec).
			OpenProjectProvider::class,
			// @spec openspec/specs/integration-registry/spec.md (canonical spec).
			KvkProvider::class,
			OpenCorporatesProvider::class,
			// Leaves: NC-native, backend-shipped (wrap existing OR services).
			// @spec openspec/specs/integration-calendar/spec.md (canonical spec).
			CalendarProvider::class,
			// @spec openspec/specs/integration-contacts/spec.md (canonical spec).
			ContactsProvider::class,
			// @spec openspec/specs/integration-deck/spec.md (canonical spec).
			DeckProvider::class,
			// @spec openspec/specs/integration-email/spec.md (canonical spec).
			EmailProvider::class,
			// Leaves: NC-app-backed greenfield (registry surface only;
			// service + link table land in per-leaf follow-ups).
			// @spec openspec/specs/integration-activity/spec.md (canonical spec).
			\OCA\OpenRegister\Service\Integration\Providers\ActivityProvider::class,
			// @spec openspec/specs/integration-analytics/spec.md (canonical spec).
			\OCA\OpenRegister\Service\Integration\Providers\AnalyticsProvider::class,
			// @spec openspec/specs/integration-bookmarks/spec.md (canonical spec).
			BookmarksProvider::class,
			// @spec openspec/specs/integration-collectives/spec.md (canonical spec).
			\OCA\OpenRegister\Service\Integration\Providers\CollectivesProvider::class,
			// @spec openspec/specs/integration-cospend/spec.md (canonical spec).
			\OCA\OpenRegister\Service\Integration\Providers\CospendProvider::class,
			// @spec openspec/specs/integration-flow/spec.md (canonical spec).
			\OCA\OpenRegister\Service\Integration\Providers\FlowProvider::class,
			// @spec openspec/specs/integration-forms/spec.md (canonical spec).
			\OCA\OpenRegister\Service\Integration\Providers\FormsProvider::class,
			// @spec openspec/specs/integration-maps/spec.md (canonical spec).
			\OCA\OpenRegister\Service\Integration\Providers\MapsProvider::class,
			// @spec openspec/specs/integration-photos/spec.md (canonical spec).
			\OCA\OpenRegister\Service\Integration\Providers\PhotosProvider::class,
			// @spec openspec/specs/integration-polls/spec.md (canonical spec).
			PollsProvider::class,
			// @spec openspec/specs/integration-shares/spec.md (canonical spec).
			SharesProvider::class,
			// @spec openspec/specs/integration-talk/spec.md (canonical spec).
			TalkProvider::class,
			// @spec openspec/specs/integration-time-tracker/spec.md (canonical spec).
			\OCA\OpenRegister\Service\Integration\Providers\TimeProvider::class,
		];

		// Resolve a logger up-front so a mis-wired factory closure or an
		// absent wrapped service is visible in the logs instead of a
		// silently-missing tab. Guarded so a logger-less build still boots.
		try {
			$logger = $server->get(\Psr\Log\LoggerInterface::class);
		} catch (\Throwable $e) {
			$logger = null;
		}

		foreach ($providerClasses as $providerClass) {
			try {
				$provider = $server->get($providerClass);
				$integrationRegistry->addProvider($provider);
			} catch (\Throwable $e) {
				// Provider construction can fail if a wrapped service
				// is missing on this NC build, or — as the live-NC E2E
				// surfaced — if the DI factory closure was not updated
				// for a Tier-2 constructor arg. Don't take the whole app
				// down for one absent provider; the failing tab simply
				// won't render. But log loudly so a mis-wired factory is
				// diagnosable rather than silently swallowed.
				if ($logger !== null) {
					$logger->warning(
						'OpenRegister: integration provider failed to construct and was dropped from the registry: {provider} — {message}',
						[
							'provider' => $providerClass,
							'message' => $e->getMessage(),
							'exception' => $e,
						]
					);
				}
			}//end try
		}//end foreach
	}//end bootBuiltinIntegrationProviders()

	/**
	 * Install the lazy leaf loader on the shared IntegrationRegistry.
	 *
	 * The loader is a closure that reads the LeafRegistry catalogue, which
	 * dispatches `RegisterLeafProvidersEvent` once and lands any data-provider
	 * leaves on the IntegrationRegistry. Installing it here (rather than
	 * dispatching now) keeps the collect-event LAZY: it fires only when
	 * something actually reads the registry or the leaf catalogue in a request
	 * (ADR-066). Guarded so a registry-less build still boots.
	 *
	 * @param mixed $server Server container (passed in from boot()).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/app-leaf-provider-registration/specs/leaf-provider-registration/spec.md
	 */
	private function bootLeafRegistry($server): void {
		try {
			$integrationRegistry = $server->get(IntegrationRegistry::class);
			$integrationRegistry->setLeafLoader(
				static function () use ($server): void {
					// Reading the catalogue triggers LeafRegistry::ensureLoaded(),
					// which dispatches the event and calls addProvider() for each
					// data-provider leaf.
					$server->get(LeafRegistry::class)->getDescriptors();
				}
			);
		} catch (\Throwable $e) {
			// Registry binding not available — skip silently; the leaf catalogue
			// simply stays empty on this build.
			return;
		}

	}//end bootLeafRegistry()

	/**
	 * Register OpenRegister's OCM cloud-federation provider so cross-instance
	 * shares of registers/schemas/objects ride Nextcloud's Open Cloud Mesh
	 * transport. Guarded: if the federation stack is unavailable, OpenRegister
	 * still runs (federation simply won't be offered).
	 *
	 * @param mixed $server Server container (passed from boot()).
	 *
	 * @return void
	 */
	private function bootFederation($server): void {
		try {
			$manager = $server->get(ICloudFederationProviderManager::class);
			$manager->addCloudFederationProvider(
				'openregister',
				'OpenRegister',
				function () use ($server) {
					return $server->get(OpenRegisterCloudFederationProvider::class);
				}
			);
		} catch (\Throwable $e) {
			try {
				$server->get(\Psr\Log\LoggerInterface::class)->warning(
					'[Federation] could not register OCM provider: ' . $e->getMessage()
				);
			} catch (\Throwable $inner) {
				// Logging unavailable — never take the app down over federation.
			}
		}
	}//end bootFederation()
}//end class
