<?php

/**
 * ObjectsController
 *
 * Controller for managing object operations in the OpenRegister app.
 * Provides CRUD functionality for objects within registers and schemas.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use DateTime;
use DateTimeImmutable;
use OCA\OpenRegister\Controller\Support\ConflictTrail;
use OCA\OpenRegister\Controller\Support\ControllerSession;
use OCA\OpenRegister\Controller\Support\EmptyValueStripper;
use OCA\OpenRegister\Controller\Support\ObjectExport;
use OCA\OpenRegister\Controller\Support\ObjectCountScope;
use OCA\OpenRegister\Controller\Support\ObjectDeleteRoute;
use OCA\OpenRegister\Controller\Support\ObjectGovernanceServices;
use OCA\OpenRegister\Controller\Support\ObjectCreate;
use OCA\OpenRegister\Controller\Support\ObjectCreateControls;
use OCA\OpenRegister\Controller\Support\ObjectReadOptions;
use OCA\OpenRegister\Controller\Support\ObjectRequestParams;
use OCA\OpenRegister\Controller\Support\ObjectShow;
use OCA\OpenRegister\Files\Service\File\BulkDownloadArchive;
use OCA\OpenRegister\Controller\Support\LinkedDataServices;
use OCA\OpenRegister\Controller\Support\RelationUrlStamper;
use OCA\OpenRegister\Controller\Support\GeoServices;
use OCA\OpenRegister\Controller\Support\ValidationWindow;
use OCA\OpenRegister\Controller\Support\ObjectsControllerServices;
use OCA\OpenRegister\Controller\Support\MagicMappedTable;
use OCA\OpenRegister\Controller\Support\ObjectsRouteScope;
use OCA\OpenRegister\Controller\Support\ObjectsTableRead;
use OCA\OpenRegister\Controller\Support\ObjectTarget;
use OCA\OpenRegister\Controller\Support\ObjectUpdate;
use OCA\OpenRegister\Controller\Support\ObjectVersionGuard;
use OCA\OpenRegister\Controller\Support\ReferenceOptionsAnswer;
use OCA\OpenRegister\Controller\Support\ReferenceOptionsAsk;
use OCA\OpenRegister\Controller\Support\ResultWindow;
use OCA\OpenRegister\Controller\Support\SentWrite;
use OCA\OpenRegister\Controller\Support\ServedExport;
use OCA\OpenRegister\Controller\Support\ShowServices;
use OCA\OpenRegister\Controller\Support\ShownEntity;
use OCA\OpenRegister\Controller\Support\VersionClaim;
use OCA\OpenRegister\Controller\Support\WriteCaller;
use OCA\OpenRegister\Controller\Support\StringTypedPatch;
use OCA\OpenRegister\Controller\Support\RenderedObjectUuids;
use OCA\OpenRegister\Controller\Trait\ResolvesRegisterAndSchemaTrait;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\AppendOnlyException;
use OCA\OpenRegister\Exception\ArchivalImmutableException;
use OCA\OpenRegister\Exception\CustomValidationException;
use OCA\OpenRegister\Exception\DuplicateBlockedException;
use OCA\OpenRegister\Exception\ExportTooLargeException;
use OCA\OpenRegister\Exception\OfficeServerRequiredException;
use OCA\OpenRegister\Exception\FolderAccessDeniedException;
use OCA\OpenRegister\Exception\HookStoppedException;
use OCA\OpenRegister\Exception\LockedException;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Exception\ObjectExistsException;
use OCA\OpenRegister\Exception\ObjectStateWriteException;
use OCA\OpenRegister\Exception\ReferentialIntegrityException;
use OCA\OpenRegister\Exception\RegisterNotFoundException;
use OCA\OpenRegister\Exception\SchemaNotFoundException;
use OCA\OpenRegister\Exception\SearchTermSyntaxException;
use OCA\OpenRegister\Exception\TranslationTargetConflictException;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Service\Audit\ObjectHistoryForReadersInterface;
use OCA\OpenRegister\Service\Audit\PurposeRefusedException;
use OCA\OpenRegister\Service\Export\ExportAuditRecorderInterface;
use OCA\OpenRegister\Service\Export\ExportRightService;
use OCA\OpenRegister\Service\Export\ExportRunRecorder;
use OCA\OpenRegister\Service\Export\ExportRunContent;
use OCA\OpenRegister\Service\Export\ExportRunFile;
use OCA\OpenRegister\Service\Export\ExportRunOrigin;
use OCA\OpenRegister\Service\FileServiceInterface;
use OCA\OpenRegister\Service\Hinge\InheritedGeoCollectorInterface;
use OCA\OpenRegister\Service\Hinge\ReferencedByServiceInterface;
use OCA\OpenRegister\Service\Interaction\ReadStateServiceInterface;
use OCA\OpenRegister\Service\Object\QueryHandlerInterface;
use OCA\OpenRegister\Service\Object\SchemaTypeConverterInterface;
use OCA\OpenRegister\Service\Object\UpsertOnKeyException;
use OCA\OpenRegister\Service\Object\UpsertOnKeyRequest;
use OCA\OpenRegister\Service\ObjectSource\DbalObjectSourceException;
use OCA\OpenRegister\Service\ObjectSource\DbalWriteException;
use OCA\OpenRegister\Service\ObjectHandlerAccessInterface;
use OCA\OpenRegister\Search\Service\Geo\GeoResponseCrsFactory;
use OCA\OpenRegister\Search\Service\Geo\GeoResponseCrsInterface;
use OCA\OpenRegister\Search\Service\Geo\FeatureShape;
use OCA\OpenRegister\Search\Service\Geo\GetFeatureRequest;
use OCA\OpenRegister\Service\Geo\GeocodeOptions;
use OCA\OpenRegister\Service\Object\PaginationLinks;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\RegisterSchemaRef;
use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCA\OpenRegister\Service\Rules\ExpressionDefaultException;
use OCA\OpenRegister\Service\Search\HistoryPredicateInterface;
use OCA\OpenRegister\Service\Search\SearchGrammar;
use OCA\OpenRegister\Service\WebhookService;
use OCA\OpenRegister\Service\WriteCause;
use OCA\OpenRegister\Support\FilterParams;
use OCA\OpenRegister\Support\UnknownFilterKeys;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Uid\Uuid;
use OCA\OpenRegister\Notifications\Service\PresenceBeat;
use OCA\OpenRegister\Notifications\Service\PresenceLookup;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectExportRequest;

/**
 * Objects controller for OpenRegister
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.TooManyMethods)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ElseExpression)           File upload extraction requires conditional branching
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)    Complex file upload handling with multiple formats
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 *
 * @spec openspec/specs/aggregation-api/spec.md
 */
class ObjectsController extends Controller {
	use \OCA\OpenRegister\Controller\Trait\HandlesExceptionsTrait;
	use ResolvesRegisterAndSchemaTrait;

	/**
	 * The `@`-prefixed body keys a client is allowed to send.
	 *
	 * Everything else starting with `@` is server-managed metadata and is
	 * dropped here. The list exists because dropping is SILENT: a key nobody
	 * allows through is not refused, it simply never reaches the save, and the
	 * caller reads a 200 on a field that was never stored. `@notSupplied` was
	 * exactly that on its first day: the handler enforced it, the validator
	 * excused it, the object stored it, and this filter removed it from every
	 * create, update and patch before any of that ran.
	 *
	 * @var array<int, string>
	 */
	private const SUBMITTABLE_RESERVED_KEYS = ['@self', Schema::NOT_SUPPLIED_KEY];

	/**
	 * The container.
	 *
	 * @var ContainerInterface
	 */
	private readonly ContainerInterface $container;

	/**
	 * The object service.
	 *
	 * @var ObjectOperationsInterface
	 */
	private readonly ObjectOperationsInterface $objectService;

	/**
	 * The user session.
	 *
	 * @var IUserSession
	 */
	private readonly IUserSession $userSession;

	/**
	 * The group manager.
	 *
	 * @var IGroupManager
	 */
	private readonly IGroupManager $groupManager;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface
	 */
	private readonly LoggerInterface $logger;

	/**
	 * The controller's injected services.
	 *
	 * @var ObjectsControllerServices
	 */
	private readonly ObjectsControllerServices $services;

	/**
	 * The recovery window, uniqueness, purpose and history services.
	 *
	 * @var ObjectGovernanceServices
	 */
	private readonly ObjectGovernanceServices $governance;

	/**
	 * The linked data services.
	 *
	 * @var LinkedDataServices
	 */
	private readonly LinkedDataServices $linkedData;

	/**
	 * The geo services.
	 *
	 * @var GeoServices
	 */
	private readonly GeoServices $geo;

	/**
	 * Constructor for the ObjectsController
	 *
	 * @param string $appName The name of the app
	 * @param IRequest $request The request object
	 * @param ControllerSession $session Session, group manager and logger.
	 * @param ObjectsControllerServices $services The objects controller services.
	 *
	 * @return void
	 */
	public function __construct(string $appName, IRequest $request, ControllerSession $session, ObjectsControllerServices $services) {
		parent::__construct(appName: $appName, request: $request);
		$this->services = $services;
		$this->governance = $services->governance;
		$this->linkedData = $services->extensions->linkedData;
		$this->geo = $services->extensions->geo;
		$this->container = $services->extensions->container;
		$this->objectService = $services->store->objectService;
		$this->userSession = $session->userSession;
		$this->groupManager = $session->groupManager;
		$this->logger = $session->logger;
	}//end __construct()

	/**
	 * The version check every write path asks before it writes.
	 *
	 * @return ObjectVersionGuard The guard for this request.
	 *
	 * @spec openspec/changes/a-conflicting-save-shows-the-other-value/specs/objects-crud/spec.md#requirement-every-write-path-asserts-the-expected-version-req-cso-003
	 */
	private function versionGuard(): ObjectVersionGuard {
		return new ObjectVersionGuard(
			claim: new VersionClaim(request: $this->request),
			container: $this->container,
			trail: new ConflictTrail(auditTrail: $this->services->store->auditTrailMapper, logger: $this->logger),
			logger: $this->logger
		);
	}//end versionGuard()

	/**
	 * The refusal body for an object that is in the trash rather than absent.
	 *
	 * Returns null when the identifier resolves to nothing at all, so a
	 * genuine miss keeps the answer it always had. The lookup is deliberately
	 * separate from the read above: the read excludes deleted rows by design,
	 * and widening it would leak soft-deleted content into every list.
	 *
	 * SCOPED EXACTLY AS THE READ IT STANDS IN FOR. This used to look up a bare
	 * uuid with `_rbac: false` and `_multitenancy: false` and no register
	 * constraint at all, which made it strictly more permissive than the read
	 * it is a fallback message for. `show()` reaches it precisely when the
	 * scoped find() answered null - the case show()'s own docblock says must be
	 * a bare 404, "so an unauthorized caller cannot distinguish 'exists but
	 * forbidden' from 'does not exist'". A caller in one organisation could name
	 * any uuid from any other and be told, in a 404-shaped body, that the object
	 * exists, that it is in the trash, and exactly when it stops being
	 * restorable. The register and schema in the URL were not even used to
	 * constrain it, so any uuid from any tenant worked the same way.
	 *
	 * The caller's own flags are passed in rather than assumed, so an admin -
	 * who reads exempt on the primary path - still gets the helpful answer.
	 *
	 * @param ObjectShow        $show The identifier the caller asked for, and the register named in the
	 *                               URL (when numeric), so the lookup cannot wander.
	 * @param ObjectReadOptions $read The caller's own RBAC and tenancy posture, as used by the read.
	 *
	 * @return array<string, mixed>|null The refusal body, or null when the object does not exist.
	 *
	 * @spec openspec/specs/deletion-audit-trail/spec.md
	 */
	private function deletedRefusal(ObjectShow $show, ObjectReadOptions $read): ?array {
		if ($this->governance->windowService === null) {
			return null;
		}

		$registerIdScope = null;
		if (is_numeric($show->resolved['register']) === true) {
			$registerIdScope = (int)$show->resolved['register'];
		}

		try {
			$magicMapper = $this->container->get(\OCA\OpenRegister\Db\MagicMapperInterface::class);
			$context = $magicMapper->findAcrossAllSources(
				identifier: $show->id,
				includeDeleted: true,
				_rbac: $read->scoped,
				_multitenancy: $read->scoped,
				registerIdScope: $registerIdScope
			);
		} catch (\Throwable $e) {
			return null;
		}

		$deleted = ($context['object'] ?? null);
		if (($deleted instanceof ObjectEntity) === false || $deleted->isSoftDeleted() === false) {
			return null;
		}

		return $this->governance->windowService->refusalBody(
			object: $deleted,
			schema: ($context['schema'] ?? null)
		);
	}//end deletedRefusal()

	/**
	 * Check if the current user is in the admin group.
	 *
	 * This helper method determines if the current logged-in user belongs to the 'admin' group,
	 * which allows bypassing RBAC and multitenancy restrictions.
	 *
	 * @return bool True if user is admin, false otherwise
	 *
	 * @psalm-return   bool
	 * @phpstan-return bool
	 */
	private function isCurrentUserAdmin(): bool {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return false;
		}

		$userGroups = $this->groupManager->getUserGroupIds($user);
		return in_array('admin', $userGroups);
	}//end isCurrentUserAdmin()

	/**
	 * Whether the current request asks for JSON-LD output via content negotiation.
	 *
	 * Null-safe: when the JSON-LD services are not wired (e.g. minimal DI in a
	 * test harness) this always returns false and the default JSON path is kept.
	 *
	 * @return bool True when JSON-LD output is requested and available.
	 *
	 * @spec openspec/specs/json-ld-output/spec.md
	 */
	private function wantsJsonLd(): bool {
		if ($this->linkedData->jsonLdSerializer === null) {
			return false;
		}

		return $this->linkedData->jsonLdSerializer->wantsJsonLd(request: $this->request);
	}//end wantsJsonLd()

	/**
	 * Decorate an already-rendered single-object array as a JSON-LD JSONResponse.
	 *
	 * The serializer wraps the rendered array only — it introduces no second
	 * data path, so RBAC / multitenancy / field-level security / the published
	 * predicate all remain applied exactly as for the plain-JSON response.
	 *
	 * @param array $renderedObject The rendered object array.
	 * @param array $resolved The resolved register and schema entities.
	 *
	 * @return JSONResponse The JSON-LD response (Content-Type/Vary set).
	 *
	 * @spec openspec/specs/json-ld-output/spec.md
	 */
	private function jsonLdObjectResponse(array $renderedObject, array $resolved): JSONResponse {
		$document = $this->linkedData->jsonLdSerializer->serialize(
			renderedObject: $renderedObject,
			schema: $resolved['schemaEntity'],
			register: $resolved['registerEntity']
		);

		return $this->withJsonLdHeaders(response: new JSONResponse(data: $document));
	}//end jsonLdObjectResponse()

	/**
	 * Decorate a paginated collection result as a JSON-LD `@graph` JSONResponse.
	 *
	 * @param array $result The paginated result array.
	 * @param array $resolved The resolved register and schema entities.
	 *
	 * @return JSONResponse The JSON-LD response (Content-Type/Vary set).
	 *
	 * @spec openspec/specs/json-ld-output/spec.md
	 */
	private function jsonLdCollectionResponse(array $result, array $resolved): JSONResponse {
		$document = $this->linkedData->jsonLdSerializer->serializeCollection(
			paginatedResult: $result,
			schema: $resolved['schemaEntity'],
			register: $resolved['registerEntity']
		);

		return $this->withJsonLdHeaders(response: new JSONResponse(data: $document));
	}//end jsonLdCollectionResponse()

	/**
	 * Add the JSON-LD content negotiation headers to a response.
	 *
	 * @param JSONResponse $response The response to decorate.
	 *
	 * @return JSONResponse The decorated response.
	 *
	 * @spec openspec/specs/json-ld-output/spec.md
	 */
	private function withJsonLdHeaders(JSONResponse $response): JSONResponse {
		$response->addHeader('Content-Type', 'application/ld+json');
		$response->addHeader('Vary', 'Accept');
		return $response;
	}//end withJsonLdHeaders()

	/**
	 * Normalize form data values by decoding JSON strings.
	 *
	 * When a request is sent as multipart/form-data, nested objects and arrays
	 * arrive as JSON-encoded strings (e.g. contactpersonen = '[{"voornaam":"John"}]').
	 * This method detects such strings and decodes them back into PHP arrays/objects
	 * so the rest of the pipeline can process them uniformly.
	 *
	 * Only runs when the request content type is multipart/form-data.
	 *
	 * @param array $data The request parameters to normalize.
	 *
	 * @return array The normalized data with JSON strings decoded.
	 */
	private function normalizeFormDataValues(array $data): array {
		$contentType = $this->request->getHeader('Content-Type');

		// Only normalize for multipart/form-data requests.
		if (stripos($contentType, 'multipart/form-data') === false) {
			return $data;
		}

		return (new ObjectRequestParams())->decodedFormValues(data: $data);
	}//end normalizeFormDataValues()

	/**
	 * Strip server-managed @self fields from client-supplied object data.
	 *
	 * The top-level filter in create/update/patch/postPatch already passes `@self`
	 * through unchanged because certain integrations legitimately set `@self.slug`
	 * or `@self.relations`. However, several `@self` sub-fields MUST NOT be accepted
	 * from client input because they are either server-authoritative (owner, organisation)
	 * or carry security-sensitive semantics (authorization, groups).
	 *
	 * The service layer (SaveObject::setSelfMetadata + applyOwnerAttribution) enforces
	 * the same rules; this controller-level strip is an additional defense-in-depth
	 * boundary that catches injections before they even reach the service (wave-11 WF2).
	 *
	 * Allowed @self keys for client input (non-exhaustive; extend as features are added):
	 *   slug, name, description, summary, image, relations, tmlo (update path only)
	 *
	 * Rejected at this layer (server-managed or security-sensitive):
	 *   owner, organisation, authorization, groups, application, folder, _autoGeneratedUuid
	 *
	 * @param array $data The raw request data (may contain a '@self' key)
	 *
	 * @return array The data with dangerous @self sub-keys stripped
	 */
	private function sanitiseSelfMetadata(array $data): array {
		if (isset($data['@self']) === false || is_array($data['@self']) === false) {
			return $data;
		}

		// Fields that clients must never supply — they are set server-side.
		$serverManagedKeys = [
			'owner',
			'organisation',
			'authorization',
			'groups',
			'application',
			'folder',
			'_autoGeneratedUuid',
			\OCA\OpenRegister\Service\WriteCause::CLIENT_ATTEMPT_KEY,
		];

		foreach ($serverManagedKeys as $key) {
			unset($data['@self'][$key]);
		}

		return $data;
	}//end sanitiseSelfMetadata()

	/**
	 * Extract all uploaded files from the current request.
	 *
	 * Uses IRequest::getUploadedFile() to retrieve files by known field names.
	 * This method checks for common file field names used in the application.
	 *
	 * @return array<string, array{name: string, type: string, tmp_name: string, error: int, size: int}>
	 *                                                                                                   Array of uploaded files keyed by field name
	 *
	 * @SuppressWarnings(PHPMD.Superglobals)         $_FILES access necessary — IRequest does not expose all file keys
	 */
	private function extractAllUploadedFiles(): array {
		$uploadedFiles = [];

		// $_FILES names every uploaded field; IRequest does not expose all file keys.
		// phpcs:ignore -- $_FILES access is necessary as IRequest doesn't expose all file keys.
		foreach (array_keys($_FILES) as $fieldName) {
			$this->collectUpload(uploadedFiles: $uploadedFiles, fieldName: (string)$fieldName);
		}

		// Some frameworks also name file fields among the request parameters.
		foreach (array_keys($this->request->getParams()) as $fieldName) {
			$this->collectUpload(uploadedFiles: $uploadedFiles, fieldName: (string)$fieldName);
		}

		return $uploadedFiles;
	}//end extractAllUploadedFiles()

	/**
	 * Add the upload of one field to the collected files, once; system fields (`_`) are skipped.
	 *
	 * @param array<string, array> $uploadedFiles The files collected so far.
	 * @param string               $fieldName     The field.
	 *
	 * @return void
	 */
	private function collectUpload(array &$uploadedFiles, string $fieldName): void {
		$uploadedFile = $this->fieldUpload(uploadedFiles: $uploadedFiles, fieldName: $fieldName);
		if ($uploadedFile === null) {
			return;
		}

		if (is_array($uploadedFile['name'] ?? null) === true) {
			$files = $this->multipleFiles(fieldName: $fieldName, uploadedFile: $uploadedFile);
			$uploadedFiles = array_replace($uploadedFiles, $files);
			return;
		}

		if (empty($uploadedFile['tmp_name']) === false) {
			$uploadedFiles[$fieldName] = $uploadedFile;
		}
	}//end collectUpload()

	/**
	 * The upload of a field not collected yet, when it is not a system field (`_`) and carries a tmp_name.
	 *
	 * @param array<string, array> $uploadedFiles The files collected so far.
	 * @param string               $fieldName     The field.
	 *
	 * @return array|null The upload, or null.
	 */
	private function fieldUpload(array $uploadedFiles, string $fieldName): ?array {
		if (isset($uploadedFiles[$fieldName]) === true || str_starts_with($fieldName, '_') === true) {
			return null;
		}

		$uploadedFile = $this->request->getUploadedFile($fieldName);
		if (isset($uploadedFile['tmp_name']) === false) {
			return null;
		}

		return $uploadedFile;
	}//end fieldUpload()

	/**
	 * The files of a multiple-file field, keyed `field[i]`; entries without a temporary file are left out.
	 *
	 * @param string $fieldName    The field.
	 * @param array  $uploadedFile The upload, whose name, type, tmp_name, error and size are lists.
	 *
	 * @return array<string, array{name: string, type: string, tmp_name: string, error: int, size: int}> The files.
	 */
	private function multipleFiles(string $fieldName, array $uploadedFile): array {
		$parts = $this->uploadParts(uploadedFile: $uploadedFile);
		$files = [];
		$count = count($parts['name']);
		for ($i = 0; $i < $count; $i++) {
			if (empty($parts['tmp_name'][$i]) === false) {
				$files[$fieldName . '[' . $i . ']'] = $this->multipleFileAt(parts: $parts, index: $i);
			}
		}

		return $files;
	}//end multipleFiles()

	/**
	 * The name, type, tmp_name, error and size lists of a multiple-file upload; a part that is not a list is empty.
	 *
	 * @param array $uploadedFile The upload.
	 *
	 * @return array<string, array> The lists.
	 */
	private function uploadParts(array $uploadedFile): array {
		$parts = ['name' => $uploadedFile['name']];
		foreach (['type', 'tmp_name', 'error', 'size'] as $key) {
			$parts[$key] = [];
			if (is_array($uploadedFile[$key] ?? null) === true) {
				$parts[$key] = $uploadedFile[$key];
			}
		}

		return $parts;
	}//end uploadParts()

	/**
	 * One file of a multiple-file upload.
	 *
	 * @param array<string, array> $parts The upload's name, type, tmp_name, error and size lists.
	 * @param int                  $index The position of the file.
	 *
	 * @return array{name: string, type: string, tmp_name: string, error: int, size: int} The file.
	 */
	private function multipleFileAt(array $parts, int $index): array {
		return [
			'name' => $parts['name'][$index] ?? '',
			'type' => $parts['type'][$index] ?? '',
			'tmp_name' => $parts['tmp_name'][$index],
			'error' => $parts['error'][$index] ?? UPLOAD_ERR_NO_FILE,
			'size' => $parts['size'][$index] ?? 0,
		];
	}//end multipleFileAt()

	/**
	 * The results in their paging envelope, with next and previous page links.
	 *
	 * @param array $results The array of objects to paginate.
	 * @param ResultWindow $window The total and the position asked for.
	 *
	 * @return (array|float|int|string)[]
	 *
	 * @phpstan-param array<int, mixed> $results
	 *
	 * @phpstan-return array<string, mixed>
	 *
	 * @psalm-param array<int, mixed> $results
	 *
	 * @psalm-return array<string, mixed>
	 */
	private function paginate(array $results, ResultWindow $window): array {
		$paginated = $window->over(results: $results);
		$links = new PaginationLinks(uri: $this->request->getRequestUri());

		return array_merge($paginated, $links->around(page: (int)$paginated['page'], pages: (int)$paginated['pages']));
	}//end paginate()

	/**
	 * The listing configuration of this request.
	 *
	 * @return array The configuration, see ObjectRequestParams::listing().
	 *
	 * @deprecated Use buildSearchQuery() instead for faceting-enabled endpoints
	 */
	private function getConfig(): array {
		$params = $this->request->getParams();

		unset($params['id']);
		unset($params['_route']);

		return (new ObjectRequestParams())->listing(params: $params);
	}//end getConfig()

	/**
	 * Perform cross-table search across multiple register+schema combinations.
	 *
	 * @param array $registers Array of register IDs/slugs.
	 * @param array $schemas Array of schema IDs/slugs.
	 * @param ObjectOperationsInterface $objectService Object service for resolution.
	 *
	 * @return JSONResponse Search results from multiple tables.
	 *
	 * @spec openspec/specs/object-interactions/spec.md#requirement-cross-table-searches-honour-the-recent-lens-like-one-schema
	 */
	private function crossTableSearch(array $registers, array $schemas, ObjectOperationsInterface $objectService): JSONResponse {
		$magicMapper = $this->container->get(\OCA\OpenRegister\Db\MagicMapperInterface::class);
		$pairs       = $this->crossTablePairs(registers: $registers, schemas: $schemas);
		if (empty($pairs) === true) {
			return new JSONResponse(
				data: ['message' => 'No valid magic-mapped register+schema combinations found', 'results' => [], 'total' => 0],
				statusCode: 404
			);
		}

		// DOELBINDING, BEFORE ANY ROW IS FETCHED.
		//
		// index() reaches this method by returning early, BEFORE its own purpose
		// check, whenever the request names more than one schema or register. So
		// `?schemas={bound},anythingElse` would return in full the rows that
		// `GET /objects/{register}/{bound}` refuses with 403.
		//
		// The guard belongs here rather than at a call site: this method IS the
		// fan-out, and objects() is a second caller.
		//
		// Refused when ANY pair is refused. A purpose-bound schema must not be
		// launderable by listing it alongside one that is not.
		foreach ($pairs as $pair) {
			$refusal = $this->refuseUnboundPurpose(
				resolved: ['registerEntity' => $pair['register'], 'schemaEntity' => $pair['schema']]
			);
			if ($refusal !== null) {
				return $refusal;
			}
		}

		$query   = $this->crossTableQuery(objectService: $objectService);
		$results = $magicMapper->searchAcrossMultipleTables(query: $query, registerSchemaPairs: $pairs);
		$rows    = $this->crossTableRows(results: $results, query: $query);

		// PERF-10: allow callers to skip the total when not needed. The total is
		// every match across the tables, counted by the mapper on the search's
		// own path, never the size of this page (a pager would show one page).
		$total = null;
		if (filter_var($this->request->getParams()['_count'] ?? true, FILTER_VALIDATE_BOOLEAN) === true) {
			$total = $magicMapper->countAcrossMultipleTables(query: $query, registerSchemaPairs: $pairs);
		}

		$responseData = ['results' => $rows] + $this->crossTablePagination(query: $query, total: $total) + [
			'@self' => [
				'source'         => 'cross_table_magic_mapper',
				'table_count'    => count($pairs),
				'register_count' => count($registers),
				'schema_count'   => count($schemas),
			],
		];

		// The `_recent` lens report, as on the single-schema list
		// (`recently-opened-means-opened`). Present only when asked.
		if (is_array($query['_recentLens'] ?? null) === true) {
			$responseData['@self']['lenses']['recent'] = $query['_recentLens'];
		}

		return new JSONResponse(data: $responseData);
	}//end crossTableSearch()

	/**
	 * The magic-mapped register+schema pairs of a cross-table search.
	 *
	 * PERF-14: each register and schema is resolved once up front, not per combination.
	 * Register::isMagicMappingEnabledForSchema() reads both the new and the legacy
	 * magic-mapping configuration.
	 *
	 * @param array $registers Register IDs/slugs.
	 * @param array $schemas   Schema IDs/slugs.
	 *
	 * @return array<int, array{register: Register, schema: Schema}> The pairs.
	 */
	private function crossTablePairs(array $registers, array $schemas): array {
		$registerEntities = $this->crossTableEntities(ids: $registers, kind: 'register');
		$schemaEntities   = $this->crossTableEntities(ids: $schemas, kind: 'schema');
		$pairs            = [];
		foreach ($registerEntities as $registerEntity) {
			foreach ($schemaEntities as $schemaEntity) {
				if ($registerEntity->isMagicMappingEnabledForSchema(
					schemaId: $schemaEntity->getId(),
					schemaSlug: $schemaEntity->getSlug()
				) === true
				) {
					$pairs[] = ['register' => $registerEntity, 'schema' => $schemaEntity];
				}
			}
		}

		return $pairs;
	}//end crossTablePairs()

	/**
	 * Resolve the registers or schemas of a cross-table search, skipping (and logging) invalid ones.
	 *
	 * @param array  $ids  Register or schema IDs/slugs.
	 * @param string $kind 'register' or 'schema'.
	 *
	 * @return array<string, Register|Schema> The entities by id.
	 */
	private function crossTableEntities(array $ids, string $kind): array {
		$entities = [];
		foreach (array_unique($ids) as $entityId) {
			try {
				$entities[(string)$entityId] = match ($kind) {
					'register' => $this->services->store->registerMapper->find(id: $entityId, _multitenancy: false, _rbac: false),
					default => $this->services->store->schemaMapper->find(id: $entityId, _multitenancy: false, _rbac: false),
				};
			} catch (\Exception $e) {
				$this->logger->warning(
					message: '[ObjectsController] Invalid ' . $kind . ' in cross-table search',
					context: ['file' => __FILE__, 'line' => __LINE__, $kind => $entityId, 'error' => $e->getMessage()]
				);
			}
		}

		return $entities;
	}//end crossTableEntities()

	/**
	 * The search query of a cross-table search, without register or schema context.
	 *
	 * SEC-CTRL-1: this path does not read rbac/multi from the request, so the
	 * request-controlled bypass does not apply; the posture follows admin status.
	 * Both cross-table builders honour these flags (MagicSearchHandler::buildWhereConditionsSql()
	 * renders the multitenancy decision for the UNION arms).
	 *
	 * @param ObjectOperationsInterface $objectService Object service that builds the query.
	 *
	 * @return array<string, mixed> The query.
	 */
	private function crossTableQuery(ObjectOperationsInterface $objectService): array {
		$query   = $this->crossTablePaged(query: $objectService->buildSearchQuery(requestParams: $this->request->getParams()));
		$isAdmin = $this->isCurrentUserAdmin();
		$query['_rbac']         = ($isAdmin === false);
		$query['_multitenancy'] = ($isAdmin === false);

		unset(
			$query['_register'],
			$query['_schema'],
			$query['register'],
			$query['schema'],
			$query['schemas'],
			$query['registers'],
			$query['@self']
		);

		return $query;
	}//end crossTableQuery()

	/**
	 * The query with `_page` turned into the `_offset` the mapper pages on.
	 *
	 * An explicit `_offset` wins, as on the single-schema list. The page counts
	 * in the page size the search can return.
	 *
	 * @param array<string, mixed> $query The search query.
	 *
	 * @return array<string, mixed> The query.
	 *
	 * @spec openspec/specs/objects-crud/spec.md#requirement-list-page-size-is-bounded-by-a-hard-maximum
	 */
	private function crossTablePaged(array $query): array {
		$limit = min((int)($query['_limit'] ?? 20), QueryHandlerInterface::MAX_PAGE_SIZE);
		if (($query['_page'] ?? null) !== null && ($query['_offset'] ?? null) === null && $limit > 0) {
			$query['_offset'] = ((max(1, (int)$query['_page']) - 1) * $limit);
		}

		return $query;
	}//end crossTablePaged()

	/**
	 * The rendered, serialised page of cross-table search results.
	 *
	 * This direct magic-mapper path bypasses renderEntity, so the write-only redaction
	 * (openregister#380, ocon#147), lenses, translations and lightweight files are applied
	 * here per row. `_rbac` gates only the `authorization.read` strip, never the writeOnly
	 * strip (#460, #389); it is always set by crossTableQuery(). Empty values are stripped
	 * unless _empty=true. PERF-6: the sequential per-table merge can return up to
	 * limit x tableCount rows, so the page is capped at _limit; the mapper already applied _offset.
	 *
	 * @param array $results The entities found.
	 * @param array $query   The search query.
	 *
	 * @return array<int, array> The rows.
	 */
	private function crossTableRows(array $results, array $query): array {
		$renderHandler = $this->container->get(\OCA\OpenRegister\Service\Object\RenderObjectInterface::class);
		$renderHandler->redactWriteOnlyFromRows(rows: $results, _rbac: $query['_rbac']);
		$renderHandler->applyLensesToRows(rows: $results, _rbac: $query['_rbac']);
		$renderHandler->resolveTranslationsForRows(rows: $results);
		$renderHandler->attachLightweightFilesToRows(rows: $results);

		$serializedResults = [];
		foreach ($results as $entity) {
			$serializedResults[] = $entity->jsonSerialize();
		}

		$includeEmpty = filter_var(
			value: $this->request->getParams()['_empty'] ?? false,
			filter: FILTER_VALIDATE_BOOLEAN
		);
		if ($includeEmpty === false) {
			$serializedResults = array_map(
				callback: [new EmptyValueStripper(), 'stripped'],
				array: $serializedResults
			);
		}

		$limit = (int)($query['_limit'] ?? 20);
		if ($limit > 0) {
			$serializedResults = array_slice($serializedResults, 0, $limit);
		}

		return $serializedResults;
	}//end crossTableRows()

	/**
	 * The pagination fields of a cross-table search response.
	 *
	 * @param array    $query The search query.
	 * @param int|null $total Every match across the tables, null when not counted.
	 *
	 * @return array{total: int|null, pages: int, page: int, limit: int} The fields.
	 */
	private function crossTablePagination(array $query, ?int $total): array {
		$limit  = min((int)($query['_limit'] ?? 20), QueryHandlerInterface::MAX_PAGE_SIZE);
		$offset = (int)($query['_offset'] ?? 0);
		$pages  = 1;
		$page   = 1;
		if ($limit > 0) {
			if ($total !== null) {
				$pages = (int)ceil($total / $limit);
			}

			$page = (int)floor($offset / $limit) + 1;
		}

		return ['total' => $total, 'pages' => $pages, 'page' => $page, 'limit' => $limit];
	}//end crossTablePagination()

	/**
	 * Refuse a read that has to name a purpose and does not.
	 *
	 * Returns null in the two cases that must stay exactly as they were: no
	 * guard wired (an older container), and a schema that does not ask for
	 * doelbinding. That null is the backwards-compatibility promise, and an
	 * instance that administers no purposes reads precisely as it did before.
	 *
	 * 403, not 404. The RBAC denials above return 404 so a caller cannot learn
	 * that an object exists; this refusal leaks nothing, because the caller
	 * already knows the schema and is being told to name a grondslag. Hiding it
	 * as a 404 would send an integrator hunting for a missing record instead of
	 * setting one header.
	 *
	 * @param array $resolved The resolved register and schema entities.
	 *
	 * @return JSONResponse|null The refusal, or null when the read may proceed.
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	private function refuseUnboundPurpose(array $resolved): ?JSONResponse {
		if ($this->governance->purposeGuard === null) {
			return null;
		}

		try {
			$this->governance->purposeGuard->enforce(
				register: ($resolved['registerEntity'] ?? null),
				schema: ($resolved['schemaEntity'] ?? null)
			);
		} catch (\OCA\OpenRegister\Service\Audit\PurposeRefusedException $refusal) {
			return self::purposeRefusal(refusal: $refusal);
		}

		return null;
	}//end refuseUnboundPurpose()

	/**
	 * The answer to a read refused for want of a purpose.
	 *
	 * @param \OCA\OpenRegister\Service\Audit\PurposeRefusedException $refusal The refusal.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	private static function purposeRefusal(\OCA\OpenRegister\Service\Audit\PurposeRefusedException $refusal): JSONResponse {
		return new JSONResponse(data: $refusal->toResponseBody(), statusCode: $refusal->getStatusCode());
	}//end purposeRefusal()

	/**
	 * Log one warning for the filter keys that name no property of the schema.
	 *
	 * Such a filter answers `1 = 0`, so the caller gets an empty list that is
	 * indistinguishable from a schema with no matching rows. That silence is
	 * what made openregister#3611 invisible for as long as it was: humaniq's
	 * hours widget summed the empty set and would have rendered `0` hours on
	 * every object. The warning names the keys, the endpoint and the schema so
	 * the mistake is visible in the log without refusing the request.
	 *
	 * @param array<string, mixed> $query The query from `buildSearchQuery()`.
	 * @param array<string, mixed> $listing The listing: the URL references and the resolved schema.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-both-filter-spellings-mean-the-same-filter-on-object-search-and-the-aggregations
	 */
	private function warnUnknownFilterKeys(array $query, array $listing): void {
		$schemaEntity = ($listing['resolved']['schemaEntity'] ?? null);
		if ($schemaEntity === null) {
			return;
		}

		FilterParams::warnUnknownKeys(
			logger: $this->logger,
			unknown: new UnknownFilterKeys(
				keys: FilterParams::unknownObjectFilterKeys(query: $query, properties: $schemaEntity->getProperties()),
				endpoint: 'objects#index',
				table: new RegisterSchemaRef(register: $listing['register'], schema: $listing['schema'])
			)
		);
	}//end warnUnknownFilterKeys()

	/**
	 * Refuse a `_search` term the boolean parser cannot read.
	 *
	 * A term with an unbalanced bracket, a dangling operator or an unterminated
	 * quote has one correct answer, and it is not "no results". Evaluating it as
	 * a literal string returns zero rows, which on screen is indistinguishable
	 * from a search that legitimately found nothing.
	 *
	 * @param array $params The raw request parameters.
	 *
	 * @phpstan-param array<string, mixed> $params
	 *
	 * @psalm-param array<string, mixed> $params
	 *
	 * @return JSONResponse|null A 400 naming the fault, or null when the term reads.
	 *
	 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
	 */
	private function refuseMalformedSearchTerm(array $params): ?JSONResponse {
		$term = $this->parsedSearchTerm(params: $params);
		if ($term === null) {
			return null;
		}

		try {
			SearchGrammar::termParser()->parse(term: $term);
		} catch (SearchTermSyntaxException $exception) {
			return $this->searchSyntaxRefusal(exception: $exception);
		}

		return null;
	}//end refuseMalformedSearchTerm()

	/**
	 * The `_search` term, trimmed, when the boolean parser has to read it.
	 *
	 * @param array $params The request params.
	 *
	 * @return string|null The term, or null when there is nothing to parse.
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	private function parsedSearchTerm(array $params): ?string {
		$search = ($params['_search'] ?? null);
		if (is_string($search) === false || trim($search) === '') {
			return null;
		}

		$term = trim($search);
		if (SearchGrammar::termParser()->needsParsing(term: $term) === true) {
			return $term;
		}

		return null;
	}//end parsedSearchTerm()

	/**
	 * The 400 for a search term the parser cannot read.
	 *
	 * @param SearchTermSyntaxException $exception The parser's refusal.
	 *
	 * @return JSONResponse The refusal, naming the position and the term.
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	private function searchSyntaxRefusal(SearchTermSyntaxException $exception): JSONResponse {
		return new JSONResponse(
			data: [
				'error' => $exception->getMessage(),
				'position' => $exception->getPosition(),
				'term' => $exception->getTerm(),
			],
			statusCode: Http::STATUS_BAD_REQUEST
		);
	}//end searchSyntaxRefusal()

	/**
	 * Refuse a history filter over a property that has no history.
	 *
	 * The answerable properties are the ones SCHEMAS DECLARE as their lifecycle
	 * field, never the property names that happen to sit in the projection: a
	 * row written by mistake must not make a property filterable, and an empty
	 * projection must still know that `status` is a property with history.
	 *
	 * @param array $params The raw request parameters.
	 *
	 * @phpstan-param array<string, mixed> $params
	 *
	 * @psalm-param array<string, mixed> $params
	 *
	 * @return JSONResponse|null A 400 naming the property, or null.
	 *
	 * @spec openspec/changes/search-over-history-and-an-administered-dictionary/specs/zoeken-filteren/spec.md
	 */
	private function refuseUnprojectedHistoryPredicate(array $params): ?JSONResponse {
		if ($this->governance->stateHistory === null) {
			return null;
		}

		$predicate = SearchGrammar::historyPredicate(query: $params);
		if ($predicate->narrows() === false) {
			return null;
		}

		return $this->historyRefusal(predicate: $predicate);
	}//end refuseUnprojectedHistoryPredicate()

	/**
	 * The 400 for a history predicate over a property with no recorded history.
	 *
	 * @param HistoryPredicateInterface $predicate The parsed predicate.
	 *
	 * @return JSONResponse|null A 400 naming the property, or null.
	 *
	 * @spec openspec/changes/search-over-history-and-an-administered-dictionary/specs/zoeken-filteren/spec.md
	 */
	private function historyRefusal(HistoryPredicateInterface $predicate): ?JSONResponse {
		$refusal = $predicate->refusalFor(projectedProperties: $this->governance->stateHistory->projectedProperties());
		if ($refusal === null) {
			return null;
		}

		return new JSONResponse(
			data: ['error' => $refusal, 'properties' => $predicate->properties()],
			statusCode: Http::STATUS_BAD_REQUEST
		);
	}//end historyRefusal()

	/**
	 * Retrieves a list of all objects for a specific register and schema
	 *
	 * This method returns a paginated list of objects that match the specified register and schema.
	 * It supports filtering, sorting, pagination, faceting, and facetable field discovery through query parameters.
	 *
	 * Supported parameters:
	 * - Standard filters: Any object field (e.g., name, status, etc.)
	 * - Metadata filters: register, schema, uuid, created, updated, etc.
	 * - Pagination: _limit, _offset, _page
	 * - Search: _search
	 * - Rendering: _extend, _fields, _filter/_unset
	 * - Faceting: _facets (facet configuration), _facetable (facetable field discovery)
	 * - Aggregations: _aggregations (enable aggregations in response)
	 * - Debug: _debug (enable debug information in response)
	 * - Source: _source (ignored; the database is the only search source)
	 * - Sorting: _order
	 * - Empty values: _empty (boolean, default: false). When false (default), null, empty string,
	 *   and empty array values are stripped from the response to reduce payload size.
	 *   Set _empty=true to include all properties including empty values.
	 *
	 * @param string $register The register slug or identifier
	 * @param string $schema The schema slug or identifier
	 * @param ObjectOperationsInterface $objectService The object service
	 *
	 * @return JSONResponse A JSON response containing the list of objects with optional facets and facetable fields
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @PublicPage
	 *
	 * @psalm-return JSONResponse<200|404, array<string, mixed>, array<never, never>>
	 *
	 * @SuppressWarnings(PHPMD.NPathComplexity)       Complex request parameter handling for flexible API
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Multi-schema search + pagination + filtering requires branching
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	#[UserRateLimit(limit: 600, period: 60)]
	#[AnonRateLimit(limit: 120, period: 60)]
	public function index(string $register, string $schema, ObjectOperationsInterface $objectService): JSONResponse {
		// Read paths were unmeasured: WritePhaseProbe::flush() is only reached
		// from the write path, so search and single-read reported no
		// per-request counts at all. Stamp + flush here so the same in-PHP
		// counters that made the write numbers trustworthy cover reads too.
		\OCA\OpenRegister\Service\WritePhase::stamp('ctrl.index.in');

		$params = $this->request->getParams();

		$refusal = $this->refuseUnanswerableSearch(params: $params);
		if ($refusal !== null) {
			return $refusal;
		}

		$urlPath = ['register' => $register, 'schema' => $schema];
		$targets = $this->crossTableTargets(params: $params, urlPath: $urlPath);
		if ($targets !== null) {
			return $this->crossTableSearch(registers: $targets['registers'], schemas: $targets['schemas'], objectService: $objectService);
		}

		try {
			$resolved = $this->resolveRegisterSchemaRef(ref: new RegisterSchemaRef(register: $register, schema: $schema), objectService: $objectService);
		} catch (RegisterNotFoundException|SchemaNotFoundException $e) {
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: 404);
		}

		// Doelbinding. A list over a schema that asks for a declared purpose is
		// a registry query like any other: the person search the requirement was
		// written for IS a list, so guarding only the single read would leave
		// the case it exists for open.
		$refusal = $this->refuseUnboundPurpose(resolved: $resolved);
		if ($refusal !== null) {
			return $refusal;
		}

		$listing = $this->buildListingScope(urlPath: $urlPath, resolved: $resolved);

		if ($this->isServedFromMagicTable(resolved: $resolved) === true) {
			return $this->indexFromMagicTable(listing: $listing, objectService: $objectService);
		}

		return $this->indexFromObjectService(listing: $listing, objectService: $objectService);
	}//end index()

	/**
	 * Refuse a search the index cannot answer truthfully.
	 *
	 * A malformed boolean term is refused before any source is chosen: deeper
	 * down the facet builders catch \Exception broadly, so a refusal raised in
	 * the mapper could be swallowed into an empty facet list. A history filter
	 * over a property nothing records is refused by name: answered instead, it
	 * would be an empty page that looks identical to a true "found nothing".
	 *
	 * @param array $params The request parameters.
	 *
	 * @psalm-param array<string, mixed> $params
	 *
	 * @return JSONResponse|null A 400 response, or null when the search may run.
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	private function refuseUnanswerableSearch(array $params): ?JSONResponse {
		$refusal = $this->refuseMalformedSearchTerm(params: $params);
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->refuseUnprojectedHistoryPredicate(params: $params);
	}//end refuseUnanswerableSearch()

	/**
	 * The registers and schemas of a cross-table search, or null for a single-table listing.
	 *
	 * Both `schemas[]=1&schemas[]=2` and `schemas=1,2` are accepted, and the same
	 * for `registers`. Only an explicit list of more than one value makes the
	 * listing cross-table; the URL path fills in the side that was not listed.
	 *
	 * @param array $params The request parameters.
	 * @param array $urlPath The register and schema from the URL path.
	 *
	 * @psalm-param array<string, mixed> $params
	 * @psalm-param array{register: string, schema: string} $urlPath
	 *
	 * @return array{registers: array, schemas: array}|null
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	private function crossTableTargets(array $params, array $urlPath): ?array {
		$schemasList = [];
		if (($params['schemas'] ?? null) !== null) {
			$schemasList = (new ObjectRequestParams())->multiValue(param: $params['schemas'], defaultValue: $urlPath['schema']);
		}

		$registersList = [];
		if (($params['registers'] ?? null) !== null) {
			$registersList = (new ObjectRequestParams())->multiValue(param: $params['registers'], defaultValue: $urlPath['register']);
		}

		if (count($schemasList) <= 1 && count($registersList) <= 1) {
			return null;
		}

		if (empty($schemasList) === true) {
			$schemasList = [$urlPath['schema']];
		}

		if (empty($registersList) === true) {
			$registersList = [$urlPath['register']];
		}

		return ['registers' => $registersList, 'schemas' => $schemasList];
	}//end crossTableTargets()

	/**
	 * Collect what both listing paths need to know about the request.
	 *
	 * SEC-CTRL-1: RBAC and multitenancy posture MUST be derived from the
	 * caller's admin status, never from request parameters. This endpoint is
	 * reachable anonymously (@PublicPage); honouring ?rbac=false&_multi=false
	 * would let any caller list every object across all organisations/ACLs.
	 *
	 * @param array $urlPath The register and schema from the URL path.
	 * @param array $resolved The resolved register and schema.
	 *
	 * @psalm-param array{register: string, schema: string} $urlPath
	 * @psalm-param array<string, mixed> $resolved
	 *
	 * @return array<string, mixed> The listing scope.
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	private function buildListingScope(array $urlPath, array $resolved): array {
		$params = $this->request->getParams();
		$isAdmin = $this->isCurrentUserAdmin();

		return [
			'register' => $urlPath['register'],
			'schema' => $urlPath['schema'],
			'resolved' => $resolved,
			'params' => $params,
			'rbac' => ($isAdmin === false),
			'multi' => ($isAdmin === false),
			'deleted' => filter_var($params['deleted'] ?? false, FILTER_VALIDATE_BOOLEAN),
		];
	}//end buildListingScope()

	/**
	 * Whether the listing reads the schema's magic table.
	 *
	 * A schema served from an external object-source (x-openregister-object-source)
	 * must never read the magic table: it goes through searchObjectsPaginated,
	 * which delegates to the registered provider (object-source-providers).
	 *
	 * @param array $resolved The resolved register and schema.
	 *
	 * @psalm-param array<string, mixed> $resolved
	 *
	 * @return bool
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	private function isServedFromMagicTable(array $resolved): bool {
		$registerEntity = $resolved['registerEntity'] ?? null;
		$schemaEntity = $resolved['schemaEntity'] ?? null;
		if ($registerEntity === null || $schemaEntity === null) {
			return false;
		}

		// Supports both {"schemas": {"module": {"magicMapping": true}}} and the
		// legacy {"enableMagicMapping": true, "magicMappingSchemas": [...]}.
		$isMagicMapped = $registerEntity->isMagicMappingEnabledForSchema(
			schemaId: $schemaEntity->getId(),
			schemaSlug: $schemaEntity->getSlug()
		);

		return $isMagicMapped === true && $schemaEntity->getObjectSource() === null;
	}//end isServedFromMagicTable()

	/**
	 * List the objects of a magic-mapped schema straight from its table.
	 *
	 * @param array $listing The listing scope.
	 * @param ObjectOperationsInterface $objectService The object service.
	 *
	 * @psalm-param array<string, mixed> $listing
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	private function indexFromMagicTable(array $listing, ObjectOperationsInterface $objectService): JSONResponse {
		$magicMapper = $this->container->get(\OCA\OpenRegister\Db\MagicMapperInterface::class);
		$registerEntity = $listing['resolved']['registerEntity'];
		$schemaEntity = $listing['resolved']['schemaEntity'];

		$query = $this->buildListingQuery(listing: $listing, objectService: $objectService);
		$query['_rbac'] = $listing['rbac'];
		$query['_multitenancy'] = $listing['multi'];
		// Never request-controlled; read by the public schema bypass logic.
		$query['_multitenancy_explicit'] = false;
		$listing['query'] = $query;
		$listing['magicMapper'] = $magicMapper;

		$results = $magicMapper->searchObjectsInRegisterSchemaTable(
			query: $query,
			register: $registerEntity,
			schema: $schemaEntity
		);
		$serializedResults = $this->serializeMagicResults(results: $results, listing: $listing);
		$page = $this->paginateMagicResults(listing: $listing);
		$page['results'] = $serializedResults;

		$responseData = $this->buildMagicResponse(page: $page, listing: $listing);
		$responseData = $this->addIgnoredFilterHint(responseData: $responseData, ignoredFilters: $magicMapper->getIgnoredFilters());
		$responseData = $this->addMagicFacets(responseData: $responseData, listing: $listing);

		return $this->magicListingResponse(responseData: $responseData, listing: $listing);
	}//end indexFromMagicTable()

	/**
	 * Strip empty values, apply the spatial post-filter and negotiate the format of a magic-table listing.
	 *
	 * @param array $responseData The response body.
	 * @param array $listing The listing scope, with its search query.
	 *
	 * @psalm-param array<string, mixed> $responseData
	 * @psalm-param array<string, mixed> $listing
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	private function magicListingResponse(array $responseData, array $listing): JSONResponse {
		$includeEmpty = filter_var(
			value: $listing['query']['_empty'] ?? $listing['params']['_empty'] ?? false,
			filter: FILTER_VALIDATE_BOOLEAN
		);
		if ($includeEmpty === false) {
			$responseData['results'] = $this->stripEmptyFromResults(results: $responseData['results']);
		}

		// Spatial post-filter, as on the non-magic-mapped path.
		$responseData = $this->applyGeoQueryFilters(params: $listing['params'], result: $responseData);

		// Content negotiation: JSON-LD @graph for magic-mapped results (json-ld-output).
		if ($this->wantsJsonLd() === true) {
			return $this->jsonLdCollectionResponse(result: $responseData, resolved: $listing['resolved']);
		}

		// Response compression is negotiated at the webserver level; the
		// controller never sets encoding headers.
		return new JSONResponse(data: $responseData);
	}//end magicListingResponse()

	/**
	 * Build the search query of a listing and warn about filter keys the schema does not know.
	 *
	 * @param array $listing The listing scope.
	 * @param ObjectOperationsInterface $objectService The object service.
	 *
	 * @psalm-param array<string, mixed> $listing
	 *
	 * @return array<string, mixed> The search query.
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	private function buildListingQuery(array $listing, ObjectOperationsInterface $objectService): array {
		$query = $objectService->buildSearchQuery(
			requestParams: $this->request->getParams(),
			register: $listing['resolved']['register'],
			schema: $listing['resolved']['schema']
		);

		$this->warnUnknownFilterKeys(query: $query, listing: $listing);

		return $query;
	}//end buildListingQuery()

	/**
	 * The `_extend` entries of a query, without the schema and register extensions.
	 *
	 * Schema and register are provided at response level, so they are never
	 * extended per row.
	 *
	 * @param array $query The search query.
	 *
	 * @psalm-param array<string, mixed> $query
	 *
	 * @return array The extensions to render.
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	private function rowExtensions(array $query): array {
		$extend = $query['_extend'] ?? [];
		if (is_string($extend) === true) {
			$extend = array_filter(array_map('trim', explode(',', $extend)));
		}

		return array_filter(
			$extend,
			function (string $item): bool {
				return !in_array($item, ['@self.schema', '@self.register', '_schema', '_register'], true);
			}
		);
	}//end rowExtensions()

	/**
	 * Render or serialize the rows a magic-table search returned.
	 *
	 * @param array $results The ObjectEntity rows.
	 * @param array $listing The listing scope, with its search query.
	 *
	 * @psalm-param array<string, mixed> $listing
	 *
	 * @return array The rendered rows.
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	private function serializeMagicResults(array $results, array $listing): array {
		$extend = $this->rowExtensions(query: $listing['query']);
		if ($this->needsFullRender(query: $listing['query'], extend: $extend) === true && empty($results) === false) {
			return $this->fullyRenderedRows(results: $results, listing: $listing + ['extend' => $extend]);
		}

		return $this->renderedMagicRows(results: $results, rbac: $listing['rbac']);
	}//end serializeMagicResults()

	/**
	 * Rows through the full render, for a listing that asks for more than plain rows.
	 *
	 * @param array<int, ObjectEntity> $results The rows.
	 * @param array<string, mixed> $listing The listing scope, with its `extend`.
	 *
	 * @return array<int, array<string, mixed>> The rendered rows.
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	private function fullyRenderedRows(array $results, array $listing): array {
		$query = $listing['query'];

		return $this->container->get(\OCA\OpenRegister\Service\Object\RenderObjectInterface::class)->renderEntities(
			entities: $results,
			_extend: $listing['extend'],
			_filter: ($query['_filter'] ?? null),
			_fields: ($query['_fields'] ?? null),
			_unset: ($query['_unset'] ?? null),
			_rbac: $listing['rbac'],
			_multitenancy: $listing['multi']
		);
	}//end fullyRenderedRows()

	/**
	 * Whether a listing asks for more than the plain rows.
	 *
	 * @param array<string, mixed> $query The search query.
	 * @param array<int, mixed> $extend The row extensions.
	 *
	 * @return bool True when extensions or a field filter apply.
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	private function needsFullRender(array $query, array $extend): bool {
		return empty($extend) === false
			|| empty($query['_fields'] ?? null) === false
			|| empty($query['_filter'] ?? null) === false
			|| empty($query['_unset'] ?? null) === false;
	}//end needsFullRender()

	/**
	 * Work out limit, page, page count and total of a magic-table listing.
	 *
	 * PERF-10: `_count=false` or `_noTotal=true` skips the extra COUNT(*)
	 * query and reports total null, for callers that do not need the grand
	 * total (e.g. infinite scroll).
	 *
	 * @param array $listing The listing scope, with its search query and magic mapper.
	 *
	 * @psalm-param array<string, mixed> $listing
	 *
	 * @return array{total: int|null, pages: int, page: int, limit: int}
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	private function paginateMagicResults(array $listing): array {
		$query = $listing['query'];
		$limit = (int)($query['_limit'] ?? 20);
		$total = $this->countMagicTotal(listing: $listing);
		if ($limit <= 0) {
			return ['total' => $total, 'pages' => 1, 'page' => 1, 'limit' => $limit];
		}

		$pages = 1;
		if ($total !== null) {
			$pages = (int)ceil($total / $limit);
		}

		return ['total' => $total, 'pages' => $pages, 'page' => $this->magicPage(query: $query, limit: $limit), 'limit' => $limit];
	}//end paginateMagicResults()

	/**
	 * The page of a magic-table query: the one asked for, or the one its offset falls on.
	 *
	 * @param array $query The search query.
	 * @param int   $limit The page size, above zero.
	 *
	 * @return int The page.
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	private function magicPage(array $query, int $limit): int {
		if (($query['_page'] ?? null) !== null) {
			return (int)$query['_page'];
		}

		return ((int)floor((int)($query['_offset'] ?? 0) / $limit) + 1);
	}//end magicPage()

	/**
	 * The grand total of a magic-table listing, or null when the caller does not want it.
	 *
	 * The count query is the search query without its pagination keys.
	 *
	 * @param array $listing The listing scope, with its search query and magic mapper.
	 *
	 * @psalm-param array<string, mixed> $listing
	 *
	 * @return int|null
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	private function countMagicTotal(array $listing): ?int {
		if ($this->wantsListingTotal(params: $listing['params']) === false) {
			return null;
		}

		$countQuery = $listing['query'];
		unset($countQuery['_limit'], $countQuery['_offset'], $countQuery['_page']);

		return $listing['magicMapper']->countObjectsInRegisterSchemaTable(
			query: $countQuery,
			register: $listing['resolved']['registerEntity'],
			schema: $listing['resolved']['schemaEntity']
		);
	}//end countMagicTotal()

	/**
	 * Whether the caller wants the grand total counted.
	 *
	 * @param array $params The request parameters.
	 *
	 * @psalm-param array<string, mixed> $params
	 *
	 * @return bool
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	private function wantsListingTotal(array $params): bool {
		$countDefault = (($params['_noTotal'] ?? false) !== true);

		return filter_var($params['_count'] ?? $countDefault, FILTER_VALIDATE_BOOLEAN);
	}//end wantsListingTotal()

	/**
	 * The response body of a magic-table listing, before hints, facets and post-filters.
	 *
	 * @param array $page The rendered rows and the pagination figures.
	 * @param array $listing The listing scope, with its search query.
	 *
	 * @psalm-param array<string, mixed> $page
	 * @psalm-param array<string, mixed> $listing
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	private function buildMagicResponse(array $page, array $listing): array {
		$responseData = [
			'results' => $page['results'],
			'total' => $page['total'],
			'pages' => $page['pages'],
			'page' => $page['page'],
			'limit' => $page['limit'],
			'@self' => $this->magicSelfBlock(listing: $listing),
		];

		// The `_recent` lens report: whether it could answer, and why not
		// (`read-history-on-audit-trail`). Present only when asked.
		if (is_array($listing['query']['_recentLens'] ?? null) === true) {
			$responseData['@self']['lenses']['recent'] = $listing['query']['_recentLens'];
		}

		return $responseData;
	}//end buildMagicResponse()

	/**
	 * The `@self` block of a magic-table listing.
	 *
	 * @param array $listing The listing scope, with its search query.
	 *
	 * @psalm-param array<string, mixed> $listing
	 *
	 * @return array The block.
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	private function magicSelfBlock(array $listing): array {
		return [
			'source' => 'magic_mapper',
			'register' => $listing['register'],
			'schema' => $listing['schema'],
			'query' => $listing['query'],
			'rbac' => $listing['rbac'],
			'multi' => $listing['multi'],
			'deleted' => $listing['deleted'],
			'activeOrganisation' => $this->activeOrganisationUuid(),
		];
	}//end magicSelfBlock()

	/**
	 * The active organisation's UUID for the debugging metadata, or null when unavailable.
	 *
	 * @return string|null
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	private function activeOrganisationUuid(): ?string {
		try {
			$organisationService = $this->container->get(\OCA\OpenRegister\Service\OrganisationService::class);
			$activeOrg = $organisationService?->getActiveOrganisation();
			return $activeOrg?->getUuid();
		} catch (\Throwable $e) {
			// Silently ignore if organisation service is not available.
			return null;
		}
	}//end activeOrganisationUuid()

	/**
	 * Report ignored filters, with a hint when a control parameter lost its underscore.
	 *
	 * `filter` is deliberately NOT a control parameter here: `filter[x]` is the
	 * bracket filter spelling, while `_filter` is the response field-exclusion
	 * parameter, so "did you mean _filter?" would turn a scoped query into an
	 * unscoped one.
	 *
	 * @param array $responseData The response body.
	 * @param array $ignoredFilters The filter keys the mapper ignored.
	 *
	 * @psalm-param array<string, mixed> $responseData
	 *
	 * @return array<string, mixed> The response body.
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	private function addIgnoredFilterHint(array $responseData, array $ignoredFilters): array {
		if (empty($ignoredFilters) === true) {
			return $responseData;
		}

		$responseData['@self']['ignoredFilters'] = $ignoredFilters;

		$controlParams = ['limit', 'offset', 'page', 'order', 'sort', 'search', 'extend', 'fields', 'unset'];
		$mistakenParams = array_intersect($ignoredFilters, $controlParams);
		if (empty($mistakenParams) === false) {
			$responseData['@self']['hint'] = $this->controlParamHint(mistakenParams: $mistakenParams);
		}

		return $responseData;
	}//end addIgnoredFilterHint()

	/**
	 * The hint for control parameters sent without their underscore.
	 *
	 * @param array $mistakenParams The parameters.
	 *
	 * @return string The hint.
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	private function controlParamHint(array $mistakenParams): string {
		$suggestions = array_map(fn ($param) => "_{$param}", $mistakenParams);
		$hint = 'Query returned 0 results because ';
		$hint .= implode(', ', $mistakenParams);
		$hint .= ' was treated as an object property filter.';
		$hint .= ' Did you mean ';
		$hint .= implode(', ', $suggestions);
		$hint .= '? Control parameters require an';
		$hint .= ' underscore prefix (e.g. _limit, _offset, _page).';

		return $hint;
	}//end controlParamHint()

	/**
	 * Add the magic table's facets when `_facets` asks for them.
	 *
	 * @param array $responseData The response body.
	 * @param array $listing The listing scope, with its search query and magic mapper.
	 *
	 * @psalm-param array<string, mixed> $responseData
	 * @psalm-param array<string, mixed> $listing
	 *
	 * @return array<string, mixed> The response body.
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	private function addMagicFacets(array $responseData, array $listing): array {
		if (empty($listing['query']['_facets']) === true) {
			return $responseData;
		}

		try {
			$facets = $this->magicFacets(listing: $listing);
			if (empty($facets) === false) {
				$responseData['facets'] = $facets;
			}
		} catch (\Exception $e) {
			$responseData['@self']['facet_error'] = $e->getMessage();
		}

		return $responseData;
	}//end addMagicFacets()

	/**
	 * The facets of a magic-table listing.
	 *
	 * @param array $listing The listing scope, with its search query and mapper.
	 *
	 * @psalm-param array<string, mixed> $listing
	 *
	 * @return array The facets.
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	private function magicFacets(array $listing): array {
		return $listing['magicMapper']->getSimpleFacetsFromRegisterSchemaTable(
			query: $listing['query'],
			register: $listing['resolved']['registerEntity'],
			schema: $listing['resolved']['schemaEntity']
		);
	}//end magicFacets()

	/**
	 * Strip empty values from every row, serializing ObjectEntity rows first.
	 *
	 * @param array $results The rows.
	 *
	 * @return array The rows without null, empty-string and empty-array values.
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	private function stripEmptyFromResults(array $results): array {
		return (new EmptyValueStripper())->strippedRows(results: $results);
	}//end stripEmptyFromResults()

	/**
	 * List the objects through ObjectService, which picks the source.
	 *
	 * @param array $listing The listing scope.
	 * @param ObjectOperationsInterface $objectService The object service.
	 *
	 * @psalm-param array<string, mixed> $listing
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	private function indexFromObjectService(array $listing, ObjectOperationsInterface $objectService): JSONResponse {
		$query = $this->buildListingQuery(listing: $listing, objectService: $objectService);

		try {
			$result = $objectService->searchObjectsPaginated(
				query: $query,
				_rbac: $listing['rbac'],
				_multitenancy: $listing['multi'],
				deleted: $listing['deleted']
			);
		} catch (NotAuthorizedException $exception) {
			// RBAC denied the schema-level list read. Mirror show(): 404, not
			// 403/500, so denial reveals nothing about the schema's contents.
			return new JSONResponse(data: ['error' => 'Not Found'], statusCode: 404);
		}

		$includeEmpty = filter_var(
			value: $listing['params']['_empty'] ?? false,
			filter: FILTER_VALIDATE_BOOLEAN
		);
		if ($includeEmpty === false && isset($result['results']) === true && is_array($result['results']) === true) {
			$result['results'] = $this->stripEmptyFromResults(results: $result['results']);
		}

		// Spatial post-filter for ?geo.bbox= / ?geo.near=&geo.radius= / ?geo.property=.
		$result = $this->applyGeoQueryFilters(params: $listing['params'], result: $result);

		// Content negotiation: a JSON-LD @graph over the already-rendered,
		// RBAC-filtered result (json-ld-output).
		$registerEntity = $listing['resolved']['registerEntity'] ?? null;
		$schemaEntity = $listing['resolved']['schemaEntity'] ?? null;
		if ($this->wantsJsonLd() === true && $registerEntity !== null && $schemaEntity !== null) {
			return $this->jsonLdCollectionResponse(
				result: $result,
				resolved: ['registerEntity' => $registerEntity, 'schemaEntity' => $schemaEntity]
			);
		}

		return new JSONResponse(data: $result);
	}//end indexFromObjectService()

	/**
	 * Batched object-count endpoint — POST /api/objects/counts.
	 *
	 * Accepts a JSON body of the shape
	 * `{ "counts": [ { "register": <id|slug>, "schema": <id|slug>, "filter": <object|null> } ] }`
	 * and returns `{ "results": [ { "register": ..., "schema": ..., "count": <int|null> } ] }`
	 * with exactly one result per input entry, in the same order as the
	 * request array. Identical `(register, schema, filter)` triples are
	 * deduped server-side so the aggregate runs once per distinct triple;
	 * every input entry still receives a result (duplicates share the count).
	 * An empty or missing `counts` array returns `{ results: [] }`.
	 *
	 * SECURITY — authorization parity with collection reads: each count is
	 * produced through the SAME RBAC + multitenancy scoping the collection
	 * list read (`index()` / `GET /api/objects/{register}/{schema}?_limit=1`)
	 * applies. The RBAC/multitenancy posture is derived from the caller's
	 * admin status (mirrors `index()` lines 1160-1162), never from request
	 * parameters, and threaded into the identical count paths `index()` uses
	 * (see `countPairScoped()`). A caller therefore cannot obtain a count for
	 * objects they are not permitted to list. A pair that cannot be resolved
	 * (unknown or withheld) yields `count: null` without disclosing whether it
	 * does not exist or is access-restricted.
	 *
	 * The route carries `@NoAdminRequired` and is deliberately NOT a public
	 * page: any authenticated user may call it; an unauthenticated request is
	 * rejected by the security middleware exactly like a non-public read.
	 *
	 * @param ObjectOperationsInterface $objectService The object service (DI).
	 *
	 * @return JSONResponse `{ results: [ { register, schema, count } ] }`
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @psalm-return JSONResponse<200|400, array<string, mixed>, array<never, never>>
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	public function counts(ObjectOperationsInterface $objectService): JSONResponse {
		$params = $this->request->getParams();
		$entries = ($params['counts'] ?? null);

		// Missing counts key or empty array → empty success (spec: empty or missing → { results: [] }).
		if ($entries === null || $entries === []) {
			return new JSONResponse(data: ['results' => []]);
		}

		// A present-but-non-array counts value is a malformed request.
		if (is_array($entries) === false) {
			return new JSONResponse(
				data: ['error' => 'counts must be an array of { register, schema, filter? } entries'],
				statusCode: 400
			);
		}

		// Validate every entry up-front so a malformed batch is rejected
		// wholesale rather than silently skipping entries.
		$malformed = self::malformedCountEntry(entries: $entries);
		if ($malformed !== null) {
			return new JSONResponse(
				data: [
					'error' => 'Malformed counts entry at index ' . $malformed
						. ': register and schema are required scalars and filter must be an object',
				],
				statusCode: 400
			);
		}

		// Derive the RBAC + multitenancy posture from the caller's admin
		// status — identical to the collection read (index() lines 1160-1162).
		// Never honour request-supplied rbac/multi flags on a data endpoint.
		$scoped = ($this->isCurrentUserAdmin() === false);
		$scope = new ObjectCountScope(rbac: $scoped, multi: $scoped, objectService: $objectService);

		return new JSONResponse(data: ['results' => $this->countResults(entries: $entries, scope: $scope)]);
	}//end counts()

	/**
	 * The index of the first malformed counts entry.
	 *
	 * @param array $entries The counts entries.
	 *
	 * @return int|string|null The index, or null when every entry is well formed.
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	private static function malformedCountEntry(array $entries): int|string|null {
		foreach ($entries as $index => $entry) {
			if (self::isCountEntry(entry: $entry) === false) {
				return $index;
			}
		}

		return null;
	}//end malformedCountEntry()

	/**
	 * Whether a counts entry names a scalar register and schema, and an object as its filter.
	 *
	 * @param mixed $entry The counts entry.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	private static function isCountEntry(mixed $entry): bool {
		return is_array($entry) === true
			&& is_scalar($entry['register'] ?? null) === true
			&& is_scalar($entry['schema'] ?? null) === true
			&& is_array($entry['filter'] ?? []) === true;
	}//end isCountEntry()

	/**
	 * The count of every entry in request order.
	 *
	 * Runs one aggregate per distinct (register, schema, filter) triple;
	 * duplicates share the deduped count.
	 *
	 * @param array            $entries The counts entries.
	 * @param ObjectCountScope $scope   The scoping of the counts.
	 *
	 * @return array The results.
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	private function countResults(array $entries, ObjectCountScope $scope): array {
		$cache = [];
		$results = [];
		foreach ($entries as $entry) {
			$cacheKey = $entry['register'] . '|' . $entry['schema'] . '|' . json_encode($entry['filter'] ?? []);
			if (array_key_exists($cacheKey, $cache) === false) {
				$cache[$cacheKey] = $this->countPairScoped(entry: $entry, scope: $scope);
			}

			$results[] = [
				'register' => $entry['register'],
				'schema' => $entry['schema'],
				'count' => $cache[$cacheKey],
			];
		}

		return $results;
	}//end countResults()

	/**
	 * Produce a single (register, schema, filter) object count with the exact
	 * RBAC + multitenancy scoping the collection read applies.
	 *
	 * This mirrors `index()`'s count logic so a batched count can never leak a
	 * total the equivalent list read would not surface. It resolves the pair,
	 * then routes through the same two count paths `index()` uses:
	 * - magic-mapped schema → `MagicMapper::countObjectsInRegisterSchemaTable()`
	 *   with `_rbac` / `_multitenancy` threaded into the query
	 *   (mirrors index() lines 1194-1198 + 1279-1283);
	 * - database-backed schema → `ObjectService::searchObjectsPaginated()`
	 *   total, produced by the same RBAC/multitenancy-scoped query
	 *   (mirrors index() lines 1443-1448), read at `?_limit=1` like the
	 *   reference collection read.
	 *
	 * Returns null when the pair cannot be resolved, so a restricted or
	 * unknown pair is withheld without disclosing which.
	 *
	 * @param array            $entry The counts entry: register, schema and filter.
	 * @param ObjectCountScope $scope The scoping of the count.
	 *
	 * @return int|null The scoped count, or null when the pair is withheld.
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	private function countPairScoped(array $entry, ObjectCountScope $scope): ?int {
		try {
			// Resolve slugs/ids to numeric ids + entities (same as index()).
			$resolved = $this->resolveRegisterSchemaRef(
				ref: new RegisterSchemaRef(register: (string)$entry['register'], schema: (string)$entry['schema']),
				objectService: $scope->objectService
			);
		} catch (RegisterNotFoundException|SchemaNotFoundException $e) {
			// Withhold: never disclose whether the pair is missing or restricted.
			return null;
		}

		// Build the search query from the per-entry filter using the same
		// builder index() uses, scoped to the resolved numeric ids.
		$query = $scope->objectService->buildSearchQuery(
			requestParams: ($entry['filter'] ?? []),
			register: $resolved['register'],
			schema: $resolved['schema']
		);

		if ($this->countsInMagicTable(resolved: $resolved) === true) {
			return $this->magicTableCount(query: self::scopedCountQuery(query: $query, scope: $scope), resolved: $resolved);
		}

		return self::paginatedCount(query: $query, scope: $scope);
	}//end countPairScoped()

	/**
	 * Whether a resolved pair is counted through its magic table.
	 *
	 * @param array $resolved The resolved register and schema.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	private function countsInMagicTable(array $resolved): bool {
		$register = ($resolved['registerEntity'] ?? null);
		$schema = ($resolved['schemaEntity'] ?? null);
		if ($register === null || $schema === null) {
			return false;
		}

		return $register->isMagicMappingEnabledForSchema(schemaId: $schema->getId(), schemaSlug: $schema->getSlug()) === true
			&& $schema->getObjectSource() === null;
	}//end countsInMagicTable()

	/**
	 * A count query with RBAC and multitenancy threaded in, without paging (index() lines 1194-1198, 1279-1283).
	 *
	 * @param array            $query The search query.
	 * @param ObjectCountScope $scope The scoping of the count.
	 *
	 * @return array The count query.
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	private static function scopedCountQuery(array $query, ObjectCountScope $scope): array {
		unset($query['_limit'], $query['_offset'], $query['_page']);
		$query['_rbac'] = $scope->rbac;
		$query['_multitenancy'] = $scope->multi;
		$query['_multitenancy_explicit'] = false;

		return $query;
	}//end scopedCountQuery()

	/**
	 * Count a pair through its magic table.
	 *
	 * @param array $query    The scoped count query.
	 * @param array $resolved The resolved register and schema.
	 *
	 * @return int The count.
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	private function magicTableCount(array $query, array $resolved): int {
		$magicMapper = $this->container->get(\OCA\OpenRegister\Db\MagicMapperInterface::class);

		return (int)$magicMapper->countObjectsInRegisterSchemaTable(
			query: $query,
			register: $resolved['registerEntity'],
			schema: $resolved['schemaEntity']
		);
	}//end magicTableCount()

	/**
	 * The paginated total at _limit=1, produced by the same scoped query the collection read runs.
	 *
	 * @param array            $query The search query.
	 * @param ObjectCountScope $scope The scoping of the count.
	 *
	 * @return int|null The total, or null when it is withheld.
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	private static function paginatedCount(array $query, ObjectCountScope $scope): ?int {
		$query['_limit'] = 1;
		unset($query['_offset'], $query['_page']);

		try {
			$result = $scope->objectService->searchObjectsPaginated(
				query: $query,
				_rbac: $scope->rbac,
				_multitenancy: $scope->multi,
				deleted: false
			);
		} catch (NotAuthorizedException $exception) {
			// Schema-level read denied (object-source parity check):
			// the count is simply unavailable to this caller.
			return null;
		}

		if (($result['total'] ?? null) === null) {
			return null;
		}

		return (int)$result['total'];
	}//end paginatedCount()

	/**
	 * Geo-search endpoint — POST /api/objects/{register}/{schema}/geo-search.
	 *
	 * Body shape (per REQ-GEO-004):
	 *   {
	 *     "geometry": {
	 *       "within":     <GeoJSON Polygon | MultiPolygon>,
	 *       "intersects": <GeoJSON Polygon | MultiPolygon>
	 *     },
	 *     "property": "<optional geo property name>"
	 *   }
	 *
	 * Either `within` or `intersects` (or both) MAY appear; both are
	 * AND-composed. Underlying listing query honours the standard
	 * filter / pagination params from the same request.
	 *
	 * @param string $register Register slug or id.
	 * @param string $schema Schema slug or id.
	 * @param ObjectOperationsInterface $objectService Object service via DI.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	#[UserRateLimit(limit: 300, period: 60)]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function geoSearch(string $register, string $schema, ObjectOperationsInterface $objectService): JSONResponse {
		if ($this->geo->geoFilterParser === null || $this->geo->geoFilterApplier === null) {
			return new JSONResponse(data: ['error' => 'Geo filtering primitives not configured'], statusCode: 501);
		}

		try {
			$filters = $this->geo->geoFilterParser->fromGeoSearchBody(body: $this->request->getParams());
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 422);
		}

		// Reuse the standard listing path, then post-filter.
		return $this->geoFilteredListing(
			listing: $this->index(register: $register, schema: $schema, objectService: $objectService),
			filters: $filters
		);
	}//end geoSearch()

	/**
	 * Export a register/schema's objects as a GeoJSON FeatureCollection.
	 *
	 * Reuses the standard listing path (`index()`), so the result is
	 * already scoped to the objects the caller may read — this endpoint
	 * adds no new data access and therefore no IDOR surface (the listing
	 * applies per-object RBAC). Objects without a geometry are omitted.
	 *
	 * Supports `?_fields=title,status` to restrict Feature properties
	 * (geometry is always retained), `?geo.property=` to pick the geo
	 * property, and the standard geo filter params (REQ-GEO-008).
	 *
	 * @param string $register Register slug or id.
	 * @param string $schema Schema slug or id.
	 * @param ObjectOperationsInterface $objectService Object service via DI.
	 *
	 * @return JSONResponse A GeoJSON FeatureCollection (application/geo+json).
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @no-admin-idor-exempt Read access is enforced by the delegated index()
	 *   listing (per-object RBAC via scopedGeoRows()); this method only
	 *   reshapes the already-scoped rows and accesses no object by id.
	 *
	 * @spec openspec/specs/geo-metadata-kaart/spec.md REQ-GEO-008
	 */
	public function geoJson(string $register, string $schema, ObjectOperationsInterface $objectService): JSONResponse {
		if ($this->geo->geoFeatureBuilder === null) {
			return new JSONResponse(data: ['error' => 'Geo feature builder not configured'], statusCode: 501);
		}

		$crs = GeoResponseCrsFactory::fromAccept(accept: $this->request->getHeader('Accept-Crs'));
		if ($crs->isServable() === false) {
			return new JSONResponse(data: $crs->refusal(), statusCode: Http::STATUS_NOT_ACCEPTABLE);
		}

		$params = $this->request->getParams();
		$rows = $this->scopedGeoRows(table: new RegisterSchemaRef(register: $register, schema: $schema), objectService: $objectService);

		$collection = $this->geo->geoFeatureBuilder->buildFeatureCollection(
			rows: $rows,
			shape: new FeatureShape(geoProperty: $this->geoPropertyParam(params: $params), fields: $this->parseFieldsParam(params: $params), includeArea: true)
		);

		// Body is a valid GeoJSON FeatureCollection. The default
		// application/json content type is kept (NC's Content-Type
		// override requires the full container); the @self envelope is
		// omitted so the body is consumable directly by GIS clients.
		return $this->geoCollectionResponse(collection: $collection, crs: $crs);
	}//end geoJson()

	/**
	 * A FeatureCollection in the requested CRS, naming that CRS when one was asked for.
	 *
	 * @param array<string, mixed>    $collection The collection, in WGS84.
	 * @param GeoResponseCrsInterface $crs        The negotiated CRS.
	 *
	 * @return JSONResponse The response.
	 *
	 * @spec openspec/specs/geo-metadata-kaart/spec.md REQ-GEO-015
	 */
	private function geoCollectionResponse(array $collection, GeoResponseCrsInterface $crs): JSONResponse {
		$response = new JSONResponse(data: $crs->applyTo(collection: $collection));
		$contentCrs = $crs->contentCrs();
		if ($contentCrs !== null) {
			$response->addHeader('Content-Crs', $contentCrs);
		}

		return $response;
	}//end geoCollectionResponse()

	/**
	 * WFS GetFeature-compatible endpoint (REQ-GEO-008).
	 *
	 * Returns a GeoJSON FeatureCollection compatible with WFS 2.0
	 * `outputFormat=application/json`. Supports `count`/`maxFeatures`
	 * caps. Reuses the RBAC-scoped listing path (no new IDOR surface).
	 *
	 * @param string $register Register slug or id.
	 * @param string $schema Schema slug or id.
	 * @param ObjectOperationsInterface $objectService Object service via DI.
	 *
	 * @return JSONResponse A WFS-compatible GeoJSON FeatureCollection.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @no-admin-idor-exempt Read access is enforced by the delegated index()
	 *   listing (per-object RBAC via scopedGeoRows()); this method only
	 *   reshapes the already-scoped rows and accesses no object by id.
	 *
	 * @spec openspec/specs/geo-metadata-kaart/spec.md REQ-GEO-008
	 */
	public function wfs(string $register, string $schema, ObjectOperationsInterface $objectService): JSONResponse {
		if ($this->geo->geoFeatureBuilder === null) {
			return new JSONResponse(data: ['error' => 'Geo feature builder not configured'], statusCode: 501);
		}

		$crs = GeoResponseCrsFactory::fromAccept(accept: $this->request->getHeader('Accept-Crs'));
		if ($crs->isServable() === false) {
			return new JSONResponse(data: $crs->refusal(), statusCode: Http::STATUS_NOT_ACCEPTABLE);
		}

		$params = $this->request->getParams();
		$rows = $this->scopedGeoRows(table: new RegisterSchemaRef(register: $register, schema: $schema), objectService: $objectService);

		$response = $this->geo->geoFeatureBuilder->buildWfsResponse(
			rows: $rows,
			request: new GetFeatureRequest(geoProperty: $this->geoPropertyParam(params: $params), maxFeatures: $this->wfsMaxFeatures(params: $params))
		);

		return $this->geoCollectionResponse(collection: $response, crs: $crs);
	}//end wfs()

	/**
	 * Forward / reverse geocoding via PDOK Locatieserver (REQ-GEO-005).
	 *
	 * `?q=<address>` performs forward geocoding; `?lon=&lat=` performs
	 * reverse geocoding. Degrades gracefully: when OpenConnector / PDOK
	 * is unavailable an empty suggestion list is returned with a flag,
	 * never an error (geocoding is non-blocking, REQ-GEO-005).
	 *
	 * @return JSONResponse Geocoding suggestions and availability flag.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @no-admin-idor-exempt Stateless PDOK address-lookup proxy: accesses no
	 *   OpenRegister object and takes no object id, so there is no IDOR
	 *   surface. Authenticated-user access is enforced by NoAdminRequired.
	 *
	 * @spec openspec/specs/geo-metadata-kaart/spec.md REQ-GEO-005
	 */
	public function geocode(): JSONResponse {
		if ($this->geo->pdokGeocoder === null) {
			return new JSONResponse(data: ['available' => false, 'suggestions' => []]);
		}

		$params = $this->request->getParams();
		$available = $this->geo->pdokGeocoder->isAvailable();

		$query = ($params['q'] ?? null);
		if ($query !== null && trim((string)$query) !== '') {
			$bagOnly = filter_var(($params['bagOnly'] ?? false), FILTER_VALIDATE_BOOLEAN);
			$suggestions = $this->geo->pdokGeocoder->geocodeFree(query: (string)$query, options: new GeocodeOptions(maxItems: 5, bagOnly: $bagOnly));
			return new JSONResponse(data: ['available' => $available, 'suggestions' => $suggestions]);
		}

		return $this->reverseGeocodeResponse(params: $params, available: $available);
	}//end geocode()

	/**
	 * The address at the `lon` and `lat` of a geocode request.
	 *
	 * @param array $params    The request params.
	 * @param bool  $available Whether the geocoder is available.
	 *
	 * @return JSONResponse The address, or a 422 without coordinates.
	 *
	 * @spec openspec/specs/geo-metadata-kaart/spec.md REQ-GEO-008
	 */
	private function reverseGeocodeResponse(array $params, bool $available): JSONResponse {
		$lon = ($params['lon'] ?? null);
		$lat = ($params['lat'] ?? null);
		if (is_numeric($lon) === false || is_numeric($lat) === false) {
			return new JSONResponse(data: ['error' => 'geocode requires either ?q=<address> or ?lon=&lat='], statusCode: 422);
		}

		$address = $this->geo->pdokGeocoder->reverseGeocode(longitude: (float)$lon, latitude: (float)$lat);
		return new JSONResponse(data: ['available' => $available, 'address' => $address]);
	}//end reverseGeocodeResponse()

	/**
	 * A listing response with its rows run through geo filters.
	 *
	 * @param JSONResponse $listing The listing.
	 * @param array        $filters The parsed filters.
	 *
	 * @return JSONResponse The filtered listing, or the listing when its results are not an array.
	 *
	 * @spec openspec/specs/geo-metadata-kaart/spec.md REQ-GEO-008
	 */
	private function geoFilteredListing(JSONResponse $listing, array $filters): JSONResponse {
		$payload = (array)$listing->getData();
		$payload['results'] = ($payload['results'] ?? []);
		if (is_array($payload['results']) === false) {
			return $listing;
		}

		return new JSONResponse(data: $this->withGeoFilteredRows(result: $payload, filters: $filters));
	}//end geoFilteredListing()

	/**
	 * A result with its rows run through geo filters, its total following when it has one.
	 *
	 * @param array $result  The result, with an array of rows.
	 * @param array $filters The parsed filters.
	 *
	 * @return array The result.
	 *
	 * @spec openspec/specs/geo-metadata-kaart/spec.md REQ-GEO-008
	 */
	private function withGeoFilteredRows(array $result, array $filters): array {
		$result['results'] = $this->geo->geoFilterApplier->applyAll(rows: $result['results'], filters: $filters);
		if (isset($result['total']) === true) {
			$result['total'] = count($result['results']);
		}

		return $result;
	}//end withGeoFilteredRows()

	/**
	 * The `geo.property` request param.
	 *
	 * @param array $params The request params.
	 *
	 * @return string|null The property, or null when not given.
	 *
	 * @spec openspec/specs/geo-metadata-kaart/spec.md REQ-GEO-008
	 */
	private function geoPropertyParam(array $params): ?string {
		if (isset($params['geo.property']) === false) {
			return null;
		}

		return (string)$params['geo.property'];
	}//end geoPropertyParam()

	/**
	 * The feature cap of a WFS request: `count`, or else `maxFeatures`.
	 *
	 * @param array $params The request params.
	 *
	 * @return int|null The cap, or null when none is given or it is not numeric.
	 *
	 * @spec openspec/specs/geo-metadata-kaart/spec.md REQ-GEO-008
	 */
	private function wfsMaxFeatures(array $params): ?int {
		$rawMax = ($params['count'] ?? ($params['maxFeatures'] ?? null));
		if (is_numeric($rawMax) === true) {
			return (int)$rawMax;
		}

		return null;
	}//end wfsMaxFeatures()

	/**
	 * Fetch the RBAC-scoped listing rows for a register/schema.
	 *
	 * Delegates to the standard `index()` listing — which enforces
	 * per-object read access — then returns just the `results`. Geo
	 * endpoints build on this so they can never widen access.
	 *
	 * @param RegisterSchemaRef         $table         Register and schema slugs or ids.
	 * @param ObjectOperationsInterface $objectService Object service via DI.
	 *
	 * @return array The RBAC-scoped result rows.
	 *
	 * @spec openspec/specs/geo-metadata-kaart/spec.md REQ-GEO-008
	 */
	private function scopedGeoRows(RegisterSchemaRef $table, ObjectOperationsInterface $objectService): array {
		$listing = $this->index(register: (string)$table->register, schema: (string)$table->schema, objectService: $objectService);
		$payload = (array)$listing->getData();
		$rows = ($payload['results'] ?? []);
		if (is_array($rows) === true) {
			return $rows;
		}

		return [];
	}//end scopedGeoRows()

	/**
	 * Parse a `?_fields=a,b,c` allow-list into an array (null = all).
	 *
	 * @param array $params The request params.
	 *
	 * @return string[]|null
	 *
	 * @spec openspec/specs/geo-metadata-kaart/spec.md REQ-GEO-008
	 */
	private function parseFieldsParam(array $params): ?array {
		$raw = ($params['_fields'] ?? null);
		if ($raw === null || $raw === '') {
			return null;
		}

		if (is_array($raw) === true) {
			return array_values(array_map('strval', $raw));
		}

		return array_values(array_filter(array_map('trim', explode(',', (string)$raw)), fn ($v) => $v !== ''));
	}//end parseFieldsParam()

	/**
	 * Apply geo query-param filters to a listing result.
	 *
	 * Reads `geo.bbox` / `geo.near` / `geo.radius` / `geo.property` from
	 * the query params via GeoFilterParser and post-filters
	 * `$result['results']` via GeoFilterApplier under AND-composition.
	 *
	 * Null-safe: when the geo deps aren't wired (older fixtures),
	 * returns the result unchanged. Parser failures (malformed bbox,
	 * missing radius) return a 422-like error envelope replacing the
	 * results.
	 *
	 * @param array $params The HTTP query params from the request.
	 * @param array $result The listing-result envelope from objectService.
	 *
	 * @return array The result, possibly with `results` filtered down.
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	private function applyGeoQueryFilters(array $params, array $result): array {
		// NC's IRequest parses `geo.bbox` etc. as a nested array
		// (`geo: {bbox: ...}`). Re-flatten back to the dotted form the
		// parser expects, so callers using either notation work.
		$flatParams = $this->flattenGeoParams(params: $params);
		if ($this->asksForGeoFilter(flatParams: $flatParams) === false) {
			return $result;
		}

		try {
			$filters = $this->geo->geoFilterParser->fromQueryParams(params: $flatParams);
		} catch (\InvalidArgumentException $e) {
			// Malformed input: surface a 422-shape envelope inside the
			// existing result body so consumers see the validation error
			// without crashing the whole listing path.
			return ['error' => 'geo filter parse error: ' . $e->getMessage(), 'results' => [], 'total' => 0];
		}

		if ($filters === [] || is_array($result['results'] ?? null) === false) {
			return $result;
		}

		return $this->withGeoFilteredRows(result: $result, filters: $filters);
	}//end applyGeoQueryFilters()

	/**
	 * Whether geo filtering is configured and the request names a geo param.
	 *
	 * @param array $flatParams The params, `geo.*` keys flattened.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/geo-metadata-kaart/spec.md REQ-GEO-008
	 */
	private function asksForGeoFilter(array $flatParams): bool {
		if ($this->geo->geoFilterParser === null || $this->geo->geoFilterApplier === null) {
			return false;
		}

		$named = array_filter(['geo.bbox', 'geo.near', 'geo.radius', 'geo.property'], fn (string $key): bool => isset($flatParams[$key]));
		return $named !== [];
	}//end asksForGeoFilter()

	/**
	 * Flatten NC's nested `geo: {bbox: ...}` query-param shape back into
	 * the dotted `geo.bbox: ...` form the GeoFilterParser expects.
	 *
	 * Accepts both already-flat keys and nested `geo` arrays — the
	 * dotted form takes priority when both are present.
	 *
	 * @param array $params The raw query params from IRequest::getParams.
	 *
	 * @return array The same params with `geo.*` keys hoisted from `geo`.
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	private function flattenGeoParams(array $params): array {
		$nested = ($params['geo'] ?? null);
		if (is_array($nested) === true) {
			foreach ($nested as $subkey => $value) {
				$flatKey = 'geo.' . $subkey;
				if (isset($params[$flatKey]) === false) {
					$params[$flatKey] = $value;
				}
			}
		}

		return $params;
	}//end flattenGeoParams()

	/**
	 * Retrieves a list of all objects across all registers and schemas
	 *
	 * This method returns a paginated list of objects that the current user has access to,
	 * regardless of register or schema boundaries. It supports filtering, sorting, pagination,
	 * faceting, and facetable field discovery through query parameters.
	 *
	 * This endpoint respects both RBAC (Role-Based Access Control) and multitenancy settings:
	 * - Regular users see only objects they have read permission for in their organization
	 * - Admin users can see all objects system-wide (overrides RBAC and multitenancy)
	 *
	 * Supported parameters:
	 * - Standard filters: Any object field (e.g., name, status, etc.)
	 * - Metadata filters: register, schema, uuid, created, updated, etc.
	 * - Pagination: _limit, _offset, _page
	 * - Search: _search
	 * - Rendering: _extend, _fields, _filter/_unset
	 * - Faceting: _facets (facet configuration), _facetable (facetable field discovery)
	 * - Aggregations: _aggregations (enable aggregations in response)
	 * - Debug: _debug (enable debug information in response)
	 * - Source: _source (ignored; the database is the only search source)
	 * - Sorting: _order
	 * - Empty values: _empty (boolean, default: false). When false (default), null, empty string,
	 *   and empty array values are stripped from the response to reduce payload size.
	 *   Set _empty=true to include all properties including empty values.
	 *
	 * @param ObjectOperationsInterface $objectService The object service
	 *
	 * @return JSONResponse A JSON response containing the list of objects with optional facets and facetable fields
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @PublicPage
	 *
	 * @psalm-return JSONResponse<200|404, array<string, mixed>, array<never, never>>
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Cross-table search + multi-schema routing requires branching
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	#[UserRateLimit(limit: 600, period: 60)]
	#[AnonRateLimit(limit: 120, period: 60)]
	public function objects(ObjectOperationsInterface $objectService): JSONResponse {
		$scope = new ObjectsRouteScope(params: $this->request->getParams());
		if ($scope->spansTables() === true) {
			return $this->crossTableSearch(registers: $scope->registers, schemas: $scope->schemas, objectService: $objectService);
		}

		$answer = ['register' => null, 'schema' => null];
		if ($scope->namesOneTable() === true) {
			$answer = $this->oneTableListing(scope: $scope, objectService: $objectService);
		}

		if ($answer instanceof JSONResponse) {
			return $answer;
		}

		return $this->searchedListing(resolved: $answer, objectService: $objectService);
	}//end objects()

	/**
	 * The answer for one named table, or its resolved ids when the search should answer.
	 *
	 * The purpose guard sits on the resolution rather than per branch: this
	 * endpoint is a public page, and both branches below descend from here.
	 *
	 * @param ObjectsRouteScope $scope The register and schema the request named.
	 * @param ObjectOperationsInterface $objectService The object service.
	 *
	 * @return array<string, mixed>|JSONResponse The resolved ids, or the response.
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	private function oneTableListing(ObjectsRouteScope $scope, ObjectOperationsInterface $objectService): array|JSONResponse {
		try {
			$resolved = $this->resolveRegisterSchemaRef(
				ref: new RegisterSchemaRef(register: $scope->register, schema: $scope->schema),
				objectService: $objectService
			);

			$answer = $this->refuseUnboundPurpose(resolved: $resolved);
			if ($answer === null && (new MagicMappedTable(resolved: $resolved))->isMapped() === true) {
				$answer = $this->magicTableListing(table: new ObjectsTableRead(scope: $scope, resolved: $resolved), objectService: $objectService);
			}
		} catch (RegisterNotFoundException|SchemaNotFoundException $e) {
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: 404);
		}

		return ($answer ?? $resolved);
	}//end oneTableListing()

	/**
	 * The rows of a magic-mapped table, straight from its mapper.
	 *
	 * @param ObjectsTableRead $table The scope and its resolved ids and entities.
	 * @param ObjectOperationsInterface $objectService The object service.
	 *
	 * @return JSONResponse The rows and their paging, marked with their source.
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	private function magicTableListing(ObjectsTableRead $table, ObjectOperationsInterface $objectService): JSONResponse {
		$magicMapper = $this->container->get(\OCA\OpenRegister\Db\MagicMapperInterface::class);
		$query = $objectService->buildSearchQuery(
			requestParams: $this->request->getParams(),
			register: $table->resolved['register'],
			schema: $table->resolved['schema']
		);
		$results = $magicMapper->searchObjectsInRegisterSchemaTable(
			query: $query,
			register: $table->resolved['registerEntity'],
			schema: $table->resolved['schemaEntity']
		);
		$rows = $this->renderedMagicRows(results: $results, rbac: ($query['_rbac'] ?? true));

		return new JSONResponse(data: $this->magicTablePage(rows: $rows, table: $table->withQuery(query: $query)));
	}//end magicTableListing()

	/**
	 * Rows from a mapper as a reader may see them.
	 *
	 * The direct mapper path bypasses renderEntity, which is where write-only
	 * secrets are redacted, lenses resolved, translations projected and files
	 * listed, so each of those is asked for here. `_rbac` (false for an admin)
	 * gates only the `authorization.read` strip; writeOnly strips unconditionally.
	 *
	 * @param array<int, ObjectEntity> $results The rows.
	 * @param bool $rbac Whether the reader is held to `authorization.read`.
	 *
	 * @return array<int, array<string, mixed>> The serialised rows.
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	private function renderedMagicRows(array $results, bool $rbac): array {
		$renderHandler = $this->container->get(\OCA\OpenRegister\Service\Object\RenderObjectInterface::class);
		$renderHandler->redactWriteOnlyFromRows(rows: $results, _rbac: $rbac);
		$renderHandler->applyLensesToRows(rows: $results, _rbac: $rbac);
		$renderHandler->resolveTranslationsForRows(rows: $results);
		$renderHandler->attachLightweightFilesToRows(rows: $results);

		return array_values(array_map(static fn ($entity) => $entity->jsonSerialize(), $results));
	}//end renderedMagicRows()

	/**
	 * The envelope of a magic-mapped listing.
	 *
	 * @param array<int, array<string, mixed>> $rows The serialised rows.
	 * @param ObjectsTableRead $table The scope and the search query.
	 *
	 * @return array<string, mixed> The envelope, with `@self.source` naming the mapper.
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	private function magicTablePage(array $rows, ObjectsTableRead $table): array {
		if ($this->wantsEmptyValues() === false) {
			$stripped = array_map(callback: [new EmptyValueStripper(), 'stripped'], array: $rows);
		}

		return array_merge(
			['results' => ($stripped ?? $rows)],
			$table->paging(total: count($rows)),
			['@self' => ['source' => 'magic_mapper', 'register' => $table->scope->register, 'schema' => $table->scope->schema]]
		);
	}//end magicTablePage()

	/**
	 * Whether the caller asked to keep null, empty string and empty array values.
	 *
	 * @return bool True for a truthy `_empty`.
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	private function wantsEmptyValues(): bool {
		$params = $this->request->getParams();

		return filter_var(value: ($params['_empty'] ?? false), filter: FILTER_VALIDATE_BOOLEAN);
	}//end wantsEmptyValues()

	/**
	 * The listing through the object search.
	 *
	 * The resolved numeric ids go into the query, so it filters on
	 * `@self.register` and `@self.schema` the way the path-style index() does;
	 * a raw slug never matches those columns.
	 *
	 * @param array<string, mixed> $resolved The resolved ids, null when none were named.
	 * @param ObjectOperationsInterface $objectService The object service.
	 *
	 * @return JSONResponse The search result; a 404 when a schema-level read is denied, as in show().
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	private function searchedListing(array $resolved, ObjectOperationsInterface $objectService): JSONResponse {
		$query = $objectService->buildSearchQuery(requestParams: $this->request->getParams(), register: $resolved['register'], schema: $resolved['schema']);

		try {
			$result = $objectService->searchObjectsPaginated($query);
		} catch (NotAuthorizedException $exception) {
			return new JSONResponse(data: ['error' => 'Not Found'], statusCode: 404);
		} catch (\OCA\OpenRegister\Service\Audit\PurposeRefusedException $refusal) {
			return self::purposeRefusal(refusal: $refusal);
		}

		if ($this->wantsEmptyValues() === false && is_array(($result['results'] ?? null)) === true) {
			$result['results'] = $this->stripEmptyFromResults(results: $result['results']);
		}

		return new JSONResponse(data: $result);
	}//end searchedListing()

	/**
	 * The options a filtered reference property may offer for this record.
	 *
	 * 🔑 IT CALLS THE SAME RESOLVER THE SAVE PATH CALLS. A picker that offers
	 * one set while the save path accepts another is two evaluators of one rule:
	 * the user picks what the form offered and the server refuses it, or the
	 * form offers something the server then accepts and should not have.
	 *
	 * 🔴 NO OPTIONS IS NOT EVERY OPTION. When an operand the filter depends on
	 * has no value yet, this answers an EMPTY list and names the property it is
	 * waiting for, with HTTP 200. Returning the unfiltered set would show every
	 * contact in the register to somebody who had not yet chosen an
	 * organisation, and each of those is a value they were never meant to
	 * browse. A 200 with `needs` is the honest shape: the request was fine, the
	 * answer is "not yet, choose that first".
	 *
	 * The record's values come from the stored object, with `_draft` merged over
	 * them, because the case a picker exists for is a form being filled in and
	 * those values are not saved yet.
	 *
	 * @param string $id The record being edited.
	 * @param string $register The register.
	 * @param string $schema The schema.
	 * @param ObjectOperationsInterface $objectService The object service.
	 *
	 * @return JSONResponse The options, or what is still needed.
	 *
	 * @NoAdminRequired
	 *
	 * @contract tests/e2e/ci/reference-options.spec.ts
	 *
	 * @spec openspec/changes/fields-a-user-adds-and-choices-a-record-narrows/specs/runtime-schema-api/spec.md
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 600, period: 60)]
	public function referenceOptions(string $id, string $register, string $schema, ObjectOperationsInterface $objectService): JSONResponse {
		$property = (string)($this->request->getParam('property') ?? '');
		if (trim($property) === '') {
			return new JSONResponse(data: ['message' => 'Name the property whose options you want, with ?property=<name>.'], statusCode: 400);
		}

		return $this->optionsFor(
			ask: new ReferenceOptionsAsk(id: $id, register: $register, schema: $schema, property: $property),
			objectService: $objectService
		);
	}//end referenceOptions()

	/**
	 * The options once the property is known.
	 *
	 * @param ReferenceOptionsAsk $ask The record and property.
	 * @param ObjectOperationsInterface $objectService The object service.
	 *
	 * @return JSONResponse The options, or why there are none.
	 *
	 * @spec openspec/changes/fields-a-user-adds-and-choices-a-record-narrows/specs/runtime-schema-api/spec.md
	 */
	private function optionsFor(ReferenceOptionsAsk $ask, ObjectOperationsInterface $objectService): JSONResponse {
		$schemaEntity = $this->optionsSchema(ask: $ask, objectService: $objectService);
		if ($schemaEntity instanceof JSONResponse) {
			return $schemaEntity;
		}

		$answer = new ReferenceOptionsAnswer(request: $this->request, logger: $this->logger, lookup: $this->storedForOptions(...), search: $objectService);

		try {
			return $answer->answer(ask: $ask, schema: $schemaEntity);
		} catch (MultipleObjectsReturnedException $e) {
			return $this->ambiguousIdentifierResponse(id: $ask->id);
		}
	}//end optionsFor()

	/**
	 * The record as it stands, read as a lookup in the caller's scope.
	 *
	 * @param ReferenceOptionsAsk $ask The record.
	 *
	 * @return ObjectEntity|null The stored record.
	 *
	 * @spec openspec/changes/fields-a-user-adds-and-choices-a-record-narrows/specs/runtime-schema-api/spec.md
	 */
	private function storedForOptions(ReferenceOptionsAsk $ask): ?ObjectEntity {
		return WriteCause::asLookup(fn () => $this->objectService->find(
			id: $ask->id,
			files: false,
			register: $ask->register,
			schema: $ask->schema,
			_render: false
		));
	}//end storedForOptions()

	/**
	 * The schema of the record a reference-options read is about.
	 *
	 * @param ReferenceOptionsAsk $ask The register and schema in the URL.
	 * @param ObjectOperationsInterface $objectService The object service.
	 *
	 * @return Schema|JSONResponse The schema, or a 404.
	 *
	 * @spec openspec/changes/fields-a-user-adds-and-choices-a-record-narrows/specs/runtime-schema-api/spec.md
	 */
	private function optionsSchema(ReferenceOptionsAsk $ask, ObjectOperationsInterface $objectService): Schema|JSONResponse {
		try {
			$resolved = $this->resolveRegisterSchemaRef(
				ref: new RegisterSchemaRef(register: $ask->register, schema: $ask->schema),
				objectService: $objectService
			);
		} catch (RegisterNotFoundException|SchemaNotFoundException $e) {
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: 404);
		}

		$schemaEntity = ($resolved['schemaEntity'] ?? null);
		if (($schemaEntity instanceof Schema) === false) {
			return new JSONResponse(data: ['message' => 'Schema not found.'], statusCode: 404);
		}

		return $schemaEntity;
	}//end optionsSchema()

	/**
	 * Shows a specific object from a register and schema
	 *
	 * Retrieves and returns a single object from the specified register and schema,
	 * with support for field filtering and related object extension.
	 *
	 * @param string $id The object ID
	 * @param string $register The register slug or identifier
	 * @param string $schema The schema slug or identifier
	 * @param ObjectHandlerAccessInterface $objectService The object service
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @PublicPage
	 *
	 * @return JSONResponse JSON response with the object or error
	 *
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Object retrieval with slug resolution + access checks requires branching
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-a-slug-lookup-that-matches-more-than-one-object-in-a-register-and-schema-must-be-refused-as-ambiguous
	 */
	#[UserRateLimit(limit: 600, period: 60)]
	#[AnonRateLimit(limit: 120, period: 60)]
	public function show(string $id, string $register, string $schema, ObjectHandlerAccessInterface $objectService): JSONResponse {
		\OCA\OpenRegister\Service\WritePhase::stamp('ctrl.show.in');

		try {
			$resolved = $this->resolveRegisterSchemaRef(ref: new RegisterSchemaRef(register: $register, schema: $schema), objectService: $objectService);
		} catch (RegisterNotFoundException|SchemaNotFoundException $e) {
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: 404);
		}

		// Doelbinding, before the object is fetched. A refused read must not
		// have happened at all, so the guard runs ahead of the read rather than
		// filtering what came back.
		$refusal = $this->refuseUnboundPurpose(resolved: $resolved);
		if ($refusal !== null) {
			return $refusal;
		}

		$services = $this->showServices(objectService: $objectService);

		return $this->shownObject(show: new ObjectShow(id: $id, path: [$register, $schema], resolved: $resolved, services: $services));
	}//end show()

	/**
	 * What show() asks of the request's object service.
	 *
	 * @param ObjectHandlerAccessInterface $objectService The object service.
	 *
	 * @return ShowServices The services.
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	private function showServices(ObjectHandlerAccessInterface $objectService): ShowServices {
		return new ShowServices(
			objects: $objectService,
			permissions: $objectService->getPermissionHandler(),
			names: $objectService->getCacheHandler()
		);
	}//end showServices()

	/**
	 * Read one object for show().
	 *
	 * A denied read answers 404, not 403, so an unauthorized caller cannot tell
	 * "exists but forbidden" from "does not exist".
	 *
	 * @param ObjectShow $show The object asked for and its resolved scope.
	 *
	 * @return JSONResponse The object, or the refusal.
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	private function shownObject(ObjectShow $show): JSONResponse {
		$read = new ObjectReadOptions(params: $this->request->getParams(), admin: $this->isCurrentUserAdmin());

		try {
			$objectEntity = $this->foundForShow(show: $show, read: $read);
			if ($objectEntity === null) {
				return $this->absentObject(show: $show, read: $read);
			}

			return $this->renderedShow(shown: new ShownEntity(show: $show, entity: $objectEntity, extend: $read->extend), read: $read);
		} catch (DoesNotExistException|NotAuthorizedException $exception) {
			return new JSONResponse(data: ['error' => 'Not Found'], statusCode: 404);
		} catch (MultipleObjectsReturnedException $exception) {
			return $this->ambiguousIdentifierResponse(id: $show->id);
		}
	}//end shownObject()

	/**
	 * Find the object without rendering it.
	 *
	 * Rendering waits for the single renderEntity() call; permission checks and
	 * read logging inside find() still run.
	 *
	 * @param ObjectShow $show The object asked for.
	 * @param ObjectReadOptions $read The caller's render options and scope.
	 *
	 * @return ObjectEntity|null The object, or null when it is absent.
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	private function foundForShow(ObjectShow $show, ObjectReadOptions $read): ?ObjectEntity {
		return $this->objectService->find(
			id: $show->id,
			_extend: $read->extend,
			files: false,
			register: $show->path[0],
			schema: $show->path[1],
			_rbac: $read->scoped,
			_multitenancy: $read->scoped,
			_render: false
		);
	}//end foundForShow()

	/**
	 * The 404 for an object find() did not return.
	 *
	 * The refusal says where the object went: a flat "not found" on a
	 * soft-deleted object makes a caseworker think their work is gone, when it
	 * is in the trash with a stated window still open.
	 *
	 * @param ObjectShow $show The object asked for and its resolved scope.
	 * @param ObjectReadOptions $read The caller's scope.
	 *
	 * @return JSONResponse The 404.
	 *
	 * @spec openspec/specs/deletion-audit-trail/spec.md
	 */
	private function absentObject(ObjectShow $show, ObjectReadOptions $read): JSONResponse {
		$deleted = $this->deletedRefusal(show: $show, read: $read);
		if ($deleted !== null) {
			return new JSONResponse(data: $deleted, statusCode: Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(data: ['error' => "Object with id {$show->id} not found"], statusCode: Http::STATUS_NOT_FOUND);
	}//end absentObject()

	/**
	 * Render a found object for its reader.
	 *
	 * This is the ONLY render on the show() path: writeOnly redaction and
	 * read-authorization stripping happen here, exactly once, inside
	 * renderEntity(). The JSON-LD serializer wraps the rendered array, so all
	 * of that stays applied to it. The permitted actions come AFTER the empty
	 * strip: an empty list of actions is an answer, not an empty value.
	 *
	 * @param ShownEntity $shown The found object and its resolved scope.
	 * @param ObjectReadOptions $read The caller's render options and scope.
	 *
	 * @return JSONResponse The object.
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	private function renderedShow(ShownEntity $shown, ObjectReadOptions $read): JSONResponse {
		$this->grantExplicitReveal(object: $shown->entity);
		$rendered = $this->withSelfExtras(rendered: $this->renderedEntity(entity: $shown->entity, read: $read), shown: $shown);
		if ($this->wantsEmptyValues() === false) {
			$rendered = (new EmptyValueStripper())->stripped(data: $rendered);
		}

		$rendered = $this->withPermittedActions(renderedData: $rendered, shown: $shown);
		if ($this->wantsJsonLd() === true && $shown->show->hasEntities() === true) {
			return $this->jsonLdObjectResponse(renderedObject: $rendered, resolved: $shown->show->resolved);
		}

		return new JSONResponse(data: $rendered);
	}//end renderedShow()

	/**
	 * Honour `_reveal` for the object found, by its uuid.
	 *
	 * Only on this single-object read: an extended or listed object stays masked,
	 * whatever id or slug it shares with the route (property-explicit-reveal).
	 *
	 * @param ObjectEntity $object The object found.
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	private function grantExplicitReveal(ObjectEntity $object): void {
		$explicitReveal = $this->container->get(\OCA\OpenRegister\Service\Rbac\ExplicitRevealInterface::class);
		if ($explicitReveal instanceof \OCA\OpenRegister\Service\Rbac\ExplicitRevealInterface) {
			$params = $this->request->getParams();
			$explicitReveal->grantFor(objectId: (string)$object->getUuid(), reveal: (string)($params['_reveal'] ?? ''));
		}
	}//end grantExplicitReveal()

	/**
	 * The render of one object.
	 *
	 * @param ObjectEntity $entity The object.
	 * @param ObjectReadOptions $read The caller's render options and scope.
	 *
	 * @return array<string, mixed> The rendered object.
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	private function renderedEntity(ObjectEntity $entity, ObjectReadOptions $read): array {
		return $this->objectService->renderEntity(
			entity: $entity,
			_extend: $read->extend,
			depth: 0,
			filter: $read->filter,
			fields: $read->fields,
			unset: $read->unset,
			_rbac: $read->scoped,
			_multitenancy: $read->scoped
		);
	}//end renderedEntity()

	/**
	 * Add what `_extend` asked for to `@self`, with the tab badges.
	 *
	 * The badges are attached here and nowhere else: this is the only read path
	 * that knows it renders exactly one object.
	 *
	 * @param array<string, mixed> $rendered The rendered object.
	 * @param ShownEntity $shown The found object and the extensions asked for.
	 *
	 * @return array<string, mixed> The object with its extras.
	 *
	 * @spec openspec/specs/object-read-state/spec.md#requirement-unread-is-a-filter-and-a-badge-resolved-in-the-query-req-ors-002
	 */
	private function withSelfExtras(array $rendered, ShownEntity $shown): array {
		if (isset($rendered['@self']) === false) {
			return $rendered;
		}

		$rendered['@self'] = array_merge(
			$this->withUnreadCounts(self: $rendered['@self'], object: $shown->entity),
			$shown->resolvedExtras()
		);
		if (in_array('_names', $shown->extend, true) === true) {
			$rendered['@self']['names'] = $this->collectNamesForResponse(renderedData: $rendered, cacheHandler: $shown->show->services->names);
		}

		return $rendered;
	}//end withSelfExtras()

	/**
	 * The 409 answer for an identifier that matches more than one object (#4037).
	 *
	 * Existing duplicates are kept on purpose (BC2), so this is a state the
	 * caller can act on, by asking with the uuid, and not a server fault: 409,
	 * as HandlesExceptionsTrait maps the same exception. The mapper logged the
	 * uuids; the response names none of them. Every route that resolves an
	 * identifier through `ObjectService::find()` or `findSilent()` answers through here:
	 * show, update, patch, postPatch, destroy, canDelete, contracts, uses, used, logs
	 * (administrators), lock, unlock, move, presenceBeat and presenceList, downloadFiles,
	 * referencedBy, geoFeatures and referenceOptions; presenceDepart stays 200, its departure is already done. The cross-table lookup behind
	 * `merge` takes the first hit and raises nothing, and the history a non-administrator
	 * reads is resolved by `ObjectReadAccess`, which answers 404 for any failure.
	 *
	 * @param string $id The identifier as the caller sent it.
	 *
	 * @return JSONResponse The 409 response.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-a-slug-lookup-that-matches-more-than-one-object-in-a-register-and-schema-must-be-refused-as-ambiguous
	 */
	private function ambiguousIdentifierResponse(string $id): JSONResponse {
		return new JSONResponse(
			data: [
				'message' => sprintf('Identifier "%s" matches more than one object in this register and schema', $id),
				'error' => 'ambiguous-identifier',
			],
			statusCode: Http::STATUS_CONFLICT
		);
	}//end ambiguousIdentifierResponse()

	/**
	 * The object a PATCH merges into, read in the caller's own scope.
	 *
	 * Read as PUT reads it, with RBAC and multitenancy for a non-admin, so an
	 * object the caller may not read is a 404 before the lock, the version
	 * check or the merge can say anything about it. A slug is decided on the
	 * caller's own filtered set: one readable object is the target, more than
	 * one is a 409, none is a 404. So a slug the caller can see only once
	 * never reveals a second holder it cannot see.
	 *
	 * @param ObjectUpdate $patch The target and the caller's scope.
	 *
	 * @return ObjectEntity|JSONResponse The object, or the 409/404 response.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-a-slug-lookup-that-matches-more-than-one-object-in-a-register-and-schema-must-be-refused-as-ambiguous
	 */
	private function findReadableForPatch(ObjectUpdate $patch): ObjectEntity|JSONResponse {
		try {
			return $this->silentPatchRead(patch: $patch);
		} catch (MultipleObjectsReturnedException $e) {
			return $this->ambiguousIdentifierResponse(id: $patch->target->id);
		} catch (\Exception $e) {
			return new JSONResponse(data: ['error' => 'Object not found'], statusCode: 404);
		}
	}//end findReadableForPatch()

	/**
	 * Read the object a patch merges into, without an audit entry.
	 *
	 * @param ObjectUpdate $patch The target and the caller's scope.
	 *
	 * @return ObjectEntity The object.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-a-slug-lookup-that-matches-more-than-one-object-in-a-register-and-schema-must-be-refused-as-ambiguous
	 */
	private function silentPatchRead(ObjectUpdate $patch): ObjectEntity {
		return $this->objectService->findSilent(
			id: $patch->target->id,
			_extend: [],
			files: false,
			register: $patch->target->resolved['registerEntity'],
			schema: $patch->target->resolved['schemaEntity'],
			_rbac: $patch->caller->scoped,
			_multitenancy: $patch->caller->scoped
		);
	}//end silentPatchRead()

	/**
	 * Add the actions this caller may take on the record being returned.
	 *
	 * Written into `@self.actions`, beside the rest of the record's own
	 * metadata, so no data property can collide with it and no existing caller
	 * has to change to keep working.
	 *
	 * BEST EFFORT, ON PURPOSE. A resolution that fails leaves the record
	 * untouched rather than failing the read: the actions are a convenience for
	 * the client, and the verdicts that matter were already made — this row was
	 * returned because the read was allowed, and every write is resolved again
	 * on its own request. A reporting field must never be able to refuse a read.
	 *
	 * @param array<string, mixed> $renderedData The rendered object.
	 * @param ShownEntity $shown The found object and its resolved scope.
	 *
	 * @return array The record, with its actions when they could be resolved.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	private function withPermittedActions(array $renderedData, ShownEntity $shown): array {
		$schema = $shown->show->resolved['schemaEntity'];
		if ($schema === null || isset($renderedData['@self']) === false) {
			return $renderedData;
		}

		try {
			$renderedData['@self']['actions'] = $shown->show->services->permissions->permittedActionsFor(schema: $schema, object: $shown->entity);
		} catch (\Throwable $e) {
			$context = ['file' => __FILE__, 'line' => __LINE__, 'schemaId' => $schema->getId(), 'error' => $e->getMessage()];
			$this->logger->warning(message: '[ObjectsController] Could not resolve the actions for a record; returning it without them', context: $context);
		}

		return $renderedData;
	}//end withPermittedActions()

	/**
	 * Creates a new object in the specified register and schema
	 *
	 * Takes the request data, validates it against the schema, and creates a new object
	 * in the database. Handles validation errors appropriately.
	 *
	 * @param string $register The register slug or identifier
	 * @param string $schema The schema slug or identifier
	 * @param ObjectOperationsInterface $objectService The object service
	 *
	 * @return JSONResponse JSON response with created object
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @PublicPage
	 *
	 * @psalm-return JSONResponse
	 *
	 * @psalm-suppress TypeDoesNotContainType
	 * @psalm-suppress NoValue
	 *
	 * @SuppressWarnings(PHPMD.NPathComplexity) Object creation requires many validation and processing steps
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 *
	 * BUG-RATE-1: this endpoint is `@PublicPage` so anonymous callers (e.g.
	 * public form submissions via CaseToken/FormLink) need throttling —
	 * that is what #[AnonRateLimit] is for (added for the public-create
	 * path, see commit 044faee7d). But Nextcloud's RateLimitingMiddleware
	 * applies an Anon-only limit to EVERY caller, authenticated or not,
	 * when no #[UserRateLimit] is present ("If only an AnonRateThrottle is
	 * specified that one will also be applied to logged-in users" —
	 * lib/private/AppFramework/Middleware/Security/RateLimitingMiddleware.php).
	 * Without this explicit, more generous authenticated-user limit, every
	 * logged-in API caller — including bulk imports/integrations and this
	 * app's own CI test suite — was capped at the same 30 requests/minute
	 * meant only for anonymous abuse prevention.
	 */
	#[UserRateLimit(limit: 300, period: 60)]
	#[AnonRateLimit(limit: 30, period: 60)]
	public function create(string $register, string $schema, ObjectOperationsInterface $objectService): JSONResponse {
		\OCA\OpenRegister\Service\WritePhase::stamp('ctrl.create.in');
		try {
			$resolved = $this->resolveRegisterSchemaRef(ref: new RegisterSchemaRef(register: $register, schema: $schema), objectService: $objectService);
		} catch (RegisterNotFoundException|SchemaNotFoundException $e) {
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: 404);
		}

		$object = $this->submittableBody(object: $this->interceptedBody(register: $register, schema: $schema));
		$controls = new ObjectCreateControls(request: $this->request, caller: $this->writeCaller());
		$create = new ObjectCreate(object: $object, resolved: $resolved, objectService: $objectService, controls: $controls);

		return ($this->upsertRefusal(create: $create) ?? $this->createdResponse(create: $create));
	}//end create()

	/**
	 * The request body, after the webhooks that may validate, transform or enrich it.
	 *
	 * @param string $register The register in the URL, for the log.
	 * @param string $schema The schema in the URL, for the log.
	 *
	 * @return array<string, mixed> The body.
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	private function interceptedBody(string $register, string $schema): array {
		$object = $this->request->getParams();
		if ($this->services->transfer->webhookService === null) {
			return $object;
		}

		try {
			return $this->services->transfer->webhookService->interceptRequest(request: $this->request, eventType: 'object.creating');
		} catch (\Throwable $e) {
			// A webhook failure must not break the API: log it and keep the original request.
			$context = ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage(), 'register' => $register, 'schema' => $schema];
			$this->logger->error(message: '[ObjectsController] Webhook interception failed', context: $context);
			return $object;
		}
	}//end interceptedBody()

	/**
	 * The body without control parameters and server-managed fields.
	 *
	 * @param array<string, mixed> $object The body.
	 *
	 * @return array<string, mixed> What may be saved.
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	private function submittableBody(array $object): array {
		// Filter out special parameters and reserved fields.
		// @todo shouldn't this be part of the object service?
		// Allow @self metadata to pass through for organization activation.
		$object = array_filter(
			$object,
			fn ($key) => str_starts_with($key, '_') === false
				&& (str_starts_with($key, '@') === false || in_array($key, self::SUBMITTABLE_RESERVED_KEYS, true) === true)
				&& in_array($key, ['uuid', 'register', 'schema']) === false,
			ARRAY_FILTER_USE_KEY
		);

		// Normalize multipart/form-data: decode JSON-encoded strings back into arrays/objects.
		$object = $this->normalizeFormDataValues(data: $object);

		// Defense-in-depth (wave-11 WF2): strip server-managed @self fields so they
		// cannot be injected via the single-object create path.
		$object = $this->sanitiseSelfMetadata(data: $object);

		return $object;
	}//end submittableBody()

	/**
	 * The refusal of an upsert that cannot run, or null.
	 *
	 * @param ObjectCreate $create The create asked for.
	 *
	 * @return JSONResponse|null The refusal.
	 *
	 * @spec openspec/changes/api-upsert-on-a-declared-key/specs/objects-crud/spec.md
	 */
	private function upsertRefusal(ObjectCreate $create): ?JSONResponse {
		if ($create->controls->upsertOn === '') {
			return null;
		}

		return $this->upsertPreconditionRefusal(failIfExists: $create->controls->failIfExists, resolved: $create->resolved);
	}//end upsertRefusal()

	/**
	 * Save the object and answer with it.
	 *
	 * Sub-objects are only returned when _extend is explicitly requested on GET.
	 * An upsert that found its record updated it: 200, not 201.
	 *
	 * @param ObjectCreate $create The create asked for.
	 *
	 * @return JSONResponse The object, or the refusal.
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	private function createdResponse(ObjectCreate $create): JSONResponse {
		try {
			$saved = $this->savedObject(create: $create);
		} catch (\Exception $exception) {
			return $this->createRefusal(exception: $exception, objectService: $create->objectService);
		}

		$status = 201;
		if ($saved['created'] === false) {
			$status = 200;
		}

		return new JSONResponse(data: $this->withUniqueHintWarnings(body: $saved['object']->jsonSerialize()), statusCode: $status);
	}//end createdResponse()

	/**
	 * Save through the upsert when a key was named, directly otherwise.
	 *
	 * @param ObjectCreate $create The create asked for.
	 *
	 * @return array{object: ObjectEntity, created: bool} The saved object.
	 *
	 * @spec openspec/changes/api-upsert-on-a-declared-key/specs/objects-crud/spec.md
	 */
	private function savedObject(ObjectCreate $create): array {
		$create->objectService->clearCreatedSubObjects();
		$save = $create->saver(body: $this->withClientCauseAttempt(object: $create->object));

		$upsert = $this->services->transfer->upsertOnKeyHandler;
		if ($create->controls->upsertOn === '' || $upsert === null) {
			return ['object' => $save(null), 'created' => true];
		}

		$keyed = new UpsertOnKeyRequest(
			constraintName: $create->controls->upsertOn,
			object: $create->object,
			register: $create->resolved['registerEntity'],
			schema: $create->resolved['schemaEntity']
		);
		return $upsert->upsert(request: $keyed, save: $save);
	}//end savedObject()

	/**
	 * The answer to a failed create.
	 *
	 * Anything no write route recognises is a 403, including RBAC refusals.
	 * Each refusal below is its own answer because a 403 would send the caller
	 * looking for a permissions problem it does not have.
	 *
	 * @param \Exception $exception The failure.
	 * @param ObjectOperationsInterface $objectService The object service.
	 *
	 * @return JSONResponse The refusal.
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	private function createRefusal(\Exception $exception, ObjectOperationsInterface $objectService): JSONResponse {
		$e = $exception;

		return ($this->writeRefusal(exception: $e, objectService: $objectService) ?? match (true) {
			$e instanceof UpsertOnKeyException => new JSONResponse(data: $e->getBody(), statusCode: $e->getStatusCode(), headers: $e->getHeaders()),
			$e instanceof ExpressionDefaultException => new JSONResponse(data: ['error' => $e->getMessage(), 'errors' => [$e->toArray()]], statusCode: 422),
			$e instanceof ObjectExistsException => new JSONResponse(data: ['error' => $e->getMessage(), 'uuid' => $e->getUuid()], statusCode: 409),
			$e instanceof DuplicateBlockedException => new JSONResponse(data: ['error' => $e->getMessage(), 'matches' => $e->getMatches()], statusCode: 409),
			$e instanceof DbalWriteException => new JSONResponse(data: ['error' => $e->getMessage()], statusCode: $e->getStatusCode()),
			default => new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 403),
		});
	}//end createRefusal()

	/**
	 * The refusal every write route gives the same answer, or null.
	 *
	 * A language-keyed body that collides with X-Translation-Target-Language keeps
	 * its structured 400, so it is asked before the validation handler. Validation
	 * refuses in the per-property shape a form can mark up.
	 *
	 * @param \Exception $exception The failure.
	 * @param ObjectOperationsInterface $objectService The object service.
	 *
	 * @return JSONResponse|null The refusal.
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	private function writeRefusal(\Exception $exception, ObjectOperationsInterface $objectService): ?JSONResponse {
		$e = $exception;

		return match (true) {
			$e instanceof AppendOnlyException => new JSONResponse(data: $e->toResponseBody(), statusCode: Http::STATUS_METHOD_NOT_ALLOWED),
			$e instanceof TranslationTargetConflictException => new JSONResponse(data: $e->toErrorBody(), statusCode: 400),
			$e instanceof ValidationException, $e instanceof CustomValidationException => $objectService->handleValidationException(exception: $e),
			$e instanceof HookStoppedException => new JSONResponse(data: ['error' => $e->getMessage(), 'errors' => $e->getErrors()], statusCode: 422),
			$e instanceof LockedException => $this->lockedResponse(exception: $e),
			$e instanceof FolderAccessDeniedException => $this->folderAccessDeniedResponse(exception: $e),
			default => null,
		};
	}//end writeRefusal()

	/**
	 * Why an `_upsertOn` request cannot proceed, or null when it can.
	 *
	 * Anonymous callers are refused: create() is a public page for form
	 * submissions, and overwriting a record by guessing its key is not what
	 * those forms are for. `_failIfExists` asks the opposite of an upsert.
	 *
	 * @param bool $failIfExists Whether the call also asked for insert-only.
	 * @param array<string, mixed> $resolved The resolved register and schema.
	 *
	 * @return JSONResponse|null The refusal, or null.
	 *
	 * @spec openspec/changes/api-upsert-on-a-declared-key/specs/objects-crud/spec.md
	 */
	private function upsertPreconditionRefusal(bool $failIfExists, array $resolved): ?JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(data: ['error' => 'An upsert needs a signed-in caller.'], statusCode: 401);
		}

		if ($failIfExists === true) {
			return new JSONResponse(
				data: ['error' => '_upsertOn and _failIfExists ask opposite things; send one of them.'],
				statusCode: 400
			);
		}

		if ($this->services->transfer->upsertOnKeyHandler === null
			|| ($resolved['registerEntity'] instanceof Register) === false
			|| ($resolved['schemaEntity'] instanceof Schema) === false
		) {
			return new JSONResponse(data: ['error' => 'Upsert on a key is not available here.'], statusCode: 501);
		}

		return null;
	}//end upsertPreconditionRefusal()

	/**
	 * Release the lock this writer holds, after their own write has landed.
	 *
	 * A save is a check-in: the writer hands their lock back, so the next
	 * editor is not kept out by a lock nobody is using any more. Only the
	 * writer's OWN lock goes. A run-held lock must survive somebody else's
	 * write: without that test an administrator's write would silently strip
	 * a run's lock as a side effect of a guard it had just passed.
	 *
	 * NO `runUuid` IS ASKED FOR HERE, DELIBERATELY, and it is the one guard in
	 * this file that omits it. This decides a RELEASE, not a refusal: asking
	 * it as the run would make a run's own write drop the run's own lock the
	 * moment it saved, and the lock is meant to outlive every write the run
	 * makes. Asked as a person, a run-held lock reads as somebody else's and
	 * is left alone, which is what this test is for.
	 *
	 * 🔴 THE ENTITY WE ARE ABOUT TO SERIALISE IS CLEARED WHEN THE RELEASE
	 * HAPPENS, AND THAT IS THE POINT OF THIS METHOD RATHER THAN A DETAIL OF
	 * IT. `unlockObject()` re-reads the row, releases it there and hands back
	 * a boolean; the entity in this request keeps the lock payload it was
	 * loaded with. So the response to a successful write reported a lock that
	 * the very same request had just released, and a client that trusts the
	 * body it was handed, such as `@conduction/nextcloud-vue`'s
	 * `useObjectLock`, goes on believing it holds a lock until its release
	 * answers 404. Nothing persists this clear: the row is already unlocked
	 * and the entity is discarded after it is serialised.
	 *
	 * The re-read is why the cheap `isLocked()` test comes first at all:
	 * `unlock()` resolves the identifier back to its register and schema, and
	 * unscoped that is a scan across every magic table on the instance. It
	 * then returns immediately when the object holds no lock (the
	 * openregister#195 idempotence branch), which is the normal case for this
	 * defensive post-save release. We already hold the saved entity, so the
	 * question is free here and the scan is pure waste, measured at about
	 * 780 ms of a 1.3 s update.
	 *
	 * @param ObjectEntity $objectEntity The entity the write just saved, and
	 *                                   the one whose serialisation answers
	 *                                   the caller.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/run-scoped-object-locking/specs/run-scoped-object-locking/spec.md#requirement-a-lock-refuses-a-write-and-names-its-holder
	 */
	private function releaseOwnLockAfterWrite(ObjectEntity $objectEntity): void {
		if ($objectEntity->isLocked() === false) {
			return;
		}

		if ($objectEntity->isLockedBySomeoneElse(userId: $this->writerId()) === true) {
			return;
		}

		try {
			$this->objectService->unlockObject($objectEntity->getUuid());
		} catch (\Exception $e) {
			// The write succeeded, so a failed release is not the caller's
			// problem and must not turn a 200 into an error. The entity keeps
			// its lock payload, which is then the truth: the lock is still on
			// the row.
			//
			// NOTE: must be the global \Exception. The unqualified `Exception`
			// resolves to OCP\DB\Exception here (see the `use` block) and
			// would NOT catch the \Exception thrown by LockHandler::unlock(),
			// which then surfaced as a spurious 403. See openregister#195.
			$this->logger->debug(
				message: '[ObjectsController] Failed to release the writer own lock after a write',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'uuid' => $objectEntity->getUuid(),
					'exception' => $e->getMessage(),
				]
			);
			return;
		}

		$objectEntity->setLocked(null);
	}//end releaseOwnLockAfterWrite()

	/**
	 * Who is writing, for the release decision.
	 *
	 * The container's `userId` is the canonical answer and is what every
	 * other guard in this file asks. It is read through a fallback because
	 * this decision had never actually run: every lock taken without a
	 * duration expired at the instant it was written, so `isLocked()` above
	 * was always false and nothing below it was ever reached. A resolution
	 * that quietly answers null here would not refuse anybody, it would
	 * silently stop releasing locks, which is the failure this whole path
	 * exists to prevent.
	 *
	 * @return string|null The writer's user id, or null when there is no user.
	 */
	private function writerId(): ?string {
		try {
			$fromContainer = $this->container->get('userId');
			if (is_string($fromContainer) === true && $fromContainer !== '') {
				return $fromContainer;
			}
		} catch (\Throwable $e) {
			// Not registered in this context; the session below is the answer.
			$this->logger->debug(
				message: '[ObjectsController] userId not registered in the container, using the session',
				context: [
					'exception' => $e->getMessage(),
				]
			);
		}

		return $this->userSession->getUser()?->getUID();
	}//end writerId()

	/**
	 * Updates an existing object
	 *
	 * Takes the request data, persist: validates it against the schema, silent: and updates an existing object
	 * in the database. Handles validation errors appropriately.
	 *
	 * @param string $register The register slug or identifier
	 * @param string $schema The schema slug or identifier
	 * @param string $id The object ID or UUID
	 * @param ObjectOperationsInterface $objectService The object service
	 *
	 * @return JSONResponse A JSON response containing the updated object
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @PublicPage
	 *
	 * @psalm-suppress TypeDoesNotContainType
	 * @psalm-suppress NoValue
	 *
	 * @SuppressWarnings(PHPMD.NPathComplexity)       Object update requires many validation and processing steps
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Object update requires many validation and processing steps
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	#[UserRateLimit(limit: 300, period: 60)]
	#[AnonRateLimit(limit: 30, period: 60)]
	public function update(string $register, string $schema, string $id, ObjectOperationsInterface $objectService): JSONResponse {
		\OCA\OpenRegister\Service\WritePhase::stamp('ctrl.update.in');

		try {
			$resolved = $this->resolveRegisterSchemaRef(ref: new RegisterSchemaRef(register: $register, schema: $schema), objectService: $objectService);
		} catch (RegisterNotFoundException|SchemaNotFoundException $e) {
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: 404);
		}

		$update = $this->updateOf(target: new ObjectTarget(id: $id, resolved: $resolved, objectService: $objectService));
		$existing = $this->writableObject(update: $update);
		if ($existing instanceof JSONResponse) {
			return $existing;
		}

		return $this->updatedResponse(update: $update, existing: $existing);
	}//end update()

	/**
	 * The update this request asks for.
	 *
	 * @param ObjectTarget $target The object aimed at.
	 *
	 * @return ObjectUpdate The update.
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	private function updateOf(ObjectTarget $target): ObjectUpdate {
		return new ObjectUpdate(
			target: $target,
			object: $this->submittableBody(object: $this->request->getParams()),
			caller: $this->writeCaller()
		);
	}//end updateOf()

	/**
	 * The caller's part of a write.
	 *
	 * @return WriteCaller The uploads and the scope.
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	private function writeCaller(): WriteCaller {
		return new WriteCaller(uploaded: $this->extractAllUploadedFiles(), scoped: ($this->isCurrentUserAdmin() === false));
	}//end writeCaller()

	/**
	 * The caller of a write that takes no uploads.
	 *
	 * @return WriteCaller The scope.
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	private function caller(): WriteCaller {
		return new WriteCaller(uploaded: [], scoped: ($this->isCurrentUserAdmin() === false));
	}//end caller()

	/**
	 * The stored object a PUT replaces, or the refusal.
	 *
	 * @param ObjectUpdate $update The update asked for.
	 *
	 * @return ObjectEntity|JSONResponse The object.
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	private function writableObject(ObjectUpdate $update): ObjectEntity|JSONResponse {
		try {
			$existing = $this->silentlyRead(update: $update);

			return ($this->preWriteRefusal(update: $update, existing: $existing) ?? $existing);
		} catch (\Exception $exception) {
			return $this->readBeforeWriteRefusal(exception: $exception, id: $update->target->id);
		}
	}//end writableObject()

	/**
	 * Read the object without an audit entry, scoped to the register and schema in the URL.
	 *
	 * An unscoped lookup resolves a uuid by UNION-ing every magic table on the
	 * instance. A uuid from another scope raises DoesNotExistException here.
	 *
	 * @param ObjectUpdate $update The update asked for.
	 *
	 * @return ObjectEntity The object.
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	private function silentlyRead(ObjectUpdate $update): ObjectEntity {
		return $this->objectService->findSilent(
			id: $update->target->id,
			_extend: [],
			files: false,
			register: $update->target->resolved['register'],
			schema: $update->target->resolved['schema'],
			_rbac: $update->caller->scoped,
			_multitenancy: $update->caller->scoped
		);
	}//end silentlyRead()

	/**
	 * Why this object may not be replaced: another scope, a lock or a stale version.
	 *
	 * A full replace asserts the expected version exactly as a partial update does.
	 *
	 * @param ObjectUpdate $update The update asked for.
	 * @param ObjectEntity $existing The stored object.
	 *
	 * @return JSONResponse|null The refusal, or null.
	 *
	 * @spec openspec/changes/a-conflicting-save-shows-the-other-value/specs/objects-crud/spec.md#requirement-every-write-path-asserts-the-expected-version-req-cso-003
	 */
	private function preWriteRefusal(ObjectUpdate $update, ObjectEntity $existing): ?JSONResponse {
		if ($update->target->holds(object: $existing) === false) {
			return new JSONResponse(data: ['error' => 'Object not found in specified register/schema'], statusCode: 404);
		}

		$lockRefusal = $this->lockRefusalResponse(existingObject: $existing);
		if ($lockRefusal !== null) {
			return $lockRefusal;
		}

		$this->noteClientSuppliedCause(object: $existing);

		return $this->versionGuard()->refusal(
			stored: $existing,
			write: new SentWrite(data: $update->object, schema: $update->target->resolved['schemaEntity'])
		);
	}//end preWriteRefusal()

	/**
	 * The answer to a read that failed before a write.
	 *
	 * @param \Exception $exception The failure.
	 * @param string $id The identifier in the URL.
	 *
	 * @return JSONResponse The refusal. An unexpected failure answers without its detail.
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	private function readBeforeWriteRefusal(\Exception $exception, string $id): JSONResponse {
		$e = $exception;
		if ($e instanceof DoesNotExistException || $e instanceof NotAuthorizedException || $e instanceof MultipleObjectsReturnedException) {
			return match (true) {
				$e instanceof DoesNotExistException => new JSONResponse(data: ['error' => 'Not Found'], statusCode: 404),
				$e instanceof NotAuthorizedException => new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 403),
				default => $this->ambiguousIdentifierResponse(id: $id),
			};
		}

		$context = ['file' => __FILE__, 'line' => __LINE__, 'exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()];
		$this->logger->error(message: '[ObjectsController] Unexpected exception in update findSilent', context: $context);

		return $this->errorResponse(e: $e);
	}//end readBeforeWriteRefusal()

	/**
	 * Save the replacement and answer with it.
	 *
	 * The write targets the object the caller's own read resolved, by its uuid:
	 * the save looks the identifier up again without the caller's scope, and the
	 * URL id alone can name another row there. A save is a check-in, so the
	 * writer's own lock goes back.
	 *
	 * @param ObjectUpdate $update The update asked for.
	 * @param ObjectEntity $existing The stored object.
	 *
	 * @return JSONResponse The object, or the refusal.
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	private function updatedResponse(ObjectUpdate $update, ObjectEntity $existing): JSONResponse {
		try {
			$objectEntity = $update->saved(uuid: ($existing->getUuid() ?? $update->target->id));
			$this->releaseOwnLockAfterWrite(objectEntity: $objectEntity);
			\OCA\OpenRegister\Service\WritePhase::stamp('ctrl.update.unlocked');
			$body = $objectEntity->jsonSerialize();
			\OCA\OpenRegister\Service\WritePhase::stamp('ctrl.update.out');

			return new JSONResponse(data: $body);
		} catch (\Exception $exception) {
			return $this->updateRefusal(exception: $exception, objectService: $update->target->objectService);
		}
	}//end updatedResponse()

	/**
	 * The answer to a failed PUT.
	 *
	 * An archived or frozen object refuses with 409 and the reason. Anything no
	 * write route recognises is a 403, including RBAC refusals.
	 *
	 * @param \Exception $exception The failure.
	 * @param ObjectOperationsInterface $objectService The object service.
	 *
	 * @return JSONResponse The refusal.
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	private function updateRefusal(\Exception $exception, ObjectOperationsInterface $objectService): JSONResponse {
		$e = $exception;

		return ($this->writeRefusal(exception: $e, objectService: $objectService) ?? match (true) {
			$e instanceof ObjectStateWriteException => $this->stateRefusal(exception: $e),
			$e instanceof DbalWriteException => new JSONResponse(data: ['error' => $e->getMessage()], statusCode: $e->getStatusCode()),
			default => new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 403),
		});
	}//end updateRefusal()

	/**
	 * The 409 of an archived or frozen object, with the reason.
	 *
	 * @param ObjectStateWriteException $exception The refusal.
	 *
	 * @return JSONResponse The 409.
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	private function stateRefusal(ObjectStateWriteException $exception): JSONResponse {
		return new JSONResponse(data: ['error' => $exception->getMessage()], statusCode: ObjectStateWriteException::HTTP_STATUS);
	}//end stateRefusal()

	/**
	 * Patches (partially updates) an existing object
	 *
	 * Takes the request data, _multitenancy: merges it with the existing object data, persist: validates it against
	 * the schema, and updates the object in the database. Only the provided fields are updated,
	 * while other fields remain unchanged. Handles validation errors appropriately.
	 *
	 * @param string $register The register slug or identifier
	 * @param string $schema The schema slug or identifier
	 * @param string $id The object ID or UUID
	 * @param ObjectOperationsInterface $objectService The object service
	 *
	 * @return JSONResponse A JSON response containing the updated object
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @PublicPage
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	#[UserRateLimit(limit: 300, period: 60)]
	#[AnonRateLimit(limit: 30, period: 60)]
	public function patch(string $register, string $schema, string $id, ObjectOperationsInterface $objectService): JSONResponse {
		try {
			$resolved = $this->resolveRegisterSchemaRef(ref: new RegisterSchemaRef(register: $register, schema: $schema), objectService: $objectService);
		} catch (RegisterNotFoundException|SchemaNotFoundException $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 404);
		}

		$target = new ObjectTarget(id: $id, resolved: $resolved, objectService: $objectService);
		$patch = new ObjectUpdate(target: $target, object: $this->submittableBody(object: $this->request->getParams()), caller: $this->caller());
		$this->logPatchScope(patch: $patch);

		try {
			return $this->patchedResponse(patch: $patch);
		} catch (\Exception $exception) {
			return ($this->knownPatchRefusal(exception: $exception, objectService: $objectService) ?? $this->unexpectedPatchFailure(exception: $exception));
		}
	}//end patch()

	/**
	 * Log the scope a PATCH runs in.
	 *
	 * @param ObjectUpdate $patch The patch asked for.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	private function logPatchScope(ObjectUpdate $patch): void {
		$scoped = $patch->caller->scoped;
		$context = ['file' => __FILE__, 'line' => __LINE__, 'id' => $patch->target->id];
		$context += ['isAdmin' => ($scoped === false), 'rbac' => $scoped, 'multi' => $scoped];
		$this->logger->debug(message: '[ObjectsController] PATCH: RBAC/Multitenancy settings', context: $context);
	}//end logPatchScope()

	/**
	 * Merge, save and answer a PATCH.
	 *
	 * @param ObjectUpdate $patch The patch asked for.
	 *
	 * @return JSONResponse The object, or the refusal.
	 *
	 * @spec openspec/changes/a-conflicting-save-shows-the-other-value/specs/objects-crud/spec.md#requirement-every-write-path-asserts-the-expected-version-req-cso-003
	 */
	private function patchedResponse(ObjectUpdate $patch): JSONResponse {
		$existing = $this->findReadableForPatch(patch: $patch);
		if ($existing instanceof JSONResponse) {
			return $existing;
		}

		$refusal = ($this->lockRefusalResponse(existingObject: $existing) ?? $this->versionRefusal(patch: $patch, existing: $existing));
		if ($refusal !== null) {
			return $refusal;
		}

		$objectEntity = $this->mergedPatch(patch: $patch, existing: $existing)->saved(uuid: ($existing->getUuid() ?? $patch->target->id));
		$this->logPatchSaved(object: $objectEntity);
		$this->releaseOwnLockAfterWrite(objectEntity: $objectEntity);
		$this->logger->debug(message: '[ObjectsController] PATCH: Starting to prepare response', context: ['file' => __FILE__, 'line' => __LINE__]);

		return new JSONResponse(data: $this->withUniqueHintWarnings(body: $objectEntity->jsonSerialize()));
	}//end patchedResponse()

	/**
	 * Log a saved PATCH.
	 *
	 * @param ObjectEntity $object The saved object.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	private function logPatchSaved(ObjectEntity $object): void {
		$context = ['file' => __FILE__, 'line' => __LINE__, 'uuid' => $object->getUuid(), 'status' => ($object->getObject()['status'] ?? 'unknown')];
		$this->logger->debug(message: '[ObjectsController] PATCH: saveObject succeeded', context: $context);
	}//end logPatchSaved()

	/**
	 * The 409 for a PATCH made from a stale read, or null.
	 *
	 * @param ObjectUpdate $patch The patch asked for.
	 * @param ObjectEntity $existing The stored object.
	 *
	 * @return JSONResponse|null The refusal.
	 *
	 * @spec openspec/changes/a-conflicting-save-shows-the-other-value/specs/objects-crud/spec.md#requirement-every-write-path-asserts-the-expected-version-req-cso-003
	 */
	private function versionRefusal(ObjectUpdate $patch, ObjectEntity $existing): ?JSONResponse {
		$this->noteClientSuppliedCause(object: $existing);

		return $this->versionGuard()->refusal(
			stored: $existing,
			write: new SentWrite(data: $patch->object, schema: $patch->target->resolved['schemaEntity'])
		);
	}//end versionRefusal()

	/**
	 * The patch with the stored data under it.
	 *
	 * The read decoded, so the write re-encodes: only keys the caller did NOT send
	 * are restored.
	 *
	 * @param ObjectUpdate $patch The patch asked for.
	 * @param ObjectEntity $existing The stored object.
	 *
	 * @return ObjectUpdate The write that replaces the object.
	 *
	 * @spec openspec/specs/schema-driven-read-coercion/spec.md
	 */
	private function mergedPatch(ObjectUpdate $patch, ObjectEntity $existing): ObjectUpdate {
		$merged = $this->restoreStringTypedValues(
			mergedData: array_merge(($existing->getObject() ?? []), $patch->object),
			patch: new StringTypedPatch(schema: $patch->target->resolved['schemaEntity'], suppliedKeys: array_keys($patch->object))
		);

		return new ObjectUpdate(target: $patch->target, object: $merged, caller: $patch->caller);
	}//end mergedPatch()

	/**
	 * The refusal both patch routes answer the same way, or null.
	 *
	 * An archived or frozen object refuses with 409 and the reason; a refusal by
	 * the rules answers 403 with that reason.
	 *
	 * @param \Exception $exception The failure.
	 * @param ObjectOperationsInterface $objectService The object service.
	 *
	 * @return JSONResponse|null The refusal.
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	private function knownPatchRefusal(\Exception $exception, ObjectOperationsInterface $objectService): ?JSONResponse {
		$e = $exception;

		return ($this->writeRefusal(exception: $e, objectService: $objectService) ?? match (true) {
			$e instanceof ObjectStateWriteException => $this->stateRefusal(exception: $e),
			$e instanceof NotAuthorizedException => new JSONResponse(data: ['error' => $e->getMessage()], statusCode: Http::STATUS_FORBIDDEN),
			default => null,
		});
	}//end knownPatchRefusal()

	/**
	 * Log a failed PATCH and answer without its detail.
	 *
	 * @param \Exception $exception The failure.
	 *
	 * @return JSONResponse The answer.
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	private function unexpectedPatchFailure(\Exception $exception): JSONResponse {
		$context = ['file' => __FILE__, 'line' => __LINE__, 'exception' => $exception->getMessage(), 'trace' => $exception->getTraceAsString()];
		$this->logger->error(message: '[ObjectsController] Unexpected exception in patch', context: $context);

		return $this->errorResponse(e: $exception);
	}//end unexpectedPatchFailure()

	/**
	 * Partially updates an existing object via POST with multipart file upload support.
	 *
	 * PHP only populates $_FILES for POST requests, so PUT/PATCH cannot handle
	 * multipart/form-data file uploads. This endpoint allows clients to POST to
	 * an existing object to update it (PATCH semantics) while supporting file uploads.
	 *
	 * @param string $register The register slug or identifier
	 * @param string $schema The schema slug or identifier
	 * @param string $id The object ID or UUID
	 * @param ObjectOperationsInterface $objectService The object service
	 *
	 * @return JSONResponse A JSON response containing the updated object
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @PublicPage
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	#[UserRateLimit(limit: 300, period: 60)]
	#[AnonRateLimit(limit: 30, period: 60)]
	public function postPatch(string $register, string $schema, string $id, ObjectOperationsInterface $objectService): JSONResponse {
		try {
			$resolved = $this->resolveRegisterSchemaRef(ref: new RegisterSchemaRef(register: $register, schema: $schema), objectService: $objectService);
		} catch (RegisterNotFoundException|SchemaNotFoundException $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 404);
		}

		$target = new ObjectTarget(id: $id, resolved: $resolved, objectService: $objectService);
		$body = array_diff_key($this->submittableBody(object: $this->request->getParams()), ['id' => true]);
		$patch = new ObjectUpdate(target: $target, object: $body, caller: $this->writeCaller());

		try {
			return $this->postPatchedResponse(patch: $patch);
		} catch (\Exception $exception) {
			return ($this->knownPatchRefusal(exception: $exception, objectService: $objectService) ?? $this->errorResponse(e: $exception));
		}
	}//end postPatch()

	/**
	 * Merge, save and answer a POST patch, which also takes file uploads.
	 *
	 * @param ObjectUpdate $patch The patch asked for.
	 *
	 * @return JSONResponse The object, or the refusal.
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	private function postPatchedResponse(ObjectUpdate $patch): JSONResponse {
		$existing = $this->findReadableForPatch(patch: $patch);
		if ($existing instanceof JSONResponse) {
			return $existing;
		}

		$refusal = ($this->lockRefusalResponse(existingObject: $existing) ?? $this->versionRefusal(patch: $patch, existing: $existing));
		if ($refusal !== null) {
			return $refusal;
		}

		$merged = $this->mergedPatch(patch: $patch, existing: $existing);
		$patch->target->objectService->clearCreatedSubObjects();
		$objectEntity = $merged->saved(uuid: ($existing->getUuid() ?? $patch->target->id));
		$this->releaseOwnLockAfterWrite(objectEntity: $objectEntity);

		return new JSONResponse(data: $this->withUniqueHintWarnings(body: $objectEntity->jsonSerialize()));
	}//end postPatchedResponse()

	/**
	 * Deletes an object
	 *
	 * This method deletes an object based on its ID.
	 *
	 * @param string $id The ID/UUID of the object to delete
	 * @param string $register The register ID
	 * @param string $schema The schema ID
	 * @param ObjectHandlerAccessInterface $objectService The object service
	 *
	 * @return JSONResponse JSON response with success or error.
	 *
	 * @throws \Exception
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	#[UserRateLimit(limit: 300, period: 60)]
	#[AnonRateLimit(limit: 30, period: 60)]
	public function destroy(string $id, string $register, string $schema, ObjectHandlerAccessInterface $objectService): JSONResponse {
		\OCA\OpenRegister\Service\WritePhase::stamp('ctrl.destroy.in');

		$route = new ObjectDeleteRoute(id: $id, register: $register, schema: $schema, objectService: $objectService);
		try {
			// Set the register and schema context for ObjectService.
			$objectService->setRegister(register: $register);
			$objectService->setSchema(schema: $schema);

			return $this->deleteInScope(route: $route);
		} catch (\Throwable $failure) {
			return $this->deleteFailureResponse(failure: $failure, route: $route);
		}
	}//end destroy()

	/**
	 * Delete the object on a route, in the scope the caller's admin status gives.
	 *
	 * @param ObjectDeleteRoute $route The delete route.
	 *
	 * @return JSONResponse 204, or 500 when the delete did not happen.
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	private function deleteInScope(ObjectDeleteRoute $route): JSONResponse {
		// Determine RBAC and multitenancy settings based on admin status.
		$scoped = ($this->isCurrentUserAdmin() === false);
		// Use ObjectService to delete the object (includes RBAC permission checks,
		// persist: audit trail, silent: and soft delete).
		//
		// Pass the scope EXPLICITLY. deleteObject() decides whether to scope
		// its lookup from its own arguments ($hasScope), not from the
		// register/schema set on the service above — so omitting them here
		// resolved the UUID by UNION-ing every magic table on the instance.
		// It also meant the URL's scope was never enforced: an object could
		// be deleted through a register/schema it does not belong to.
		$deleteResult = $route->objectService->deleteObject(
			uuid: $route->id,
			register: $route->register,
			schema: $route->schema,
			_rbac: $scoped,
			_multitenancy: $scoped
		);

		if ($deleteResult === false) {
			// If delete operation failed, return error.
			return new JSONResponse(data: ['error' => 'Failed to delete object'], statusCode: 500);
		}

		// Return 204 No Content for successful delete (REST convention).
		return new JSONResponse(data: null, statusCode: 204);
	}//end deleteInScope()

	/**
	 * The answer to a delete that threw; the order keeps the most specific refusal first.
	 *
	 * @param \Throwable        $failure The failure.
	 * @param ObjectDeleteRoute $route   The delete route.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	private function deleteFailureResponse(\Throwable $failure, ObjectDeleteRoute $route): JSONResponse {
		return match (true) {
			$failure instanceof ArchivalImmutableException => self::archivalDeleteRefusal(exception: $failure),
			// Reject delete on append-only schema with HTTP 405.
			$failure instanceof AppendOnlyException => new JSONResponse(data: $failure->toResponseBody(), statusCode: Http::STATUS_METHOD_NOT_ALLOWED),
			$failure instanceof ReferentialIntegrityException => $this->integrityDeleteRefusal(exception: $failure, objectService: $route->objectService),
			$failure instanceof HookStoppedException => self::hookDeleteRefusal(exception: $failure),
			// Absent objects (native or external) are a uniform 404.
			$failure instanceof DoesNotExistException => new JSONResponse(data: ['error' => 'Not Found'], statusCode: 404),
			$failure instanceof MultipleObjectsReturnedException => $this->ambiguousIdentifierResponse(id: $route->id),
			$failure instanceof \Exception => self::deleteErrorResponse(exception: $failure),
			default => $this->unexpectedDeleteResponse(failure: $failure, route: $route),
		};
	}//end deleteFailureResponse()

	/**
	 * The answer to a delete an archival schema refuses.
	 *
	 * @param ArchivalImmutableException $exception The refusal.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	private static function archivalDeleteRefusal(ArchivalImmutableException $exception): JSONResponse {
		// 🔴 THE REFUSAL HAS A WIRE CONTRACT AND THIS ENDPOINT WAS NOT
		// HONOURING IT. `ArchivalImmutableException::toResponseBody()`
		// exists and DeletedController and BulkController both use it, but
		// this catch was missing here, so a refusal on the endpoint clients
		// actually call fell through to the generic handler and answered
		// `{"error": "SCHEMA_ARCHIVAL_IMMUTABLE: Schema ... declares ..."}`
		// as one flattened string. A client following the spec and testing
		// `body.error === 'SCHEMA_ARCHIVAL_IMMUTABLE'` matched nothing, and
		// `schema`, `operation` and `hint` were not there to read at all.
		//
		// 403, not the trait's 405: the spec says 403 and the route already
		// answered 403 through the generic path, so the status stays put
		// and only the body becomes what was promised.
		return new JSONResponse(data: $exception->toResponseBody(), statusCode: Http::STATUS_FORBIDDEN);
	}//end archivalDeleteRefusal()

	/**
	 * The answer to a delete referential integrity refuses; a non-admin hears only what they may read.
	 *
	 * @param ReferentialIntegrityException $exception     The refusal.
	 * @param ObjectHandlerAccessInterface  $objectService The object service.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	private function integrityDeleteRefusal(ReferentialIntegrityException $exception, ObjectHandlerAccessInterface $objectService): JSONResponse {
		if ($this->isCurrentUserAdmin() === false) {
			$readable = $objectService->readableDeletionAnalysis(analysis: $exception->getAnalysis());
			$exception = new ReferentialIntegrityException(analysis: $readable);
		}

		return new JSONResponse(
			data: $exception->toResponseBody(),
			statusCode: 409
		);
	}//end integrityDeleteRefusal()

	/**
	 * The answer to a delete a hook stopped.
	 *
	 * @param HookStoppedException $exception The refusal.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	private static function hookDeleteRefusal(HookStoppedException $exception): JSONResponse {
		// A guard on the deleting event may name its own status. A refusal
		// because another row still references this one is a conflict, not
		// a malformed body, and 422 would tell the caller to fix a payload
		// that has nothing wrong with it. Only a hook that says so gets a
		// different status; everything else keeps the 422 it had.
		$errors = $exception->getErrors();
		$statusCode = 422;
		if (isset($errors['status']) === true && is_int($errors['status']) === true) {
			$statusCode = $errors['status'];
		}

		return new JSONResponse(
			data: ['error' => $exception->getMessage(), 'errors' => $errors],
			statusCode: $statusCode
		);
	}//end hookDeleteRefusal()

	/**
	 * The answer to any other delete exception.
	 *
	 * @param \Exception $exception The exception.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	private static function deleteErrorResponse(\Exception $exception): JSONResponse {
		// Handle all exceptions (including RBAC permission errors and object not found).
		// Sanitized external-write failures carry their own 4xx status
		// (dbal-virtual-registers-crud) — never flatten them to 403.
		if ($exception instanceof \OCA\OpenRegister\Service\ObjectSource\DbalWriteException === true) {
			return new JSONResponse(data: ['error' => $exception->getMessage()], statusCode: $exception->getStatusCode());
		}

		return new JSONResponse(data: ['error' => $exception->getMessage()], statusCode: 403);
	}//end deleteErrorResponse()

	/**
	 * The answer to a fatal error during a delete.
	 *
	 * @param \Throwable        $failure The failure.
	 * @param ObjectDeleteRoute $route   The delete route.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	private function unexpectedDeleteResponse(\Throwable $failure, ObjectDeleteRoute $route): JSONResponse {
		// Safety net for fatal errors (\Error/\TypeError) that do NOT extend
		// \Exception and would otherwise escape as an HTML 500 fatal-error page
		// to API clients. Log the full trace and return a clean JSON 500 so the
		// contract is always machine-readable. \Throwable MUST be caught last so
		// the specific catches above keep their dedicated status codes.
		$this->logger->error(
			message: '[ObjectsController] Unexpected fatal error while deleting object',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'register' => $route->register,
				'schema' => $route->schema,
				'id' => $route->id,
				'exception' => $failure->getMessage(),
				'trace' => $failure->getTraceAsString(),
			]
		);
		return new JSONResponse(
			data: ['error' => 'An unexpected error occurred while deleting the object'],
			statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
		);
	}//end unexpectedDeleteResponse()

	/**
	 * Check if an object can be deleted (pre-flight referential integrity analysis).
	 *
	 * Returns the full deletion analysis without performing any mutations.
	 *
	 * @param string $id The ID/UUID of the object to check
	 * @param string $register The register slug or identifier
	 * @param string $schema The schema slug or identifier
	 * @param ObjectHandlerAccessInterface $objectService The object service
	 *
	 * @return JSONResponse JSON response with DeletionAnalysis
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	public function canDelete(
		string $id,
		string $register,
		string $schema,
		ObjectHandlerAccessInterface $objectService,
	): JSONResponse {
		try {
			$objectService->setRegister(register: $register);
			$objectService->setSchema(schema: $schema);

			return $this->deletionAnalysisResponse(
				route: new ObjectDeleteRoute(id: $id, register: $register, schema: $schema, objectService: $objectService)
			);
		} catch (\OCP\AppFramework\Db\DoesNotExistException $exception) {
			return new JSONResponse(data: ['error' => 'Object not found'], statusCode: 404);
		} catch (MultipleObjectsReturnedException $exception) {
			return $this->ambiguousIdentifierResponse(id: $id);
		} catch (\Exception $exception) {
			return new JSONResponse(data: ['error' => $exception->getMessage()], statusCode: 403);
		}//end try
	}//end canDelete()

	/**
	 * What a delete of the object on a route would do, as far as the caller may read.
	 *
	 * @param ObjectDeleteRoute $route The delete route.
	 *
	 * @return JSONResponse The analysis, or 404.
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	private function deletionAnalysisResponse(ObjectDeleteRoute $route): JSONResponse {
		$objectService = $route->objectService;

		// Read in the caller's own scope, as the delete itself reads it: an
		// object the caller may not read is a 404, not an analysis of what
		// refers to it.
		$scoped = ($this->isCurrentUserAdmin() === false);
		$objectEntity = WriteCause::asLookup(fn () => $objectService->find(
			id: $route->id,
			register: $route->register,
			schema: $route->schema,
			_rbac: $scoped,
			_multitenancy: $scoped
		));
		if ($objectEntity === null) {
			return new JSONResponse(data: ['error' => 'Object not found'], statusCode: 404);
		}

		$analysis = $objectService->getDeleteHandler()->canDelete($objectEntity);
		if ($scoped === true) {
			$analysis = $objectService->readableDeletionAnalysis(analysis: $analysis);
		}

		return new JSONResponse(data: $analysis->toArray(), statusCode: 200);
	}//end deletionAnalysisResponse()

	/**
	 * Retrieves call logs for a object
	 *
	 * This method returns all the call logs associated with a object based on its ID.
	 *
	 * @param string $id The ID/UUID of the object to retrieve logs for
	 * @param string $register The register slug or identifier
	 * @param string $schema The schema slug or identifier
	 * @param ObjectOperationsInterface $objectService The object service
	 *
	 * @return JSONResponse JSON response with object contracts
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @todo Implement contract functionality to handle object contracts and their relationships
	 *
	 * @psalm-return JSONResponse<200,
	 *     array{results: array<int, mixed>, total: int<0, max>,
	 *     page: float|int<1, max>, pages: 1|float, limit: int<1, max>,
	 *     offset: int<0, max>, next?: string, prev?: string},
	 *     array<never, never>>
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	public function contracts(string $id, string $register, string $schema, ObjectOperationsInterface $objectService): JSONResponse {
		// REGISTER FIRST. `setSchema()` scopes its slug lookup to whatever
		// register is currently set, and ObjectService is reused across many
		// operations in one process, so naming the schema first resolves it
		// against a register an unrelated call left behind.
		$objectService->setRegister(register: $register);
		$objectService->setSchema(schema: $schema);

		$filters = self::contractsFilters(params: $this->request->getParams());

		// Use ObjectService delegation to handler.
		try {
			$result = $objectService->getObjectContracts(objectId: $id, filters: $filters);
		} catch (MultipleObjectsReturnedException $e) {
			return $this->ambiguousIdentifierResponse(id: $id);
		}

		// Stamp resourceUrls (deep-links) before paginating.
		return $this->contractsPage(result: $this->stampObjectUrls(result: $result), filters: $filters);
	}//end contracts()

	/**
	 * The paging filters of a contracts request; a plain name wins over its `_` form.
	 *
	 * @param array $params The request parameters.
	 *
	 * @return array{limit: int, offset: int|null, page: int|null} The filters.
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	private static function contractsFilters(array $params): array {
		return [
			'limit' => (self::pagingParam(params: $params, name: 'limit') ?? 20),
			'offset' => self::pagingParam(params: $params, name: 'offset'),
			'page' => self::pagingParam(params: $params, name: 'page'),
		];
	}//end contractsFilters()

	/**
	 * A paging parameter as an integer, by its plain name or its `_` form.
	 *
	 * @param array  $params The request parameters.
	 * @param string $name   The plain name.
	 *
	 * @return int|null The value, or null when neither form is set.
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	private static function pagingParam(array $params, string $name): int|null {
		$value = ($params[$name] ?? $params['_' . $name] ?? null);
		if ($value === null) {
			return null;
		}

		return (int)$value;
	}//end pagingParam()

	/**
	 * One page of an object's contracts.
	 *
	 * @param array $result  The stamped contracts.
	 * @param array $filters The paging filters.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	private function contractsPage(array $result, array $filters): JSONResponse {
		return new JSONResponse(
			data: $this->paginate(
				results: ($result['results'] ?? []),
				window: new ResultWindow(
					total: ($result['total'] ?? 0),
					limit: $filters['limit'],
					offset: $filters['offset'],
					page: $filters['page']
				)
			)
		);
	}//end contractsPage()

	/**
	 * Stamp a canonical `url` (resourceUrl) onto each related-object record.
	 *
	 * This reuses the SAME resolver the unified-search provider uses
	 * ({@see \OCA\OpenRegister\Service\DeepLinkRegistryService::resolveUrl()}),
	 * so there is a single source of truth for "how is an object opened in the
	 * UI". Consuming apps register per-(register, schema) URL templates via the
	 * DeepLinkRegistrationEvent; when no registration exists we fall back to
	 * OpenRegister's own object route (mirroring `lib/Search/ObjectsProvider.php`).
	 *
	 * Resolution is defensive: a failure (or missing dependency) omits the `url`
	 * for that record without altering or dropping the record itself.
	 *
	 * @param array $result The relation envelope ({results, total, ...}).
	 *
	 * @return array The same envelope with `url` stamped on each resolvable record.
	 */
	private function stampObjectUrls(array $result): array {
		return (new RelationUrlStamper(linkedData: $this->linkedData, logger: $this->logger))->stamped(result: $result);
	}//end stampObjectUrls()

	/**
	 * Retrieves all objects that this object references
	 *
	 * This method returns all objects that this object uses/references.
	 * A -> B means that A (This object) references B (Another object).
	 *
	 * @param string $id The ID of the object to retrieve relations for
	 * @param string $register The register slug or identifier
	 * @param string $schema The schema slug or identifier
	 * @param ObjectOperationsInterface $objectService The object service
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with related objects
	 *
	 * @psalm-return JSONResponse<200,
	 *     array{results: list<array<string, mixed>>, total: int<0, max>,
	 *     limit: 30|mixed, offset: 0|mixed},
	 *     array<never, never>>
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	public function uses(string $id, string $register, string $schema, ObjectOperationsInterface $objectService): JSONResponse {
		// Set the register and schema context first.
		$objectService->setRegister(register: $register);
		$objectService->setSchema(schema: $schema);

		// Build search query from request parameters.
		$queryParams = $this->request->getParams();
		$searchQuery = $queryParams;

		// Clean up unwanted parameters.
		unset($searchQuery['id'], $searchQuery['_route']);

		// Use ObjectService delegation to handler.
		try {
			$result = $objectService->getObjectUses(
				objectId: $id,
				query: $searchQuery,
				_rbac: true,
				_multitenancy: true
			);
		} catch (MultipleObjectsReturnedException $e) {
			return $this->ambiguousIdentifierResponse(id: $id);
		}

		// Stamp resourceUrls (deep-links) and return the result from ObjectService.
		return new JSONResponse(data: $this->stampObjectUrls(result: $result));
	}//end uses()

	/**
	 * Retrieves all objects that use a object
	 *
	 * This method returns all objects that reference (use) this object.
	 * B -> A means that B (Another object) references A (This object).
	 *
	 * @param string $id The ID of the object to retrieve uses for
	 * @param string $register The register slug or identifier
	 * @param string $schema The schema slug or identifier
	 * @param ObjectOperationsInterface $objectService The object service
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with objects that use this object
	 *
	 * @psalm-return JSONResponse<200,
	 *     array{results: list<array<string, mixed>>, total: int<0, max>,
	 *     limit: 30|mixed, offset: 0|mixed, message?: string},
	 *     array<never, never>>
	 *
	 * The old annotation said `results: array<never, never>, total: 0` — read
	 * off the stub this method used to be. Each row now carries a `relation`
	 * block naming the referencing property and its inverse label
	 * (openspec/changes/relation-types-with-inverses).
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	public function used(string $id, string $register, string $schema, ObjectOperationsInterface $objectService): JSONResponse {
		// REGISTER FIRST. `setSchema()` scopes its slug lookup to whatever
		// register is currently set, and ObjectService is reused across many
		// operations in one process, so naming the schema first resolves it
		// against a register an unrelated call left behind.
		$objectService->setRegister(register: $register);
		$objectService->setSchema(schema: $schema);

		// Build search query from request parameters.
		$queryParams = $this->request->getParams();
		$searchQuery = $queryParams;

		// Clean up unwanted parameters.
		unset($searchQuery['id'], $searchQuery['_route']);

		// Use ObjectService delegation to handler.
		try {
			$result = $objectService->getObjectUsedBy(
				objectId: $id,
				query: $searchQuery,
				_rbac: true,
				_multitenancy: true
			);
		} catch (MultipleObjectsReturnedException $e) {
			return $this->ambiguousIdentifierResponse(id: $id);
		}

		// Stamp resourceUrls (deep-links) and return the result from ObjectService.
		return new JSONResponse(data: $this->stampObjectUrls(result: $result));
	}//end used()

	/**
	 * Retrieves logs for an object
	 *
	 * This method returns a JSON response containing the logs for a specific object.
	 *
	 * @param string $id The ID of the object to retrieve logs for
	 * @param string $register The register slug or identifier
	 * @param string $schema The schema slug or identifier
	 * @param ObjectOperationsInterface $objectService The object service
	 *
	 * @return JSONResponse JSON response with object audit logs
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @psalm-return JSONResponse<200|404,
	 *     array{results?: array<int, mixed>, total?: int<0, max>,
	 *     page?: float|int<1, max>, pages?: 1|float, limit?: int<1, max>,
	 *     offset?: int<0, max>, next?: string, prev?: string,
	 *     message?: 'Object does not belong to specified register/schema'|'Object not found'},
	 *     array<never, never>>
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	public function logs(string $id, string $register, string $schema, ObjectOperationsInterface $objectService): JSONResponse {
		// Whoever may read the object reads its history, redacted like the
		// audit-trails route: a non-admin is answered by ObjectHistoryForReaders
		// (404 when the object GET would be, the doelbinding guard, and no
		// `session`, `request`, `ipAddress` or property the caller may not see).
		if ($this->isCurrentUserAdmin() === false) {
			return $this->logsForReader(ref: new ObjectRef(register: $register, schema: $schema, id: $id));
		}

		// Set the register and schema context first.
		$objectService->setRegister(register: $register);
		$objectService->setSchema(schema: $schema);

		// Fetch the object by ID/UUID only (no register/schema filter yet).
		$object = $this->historyObject(id: $id, objectService: $objectService);
		if ($object instanceof JSONResponse) {
			return $object;
		}

		if (self::livesAt(object: $object, route: self::resolvedRoute(route: [$register, $schema], objectService: $objectService)) === false) {
			$msg = 'Object does not belong to specified register/schema';
			return new JSONResponse(data: ['message' => $msg], statusCode: 404);
		}

		return $this->logsPage(id: $id, objectService: $objectService);
	}//end logs()

	/**
	 * The object whose history an administrator asks for.
	 *
	 * @param string                    $id            The object ID or UUID.
	 * @param ObjectOperationsInterface $objectService The object service.
	 *
	 * @return ObjectEntity|JSONResponse The object, or the answer when it cannot be read.
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	private function historyObject(string $id, ObjectOperationsInterface $objectService): ObjectEntity|JSONResponse {
		try {
			$object = WriteCause::asLookup(fn () => $objectService->find(id: $id));
		} catch (MultipleObjectsReturnedException $e) {
			return $this->ambiguousIdentifierResponse(id: $id);
		} catch (\Exception $e) {
			return $this->historyLookupFailureResponse(failure: $e);
		}

		return ($object ?? new JSONResponse(data: ['message' => 'Object not found'], statusCode: 404));
	}//end historyObject()

	/**
	 * Whether an object's stored register and schema are the given ones, ignoring case.
	 *
	 * @param ObjectEntity $object The object.
	 * @param array        $route  The register and schema to compare with.
	 *
	 * @return bool
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	private static function livesAt(ObjectEntity $object, array $route): bool {
		return strtolower((string)$object->getRegister()) === strtolower($route[0])
			&& strtolower((string)$object->getSchema()) === strtolower($route[1]);
	}//end livesAt()

	/**
	 * The ids a route's register and schema resolved to; a part that did not resolve stays as named.
	 *
	 * @param array                     $route         The register and schema on the route.
	 * @param ObjectOperationsInterface $objectService The object service, with the route set.
	 *
	 * @return array The register and schema.
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	private static function resolvedRoute(array $route, ObjectOperationsInterface $objectService): array {
		return [
			(string)($objectService->getCurrentRegisterEntity()?->getId() ?? $route[0]),
			(string)($objectService->getCurrentSchemaEntity()?->getId() ?? $route[1]),
		];
	}//end resolvedRoute()

	/**
	 * One page of an object's audit log, masked for explicit-reveal values.
	 *
	 * @param string                    $id            The object ID or UUID.
	 * @param ObjectOperationsInterface $objectService The object service.
	 *
	 * @return JSONResponse The page.
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	private function logsPage(string $id, ObjectOperationsInterface $objectService): JSONResponse {
		// Get config and fetch logs.
		$config = $this->getConfig();
		$logs = $objectService->getLogs(uuid: $id, filters: $config['filters']);

		// An explicit-reveal value reads masked for an administrator too.
		$trailMasker = $this->container->get(\OCA\OpenRegister\Service\Rbac\ExplicitRevealTrailMaskerInterface::class);
		if ($trailMasker instanceof \OCA\OpenRegister\Service\Rbac\ExplicitRevealTrailMaskerInterface) {
			$logs = $trailMasker->mask(entries: $logs);
		}

		// Return paginated results.
		return new JSONResponse(
			data: $this->paginate(
				results: $logs,
				window: new ResultWindow(total: count($logs), limit: $config['limit'], offset: $config['offset'], page: $config['page'])
			)
		);
	}//end logsPage()

	/**
	 * One object's history for a non-admin, when they may read the object.
	 *
	 * @param ObjectRef $ref The register, the schema and the object ID or UUID, as the route names them.
	 *
	 * @return JSONResponse 200 with the redacted page, 404 when the object is not readable to this caller,
	 *                      or the doelbinding refusal.
	 *
	 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-history-of-one-object-is-readable-by-whoever-may-read-that-object
	 */
	private function logsForReader(ObjectRef $ref): JSONResponse {
		$config = $this->getConfig();
		$search = null;
		if ($config['_search'] !== null) {
			$search = (string)$config['_search'];
		}

		try {
			$page = $this->container->get(ObjectHistoryForReadersInterface::class)->page(
				ref: $ref,
				params: [
					'limit' => $config['limit'],
					'offset' => $config['offset'],
					'page' => $config['page'],
					'filters' => $config['filters'],
					'sort' => $config['sort'],
					'search' => $search,
				]
			);
		} catch (PurposeRefusedException $refusal) {
			return new JSONResponse(data: $refusal->toResponseBody(), statusCode: $refusal->getStatusCode());
		} catch (MultipleObjectsReturnedException $e) {
			// The 409 an administrator gets on this route (#4037), not a 500.
			return $this->ambiguousIdentifierResponse(id: $ref->id);
		}

		if ($page === null) {
			// The answer the object GET gives a caller who may not read the object.
			return new JSONResponse(data: ['message' => 'Object not found'], statusCode: 404);
		}

		return new JSONResponse(data: $page);
	}//end logsForReader()

	/**
	 * Lock an object
	 *
	 * @param string $register The register slug or identifier
	 * @param string $schema The schema slug or identifier
	 * @param string $id The ID/UUID of the object to lock
	 *
	 * @return JSONResponse JSON response with lock result
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	public function lock(string $register, string $schema, string $id): JSONResponse {
		try {
			// Set the schema and register to the object service.
			$this->objectService->setRegister(register: $register);
			$this->objectService->setSchema(schema: $schema);

			return $this->takeLock(id: $id);
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			return new JSONResponse(data: ['error' => 'Object not found'], statusCode: 404);
		} catch (MultipleObjectsReturnedException $e) {
			return $this->ambiguousIdentifierResponse(id: $id);
		} catch (NotAuthorizedException $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 403);
		} catch (\Throwable $e) {
			// SEC-CTRL-7: do not leak internal exception detail on 500.
			return $this->errorResponse(e: $e);
		}//end try
	}//end lock()

	/**
	 * Lock an object for the process and duration the request names.
	 *
	 * @param string $id The object ID or UUID.
	 *
	 * @return JSONResponse The lock, with whether it is held.
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	private function takeLock(string $id): JSONResponse {
		$data = $this->request->getParams();

		$lockResult = $this->objectService->lockObject(
			identifier: $id,
			process: ($data['process'] ?? null),
			duration: self::lockDuration(data: $data)
		);

		// 🔴 `locked` WAS THE LITERAL `true`, WHICH IS WHY THE STEP
		// ASSERTING IT COULD NOT FAIL. `LockHandler` now reports whether
		// the lock it took is actually held, read back off the entity, and
		// that is what goes on the wire. The advisory (pre-creation) path
		// has no entity to read, so it keeps the old answer.
		$held = ($lockResult['held'] ?? true);
		unset($lockResult['held']);

		return new JSONResponse(data: array_merge($lockResult, ['locked' => $held]));
	}//end takeLock()

	/**
	 * The lock duration a request names.
	 *
	 * @param array $data The request parameters.
	 *
	 * @return int|null The duration, or null when none is set.
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	private static function lockDuration(array $data): int|null {
		if (($data['duration'] ?? null) !== null) {
			return (int)$data['duration'];
		}

		return null;
	}//end lockDuration()

	/**
	 * Unlock an object
	 *
	 * @param string $register The register slug or identifier
	 * @param string $schema The schema slug or identifier
	 * @param string $id The ID of the object to unlock
	 *
	 * @return JSONResponse A JSON response containing the unlocked object
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	public function unlock(string $register, string $schema, string $id): JSONResponse {
		// Authorization: anonymous callers cannot unlock anything; the
		// per-object permission check (lock-holder OR owner OR schema-manage
		// OR admin) lives in LockHandler::unlock and surfaces a permission
		// error message we map to 403 here. This closes the wave-3 C14
		// "any authenticated user can unlock anything" finding.
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(
				data: ['error' => 'Not authenticated'],
				statusCode: 401
			);
		}

		try {
			$this->objectService->setRegister(register: $register);
			$this->objectService->setSchema(schema: $schema);
			$released = $this->objectService->unlockObject($id);
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			return new JSONResponse(data: ['error' => 'Object not found'], statusCode: 404);
		} catch (MultipleObjectsReturnedException $e) {
			return $this->ambiguousIdentifierResponse(id: $id);
		} catch (\Exception $e) {
			return self::unlockFailureResponse(message: $e->getMessage());
		}

		return self::unlockResponse(released: $released, id: $id);
	}//end unlock()

	/**
	 * The answer to a failed unlock: 403 for a permission error, else 500.
	 *
	 * @param string $message The failure message.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	private static function unlockFailureResponse(string $message): JSONResponse {
		if (str_contains($message, 'does not have permission to unlock') === true) {
			return new JSONResponse(data: ['error' => $message], statusCode: 403);
		}

		return new JSONResponse(data: ['error' => $message], statusCode: 500);
	}//end unlockFailureResponse()

	/**
	 * The answer to an unlock that ran.
	 *
	 * @param bool   $released Whether a lock was released.
	 * @param string $id       The object ID or UUID.
	 *
	 * @return JSONResponse
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The flag is the fact this response reports
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	private static function unlockResponse(bool $released, string $id): JSONResponse {
		// 🔴 404 MEANS THIS OBJECT WAS NOT LOCKED, AND IT IS A FACT RATHER THAN
		// A MISSING ROUTE. A client that releases a lock when its editor closes
		// has to be able to tell "I handed mine back" from "somebody had
		// already taken it away", and a 200 for both is how a UI reports
		// success on a lock it never held. `@conduction/nextcloud-vue`'s
		// `useObjectLock.release()` already reads 404 as "already released;
		// idempotent" — before this, that branch was being fed a 404 from the
		// ROUTER, on a verb this app did not declare, so it was right by
		// accident and would have gone on being right if the lock had never
		// worked at all (nextcloud-vue#1202).
		//
		// It is not an error: nothing was refused and nothing threw. The status
		// carries the fact, the body names it, and `locked: false` is true
		// either way, so a client that only reads that keeps working.
		if ($released === false) {
			return new JSONResponse(
				data: [
					'message' => 'This object was not locked, so no lock was released.',
					'error' => 'not-locked',
					'locked' => false,
					'released' => false,
					'uuid' => $id,
				],
				statusCode: 404
			);
		}

		// Return response with locked status for test compatibility.
		return new JSONResponse(
			data: [
				'message' => 'Object unlocked successfully',
				'locked' => false,
				'released' => true,
				'uuid' => $id,
			]
		);
	}//end unlockResponse()

	/**
	 * Whether a row exists in other registers, and nothing about the row.
	 *
	 * 🔴 IT IS NOT A SEARCH WITH FIELDS REMOVED. The answer is assembled from
	 * named values, so a schema that grows a property grows nothing here. The
	 * question purpose limitation actually allows is "is this person already
	 * known elsewhere", and answering it by handing over rows and trusting the
	 * caller to discard them puts the decision in code the register's owner
	 * never sees.
	 *
	 * Authorisation is the read it replaces: every probe goes through the same
	 * RBAC the search does, so this can never answer about a register the
	 * caller could not have searched. A refused probe says REFUSED rather than
	 * "nothing exists" — reporting a refusal as an absence would itself be an
	 * answer the caller was not entitled to.
	 *
	 * @return JSONResponse The per-probe answers, or a 4xx.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @no-admin-idor-exempt the probe names no object id; the guard is in
	 *       CrossRegisterExistenceService::probe(), which sends every probe
	 *       through the search with `_rbac: true` and answers REFUSED rather
	 *       than "nothing exists" when the caller could not have searched that
	 *       register.
	 *
	 * @psalm-suppress PossiblyUnusedMethod
	 *
	 * @spec openspec/specs/cross-register-existence-query/spec.md#requirement-a-caller-can-ask-whether-a-row-exists-without-reading-it
	 */
	#[NoAdminRequired]
	public function exists(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			// Before anything is asked. An anonymous caller learning that a
			// register holds nothing about a person has still learned something.
			return new JSONResponse(data: ['error' => 'Not authenticated'], statusCode: 401);
		}

		$service = $this->container->get(\OCA\OpenRegister\Service\CrossRegisterExistenceService::class);
		$probes = $this->request->getParam('probes', []);
		$asked = [];
		if (is_array($probes) === true) {
			$asked = $probes;
		}

		$answer = $service->probe(probes: $asked);

		if (isset($answer['error']) === true) {
			return new JSONResponse(data: $answer, statusCode: 422);
		}

		return new JSONResponse(data: $answer);
	}//end exists()

	/**
	 * Move an object to another register and schema, keeping who it is.
	 *
	 * 🔴 NOT A COPY. Every side table — the audit trail, the versions, the
	 * files, the notes, the watchers, the favourites, the presence, the timers
	 * — is keyed on the uuid, and the uuid does not change. A copy would mint a
	 * second identity and orphan all of them silently, which is what "close it
	 * and refile it" does today and what this replaces.
	 *
	 * Authorised on BOTH SIDES: the object is read under the caller's own
	 * permissions, and {@see mayMove()} asks for update on it and create on the
	 * target schema before anything is written.
	 *
	 * @param string $register The source register.
	 * @param string $schema The source schema.
	 * @param string $id The object.
	 *
	 * @return JSONResponse The outcome, or a 4xx naming what stood in the way.
	 *
	 * @NoAdminRequired
	 *
	 * @psalm-suppress PossiblyUnusedMethod
	 *
	 * @spec openspec/changes/identity-survives-a-move/specs/objects-crud/spec.md#requirement-an-object-can-move-between-registers-and-schemas-without-changing-identity
	 */
	#[NoAdminRequired]
	public function move(string $register, string $schema, string $id): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(data: ['error' => 'Not authenticated'], statusCode: 401);
		}

		try {
			$object = $this->presenceObject(ref: new ObjectRef(register: $register, schema: $schema, id: $id));
		} catch (MultipleObjectsReturnedException $e) {
			return $this->ambiguousIdentifierResponse(id: $id);
		}

		if ($object === null) {
			return new JSONResponse(data: ['error' => 'Object not found'], statusCode: 404);
		}

		return $this->moveToRequestedTarget(object: $object, source: [$register, $schema]);
	}//end move()

	/**
	 * Move an object to the register and schema the request names.
	 *
	 * @param ObjectEntity $object The object.
	 * @param array        $source The register and schema it is in.
	 *
	 * @return JSONResponse The move's outcome, or the refusal.
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	private function moveToRequestedTarget(ObjectEntity $object, array $source): JSONResponse {
		$targetRegister = trim((string)$this->request->getParam('targetRegister', ''));
		$targetSchema = trim((string)$this->request->getParam('targetSchema', ''));
		if ($targetRegister === '' || $targetSchema === '') {
			return new JSONResponse(
				data: ['error' => 'Name the register and the schema this object is moving to.'],
				statusCode: 422
			);
		}

		$ends = $this->moveEnds(source: $source, target: [$targetRegister, $targetSchema]);
		if ($ends === null) {
			return new JSONResponse(data: ['error' => 'No such register or schema'], statusCode: 404);
		}

		if ($this->mayMove(object: $object, schemas: [$ends['from']['schema'], $ends['to']['schema']]) === false) {
			return new JSONResponse(data: ['error' => 'You may not move this object there.'], statusCode: 403);
		}

		$outcome = $this->container->get(\OCA\OpenRegister\Service\Object\MoveObjectInterface::class)->move(
			object: $object,
			sourceRegister: $ends['from']['register'],
			sourceSchema: $ends['from']['schema'],
			targetRegister: $ends['to']['register'],
			targetSchema: $ends['to']['schema'],
			actor: $this->userSession->getUser()->getUID(),
		);

		return self::moveResponse(outcome: $outcome);
	}//end moveToRequestedTarget()

	/**
	 * The registers and schemas a move goes from and to.
	 *
	 * @param array $source The source register and schema references.
	 * @param array $target The target register and schema references.
	 *
	 * @return array|null `from` and `to`, or null when one of them does not exist.
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	private function moveEnds(array $source, array $target): array|null {
		$registers = $this->services->store->registerMapper;
		$schemas = $this->services->store->schemaMapper;
		try {
			return [
				'from' => ['register' => $registers->find($source[0]), 'schema' => $schemas->find($source[1])],
				'to' => ['register' => $registers->find($target[0]), 'schema' => $schemas->find($target[1])],
			];
		} catch (\Throwable $e) {
			return null;
		}
	}//end moveEnds()

	/**
	 * The answer to a move that ran.
	 *
	 * @param array $outcome The move's outcome.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	private static function moveResponse(array $outcome): JSONResponse {
		if ($outcome['moved'] === false) {
			// 422, not 400: the request is well formed and the object does not
			// fit where it was asked to go, which is the caller's to act on.
			return new JSONResponse(data: $outcome, statusCode: 422);
		}

		return new JSONResponse(data: $outcome);
	}//end moveResponse()

	/**
	 * Whether the caller may take this object out of its schema and create it in the target.
	 *
	 * The rights a migration asks for: update on the object, create on the target schema.
	 *
	 * @param ObjectEntity                $object  The object.
	 * @param array{0: Schema, 1: Schema} $schemas The source and the target schema.
	 *
	 * @return bool True when both rights are held.
	 *
	 * @spec openspec/changes/identity-survives-a-move/specs/objects-crud/spec.md#requirement-an-object-can-move-between-registers-and-schemas-without-changing-identity
	 */
	private function mayMove(ObjectEntity $object, array $schemas): bool {
		$permissions = $this->container->get(\OCA\OpenRegister\Service\Object\PermissionHandlerInterface::class);

		return $permissions->hasPermission(schema: $schemas[0], action: 'update', objectOwner: $object->getOwner(), object: $object)
			&& $permissions->hasPermission(schema: $schemas[1], action: 'create');
	}//end mayMove()

	/**
	 * Note, and discard, a cause a request tried to name for itself.
	 *
	 * 🔴 A CLIENT THAT CAN CLAIM ITS WRITE WAS A MIGRATION CAN HIDE A WRITE. An
	 * administrator filtering out the noise of a bulk load would filter out
	 * exactly the entry somebody wanted buried. So a `cause` in a request is
	 * never stored, and the ATTEMPT is recorded, because a caller trying to
	 * label its own writes is itself worth knowing about.
	 *
	 * Called from the write doors. It changes nothing about the request: the
	 * key is already stripped from the payload by the `_`-prefix filter or
	 * ignored by validation, so this only notices.
	 *
	 * @param ObjectEntity $object The object this write is for; only its audit rows carry the mark.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/runs-recorded-and-causes-named/specs/enhanced-audit-trail/spec.md#requirement-every-audit-entry-names-the-cause-of-the-write-req-rcn-001
	 */
	private function noteClientSuppliedCause(ObjectEntity $object): void {
		if ($this->requestNamesItsCause() === true) {
			\OCA\OpenRegister\Service\WriteCause::noteClientAttempt(objectUuid: (string)$object->getUuid());
		}
	}//end noteClientSuppliedCause()

	/**
	 * The body of a create, marked when the request tried to name its own cause.
	 *
	 * The create door cannot note the attempt itself: the object has no uuid
	 * yet, and the objects nested in it are saved and audited first. The mark
	 * rides on this object's own `@self`, and the save takes it off and notes
	 * it for the uuid it wrote. See {@see WriteCause::CLIENT_ATTEMPT_KEY}.
	 *
	 * @param array<string, mixed> $object The filtered request body.
	 *
	 * @return array<string, mixed> The body to save.
	 *
	 * @spec openspec/changes/runs-recorded-and-causes-named/specs/enhanced-audit-trail/spec.md#requirement-every-audit-entry-names-the-cause-of-the-write-req-rcn-001
	 */
	private function withClientCauseAttempt(array $object): array {
		if ($this->requestNamesItsCause() === false) {
			return $object;
		}

		$self = ($object['@self'] ?? []);
		if (is_array($self) === false) {
			$self = [];
		}

		$object['@self'] = array_merge($self, [\OCA\OpenRegister\Service\WriteCause::CLIENT_ATTEMPT_KEY => true]);

		return $object;
	}//end withClientCauseAttempt()

	/**
	 * Whether this request tried to name its own cause. Logs the attempt.
	 *
	 * @return boolean True when a cause key is in the request.
	 *
	 * @spec openspec/changes/runs-recorded-and-causes-named/specs/enhanced-audit-trail/spec.md#requirement-every-audit-entry-names-the-cause-of-the-write-req-rcn-001
	 */
	private function requestNamesItsCause(): bool {
		foreach (['cause', '_cause', 'causeRun', '_causeRun'] as $key) {
			if ($this->request->getParam($key) !== null) {
				$this->logger->warning(
					message: '[ObjectsController] a request supplied its own audit cause; it was ignored',
					context: ['file' => __FILE__, 'line' => __LINE__, 'key' => $key]
				);

				return true;
			}
		}

		return false;
	}//end requestNamesItsCause()

	/**
	 * Say that the caller still has this object open.
	 *
	 * 🔴 A HEARTBEAT, NOT A CONNECTION (D-1). notify_push tells the server
	 * nothing about who is looking at what, so the client says so every 30
	 * seconds and the server stops believing it after 90. Missing two beats
	 * reads as gone, which survives a lost socket where connection tracking
	 * does not.
	 *
	 * Reads the object first, so presence is under the object's own RBAC: a
	 * caller who cannot read it cannot appear on it, and cannot learn from the
	 * answer that it exists.
	 *
	 * @param string $register The register slug or identifier.
	 * @param string $schema The schema slug or identifier.
	 * @param string $id The object.
	 *
	 * @return JSONResponse Who else is present.
	 *
	 * @NoAdminRequired
	 *
	 * @psalm-suppress PossiblyUnusedMethod
	 *
	 * @spec openspec/changes/object-presence/specs/realtime-updates/spec.md#requirement-an-object-knows-who-has-it-open
	 */
	#[NoAdminRequired]
	public function presenceBeat(string $register, string $schema, string $id): JSONResponse {
		$caller = $this->presenceCaller();
		if ($caller === null) {
			return new JSONResponse(data: ['error' => 'Not authenticated'], statusCode: 401);
		}

		try {
			$object = $this->presenceObject(ref: new ObjectRef(register: $register, schema: $schema, id: $id));
		} catch (MultipleObjectsReturnedException $e) {
			return $this->ambiguousIdentifierResponse(id: $id);
		}

		if ($object === null) {
			return new JSONResponse(data: ['error' => 'Object not found'], statusCode: 404);
		}

		$service = $this->container->get(\OCA\OpenRegister\Notifications\Service\PresenceServiceInterface::class);
		$beat = $service->heartbeat(beat: new PresenceBeat(userId: $caller, objectUuid: $id, now: new DateTimeImmutable()));
		$present = $service->present(lookup: new PresenceLookup(objectUuid: $id, exceptUser: $caller));

		// ONLY on an arrival. A renewal that changed nothing is silent, which
		// is the whole of D-2 and the reason `heartbeat()` reports which it was
		// rather than leaving the caller to work it out.
		if ($beat['arrived'] === true) {
			$this->container->get(\OCA\OpenRegister\Listener\PresencePushInterface::class)
				->pushPresence(object: $object, present: $service->present(lookup: new PresenceLookup(objectUuid: $id)));
		}

		return new JSONResponse(
			data: ['present' => $present, 'beatSeconds' => \OCA\OpenRegister\Notifications\Service\PresenceServiceInterface::BEAT_SECONDS]
		);
	}//end presenceBeat()

	/**
	 * Say that the caller has closed this object.
	 *
	 * @param string $register The register slug or identifier.
	 * @param string $schema The schema slug or identifier.
	 * @param string $id The object.
	 *
	 * @return JSONResponse Who is left.
	 *
	 * @NoAdminRequired
	 *
	 * @psalm-suppress PossiblyUnusedMethod
	 *
	 * @spec openspec/changes/object-presence/specs/realtime-updates/spec.md#requirement-an-object-knows-who-has-it-open
	 */
	#[NoAdminRequired]
	public function presenceDepart(string $register, string $schema, string $id): JSONResponse {
		$caller = $this->presenceCaller();
		if ($caller === null) {
			return new JSONResponse(data: ['error' => 'Not authenticated'], statusCode: 401);
		}

		$service = $this->container->get(\OCA\OpenRegister\Notifications\Service\PresenceServiceInterface::class);
		$left = $service->depart(userId: $caller, objectUuid: $id);

		// A departure by somebody who was not there pushes nothing: there is no
		// change to tell anybody about, and a page unmounting twice is ordinary.
		if ($left === true) {
			// The departure above is already done and keyed by the identifier as sent, so an
			// ambiguous identifier only means there is no object to push to: the answer stays 200.
			try {
				$object = $this->presenceObject(ref: new ObjectRef(register: $register, schema: $schema, id: $id));
			} catch (MultipleObjectsReturnedException $e) {
				$object = null;
			}

			if ($object !== null) {
				$this->container->get(\OCA\OpenRegister\Listener\PresencePushInterface::class)
					->pushPresence(object: $object, present: $service->present(lookup: new PresenceLookup(objectUuid: $id)));
			}
		}

		return new JSONResponse(data: ['present' => $service->present(lookup: new PresenceLookup(objectUuid: $id, exceptUser: $caller))]);
	}//end presenceDepart()

	/**
	 * Say that the caller has closed this object, from a closing tab.
	 *
	 * A closing tab can only send a beacon, and a beacon is always a POST, so
	 * it cannot reach the DELETE route above. The client marks the request with
	 * `_method=DELETE` and this route honours exactly that and nothing else:
	 * a POST without the marker is refused, so a stray POST never makes a
	 * reader vanish from everybody else's screen. CSRF stays on; the client
	 * sends the request token in the beacon's form body.
	 *
	 * @param string $register The register slug or identifier.
	 * @param string $schema The schema slug or identifier.
	 * @param string $id The object.
	 *
	 * @return JSONResponse Who is left, or 400 without the DELETE marker.
	 *
	 * @NoAdminRequired
	 *
	 * @psalm-suppress PossiblyUnusedMethod
	 *
	 * @spec openspec/specs/realtime-updates/spec.md#requirement-a-closing-tab-can-say-it-left
	 */
	#[NoAdminRequired]
	public function presenceDepartByBeacon(string $register, string $schema, string $id): JSONResponse {
		$override = $this->request->getParam('_method');
		if (is_string($override) === false || strtoupper($override) !== 'DELETE') {
			return new JSONResponse(data: ['error' => 'POST on presence only departs, with _method=DELETE'], statusCode: 400);
		}

		return $this->presenceDepart(register: $register, schema: $schema, id: $id);
	}//end presenceDepartByBeacon()

	/**
	 * Who has this object open.
	 *
	 * @param string $register The register slug or identifier.
	 * @param string $schema The schema slug or identifier.
	 * @param string $id The object.
	 *
	 * @return JSONResponse The present readers.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @psalm-suppress PossiblyUnusedMethod
	 *
	 * @spec openspec/changes/object-presence/specs/realtime-updates/spec.md#requirement-an-object-knows-who-has-it-open
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function presenceList(string $register, string $schema, string $id): JSONResponse {
		$caller = $this->presenceCaller();
		if ($caller === null) {
			return new JSONResponse(data: ['error' => 'Not authenticated'], statusCode: 401);
		}

		try {
			$object = $this->presenceObject(ref: new ObjectRef(register: $register, schema: $schema, id: $id));
		} catch (MultipleObjectsReturnedException $e) {
			return $this->ambiguousIdentifierResponse(id: $id);
		}

		if ($object === null) {
			return new JSONResponse(data: ['error' => 'Object not found'], statusCode: 404);
		}

		return new JSONResponse(
			data: [
				'present' => $this->container->get(\OCA\OpenRegister\Notifications\Service\PresenceServiceInterface::class)
					->present(lookup: new PresenceLookup(objectUuid: $id, exceptUser: $caller)),
			]
		);
	}//end presenceList()

	/**
	 * The signed-in caller's uid, or null.
	 *
	 * @return string|null The uid.
	 */
	private function presenceCaller(): ?string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return null;
		}

		return $user->getUID();
	}//end presenceCaller()

	/**
	 * Read the object under the caller's own RBAC, or null.
	 *
	 * 🔴 THIS IS THE AUTHORISATION, AND IT IS A REAL READ. Presence is served to
	 * whoever may read the object (D-3), so the check is performing that read
	 * rather than asking a second question that could answer differently. A
	 * caller who cannot read the object gets 404 and learns nothing, including
	 * whether it exists.
	 *
	 * @param ObjectRef $ref The register, schema and object.
	 *
	 * @return ObjectEntity|null The object, or null when it cannot be read.
	 *
	 * @throws MultipleObjectsReturnedException When the identifier matches more than one object.
	 *
	 * @spec openspec/specs/object-interactions/spec.md#requirement-only-a-person-opening-an-object-counts-as-recently-opened
	 */
	private function presenceObject(ObjectRef $ref): ?ObjectEntity {
		try {
			$this->objectService->setRegister(register: $ref->register);
			$this->objectService->setSchema(schema: $ref->schema);
			$found = WriteCause::asLookup(fn () => $this->objectService->find($ref->id));
		} catch (MultipleObjectsReturnedException $e) {
			// Not "cannot be read": the caller can act on it, by asking with the uuid.
			throw $e;
		} catch (\Throwable $e) {
			return null;
		}

		if ($found instanceof ObjectEntity) {
			return $found;
		}

		return null;
	}//end presenceObject()

	/**
	 * Export objects to specified format
	 *
	 * @param string $register The register slug or identifier
	 * @param string $schema The schema slug or identifier
	 * @param ObjectOperationsInterface $objectService The object service
	 *
	 * @return DataDownloadResponse|JSONResponse The exported file as a download response, or a
	 *                                           400 JSON error when a `format=pdf` request exceeds
	 *                                           {@see \OCA\OpenRegister\Service\ExportService::MAX_PDF_EXPORT_ROWS}.
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @psalm-return DataDownloadResponse<200,
	 *     'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'|'text/csv'|'application/pdf',
	 *     array<never, never>>|JSONResponse
	 *
	 * @psalm-suppress NoValue
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 * @spec openspec/specs/export-pdf-format/spec.md
	 */
	public function export(string $register, string $schema, ObjectOperationsInterface $objectService): DataDownloadResponse|JSONResponse {
		// Set the register and schema context.
		$objectService->setRegister(register: $register);
		$objectService->setSchema(schema: $schema);

		// Get filters and type from request.
		$filters = $this->exportFilters();
		$type = $this->exportFormat();

		// Get register and schema entities.
		//
		// Reuse what setRegister()/setSchema() already resolved instead of
		// re-resolving. The re-resolution was GLOBAL — `$this->services->store->schemaMapper->find()`
		// matches `LOWER(slug)` across the whole instance — so on an instance where
		// two apps share a schema slug the export was named after ANOTHER app's
		// schema while its rows came from this register's. A filename is exactly
		// where that goes unnoticed: the file downloads, opens, and lies about its
		// own provenance.
		$registerEntity = $objectService->getCurrentRegisterEntity();
		$schemaEntity = $objectService->getCurrentSchemaEntity();
		if ($registerEntity === null || $schemaEntity === null) {
			// Unreachable in practice — setRegister()/setSchema() above either
			// resolve or throw. Refusing here rather than re-resolving keeps the
			// register a boundary: a fallback lookup would be a second, unscoped
			// chance to find *a* schema with this slug, which is the whole defect.
			return new JSONResponse(data: ['error' => 'Register or schema not found'], statusCode: 404);
		}

		// THE EXPORT VERB, before a single row is read. Hiding the menu item is
		// not a control: this endpoint is what an integration calls, so this is
		// where the right has to hold (design D-1). The refusal names the verb
		// rather than the register, because "forbidden" leaves an operator
		// guessing which of the two grants they are missing.
		$refusal = $this->exportRefusalFor(schema: $schemaEntity, register: $registerEntity);
		if ($refusal !== null) {
			return $refusal;
		}

		$export = new ObjectExport(
			register: $registerEntity,
			schema: $schemaEntity,
			filters: $filters,
			filenameBase: self::exportFilenameBase(register: $registerEntity, schema: $schemaEntity)
		);

		// Call ExportService directly (bypassing ObjectService which has circular dependency issues).
		return match ($type) {
			'csv' => $this->csvExport(export: $export),
			'json' => $this->jsonExport(export: $export),
			'pdf' => $this->pdfExport(export: $export),
			default => $this->excelExport(export: $export),
		};
	}//end export()

	/**
	 * The request parameters an export filters on.
	 *
	 * @return array The filters.
	 *
	 * @spec openspec/specs/export-pdf-format/spec.md
	 */
	private function exportFilters(): array {
		$filters = $this->request->getParams();
		unset($filters['_route']);

		return $filters;
	}//end exportFilters()

	/**
	 * The format an export asks for: `format`, else `type`, else excel.
	 *
	 * @return mixed The format.
	 *
	 * @spec openspec/specs/export-pdf-format/spec.md
	 */
	private function exportFormat(): mixed {
		return $this->request->getParam(key: 'format') ?? $this->request->getParam(key: 'type', default: 'excel');
	}//end exportFormat()

	/**
	 * The file name of an export, without extension.
	 *
	 * @param Register $register The register being exported.
	 * @param Schema   $schema   The schema being exported.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/specs/export-pdf-format/spec.md
	 */
	private static function exportFilenameBase(Register $register, Schema $schema): string {
		return sprintf(
			'%s_%s_%s',
			$register->getSlug() ?? 'register',
			$schema->getSlug() ?? 'schema',
			(new DateTime())->format('Y-m-d_His')
		);
	}//end exportFilenameBase()

	/**
	 * Export as CSV.
	 *
	 * @param ObjectExport $export The export.
	 *
	 * @return DataDownloadResponse The file.
	 *
	 * @spec openspec/specs/export-pdf-format/spec.md
	 */
	private function csvExport(ObjectExport $export): DataDownloadResponse {
		$content = $this->services->transfer->exportService->exportToCsv(
			request: new ObjectExportRequest(
				register: $export->register,
				schema: $export->schema,
				filters: $export->filters,
				currentUser: $this->userSession->getUser(),
			),
		);

		$this->recordExportCompleted(export: $export, served: new ServedExport(format: 'csv', rowCount: $this->countCsvExportRows(csv: $content)));

		return $export->download(format: 'csv', content: $content);
	}//end csvExport()

	/**
	 * Export as JSON.
	 *
	 * @param ObjectExport $export The export.
	 *
	 * @return DataDownloadResponse The file.
	 *
	 * @spec openspec/specs/export-pdf-format/spec.md
	 */
	private function jsonExport(ObjectExport $export): DataDownloadResponse {
		$content = $this->services->transfer->exportService->exportToJson(
			request: new ObjectExportRequest(register: $export->register, schema: $export->schema, filters: $export->filters),
		);

		$this->recordExportCompleted(export: $export, served: new ServedExport(format: 'json', rowCount: $this->countJsonExportRows(json: $content)));

		return $export->download(format: 'json', content: $content);
	}//end jsonExport()

	/**
	 * Export as PDF; an export over the row limit or without an office server is refused.
	 *
	 * @param ObjectExport $export The export.
	 *
	 * @return DataDownloadResponse|JSONResponse The file, or the refusal.
	 *
	 * @spec openspec/specs/export-pdf-format/spec.md
	 */
	private function pdfExport(ObjectExport $export): DataDownloadResponse|JSONResponse {
		try {
			$content = $this->services->transfer->exportService->exportToPdf(
				request: new ObjectExportRequest(
					register: $export->register,
					schema: $export->schema,
					filters: $export->filters,
					currentUser: $this->userSession->getUser(),
				),
			);
		} catch (ExportTooLargeException $e) {
			return self::exportTooLargeResponse(exception: $e);
		} catch (OfficeServerRequiredException $e) {
			return new JSONResponse(data: $e->toResponseData(), statusCode: OfficeServerRequiredException::HTTP_STATUS);
		}

		$this->recordPdfExport(export: $export);

		return $export->download(format: 'pdf', content: $content);
	}//end pdfExport()

	/**
	 * Record a finished PDF export, with the row count the export service answers.
	 *
	 * @param ObjectExport $export The export.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/export-pdf-format/spec.md
	 */
	private function recordPdfExport(ObjectExport $export): void {
		$rowCount = $this->services->transfer->exportService->countExportRows(
			request: new ObjectExportRequest(register: $export->register, schema: $export->schema, filters: $export->filters),
		);
		$this->recordExportCompleted(export: $export, served: new ServedExport(format: 'pdf', rowCount: $rowCount));
	}//end recordPdfExport()

	/**
	 * The answer to a PDF export over the row limit.
	 *
	 * @param ExportTooLargeException $exception The refusal.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/export-pdf-format/spec.md
	 */
	private static function exportTooLargeResponse(ExportTooLargeException $exception): JSONResponse {
		return new JSONResponse(
			data: [
				'error' => 'export_too_large',
				'message' => $exception->getMessage(),
				'rowCount' => $exception->getRowCount(),
				'maxRows' => $exception->getMaxRows(),
			],
			statusCode: ExportTooLargeException::HTTP_STATUS
		);
	}//end exportTooLargeResponse()

	/**
	 * Export as Excel.
	 *
	 * @param ObjectExport $export The export.
	 *
	 * @return DataDownloadResponse The file.
	 *
	 * @spec openspec/specs/export-pdf-format/spec.md
	 */
	private function excelExport(ObjectExport $export): DataDownloadResponse {
		$spreadsheet = $this->services->transfer->exportService->exportToExcel(
			request: new ObjectExportRequest(
				register: $export->register,
				schema: $export->schema,
				filters: $export->filters,
				currentUser: $this->userSession->getUser(),
			),
		);

		// Create Excel writer and get content.
		$writer = new Xlsx($spreadsheet);
		ob_start();
		$writer->save('php://output');
		$content = ob_get_clean();

		$rowCount = $this->countSpreadsheetExportRows(spreadsheet: $spreadsheet);
		$this->recordExportCompleted(export: $export, served: new ServedExport(format: 'excel', rowCount: $rowCount));

		return $export->download(format: 'excel', content: $content);
	}//end excelExport()

	/**
	 * The refusal for this caller's export, or null when they may take it.
	 *
	 * Resolved from the container rather than injected, because a nullable
	 * constructor dependency would make the control skippable: a caller that
	 * constructs this controller without the service would get an export with
	 * no verb check and no sign that one was missing. A service that cannot be
	 * resolved refuses, for the same reason an unreadable rule refuses.
	 *
	 * @param Schema $schema The schema being exported.
	 * @param Register $register The register being exported.
	 *
	 * @return JSONResponse|null The refusal to return, or null when the export may run.
	 *
	 * @spec openspec/specs/authorization-rbac/spec.md
	 */
	private function exportRefusalFor(Schema $schema, Register $register): ?JSONResponse {
		$rightService = null;
		try {
			$rightService = $this->container->get(ExportRightService::class);
		} catch (\Throwable $e) {
			$this->logger->error(
				message: '[ObjectsController] Export right service unresolvable, refusing the export',
				context: ['error' => $e->getMessage()]
			);
		}

		// A container that answers with something other than the service is the
		// same situation as one that throws, and it must end the same way. The
		// alternative is an export that runs with no verb check and nothing to
		// say one was missing, which is the hole this whole change closes.
		if (($rightService instanceof ExportRightService) === false) {
			return new JSONResponse(
				data: [
					'error' => 'EXPORT_REFUSED',
					'verb' => ExportRightService::ACTION,
					'rule' => 'right-service-unavailable',
					'message' => 'The export right cannot be evaluated right now, so nothing was exported.',
				],
				statusCode: 503
			);
		}

		$refusal = $rightService->refusalFor(schema: $schema);
		if ($refusal === null) {
			return null;
		}

		$this->recordExportRefusal(refusal: $refusal, table: new RegisterSchema(register: $register, schema: $schema));

		return new JSONResponse(data: $refusal->toResponseBody(), statusCode: $refusal->getStatusCode());
	}//end exportRefusalFor()

	/**
	 * Record a refused export on the audit trail.
	 *
	 * @param \OCA\OpenRegister\Service\Export\ExportRefusedException $refusal The refusal.
	 * @param RegisterSchema                                         $table   The register and schema that were asked for.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function recordExportRefusal(\OCA\OpenRegister\Service\Export\ExportRefusedException $refusal, RegisterSchema $table): void {
		$recorder = $this->exportAuditRecorder();
		if ($recorder === null) {
			return;
		}

		$recorder->recordRefused(
			profile: 'ad-hoc',
			rule: $refusal->getRule(),
			reason: $refusal->getMessage(),
			register: $table->register->getId(),
			schema: $table->schema->getId()
		);
	}//end recordExportRefusal()

	/**
	 * Record a completed export on the audit trail.
	 *
	 * @param ObjectExport $export The export, for its register and schema.
	 * @param ServedExport $served The format written and how many rows left the instance.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function recordExportCompleted(ObjectExport $export, ServedExport $served): void {
		$recorder = $this->exportAuditRecorder();
		if ($recorder === null) {
			return;
		}

		$recorder->recordCompleted(
			profile: 'ad-hoc',
			rowCount: $served->rowCount,
			format: $served->format,
			valueMode: null,
			register: $export->register->getId(),
			schema: $export->schema->getId()
		);

		$this->recordExportRun(export: $export, served: $served);
	}//end recordExportCompleted()

	/**
	 * Put an ad-hoc API export in the export area as a run.
	 *
	 * The bytes went straight to the caller, so the run keeps no file and has
	 * no expiry, and its download count is one: the register served it once,
	 * in this response. A recorder that cannot be resolved or fails costs the
	 * row, never the export.
	 *
	 * @param ObjectExport $export The export, for its register and schema.
	 * @param ServedExport $served The format and the rows served.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/an-export-is-a-file-with-a-life/specs/data-import-export/spec.md
	 */
	private function recordExportRun(ObjectExport $export, ServedExport $served): void {
		try {
			$runs = $this->container->get(ExportRunRecorder::class);
			if ($runs instanceof ExportRunRecorder === false) {
				return;
			}

			$runs->record(
				origin: new ExportRunOrigin(
					source: 'api',
					actor: (string)($this->userSession->getUser()?->getUID() ?? ''),
					profile: 'ad-hoc'
				),
				content: new ExportRunContent(
					format: $served->format,
					rowCount: $served->rowCount,
					registerName: (string)($export->register->getSlug() ?? $export->register->getId()),
					schemaName: (string)($export->schema->getSlug() ?? $export->schema->getId())
				),
				file: new ExportRunFile(
					downloadCount: 1
				),
				retentionSeconds: null
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: '[ObjectsController] Could not record the export run; the export itself was served',
				context: ['error' => $e->getMessage()]
			);
		}//end try
	}//end recordExportRun()

	/**
	 * The audit recorder, or null when it cannot be resolved.
	 *
	 * Unlike the right service this one may be absent without refusing the
	 * export: see ExportAuditRecorder's own note on why a ledger hiccup must
	 * not fail a monthly aanlevering.
	 *
	 * @return ExportAuditRecorderInterface|null The recorder.
	 */
	private function exportAuditRecorder(): ?ExportAuditRecorderInterface {
		try {
			return $this->container->get(ExportAuditRecorderInterface::class);
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: '[ObjectsController] Export audit recorder unresolvable, the export is not on the trail',
				context: ['error' => $e->getMessage()]
			);

			return null;
		}
	}//end exportAuditRecorder()

	/**
	 * Count the data rows in exported CSV bytes.
	 *
	 * Counted off the artefact that was actually produced rather than off a
	 * second query, so the number on the trail is the number in the file.
	 *
	 * @param string $csv The CSV content.
	 *
	 * @return int The row count, header excluded.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function countCsvExportRows(string $csv): int {
		$lines = array_filter(explode("\n", $csv), static fn ($line) => trim($line) !== '');

		return max(0, (count($lines) - 1));
	}//end countCsvExportRows()

	/**
	 * Count the objects in exported JSON bytes.
	 *
	 * @param string $json The JSON content.
	 *
	 * @return int The row count, or 0 when the payload is not a list.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function countJsonExportRows(string $json): int {
		$decoded = json_decode($json, true);
		if (is_array($decoded) === false) {
			return 0;
		}

		if (isset($decoded['results']) === true && is_array($decoded['results']) === true) {
			return count($decoded['results']);
		}

		return count($decoded);
	}//end countJsonExportRows()

	/**
	 * Sum the data rows across every sheet of an exported spreadsheet.
	 *
	 * @param \PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet The spreadsheet.
	 *
	 * @return int The row count, each sheet header excluded.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function countSpreadsheetExportRows(\PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet): int {
		$rows = 0;
		foreach ($spreadsheet->getAllSheets() as $sheet) {
			$rows += max(0, ($sheet->getHighestRow() - 1));
		}

		return $rows;
	}//end countSpreadsheetExportRows()

	/**
	 * Merge two objects
	 *
	 * This method merges object A into object B within the same register and schema.
	 * It handles merging of properties, files, and relations based on user preferences.
	 *
	 * @param string $id The ID of object A (source object to merge from)
	 * @param string $register The register slug or identifier
	 * @param string $schema The schema slug or identifier
	 * @param ObjectOperationsInterface $objectService The object service
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with merge result or error
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	public function merge(
		string $id,
		string $register,
		string $schema,
		ObjectOperationsInterface $objectService,
	): JSONResponse {
		// Set the schema and register to the object service.
		$objectService->setRegister($register);
		$objectService->setSchema($schema);

		// Merging large objects with many references can take a long time.
		set_time_limit(0);

		try {
			// Get merge data from request body.
			$requestParams = $this->request->getParams();

			// Validate required parameters.
			$refusal = self::mergeRefusal(params: $requestParams);
			if ($refusal !== null) {
				return new JSONResponse(data: ['error' => $refusal], statusCode: 400);
			}

			// The merge reads and writes both objects without RBAC, so the
			// caller's own rights on them are checked before it runs.
			$denied = $this->mergeAccessRefusal(
				source: new ObjectRef(register: $register, schema: $schema, id: $id),
				targetId: (string)$requestParams['target']
			);
			if ($denied !== null) {
				return $denied;
			}

			// Perform the merge operation with the new payload structure.
			$mergeResult = $objectService->mergeObjects(sourceObjectId: $id, mergeData: $requestParams);
			return new JSONResponse(data: $mergeResult);
		} catch (DoesNotExistException $exception) {
			return new JSONResponse(data: ['error' => 'Object not found'], statusCode: 404);
		} catch (\InvalidArgumentException $exception) {
			return new JSONResponse(data: ['error' => $exception->getMessage()], statusCode: 400);
		} catch (\Exception $exception) {
			return new JSONResponse(
				data: [
					'error' => 'Internal server error',
				],
				statusCode: 500
			);
		}//end try
	}//end merge()

	/**
	 * Why the caller may not merge these two objects, or null when they may.
	 *
	 * Both objects must be readable to the caller (otherwise they do not exist
	 * for them: 404), the target must be theirs to update and the source theirs
	 * to delete, because a merge overwrites the one and removes the other.
	 *
	 * @param ObjectRef $source   The register, schema and object merged away.
	 * @param string    $targetId The object merged into, in the same register and schema.
	 *
	 * @return JSONResponse|null The refusal, or null when the merge may run.
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	private function mergeAccessRefusal(ObjectRef $source, string $targetId): ?JSONResponse {
		$sourceObject = $this->presenceObject(ref: $source);
		$target = $this->presenceObject(ref: new ObjectRef(register: $source->register, schema: $source->schema, id: $targetId));
		if ($sourceObject === null || $target === null) {
			return new JSONResponse(data: ['error' => 'Object not found'], statusCode: 404);
		}

		$schemaEntity = $this->services->store->schemaMapper->find($source->schema);
		$permissions = $this->container->get(\OCA\OpenRegister\Service\Object\PermissionHandlerInterface::class);
		$mayUpdateTarget = $permissions->hasPermission(schema: $schemaEntity, action: 'update', objectOwner: $target->getOwner(), object: $target);
		$mayDeleteSource = $permissions->hasPermission(
			schema: $schemaEntity,
			action: 'delete',
			objectOwner: $sourceObject->getOwner(),
			object: $sourceObject
		);
		if ($mayUpdateTarget === false || $mayDeleteSource === false) {
			return new JSONResponse(data: ['error' => 'You may not merge these objects.'], statusCode: 403);
		}

		return null;
	}//end mergeAccessRefusal()

	/**
	 * What a merge request is missing.
	 *
	 * @param array $params The request parameters.
	 *
	 * @return string|null The refusal, or null when the request is complete.
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	private static function mergeRefusal(array $params): string|null {
		if (isset($params['target']) === false) {
			return 'Target object ID is required';
		}

		if (empty($params['object']) === true) {
			return 'Object data is required';
		}

		return null;
	}//end mergeRefusal()

	/**
	 * Migrate objects between registers and/or schemas
	 *
	 * This method migrates multiple objects from one register/schema combination
	 * to another register/schema combination with property mapping.
	 *
	 * @param ObjectOperationsInterface $objectService The object service
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with migration result or error
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	public function migrate(ObjectOperationsInterface $objectService): JSONResponse {
		try {
			return $this->runMigration(params: $this->request->getParams(), objectService: $objectService);
		} catch (NotAuthorizedException $exception) {
			return new JSONResponse(data: ['error' => $exception->getMessage()], statusCode: 403);
		} catch (DoesNotExistException $exception) {
			return new JSONResponse(data: ['error' => 'Register or schema not found'], statusCode: 404);
		} catch (\InvalidArgumentException $exception) {
			return new JSONResponse(data: ['error' => $exception->getMessage()], statusCode: 400);
		} catch (\Exception $exception) {
			return new JSONResponse(
				data: [
					'error' => 'Internal server error',
				],
				statusCode: 500
			);
		}//end try
	}//end migrate()

	/**
	 * Run the migration a request asks for, when it is complete.
	 *
	 * @param array                     $params        The request parameters.
	 * @param ObjectOperationsInterface $objectService The object service.
	 *
	 * @return JSONResponse The result, or the refusal.
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	private function runMigration(array $params, ObjectOperationsInterface $objectService): JSONResponse {
		$refusal = self::migrationRefusal(params: $params);
		if ($refusal !== null) {
			return new JSONResponse(data: ['error' => $refusal], statusCode: 400);
		}

		$migrationResult = $objectService->migrateObjects(
			sourceRegister: $params['sourceRegister'],
			sourceSchema: $params['sourceSchema'],
			targetRegister: $params['targetRegister'],
			targetSchema: $params['targetSchema'],
			objectIds: $params['objects'],
			mapping: $params['mapping']
		);

		return new JSONResponse(data: $migrationResult);
	}//end runMigration()

	/**
	 * What a migration request is missing.
	 *
	 * @param array $params The request parameters.
	 *
	 * @return string|null The refusal, or null when the request is complete.
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	private static function migrationRefusal(array $params): string|null {
		if (isset($params['sourceRegister'], $params['sourceSchema']) === false) {
			return 'Source register and schema are required';
		}

		if (isset($params['targetRegister'], $params['targetSchema']) === false) {
			return 'Target register and schema are required';
		}

		if (empty($params['objects']) === true) {
			return 'At least one object ID is required';
		}

		if (empty($params['mapping']) === true) {
			return 'Property mapping is required';
		}

		return null;
	}//end migrationRefusal()

	/**
	 * Download all files of an object as a ZIP archive
	 *
	 * This method creates a ZIP file containing all files associated with a specific object
	 * and returns it as a downloadable file. The ZIP file includes all files stored in the
	 * object's folder with their original names.
	 *
	 * @param string $id The identifier of the object to download files for
	 * @param string $register The register (identifier or slug) to search within
	 * @param string $schema The schema (identifier or slug) to search within
	 * @param ObjectOperationsInterface $objectService The object service for handling object operations
	 *
	 * @return DataDownloadResponse|JSONResponse Download response or error.
	 *
	 * @throws ContainerExceptionInterface If there's an issue with dependency injection.
	 * @throws NotFoundExceptionInterface If the FileService dependency is not found.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	public function downloadFiles(
		string $id,
		string $register,
		string $schema,
		ObjectOperationsInterface $objectService,
	): JSONResponse|DataDownloadResponse {
		try {
			// Set the context for the object service.
			$objectService->setRegister(register: $register);
			$objectService->setSchema(schema: $schema);

			// Get the object to ensure it exists and we have access.
			$object = WriteCause::asLookup(fn () => $objectService->find(id: $id));

			/*
			 * Get the FileService from the container.
			 * @var FileServiceInterface $fileService
			 */

			$fileService = $this->container->get(FileServiceInterface::class);

			// Optional: get custom filename from query parameters.
			$customFilename = $this->request->getParam(key: 'filename');

			// Create the ZIP archive.
			$zipInfo = $fileService->createObjectFilesZip(object: $object, zipName: $customFilename);

			$zipContent = self::takeZipContent(path: $zipInfo['path']);

			$this->auditBulkDownload(object: $object, zipInfo: $zipInfo);

			// Return the ZIP file as a download response.
			return new DataDownloadResponse(
				$zipContent,
				$zipInfo['filename'],
				$zipInfo['mimeType']
			);
		} catch (DoesNotExistException $exception) {
			return new JSONResponse(data: ['error' => 'Object not found'], statusCode: 404);
		} catch (MultipleObjectsReturnedException $exception) {
			return $this->ambiguousIdentifierResponse(id: $id);
		} catch (\Exception $exception) {
			return new JSONResponse(
				data: [
					'error' => 'Internal server error',
				],
				statusCode: 500
			);
		}//end try
	}//end downloadFiles()

	/**
	 * Read a temporary ZIP file and remove it.
	 *
	 * @param string $path The file path.
	 *
	 * @return string The content.
	 *
	 * @throws RuntimeException When the file cannot be read.
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	private static function takeZipContent(string $path): string {
		$zipContent = file_get_contents($path);
		if (file_exists($path) === true) {
			// NOSEMGREP: php.lang.security.unlink-use.unlink-use -- The path is the temporary zip this endpoint built itself.
			unlink($path);
		}

		if ($zipContent === false) {
			throw new RuntimeException('Failed to read ZIP file content');
		}

		return $zipContent;
	}//end takeZipContent()

	/**
	 * Audit a bulk download as ONE entry tied to the parent object.
	 *
	 * Best-effort: an audit-trail failure must not break the download.
	 *
	 * @param ObjectEntity $object  The object whose files were downloaded.
	 * @param array        $zipInfo The ZIP file's name and size.
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	private function auditBulkDownload(ObjectEntity $object, array $zipInfo): void {
		try {
			$fileService = $this->container->get(FileServiceInterface::class);
			$files = $fileService->getFiles($object);
			$fileService->getAuditHandler()->logBulkDownload(
				object: $object,
				archive: new BulkDownloadArchive(
					fileIds: array_map(static fn ($file) => $file->getId(), $files),
					fileNames: array_map(static fn ($file) => $file->getName(), $files),
					zipName: $zipInfo['filename'],
					totalBytes: $zipInfo['size'] ?? null
				)
			);
		} catch (\Throwable $auditError) {
			// The audit trail must never break the download response.
			$this->logger->warning(
				message: '[ObjectsController] Bulk download audit entry not written',
				context: [
					'zipName' => $zipInfo['filename'],
					'exception' => $auditError->getMessage(),
				]
			);
		}
	}//end auditBulkDownload()

	/**
	 * Start batch vectorization of objects
	 *
	 * @return JSONResponse JSON response with batch vectorization results
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @psalm-suppress NoValue
	 *
	 * @psalm-return JSONResponse<200|500, array{success: bool, error?: string, data?: mixed}, array<never, never>>
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	public function vectorizeBatch(): JSONResponse {
		try {
			$data = $this->request->getParams();
			$views = $data['views'] ?? null;
			$batchSize = (int)($data['batchSize'] ?? 25);

			// Use ObjectService delegation to handler.
			$result = $this->objectService->vectorizeBatchObjects(_views: $views, _batchSize: $batchSize);

			return new JSONResponse(data: ['success' => true, 'data' => $result]);
		} catch (\Throwable $e) {
			return $this->vectorizationFailureResponse(failure: $e);
		}
	}//end vectorizeBatch()

	/**
	 * Get object vectorization statistics
	 *
	 * @return JSONResponse Vectorization statistics
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @psalm-suppress NoValue
	 *
	 * @psalm-return JSONResponse<200|500, array{success: bool, error?: string, stats?: mixed}, array<never, never>>
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	public function getObjectVectorizationStats(): JSONResponse {
		try {
			// Get views parameter if provided.
			$views = $this->request->getParam(key: 'views');
			if (is_string($views) === true) {
				$views = json_decode($views, true);
			}

			// Use ObjectService delegation to handler.
			$stats = $this->objectService->getVectorizationStatistics(_views: $views);

			return new JSONResponse(data: ['success' => true, 'stats' => $stats]);
		} catch (\Throwable $e) {
			return $this->vectorizationFailureResponse(failure: $e);
		}
	}//end getObjectVectorizationStats()

	/**
	 * Validate all objects for a register/schema combination
	 *
	 * This endpoint validates all objects in a specific schema, ensuring they conform
	 * to the schema definition and updating metadata like name, description, etc.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with validation results
	 *
	 * @psalm-return JSONResponse
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	public function validate(): JSONResponse {
		try {
			$window = new ValidationWindow(request: $this->request);
			if ($window->isComplete() === false) {
				return new JSONResponse(
					data: ['success' => false, 'error' => 'Register and schema parameters are required'],
					statusCode: 400
				);
			}

			return $this->validationResponse(window: $window, result: $this->validatedChunk(window: $window));
		} catch (\Exception $e) {
			return $this->validationFailureResponse(failure: $e);
		}
	}//end validate()

	/**
	 * Validate and save one chunk of a schema's objects, logging start and end.
	 *
	 * @param ValidationWindow $window The register, schema and chunk.
	 *
	 * @return array The validation result.
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	private function validatedChunk(ValidationWindow $window): array {
		$scope = ['file' => __FILE__, 'line' => __LINE__, 'register' => $window->register, 'schema' => $window->schema];
		$start = '[ObjectsController] Starting bulk validation for schema';
		$this->logger->info(message: $start, context: $scope + ['limit' => $window->limit, 'offset' => $window->offset]);

		// Validate and save objects in the schema to update metadata.
		$result = $this->objectService->validateAndSaveObjectsBySchema(
			registerId: (int)$window->register,
			schemaId: (int)$window->schema,
			limit: $window->limit,
			offset: $window->offset
		);

		$done = '[ObjectsController] Bulk validation and save completed';
		$this->logger->info(message: $done, context: $scope + $this->validationCounts(result: $result));

		return $result;
	}//end validatedChunk()

	/**
	 * The processed, updated and failed counts of a validation result.
	 *
	 * @param array $result The validation result.
	 *
	 * @return array{processed: mixed, updated: mixed, failed: mixed} The counts.
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	private function validationCounts(array $result): array {
		return [
			'processed' => ($result['processed'] ?? 0),
			'updated' => ($result['updated'] ?? 0),
			'failed' => ($result['failed'] ?? 0),
		];
	}//end validationCounts()

	/**
	 * The answer to a completed bulk validation.
	 *
	 * @param ValidationWindow $window The register, schema and chunk.
	 * @param array            $result The validation result.
	 *
	 * @return JSONResponse The statistics, pagination and errors.
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	private function validationResponse(ValidationWindow $window, array $result): JSONResponse {
		return new JSONResponse(
			data: [
				'success' => true,
				'message' => 'Validation completed successfully',
				'statistics' => ($this->validationCounts(result: $result) + ['total' => ($result['total'] ?? null)]),
				'pagination' => ['limit' => $window->limit, 'offset' => $window->offset],
				'errors' => ($result['errors'] ?? []),
			]
		);
	}//end validationResponse()

	/**
	 * Log a failed bulk validation and answer it.
	 *
	 * @param \Exception $failure The failure.
	 *
	 * @return JSONResponse The 500 answer.
	 *
	 * @throws DbalObjectSourceException|DbalWriteException For ObjectSourceErrorMiddleware to answer.
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	private function validationFailureResponse(\Exception $failure): JSONResponse {
		$this->rethrowExternalSourceFailure(failure: $failure);
		$this->logger->error(
			message: '[ObjectsController] Bulk validation failed',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'error' => $failure->getMessage(),
				'trace' => $failure->getTraceAsString(),
			]
		);

		return new JSONResponse(
			data: ['success' => false, 'error' => 'Internal server error', 'message' => 'Validation failed'],
			statusCode: 500
		);
	}//end validationFailureResponse()

	/**
	 * Log a failed vectorization route and answer it without the failure's text.
	 *
	 * @param \Throwable $failure The failure.
	 *
	 * @return JSONResponse The 500 answer.
	 *
	 * @throws DbalObjectSourceException|DbalWriteException For ObjectSourceErrorMiddleware to answer.
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	private function vectorizationFailureResponse(\Throwable $failure): JSONResponse {
		$this->rethrowExternalSourceFailure(failure: $failure);
		$this->logger->error(
			message: '[ObjectsController] Vectorization route failed',
			context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $failure->getMessage()]
		);

		return new JSONResponse(data: ['success' => false, 'error' => 'Internal server error'], statusCode: 500);
	}//end vectorizationFailureResponse()

	/**
	 * Answer an object history lookup that failed with a 404, logging anything but a plain miss.
	 *
	 * @param \Exception $failure The failure.
	 *
	 * @return JSONResponse The 404 answer.
	 *
	 * @throws DbalObjectSourceException|DbalWriteException For ObjectSourceErrorMiddleware to answer.
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	private function historyLookupFailureResponse(\Exception $failure): JSONResponse {
		$this->rethrowExternalSourceFailure(failure: $failure);
		if ($failure instanceof DoesNotExistException === false) {
			$this->logger->error(
				message: '[ObjectsController] Object history lookup failed',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $failure->getMessage()]
			);
		}

		return new JSONResponse(data: ['message' => 'Object not found'], statusCode: 404);
	}//end historyLookupFailureResponse()

	/**
	 * Let an external source's failure leave the route: ObjectSourceErrorMiddleware answers it with its own status.
	 *
	 * @param \Throwable $failure The failure a route caught.
	 *
	 * @return void
	 *
	 * @throws DbalObjectSourceException|DbalWriteException When the failure is one of these.
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	private function rethrowExternalSourceFailure(\Throwable $failure): void {
		if ($failure instanceof DbalObjectSourceException || $failure instanceof DbalWriteException) {
			throw $failure;
		}
	}//end rethrowExternalSourceFailure()

	/**
	 * Collect UUID-to-name mappings for all related objects in a response.
	 *
	 * This method extracts all UUIDs from the response data (relations, extended objects)
	 * and resolves them to human-readable names using the CacheHandler.
	 *
	 * @param array $renderedData The rendered object data.
	 * @param \OCA\OpenRegister\Kernel\Contracts\ObjectNameCacheInterface|null $cacheHandler The cache handler for name resolution.
	 *
	 * @return array<string, string> Map of UUID to name.
	 */
	private function collectNamesForResponse(
		array $renderedData,
		?\OCA\OpenRegister\Kernel\Contracts\ObjectNameCacheInterface $cacheHandler,
	): array {
		if ($cacheHandler === null) {
			return [];
		}

		$uuids = array_unique((new RenderedObjectUuids())->inRendered(rendered: $renderedData));
		if ($uuids === []) {
			return [];
		}

		return $cacheHandler->getMultipleObjectNames($uuids);
	}//end collectNamesForResponse()

	/**
	 * Build the structured HTTP 403 response for a folder-access denial.
	 *
	 * Per the `self-folder-access-control` capability spec, every save
	 * endpoint that propagates `FolderAccessDeniedException` MUST return
	 * status 403 with body `{ "error": "folder_access_denied" }`.
	 *
	 * The body does NOT echo the attempted folder ID. Doing so would add
	 * an enumeration oracle: a caller probing `@self.folder` with sequential
	 * integers could distinguish "folder exists but I can't read it" (403)
	 * from "folder does not exist" (auto-create / no-op) just by observing
	 * the response shape. Returning a uniform 403 with no folder context
	 * forces the attacker to rely on the status code alone — which is already
	 * a documented privacy property of the spec — and removes the body-level
	 * confirmation. The caller already knows which folder ID they sent.
	 *
	 * The exception's `getAttemptedFolderId()` still carries the ID for
	 * server-side logging and the audit trail.
	 *
	 * Centralised here so the three save endpoints (create / update / postPatch)
	 * stay in sync without copy-pasting the response shape.
	 *
	 * @param FolderAccessDeniedException $exception The denial exception carrying the attempted folder ID.
	 *
	 * @return JSONResponse HTTP 403 with the structured body.
	 */
	private function folderAccessDeniedResponse(FolderAccessDeniedException $exception): JSONResponse {
		// Side-effect: ensure the attempted ID is recorded server-side
		// (visible in the audit trail via logFolderAccessDenied + the
		// exception message) even though we do NOT echo it back to the
		// caller. `$exception` is referenced only to make the audit
		// intent clear; the structured body is intentionally minimal.
		$this->logger->info(
			'[ObjectsController] Folder access denied — returning 403',
			[
				'attemptedFolderId' => $exception->getAttemptedFolderId(),
			]
		);

		return new JSONResponse(
			data: ['error' => 'folder_access_denied'],
			statusCode: FolderAccessDeniedException::HTTP_STATUS
		);
	}//end folderAccessDeniedResponse()

	/**
	 * The flow run this write is being made for, or null when a person is
	 * writing.
	 *
	 * A run-scoped lock refuses every caller but the holding run, so a guard
	 * that cannot name the caller's run refuses the run that took the lock.
	 * Resolved from the container rather than injected because the ambient
	 * stack is a shared service and this is the only thing here that needs it.
	 *
	 * A container that cannot serve it answers "a person", which is the
	 * FAIL-CLOSED direction: a run lock refuses a caller with no run uuid, so
	 * the worst outcome is a refusal, never a lock walked through.
	 *
	 * @return string|null The executing run's uuid, or null.
	 *
	 * @spec openspec/changes/run-scoped-object-locking/specs/run-scoped-object-locking/spec.md#requirement-ownership-is-decided-by-one-predicate
	 */
	private function callerRunUuid(): ?string {
		try {
			$flowContext = $this->container->get(\OCA\OpenRegister\Service\Flow\FlowRunContextInterface::class);
		} catch (\Throwable $unavailable) {
			return null;
		}

		if ($flowContext instanceof \OCA\OpenRegister\Service\Flow\FlowRunContextInterface === false) {
			return null;
		}

		return $flowContext->currentRunUuid();
	}//end callerRunUuid()

	/**
	 * Restore the stored form of string-typed properties the read path decoded.
	 *
	 * ONE helper for both patch doors, and it holds no rule of its own: the
	 * rule lives in `SchemaTypeConverter::restoreStringTypedValues()`, beside
	 * the decode it undoes. All this does is find the schema's property
	 * declarations and say which keys the caller sent.
	 *
	 * Why it is needed at all: the magic-table read decodes a `type: string`
	 * value that looks like JSON, so `$existingObject->getObject()` hands back
	 * an ARRAY where the schema declares a string. Merging that array into the
	 * payload and saving it makes validation refuse a PATCH that never
	 * mentioned the property — the "only the provided fields change" promise in
	 * this method's own docblock, broken by a property nobody named.
	 *
	 * If the schema entity is not resolvable the data is returned unchanged,
	 * leaving today's loud validation refusal in place rather than guessing.
	 *
	 * @param array $mergedData The merged object data about to be saved.
	 * @param StringTypedPatch $patch The resolved schema and the keys the caller sent.
	 *
	 * @return array The data with untouched string-typed JSON values restored.
	 *
	 * @spec openspec/specs/schema-driven-read-coercion/spec.md
	 */
	private function restoreStringTypedValues(array $mergedData, StringTypedPatch $patch): array {
		if (($patch->schema instanceof Schema) === false) {
			return $mergedData;
		}

		$converter = $this->stringTypeConverter();
		if ($converter === null) {
			return $mergedData;
		}

		return $converter->restoreStringTypedValues(
			data: $mergedData,
			properties: $patch->schema->getProperties(),
			suppliedKeys: $patch->suppliedKeys
		);
	}//end restoreStringTypedValues()

	/**
	 * The converter from the container, or null when it answers with anything else.
	 *
	 * A container may answer with something other than a converter, or with
	 * nothing at all. Having none keeps the pre-existing refusal; calling a
	 * method on null would turn a patch into a fatal.
	 *
	 * @return SchemaTypeConverterInterface|null The converter.
	 *
	 * @spec openspec/specs/schema-driven-read-coercion/spec.md
	 */
	private function stringTypeConverter(): ?SchemaTypeConverterInterface {
		try {
			$converter = $this->container->get(SchemaTypeConverterInterface::class);
		} catch (NotFoundExceptionInterface|ContainerExceptionInterface $unavailable) {
			return null;
		}

		if (($converter instanceof SchemaTypeConverterInterface) === false) {
			return null;
		}

		return $converter;
	}//end stringTypeConverter()

	/**
	 * Refuse a write to a locked object, or return null when it may proceed.
	 *
	 * ONE guard for all three write doors. PUT carried this inline and PATCH
	 * and POST-patch carried nothing, so the two doors to the same object gave
	 * two different answers: 423 naming the holder on PUT, and on PATCH
	 * whatever the payload happened to produce first — 400 from validation, or
	 * 500 when the payload was valid and the service-layer guard's untyped
	 * exception fell through to the generic handler. Neither said anything
	 * about a lock.
	 *
	 * The condition is not restated here: `isLockedBySomeoneElse()` is the
	 * single predicate (openregister#3454), run identity reaches it ambiently
	 * through `FlowRunContext::currentRunUuid()`, and the refusal itself is
	 * built by `LockedException::forObject()` so the words and the holder
	 * fields are the same wherever a lock refuses a write.
	 *
	 * Callers MUST invoke this immediately after the existence pre-read and
	 * BEFORE merging, validating or saving. A caller who is told their payload
	 * is malformed when the real answer is "someone else holds this" has been
	 * sent to fix the wrong thing.
	 *
	 * @param ObjectEntity $existingObject The object as stored, from the pre-read.
	 *
	 * @return JSONResponse|null The 423 refusal, or null when the caller may write.
	 *
	 * @spec openspec/changes/run-scoped-object-locking/specs/run-scoped-object-locking/spec.md#requirement-a-lock-refuses-a-write-and-names-its-holder
	 */
	private function lockRefusalResponse(ObjectEntity $existingObject): ?JSONResponse {
		try {
			$callerUserId = $this->container->get('userId');
		} catch (NotFoundExceptionInterface|ContainerExceptionInterface $unavailable) {
			// No resolvable caller identity. A user lock is held against
			// "nobody" just as it is against anyone who is not the holder, so
			// the predicate still answers correctly with a null user id.
			$callerUserId = null;
		}

		if ($existingObject->isLockedBySomeoneElse(userId: $callerUserId, runUuid: $this->callerRunUuid()) === false) {
			return null;
		}

		return $this->lockedResponse(exception: LockedException::forObject($existingObject));
	}//end lockRefusalResponse()

	/**
	 * Render a lock refusal as the canonical 423 response body.
	 *
	 * Kept separate from `lockRefusalResponse()` so a catch site that receives
	 * a `LockedException` thrown further down (the service-layer guard in
	 * `SaveObject::findAndValidateExistingObject()`) answers with the SAME body
	 * rather than a second spelling of it.
	 *
	 * The `error` string keeps the wording PUT has always returned, because
	 * clients match on it.
	 *
	 * @param LockedException $exception The refusal carrying the holder.
	 *
	 * @return JSONResponse The 423 response naming the lock holder.
	 *
	 * @spec openspec/changes/run-scoped-object-locking/specs/run-scoped-object-locking/spec.md#requirement-a-lock-refuses-a-write-and-names-its-holder
	 */
	private function lockedResponse(LockedException $exception): JSONResponse {
		return new JSONResponse(
			data: [
				'error' => 'Object is locked by ' . (string)$exception->getHolder(),
				'lockedBy' => $exception->getLockedBy(),
				'lockedByRun' => $exception->getLockedByRun(),
			],
			statusCode: LockedException::HTTP_STATUS
		);
	}//end lockedResponse()
	/**
	 * Add the per-sub-resource unread counts to a single object's `@self`.
	 *
	 * One map, from one read, so the detail page renders every tab badge
	 * without a call per tab. Absent entirely for an anonymous read and when
	 * there is nothing to badge, because an empty map and "no badges here" are
	 * the same claim and neither should be rendered as a nought.
	 *
	 * Resolved through the container rather than the constructor, the same lazy
	 * posture the render layer uses for the same primitive: a read-state lookup
	 * must never be able to take out an object read.
	 *
	 * @param array<string, mixed> $self The `@self` envelope as rendered.
	 * @param ObjectEntity $object The object being read.
	 *
	 * @return array<string, mixed> The envelope, with the counts when there are any.
	 *
	 * @spec openspec/specs/object-read-state/spec.md#requirement-unread-is-a-filter-and-a-badge-resolved-in-the-query-req-ors-002
	 */
	private function withUnreadCounts(array $self, ObjectEntity $object): array {
		try {
			$counts = $this->unreadCounts(object: $object);
		} catch (\Throwable $e) {
			$this->logger->debug(
				sprintf('[ObjectsController] unread counts skipped: %s', $e->getMessage())
			);
			return $self;
		}

		if ($counts !== []) {
			$self['unreadCounts'] = $counts;
		}

		return $self;
	}//end withUnreadCounts()

	/**
	 * The signed-in caller's unread counts of an object; nothing for an anonymous read.
	 *
	 * @param ObjectEntity $object The object being read.
	 *
	 * @return array<string, mixed> The counts.
	 *
	 * @spec openspec/specs/object-read-state/spec.md#requirement-unread-is-a-filter-and-a-badge-resolved-in-the-query-req-ors-002
	 */
	private function unreadCounts(ObjectEntity $object): array {
		$readState = $this->container->get(ReadStateServiceInterface::class);
		if ($readState->callerUid() === null) {
			return [];
		}

		return $readState->unreadCounts(object: $object);
	}//end unreadCounts()

	/**
	 * Read the records that reference this object, grouped by schema.
	 *
	 * The reverse of `uses`: an address, an asset or a licence read as the thing
	 * several cases hinge on. Each group carries its own total and one page of
	 * records, each with its title, its status and when it last changed. The
	 * caller's access is applied inside the query, so a caller who may read one
	 * of three gets one of three and a total of one.
	 *
	 * @param string $id The object being read as the hinge.
	 * @param string $register The register slug or identifier.
	 * @param string $schema The schema slug or identifier.
	 * @param ObjectOperationsInterface $objectService The object service.
	 * @param ReferencedByServiceInterface $referencedByService The reverse view.
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse The grouped reverse view, or 404 when the object is gone.
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function referencedBy(
		string $id,
		string $register,
		string $schema,
		ObjectOperationsInterface $objectService,
		ReferencedByServiceInterface $referencedByService,
	): JSONResponse {
		$isAdmin = $this->isCurrentUserAdmin();
		$rbac = ($isAdmin === false);

		try {
			$objectEntity = WriteCause::asLookup(fn () => $objectService->find(
				id: $id,
				files: false,
				register: $register,
				schema: $schema,
				_rbac: $rbac,
				_multitenancy: $rbac,
				_render: false
			));
		} catch (MultipleObjectsReturnedException $e) {
			return $this->ambiguousIdentifierResponse(id: $id);
		} catch (\Exception $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: Http::STATUS_NOT_FOUND);
		}

		if ($objectEntity === null) {
			return new JSONResponse(
				data: ['error' => "Object with id {$id} not found"],
				statusCode: Http::STATUS_NOT_FOUND
			);
		}

		$query = $this->request->getParams();
		unset($query['id'], $query['register'], $query['schema'], $query['_route']);

		return new JSONResponse(
			data: $referencedByService->getReferencingGroups(
				object: $objectEntity,
				query: $query,
				_rbac: $rbac
			)
		);
	}//end referencedBy()

	/**
	 * Read this object's map features, its own and the ones it inherits.
	 *
	 * Each inherited feature names the relation it arrived through, and a feature
	 * the record holds itself outranks an inherited one for the same purpose. The
	 * inherited one is still returned, marked superseded, because "where did the
	 * other pin go" is a question worth an answer.
	 *
	 * @param string $id The object whose features are read.
	 * @param string $register The register slug or identifier.
	 * @param string $schema The schema slug or identifier.
	 * @param ObjectOperationsInterface $objectService The object service.
	 * @param InheritedGeoCollectorInterface $collector The feature collector.
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse A GeoJSON FeatureCollection, or 404 when the object is gone.
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function geoFeatures(
		string $id,
		string $register,
		string $schema,
		ObjectOperationsInterface $objectService,
		InheritedGeoCollectorInterface $collector,
	): JSONResponse {
		$isAdmin = $this->isCurrentUserAdmin();
		$rbac = ($isAdmin === false);

		try {
			$objectEntity = WriteCause::asLookup(fn () => $objectService->find(
				id: $id,
				files: false,
				register: $register,
				schema: $schema,
				_rbac: $rbac,
				_multitenancy: $rbac,
				_render: false
			));
		} catch (MultipleObjectsReturnedException $e) {
			return $this->ambiguousIdentifierResponse(id: $id);
		} catch (\Exception $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: Http::STATUS_NOT_FOUND);
		}

		if ($objectEntity === null) {
			return new JSONResponse(
				data: ['error' => "Object with id {$id} not found"],
				statusCode: Http::STATUS_NOT_FOUND
			);
		}

		$schemaEntity = null;
		try {
			$schemaEntity = $this->services->store->schemaMapper->find(
				id: $objectEntity->getSchema(),
				_rbac: false,
				_multitenancy: false
			);
		} catch (\Throwable $e) {
			$this->logger->debug(
				sprintf('[ObjectsController] geo features without a schema: %s', $e->getMessage())
			);
		}

		return new JSONResponse(
			data: $collector->collect(object: $objectEntity, schema: $schemaEntity, _rbac: $rbac)
		);
	}//end geoFeatures()

	/**
	 * Put any soft-uniqueness warnings this write produced onto the response.
	 *
	 * Under `@warnings`, beside `@self`: it is metadata about this write, not a
	 * property of the object, and it must never be mistaken for one. The status
	 * code is untouched, because the hint is an ALERT and the write succeeded.
	 * Turning it into a 4xx would make it the refusal the spec explicitly does
	 * not want, and a schema that needs a refusal declares
	 * `x-openregister-dedup.onCreate: "block"` instead.
	 *
	 * The collector is drained, so a bulk request cannot hand a later row the
	 * warnings of an earlier one.
	 *
	 * @param array<string, mixed> $body The serialised object.
	 *
	 * @return array<string, mixed> The body, with `@warnings` when there are any.
	 *
	 * @spec openspec/specs/duplicate-detection/spec.md#requirement-a-nominated-property-warns-when-its-value-already-exists-req-dmd-004
	 */
	private function withUniqueHintWarnings(array $body): array {
		if ($this->governance->uniqueHintWarnings === null) {
			return $body;
		}

		$warnings = $this->governance->uniqueHintWarnings->drain();
		if (count($warnings) === 0) {
			return $body;
		}

		$body['@warnings'] = $warnings;

		return $body;
	}//end withUniqueHintWarnings()
}//end class
