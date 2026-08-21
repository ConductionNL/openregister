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
 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use DateTime;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\AppendOnlyException;
use OCA\OpenRegister\Exception\CustomValidationException;
use OCA\OpenRegister\Exception\ExportTooLargeException;
use OCA\OpenRegister\Exception\FolderAccessDeniedException;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Exception\ReferentialIntegrityException;
use OCA\OpenRegister\Exception\RegisterNotFoundException;
use OCA\OpenRegister\Exception\SchemaNotFoundException;
use OCA\OpenRegister\Exception\TranslationTargetConflictException;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Service\ExportService;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ImportService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\WebhookService;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\DB\Exception;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

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

	/**
	 * Export service for handling data exports
	 *
	 * @var ExportService
	 */
	private readonly ExportService $exportService;

	/**
	 * Import service for handling data imports
	 *
	 * @var ImportService
	 */
	private readonly ImportService $importService;

	/**
	 * Constructor for the ObjectsController
	 *
	 * @param string $appName The name of the app
	 * @param IRequest $request The request object
	 * @param IAppConfig $config The app configuration object
	 * @param IAppManager $appManager The app manager
	 * @param ContainerInterface $container The DI container
	 * @param RegisterMapper $registerMapper The register mapper
	 * @param SchemaMapper $schemaMapper The schema mapper
	 * @param AuditTrailMapper $auditTrailMapper The audit trail mapper
	 * @param ObjectService $objectService The object service
	 * @param IUserSession $userSession The user session
	 * @param IGroupManager $groupManager The group manager
	 * @param ExportService $exportService The export service
	 * @param ImportService $importService The import service
	 * @param WebhookService $webhookService The webhook service (optional)
	 * @param LoggerInterface $logger The logger (optional)
	 * @param ?\OCA\OpenRegister\Service\Geo\GeoFilterParser $geoFilterParser Optional geo wire-format adapter (null-safe)
	 * @param ?\OCA\OpenRegister\Service\Geo\GeoFilterApplier $geoFilterApplier Optional geo post-filter (null-safe)
	 * @param ?\OCA\OpenRegister\Service\JsonLd\JsonLdSerializer $jsonLdSerializer Optional JSON-LD serializer (null-safe)
	 * @param ?\OCA\OpenRegister\Service\JsonLd\JsonLdContextService $jsonLdContextService Optional JSON-LD context service (null-safe)
	 * @param ?\OCA\OpenRegister\Service\Geo\GeoFeatureCollectionBuilder $geoFeatureBuilder Optional GeoJSON/WFS feature builder (null-safe)
	 * @param ?\OCA\OpenRegister\Service\Geo\PdokGeocoder $pdokGeocoder Optional PDOK geocoder (null-safe)
	 * @param ?\OCA\OpenRegister\Service\DeepLinkRegistryService $deepLinkRegistry Relation resourceUrl resolver (null-safe)
	 * @param ?\OCP\IURLGenerator $relationUrlGenerator Relation fallback URL generator (null-safe)
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Nextcloud DI requires constructor injection
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IAppConfig $config,
		private readonly IAppManager $appManager,
		private readonly ContainerInterface $container,
		private readonly RegisterMapper $registerMapper,
		private readonly SchemaMapper $schemaMapper,
		private readonly AuditTrailMapper $auditTrailMapper,
		private readonly ObjectService $objectService,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		ExportService $exportService,
		ImportService $importService,
		private readonly ?WebhookService $webhookService = null,
		private readonly ?LoggerInterface $logger = null,
		private readonly ?\OCA\OpenRegister\Service\Geo\GeoFilterParser $geoFilterParser = null,
		private readonly ?\OCA\OpenRegister\Service\Geo\GeoFilterApplier $geoFilterApplier = null,
		private readonly ?\OCA\OpenRegister\Service\JsonLd\JsonLdSerializer $jsonLdSerializer = null,
		private readonly ?\OCA\OpenRegister\Service\JsonLd\JsonLdContextService $jsonLdContextService = null,
		private readonly ?\OCA\OpenRegister\Service\Geo\GeoFeatureCollectionBuilder $geoFeatureBuilder = null,
		private readonly ?\OCA\OpenRegister\Service\Geo\PdokGeocoder $pdokGeocoder = null,
		private readonly ?\OCA\OpenRegister\Service\DeepLinkRegistryService $deepLinkRegistry = null,
		private readonly ?\OCP\IURLGenerator $relationUrlGenerator = null,
	) {
		parent::__construct(appName: $appName, request: $request);
		$this->exportService = $exportService;
		$this->importService = $importService;
	}//end __construct()

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
		if ($this->jsonLdSerializer === null) {
			return false;
		}

		return $this->jsonLdSerializer->wantsJsonLd(request: $this->request);
	}//end wantsJsonLd()

	/**
	 * Decorate an already-rendered single-object array as a JSON-LD JSONResponse.
	 *
	 * The serializer wraps the rendered array only — it introduces no second
	 * data path, so RBAC / multitenancy / field-level security / the published
	 * predicate all remain applied exactly as for the plain-JSON response.
	 *
	 * @param array $renderedObject The rendered object array.
	 * @param \OCA\OpenRegister\Db\Register|null $register The resolved register entity.
	 * @param \OCA\OpenRegister\Db\Schema|null $schema The resolved schema entity.
	 *
	 * @return JSONResponse The JSON-LD response (Content-Type/Vary set).
	 *
	 * @spec openspec/specs/json-ld-output/spec.md
	 */
	private function jsonLdObjectResponse(array $renderedObject, $register, $schema): JSONResponse {
		$document = $this->jsonLdSerializer->serialize(
			renderedObject: $renderedObject,
			schema: $schema,
			register: $register
		);

		return $this->withJsonLdHeaders(response: new JSONResponse(data: $document));
	}//end jsonLdObjectResponse()

	/**
	 * Decorate a paginated collection result as a JSON-LD `@graph` JSONResponse.
	 *
	 * @param array $result The paginated result array.
	 * @param \OCA\OpenRegister\Db\Register|null $register The resolved register entity.
	 * @param \OCA\OpenRegister\Db\Schema|null $schema The resolved schema entity.
	 *
	 * @return JSONResponse The JSON-LD response (Content-Type/Vary set).
	 *
	 * @spec openspec/specs/json-ld-output/spec.md
	 */
	private function jsonLdCollectionResponse(array $result, $register, $schema): JSONResponse {
		$document = $this->jsonLdSerializer->serializeCollection(
			paginatedResult: $result,
			schema: $schema,
			register: $register
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

		foreach ($data as $key => $value) {
			if (is_string($value) === false) {
				continue;
			}

			$trimmed = trim($value);

			// Only attempt decode on values that look like JSON arrays or objects.
			if (($trimmed[0] ?? '') !== '[' && ($trimmed[0] ?? '') !== '{') {
				continue;
			}

			$decoded = json_decode($trimmed, associative: true);
			if (json_last_error() === JSON_ERROR_NONE && is_array($decoded) === true) {
				$data[$key] = $decoded;
			}
		}

		return $data;
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
	 *   owner, organisation, authorization, groups, application, folder
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
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) File extraction must handle multiple upload field formats
	 */
	private function extractAllUploadedFiles(): array {
		$uploadedFiles = [];

		// Primary method: iterate through $_FILES directly to get all uploaded file field names.
		// This is the most reliable way to detect all file uploads regardless of field name.
		// phpcs:ignore -- $_FILES access is necessary as IRequest doesn't expose all file keys.
		foreach (array_keys($_FILES) as $fieldName) {
			// Skip if already processed.
			if (isset($uploadedFiles[$fieldName]) === true) {
				continue;
			}

			// Skip system parameters.
			if (str_starts_with((string)$fieldName, '_') === true) {
				continue;
			}

			// Use IRequest to get the file data for consistency.
			$uploadedFile = $this->request->getUploadedFile((string)$fieldName);
			if ($uploadedFile !== null && isset($uploadedFile['tmp_name']) === true) {
				// Check if this is an array upload (multiple files).
				$nameValue = $uploadedFile['name'] ?? null;
				if (is_array($nameValue) === true) {
					// Handle multiple files with indexed keys.
					$this->extractMultipleFiles(
						uploadedFiles: $uploadedFiles,
						fieldName: $fieldName,
						uploadedFile: $uploadedFile,
						nameValue: $nameValue
					);
					continue;
				}

				// Single file upload - only add if tmp_name is not empty.
				if (empty($uploadedFile['tmp_name']) === false) {
					$uploadedFiles[(string)$fieldName] = $uploadedFile;
				}
			}
		}//end foreach

		// Secondary method: also check request params for file fields.
		// Some frameworks may include file field names in params.
		$params = $this->request->getParams();
		foreach (array_keys($params) as $fieldName) {
			// Skip if already processed.
			if (isset($uploadedFiles[$fieldName]) === true) {
				continue;
			}

			// Skip system parameters.
			if (str_starts_with((string)$fieldName, '_') === true) {
				continue;
			}

			$uploadedFile = $this->request->getUploadedFile((string)$fieldName);
			if ($uploadedFile !== null && isset($uploadedFile['tmp_name']) === true) {
				$nameValue = $uploadedFile['name'] ?? null;
				if (is_array($nameValue) === true) {
					$this->extractMultipleFiles(
						uploadedFiles: $uploadedFiles,
						fieldName: $fieldName,
						uploadedFile: $uploadedFile,
						nameValue: $nameValue
					);
					continue;
				}

				if (empty($uploadedFile['tmp_name']) === false) {
					$uploadedFiles[(string)$fieldName] = $uploadedFile;
				}
			}
		}//end foreach

		return $uploadedFiles;
	}//end extractAllUploadedFiles()

	/**
	 * Helper method to extract multiple files from an array upload field.
	 *
	 * @param array $uploadedFiles Reference to the uploaded files array to populate.
	 * @param string $fieldName The field name for the file upload.
	 * @param array $uploadedFile The uploaded file data from IRequest.
	 * @param array $nameValue The array of file names.
	 *
	 * @return void
	 */
	private function extractMultipleFiles(
		array &$uploadedFiles,
		string $fieldName,
		array $uploadedFile,
		array $nameValue,
	): void {
		$fileCount = count($nameValue);
		for ($i = 0; $i < $fileCount; $i++) {
			if (is_array($uploadedFile['type'] ?? null) === true) {
				$typeArray = $uploadedFile['type'];
			} else {
				$typeArray = [];
			}

			if (is_array($uploadedFile['tmp_name'] ?? null) === true) {
				$tmpNameArray = $uploadedFile['tmp_name'];
			} else {
				$tmpNameArray = [];
			}

			if (is_array($uploadedFile['error'] ?? null) === true) {
				$errorArray = $uploadedFile['error'];
			} else {
				$errorArray = [];
			}

			if (is_array($uploadedFile['size'] ?? null) === true) {
				$sizeArray = $uploadedFile['size'];
			} else {
				$sizeArray = [];
			}

			// Only add if tmp_name is not empty.
			if (empty($tmpNameArray[$i]) === false) {
				$uploadedFiles[$fieldName . '[' . $i . ']'] = [
					'name' => $nameValue[$i] ?? '',
					'type' => $typeArray[$i] ?? '',
					'tmp_name' => $tmpNameArray[$i] ?? '',
					'error' => $errorArray[$i] ?? UPLOAD_ERR_NO_FILE,
					'size' => $sizeArray[$i] ?? 0,
				];
			}
		}//end for
	}//end extractMultipleFiles()

	/**
	 * Private helper method to handle pagination of results.
	 *
	 * This method paginates the given results array based on the provided total, limit, offset, and page parameters.
	 * It calculates the number of pages, sets the appropriate offset and page values, and returns the paginated results
	 * along with metadata such as total items, current page, total pages, limit, and offset.
	 *
	 * @param array $results The array of objects to paginate.
	 * @param int|null $total The total number of items (before pagination). Defaults to 0.
	 * @param int|null $limit The number of items per page. Defaults to 20.
	 * @param int|null $offset The offset of items. Defaults to 0.
	 * @param int|null $page The current page number. Defaults to 1.
	 *
	 * @return (array|float|int|string)[]
	 *
	 * @phpstan-param array<int, mixed> $results
	 *
	 * @phpstan-return array<string, mixed>
	 *
	 * @psalm-param array<int, mixed> $results
	 *
	 * @psalm-return array{
	 *     results: array<int, mixed>,
	 *     total: int<0, max>,
	 *     page: float|int<1, max>,
	 *     pages: 1|float,
	 *     limit: int<1, max>,
	 *     offset: int<0, max>,
	 *     next?: string,
	 *     prev?: string
	 * }
	 */
	private function paginate(array $results, ?int $total = 0, ?int $limit = 20, ?int $offset = 0, ?int $page = 1): array {
		// Ensure we have valid values (never null).
		$total = max(0, $total ?? 0);
		$limit = max(1, $limit ?? 20);
		// Minimum limit of 1.
		$offset = max(0, $offset ?? 0);
		$page = max(1, $page ?? 1);
		// Minimum page of 1        // Calculate the number of pages (minimum 1 page).
		$pages = max(1, ceil($total / $limit));

		// If we have a page but no offset, calculate the offset.
		if ($offset === 0) {
			$offset = ($page - 1) * $limit;
		}

		// If we have an offset but page is 1, calculate the page.
		if ($page === 1 && $offset > 0) {
			$page = floor($offset / $limit) + 1;
		}

		// If total is smaller than the number of results, set total to the number of results.
		// @todo: this is a hack to ensure the pagination is correct when the total is not known.
		// That suggests that the underlying count service has a problem that needs to be fixed instead.
		if ($total < count($results)) {
			$total = count($results);
			$pages = max(1, ceil($total / $limit));
		}

		// Initialize the results array with pagination information.
		$paginatedResults = [
			'results' => $results,
			'total' => $total,
			'page' => $page,
			'pages' => $pages,
			'limit' => $limit,
			'offset' => $offset,
		];

		// Add next/prev page URLs if applicable.
		$currentUrl = $this->request->getRequestUri();

		// Add next page link if there are more pages.
		if ($page < $pages) {
			$nextPage = $page + 1;
			$nextUrl = preg_replace('/([?&])page=\d+/', '$1page=' . $nextPage, $currentUrl);
			if (strpos($nextUrl, 'page=') === false) {
				$nextUrl .= '&page=' . $nextPage;
				if (strpos($nextUrl, '?') === false) {
					$nextUrl .= '?page=' . $nextPage;
				}
			}

			$paginatedResults['next'] = $nextUrl;
		}

		// Add previous page link if not on first page.
		if ($page > 1) {
			$prevPage = $page - 1;
			$prevUrl = preg_replace('/([?&])page=\d+/', '$1page=' . $prevPage, $currentUrl);
			if (strpos($prevUrl, 'page=') === false) {
				$prevUrl .= '&page=' . $prevPage;
				if (strpos($prevUrl, '?') === false) {
					$prevUrl .= '?page=' . $prevPage;
				}
			}

			$paginatedResults['prev'] = $prevUrl;
		}

		return $paginatedResults;
	}//end paginate()

	/**
	 * Helper method to get configuration array from the current request (LEGACY)
	 *
	 * @param string|null $_register Optional register identifier (unused).
	 * @param string|null $_schema Optional schema identifier (unused).
	 * @param array|null $ids Optional array of specific IDs to filter
	 *
	 * @return (array|int|mixed|null)[] Configuration array containing:
	 *
	 * @deprecated Use buildSearchQuery() instead for faceting-enabled endpoints
	 *               - limit: (int) Maximum number of items per page
	 *               - offset: (int|null) Number of items to skip
	 *               - page: (int|null) Current page number
	 *               - filters: (array) Filter parameters
	 *               - sort: (array) Sort parameters
	 *               - search: (string|null) Search term
	 *               - _extend: (array|null) Properties to extend
	 *               - fields: (array|null) Fields to include
	 *               - unset: (array|null) Fields to exclude
	 *               - register: (string|null) Register identifier
	 *               - schema: (string|null) Schema identifier
	 *               - ids: (array|null) Specific IDs to filter
	 *
	 * @psalm-return array{
	 *     limit: int,
	 *     offset: int|null,
	 *     page: int|null,
	 *     filters: array,
	 *     sort: array<never, never>|mixed,
	 *     _search: mixed|null,
	 *     _extend: mixed|null,
	 *     _fields: mixed|null,
	 *     _unset: mixed|null,
	 *     ids: array|null
	 * }
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	private function getConfig(?string $_register = null, ?string $_schema = null, ?array $ids = null): array {
		$params = $this->request->getParams();

		unset($params['id']);
		unset($params['_route']);

		// Extract and normalize parameters.
		$limit = (int)($params['limit'] ?? $params['_limit'] ?? 20);
		$offset = null;
		if (($params['_offset'] ?? null) !== null) {
			$offset = (int)$params['_offset'];
		}

		if (($params['offset'] ?? null) !== null) {
			$offset = (int)$params['offset'];
		}

		$page = null;
		if (($params['_page'] ?? null) !== null) {
			$page = (int)$params['_page'];
		}

		if (($params['page'] ?? null) !== null) {
			$page = (int)$params['page'];
		}

		// If we have a page but no offset, calculate the offset.
		if ($page !== null && $offset === null) {
			$offset = ($page - 1) * $limit;
		}

		return [
			'limit' => $limit,
			'offset' => $offset,
			'page' => $page,
			'filters' => $params,
			'sort' => $this->normalizeOrderParameter(order: $params['order'] ?? $params['_order'] ?? []),
			'_search' => ($params['_search'] ?? null),
			'_extend' => $this->normalizeExtendParameter(extend: $params['extend'] ?? $params['_extend'] ?? null),
			'_fields' => ($params['fields'] ?? $params['_fields'] ?? null),
			'_unset' => ($params['unset'] ?? $params['_unset'] ?? null),
			'ids' => $ids,
		];
	}//end getConfig()

	/**
	 * Normalize order parameter from request.
	 *
	 * The _order parameter may arrive as a JSON-encoded string from URL query
	 * parameters (e.g., _order={"deadline":"asc"}). This method ensures it
	 * is always returned as an array.
	 *
	 * @param mixed $order The order parameter from the request.
	 *
	 * @return array The normalized order array.
	 */
	private function normalizeOrderParameter(mixed $order): array {
		if (is_array($order) === true) {
			return $order;
		}

		if (is_string($order) === true && $order !== '') {
			$decoded = json_decode($order, true);
			if (is_array($decoded) === true) {
				return $decoded;
			}
		}

		return [];
	}//end normalizeOrderParameter()

	/**
	 * Normalize extend parameter for backwards compatibility
	 *
	 * Converts old @self.schema format to new _schema format.
	 * Supports both single strings and arrays of extend values.
	 *
	 * @param mixed $extend The extend parameter from request (string, array, or null)
	 *
	 * @return array|null Normalized extend array or null
	 */
	private function normalizeExtendParameter(mixed $extend): ?array {
		if ($extend === null) {
			return null;
		}

		// Convert string to array.
		if (is_string($extend) === true) {
			$extend = explode(',', $extend);
		}

		// Ensure it's an array.
		if (is_array($extend) === false) {
			return null;
		}

		// Normalize each extend value for backwards compatibility.
		$normalized = [];
		foreach ($extend as $key => $value) {
			// Skip if not a string.
			if (is_string($value) === false) {
				$normalized[$key] = $value;
				continue;
			}

			// Convert @self.schema to _schema for backwards compatibility.
			if ($value === '@self.schema') {
				$normalized[$key] = '_schema';
				continue;
			}

			// Convert @self.register to _register for backwards compatibility.
			if ($value === '@self.register') {
				$normalized[$key] = '_register';
				continue;
			}

			// Keep original value.
			$normalized[$key] = $value;
		}//end foreach

		return $normalized;
	}//end normalizeExtendParameter()

	/**
	 * Helper method to resolve register and schema slugs to numeric IDs
	 *
	 * This ensures consistent slug-to-ID conversion across all controller methods
	 * and prevents the discrepancy between slug-based and ID-based API calls.
	 *
	 * @param string $register Register slug or ID
	 * @param string $schema Schema slug or ID
	 * @param ObjectService $objectService Object service instance
	 *
	 * @return array Array with resolved register and schema IDs: ['register' => int, 'schema' => int]
	 *
	 * @throws \OCA\OpenRegister\Exception\RegisterNotFoundException
	 * @throws \OCA\OpenRegister\Exception\SchemaNotFoundException
	 *
	 * @psalm-return   array{register: int, schema: int, registerEntity: mixed, schemaEntity: mixed}
	 * @phpstan-return array{register: int, schema: int, registerEntity: mixed, schemaEntity: mixed}
	 */

	/**
	 * Parse multi-value parameter (array or comma-separated).
	 *
	 * Supports both formats:
	 * - Array: schemas[]=1&schemas[]=2
	 * - Comma-separated: schemas=1,2,3
	 *
	 * @param mixed $param The parameter value (string, array, or null).
	 * @param string $defaultValue Default value to use if param is null.
	 *
	 * @return array Array of values.
	 */
	private function parseMultiValue($param, string $defaultValue): array {
		// If no parameter provided, use default.
		if ($param === null || $param === '') {
			return [$defaultValue];
		}

		// If already an array, return as-is.
		if (is_array($param) === true) {
			return array_values(array_unique(array_filter($param)));
			// Remove empty values and duplicates.
		}

		// If string contains comma, split on comma.
		if (is_string($param) === true && str_contains($param, ',') === true) {
			return array_values(array_unique(array_filter(array_map('trim', explode(',', $param)))));
		}

		// Single value.
		return [$param];
	}//end parseMultiValue()

	/**
	 * Perform cross-table search across multiple register+schema combinations.
	 *
	 * @param array $registers Array of register IDs/slugs.
	 * @param array $schemas Array of schema IDs/slugs.
	 * @param ObjectService $objectService Object service for resolution.
	 *
	 * @return JSONResponse Search results from multiple tables.
	 *
	 * @psalm-suppress UnusedParam Params are used in foreach loops and method calls.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
	 */
	private function crossTableSearch(array $registers, array $schemas, ObjectService $objectService): JSONResponse {
		$magicMapper = \OC::$server->get(\OCA\OpenRegister\Db\MagicMapper::class);
		$registerMapper = $this->registerMapper;
		$schemaMapper = $this->schemaMapper;

		// PERF-14: resolve each register and schema exactly once up front instead of
		// re-running find() for every register×schema combination in the inner loop.
		$registerEntities = [];
		foreach (array_unique($registers) as $registerId) {
			try {
				$registerEntities[(string)$registerId] = $registerMapper->find(id: $registerId, _multitenancy: false, _rbac: false);
			} catch (\Exception $e) {
				$this->logger->warning(
					message: '[ObjectsController] Invalid register in cross-table search',
					context: ['file' => __FILE__, 'line' => __LINE__, 'register' => $registerId, 'error' => $e->getMessage()]
				);
			}
		}

		$schemaEntities = [];
		foreach (array_unique($schemas) as $schemaId) {
			try {
				$schemaEntities[(string)$schemaId] = $schemaMapper->find(id: $schemaId, _multitenancy: false, _rbac: false);
			} catch (\Exception $e) {
				$this->logger->warning(
					message: '[ObjectsController] Invalid schema in cross-table search',
					context: ['file' => __FILE__, 'line' => __LINE__, 'schema' => $schemaId, 'error' => $e->getMessage()]
				);
			}
		}

		// Build register+schema pairs from the pre-resolved entities.
		$pairs = [];
		foreach ($registerEntities as $registerEntity) {
			foreach ($schemaEntities as $schemaEntity) {
				// Check if magic mapping is enabled for this combination.
				// Uses Register::isMagicMappingEnabledForSchema() which supports both
				// new format {"schemas": {"slug": {"magicMapping": true}}} and
				// legacy format {"enableMagicMapping": true, "magicMappingSchemas": [...]}.
				if ($registerEntity->isMagicMappingEnabledForSchema(
					schemaId: $schemaEntity->getId(),
					schemaSlug: $schemaEntity->getSlug()
				) === true
				) {
					$pairs[] = [
						'register' => $registerEntity,
						'schema' => $schemaEntity,
					];
				}
			}//end foreach
		}//end foreach

		if (empty($pairs) === true) {
			return new JSONResponse(
				data: [
					'message' => 'No valid magic-mapped register+schema combinations found',
					'results' => [],
					'total' => 0,
				],
				statusCode: 404
			);
		}

		// Build search query WITHOUT register/schema to avoid filtering.
		// Cross-table search handles multiple register+schema pairs internally.
		$query = $objectService->buildSearchQuery(requestParams: $this->request->getParams());

		// SEC-CTRL-1: This path does NOT read rbac/multi from the request, so the
		// request-controlled bypass does not apply here. Derive the posture from
		// admin status for completeness and forward it on the query.
		// TODO(SEC-CTRL-1): MagicMapper::searchAcrossMultipleTables() and its
		// union/sequential builders currently apply NO RBAC or multitenancy filter
		// (they ignore these query flags). Enforcing per-pair RBAC/tenant scoping
		// lives in lib/Db/MagicMapper.php (out of this controller's scope) and must
		// be wired there before cross-table search is exposed to non-admins.
		$isAdmin = $this->isCurrentUserAdmin();
		$query['_rbac'] = ($isAdmin === false);
		$query['_multitenancy'] = ($isAdmin === false);

		// Remove all register/schema context from query to prevent filtering.
		unset(
			$query['_register'],
			$query['_schema'],
			$query['register'],
			$query['schema'],
			$query['schemas'],
			$query['registers'],
			$query['@self']
		);

		// Perform cross-table search.
		$results = $magicMapper->searchAcrossMultipleTables(query: $query, registerSchemaPairs: $pairs);

		// Redact write-only secrets before serialising (openregister#380, ocon#147): this
		// direct-magic-mapper path bypasses renderEntity, so without this the read returns
		// write-only fields in cleartext. redactWriteOnlyFromRows resolves each row's schema
		// individually, so the cross-schema result set is handled correctly.
		//
		// `_rbac` is forwarded only to gate the property `authorization.read` strip. It does
		// NOT gate the writeOnly strip (#460): `$query['_rbac']` is false for an ADMIN here,
		// and an admin is not exempt from the writeOnly render boundary (#389).
		$renderHandler = \OC::$server->get(\OCA\OpenRegister\Service\Object\RenderObject::class);
		$renderHandler->redactWriteOnlyFromRows(rows: $results, _rbac: $query['_rbac'] ?? true);

		// Serialize results.
		$serializedResults = [];
		foreach ($results as $entity) {
			$serializedResults[] = $entity->jsonSerialize();
		}

		// Strip empty values from results unless _empty=true is set.
		$params = $this->request->getParams();
		$includeEmpty = filter_var(
			value: $params['_empty'] ?? false,
			filter: FILTER_VALIDATE_BOOLEAN
		);
		if ($includeEmpty === false) {
			$serializedResults = array_map(
				callback: [$this, 'stripEmptyValues'],
				array: $serializedResults
			);
		}

		// Calculate pagination.
		$limit = (int)($query['_limit'] ?? 20);
		$offset = (int)($query['_offset'] ?? 0);

		// PERF-6: the per-table merge can return up to limit×tableCount rows. Slice
		// down to the requested page window so the response honours _limit/_offset
		// instead of returning every fetched row.
		// NOTE: an exact cross-table total still requires a per-table COUNT(*) in SQL
		// (MagicMapper, out of this controller's scope); $fetchedCount is the best
		// available bound here. TODO(PERF-6): sum per-table COUNT(*) in MagicMapper.
		$fetchedCount = count($serializedResults);
		if ($limit > 0) {
			$serializedResults = array_slice($serializedResults, $offset, $limit);
		} elseif ($offset > 0) {
			$serializedResults = array_slice($serializedResults, $offset);
		}

		// PERF-10: allow callers to skip the (here, in-PHP) total when not needed.
		$wantTotal = filter_var($params['_count'] ?? true, FILTER_VALIDATE_BOOLEAN);
		if ($wantTotal === true) {
			$total = $fetchedCount;
		} else {
			$total = null;
		}

		$pages = 1;
		$page = 1;
		if ($limit > 0) {
			if ($total !== null) {
				$pages = (int)ceil($total / $limit);
			}

			$page = (int)floor($offset / $limit) + 1;
		}

		return new JSONResponse(
			data: [
				'results' => $serializedResults,
				'total' => $total,
				'pages' => $pages,
				'page' => $page,
				'limit' => $limit,
				'@self' => [
					'source' => 'cross_table_magic_mapper',
					'table_count' => count($pairs),
					'register_count' => count($registers),
					'schema_count' => count($schemas),
				],
			]
		);
	}//end crossTableSearch()

	/**
	 * Resolve register and schema IDs from slugs or IDs.
	 *
	 * @param string $register Register ID or slug.
	 * @param string $schema Schema ID or slug.
	 * @param ObjectService $objectService Object service for resolution.
	 *
	 * @return array Resolved register and schema information.
	 *
	 * @throws RegisterNotFoundException When register is not found.
	 * @throws SchemaNotFoundException When schema is not found.
	 */
	private function resolveRegisterSchemaIds(string $register, string $schema, ObjectService $objectService): array {
		try {
			// STEP 1: Initial resolution - convert slugs/IDs to numeric IDs.
			$objectService->setRegister(register: $register);
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			// If register not found, throw custom exception.
			throw new RegisterNotFoundException(registerSlugOrId: $register, code: 404, previous: $e);
		}

		try {
			$objectService->setSchema(schema: $schema);
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			// If schema not found, throw custom exception.
			throw new SchemaNotFoundException(schemaSlugOrId: $schema, code: 404, previous: $e);
		}

		// STEP 2: Get resolved numeric IDs.
		$resolvedRegisterId = $objectService->getRegister();
		$resolvedSchemaId = $objectService->getSchema();

		// STEP 3: Reuse the entities already resolved by setRegister()/setSchema()
		// above — re-fetching them via the mappers would resolve the same
		// register and schema twice per request.
		return [
			'register' => $resolvedRegisterId,
			'schema' => $resolvedSchemaId,
			'registerEntity' => $objectService->getCurrentRegisterEntity(),
			'schemaEntity' => $objectService->getCurrentSchemaEntity(),
		];
	}//end resolveRegisterSchemaIds()

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
	 * - Aggregations: _aggregations (enable aggregations in response - SOLR only)
	 * - Debug: _debug (enable debug information in response - SOLR only)
	 * - Source: _source (force search source: 'database' or 'index'/'solr')
	 * - Sorting: _order
	 * - Empty values: _empty (boolean, default: false). When false (default), null, empty string,
	 *   and empty array values are stripped from the response to reduce payload size.
	 *   Set _empty=true to include all properties including empty values.
	 *
	 * @param string $register The register slug or identifier
	 * @param string $schema The schema slug or identifier
	 * @param ObjectService $objectService The object service
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
	public function index(string $register, string $schema, ObjectService $objectService): JSONResponse {
		// Read paths were unmeasured: WritePhaseProbe::flush() is only reached
		// from the write path, so search and single-read reported no
		// per-request counts at all. Stamp + flush here so the same in-PHP
		// counters that made the write numbers trustworthy cover reads too.
		\OCA\OpenRegister\Service\WritePhaseProbe::stamp('ctrl.index.in');

		// Check if multiple schemas are requested via query parameters.
		$params = $this->request->getParams();
		$schemasParam = $params['schemas'] ?? null;
		$registersParam = $params['registers'] ?? null;

		// Parse schemas: support both array format (schemas[]=1&schemas[]=2) and comma-separated (schemas=1,2,3).
		// Only parse if explicitly set; don't use URL path schema as default for multi-value.
		$schemasList = [];
		if ($schemasParam !== null) {
			$schemasList = $this->parseMultiValue(param: $schemasParam, defaultValue: $schema);
		}

		// Parse registers: same logic.
		$registersList = [];
		if ($registersParam !== null) {
			$registersList = $this->parseMultiValue(param: $registersParam, defaultValue: $register);
		}

		// If multiple schemas or registers are specified via parameters, use cross-table search.
		if ((count($schemasList) > 1) || (count($registersList) > 1)) {
			// Use schema list if specified, otherwise use URL path schema.
			$finalSchemas = [$schema];
			if (empty($schemasList) === false) {
				$finalSchemas = $schemasList;
			}

			$finalRegisters = [$register];
			if (empty($registersList) === false) {
				$finalRegisters = $registersList;
			}

			return $this->crossTableSearch(
				registers: $finalRegisters,
				schemas: $finalSchemas,
				objectService: $objectService
			);
		}

		// Single schema/register: use existing logic.
		try {
			// Resolve slugs to numeric IDs consistently (validation only).
			$resolved = $this->resolveRegisterSchemaIds(register: $register, schema: $schema, objectService: $objectService);
		} catch (RegisterNotFoundException|SchemaNotFoundException $e) {
			// Return 404 with clear error message if register or schema not found.
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: 404);
		}

		// Extract filtering parameters from request.
		$params = $this->request->getParams();
		// SEC-CTRL-1: RBAC and multitenancy posture MUST be derived from the
		// caller's admin status, never from request parameters. This endpoint is
		// reachable anonymously (@PublicPage); honouring ?rbac=false&_multi=false
		// would let any caller list every object across all organisations/ACLs.
		$isAdmin = $this->isCurrentUserAdmin();
		$rbac = ($isAdmin === false);
		$multi = ($isAdmin === false);
		// No longer request-controlled: never treat _multi as explicitly set by the client.
		$multiExplicitlySet = false;
		$deleted = filter_var($params['deleted'] ?? false, FILTER_VALIDATE_BOOLEAN);

		// Check if magic mapping is enabled for this register+schema.
		$registerEntity = $resolved['registerEntity'] ?? null;
		$schemaEntity = $resolved['schemaEntity'] ?? null;

		if ($registerEntity !== null && $schemaEntity !== null) {
			// Check if this specific schema is magic-mapped using Register method.
			// This supports both new format {"schemas": {"module": {"magicMapping": true}}}.
			// and legacy format {"enableMagicMapping": true, "magicMappingSchemas": [...]}.
			$isMagicMapped = $registerEntity->isMagicMappingEnabledForSchema(
				schemaId: $schemaEntity->getId(),
				schemaSlug: $schemaEntity->getSlug()
			);

			// A schema served from an external object-source (x-openregister-object-source)
			// must never read the magic table — fall through to searchObjectsPaginated,
			// which delegates to the registered provider (object-source-providers).
			if ($isMagicMapped === true && $schemaEntity->getObjectSource() === null) {
				// Use MagicMapper for magic-mapped schemas.
				$magicMapper = \OC::$server->get(\OCA\OpenRegister\Db\MagicMapper::class);

				// Build search query with resolved numeric IDs.
				$query = $objectService->buildSearchQuery(
					requestParams: $this->request->getParams(),
					register: $resolved['register'],
					schema: $resolved['schema']
				);

				// Pass RBAC and multitenancy settings to the query.
				$query['_rbac'] = $rbac;
				$query['_multitenancy'] = $multi;
				// Track if _multi was explicitly set - used by public schema bypass logic.
				$query['_multitenancy_explicit'] = $multiExplicitlySet;

				// Use MagicMapper search directly.
				$results = $magicMapper->searchObjectsInRegisterSchemaTable(
					query: $query,
					register: $registerEntity,
					schema: $schemaEntity
				);

				// Extract rendering parameters from query.
				$extend = $query['_extend'] ?? [];
				if (is_string($extend) === true) {
					$extend = array_filter(array_map('trim', explode(',', $extend)));
				}

				// Remove schema and register extensions - we provide them at response level.
				$extend = array_filter(
					$extend,
					function (string $item): bool {
						return !in_array($item, ['@self.schema', '@self.register', '_schema', '_register'], true);
					}
				);

				$hasComplexRendering = empty($extend) === false
					|| empty($query['_fields'] ?? null) === false
					|| empty($query['_filter'] ?? null) === false
					|| empty($query['_unset'] ?? null) === false;

				// Apply complex rendering if needed (extensions, fields, filters).
				if ($hasComplexRendering === true && is_array($results) === true && empty($results) === false) {
					$renderHandler = \OC::$server->get(\OCA\OpenRegister\Service\Object\RenderObject::class);
					$serializedResults = $renderHandler->renderEntities(
						entities: $results,
						_extend: $extend,
						_filter: $query['_filter'] ?? null,
						_fields: $query['_fields'] ?? null,
						_unset: $query['_unset'] ?? null,
						_rbac: $rbac,
						_multitenancy: $multi
					);
				} else {
					// Convert ObjectEntity array to JSON-serializable format (no complex
					// rendering). This fast path bypasses renderEntity — where write-only
					// secrets are stripped (openregister#380, ocon#147) — so without the
					// redaction call below it returns every write-only field in cleartext.
					// This is the exact path the OpenConnector Source leak used: a plain
					// list read (no _extend) hit this branch. Redact the raw entities with
					// the same read-strip renderEntity applies, then serialize.
					// `$rbac` is `($isAdmin === false)` and gates ONLY the property
					// `authorization.read` strip — writeOnly strips unconditionally, admin
					// included (#389/#460).
					$renderHandler = \OC::$server->get(\OCA\OpenRegister\Service\Object\RenderObject::class);
					$renderHandler->redactWriteOnlyFromRows(rows: $results, _rbac: $rbac);

					$serializedResults = [];
					foreach ($results as $entity) {
						$serializedResults[] = $entity->jsonSerialize();
					}
				}//end if

				// Calculate pagination - need a separate count query since search applies limit/offset.
				$limit = (int)($query['_limit'] ?? 20);
				$offset = $query['_offset'] ?? null;
				$page = $query['_page'] ?? null;

				// Convert page to offset if page is provided but offset is not.
				if ($page !== null && $offset === null && $limit > 0) {
					$offset = ((int)$page - 1) * $limit;
				} else {
					$offset = (int)($offset ?? 0);
				}

				// PERF-10: allow callers to skip the extra COUNT(*) query when they
				// don't need the grand total (e.g. infinite scroll). _count=false /
				// _noTotal=true returns total:null and skips the count round-trip.
				if (($params['_noTotal'] ?? false) === true) {
					$countDefault = false;
				} else {
					$countDefault = true;
				}

				$wantTotal = filter_var(
					$params['_count'] ?? $countDefault,
					FILTER_VALIDATE_BOOLEAN
				);

				$total = null;
				if ($wantTotal === true) {
					// Build count query (same filters, no pagination).
					$countQuery = $query;
					unset($countQuery['_limit'], $countQuery['_offset'], $countQuery['_page']);

					// Get actual total count.
					$total = $magicMapper->countObjectsInRegisterSchemaTable(
						query: $countQuery,
						register: $registerEntity,
						schema: $schemaEntity
					);
				}

				$pages = 1;
				if ($limit > 0) {
					if ($total !== null) {
						$pages = (int)ceil($total / $limit);
					}

					// Calculate page from offset if not explicitly provided.
					if ($page === null) {
						$page = (int)floor($offset / $limit) + 1;
					} else {
						$page = (int)$page;
					}
				} else {
					$page = 1;
				}

				// Get active organisation for debugging metadata.
				$activeOrganisation = null;
				try {
					$organisationService = \OC::$server->get(\OCA\OpenRegister\Service\OrganisationService::class);
					$activeOrg = $organisationService?->getActiveOrganisation();
					$activeOrganisation = $activeOrg?->getUuid();
				} catch (\Throwable $e) {
					// Silently ignore if organisation service is not available.
				}

				// Build response data.
				$ignoredFilters = $magicMapper->getIgnoredFilters();
				$responseData = [
					'results' => $serializedResults,
					'total' => $total,
					'pages' => $pages,
					'page' => $page,
					'limit' => $limit,
					'@self' => [
						'source' => 'magic_mapper',
						'register' => $register,
						'schema' => $schema,
						'query' => $query,
						'rbac' => $rbac,
						'multi' => $multi,
						'deleted' => $deleted,
						'activeOrganisation' => $activeOrganisation,
					],
				];

				// Add ignored filters and developer hint if applicable.
				if (empty($ignoredFilters) === false) {
					$responseData['@self']['ignoredFilters'] = $ignoredFilters;

					$controlParams = [
						'limit',
						'offset',
						'page',
						'order',
						'sort',
						'search',
						'extend',
						'fields',
						'filter',
						'unset',
					];
					$mistakenParams = array_intersect($ignoredFilters, $controlParams);
					if (empty($mistakenParams) === false) {
						$suggestions = array_map(fn ($param) => "_{$param}", $mistakenParams);
						$hint = 'Query returned 0 results because ';
						$hint .= implode(', ', $mistakenParams);
						$hint .= ' was treated as an object property filter.';
						$hint .= ' Did you mean ';
						$hint .= implode(', ', $suggestions);
						$hint .= '? Control parameters require an';
						$hint .= ' underscore prefix (e.g. _limit, _offset, _page).';
						$responseData['@self']['hint'] = $hint;
					}
				}//end if

				// Add facets if requested via _facets parameter.
				// Use MagicMapper's facet method for magic-mapped tables.
				if (empty($query['_facets']) === false) {
					try {
						$facets = $magicMapper->getSimpleFacetsFromRegisterSchemaTable(
							query: $query,
							register: $registerEntity,
							schema: $schemaEntity
						);
						if (empty($facets) === false) {
							$responseData['facets'] = $facets;
						}
					} catch (\Exception $e) {
						// Log error in @self for debugging.
						$responseData['@self']['facet_error'] = $e->getMessage();
					}
				}

				// Strip empty values from results unless _empty=true is set.
				// This reduces response payload by omitting null/empty properties.
				$includeEmpty = filter_var(
					value: $query['_empty'] ?? $params['_empty'] ?? false,
					filter: FILTER_VALIDATE_BOOLEAN
				);
				if ($includeEmpty === false) {
					$responseData['results'] = array_map(
						callback: function ($item) {
							// Serialize ObjectEntity instances to arrays before stripping empty values.
							// renderEntities() returns ObjectEntity objects when _extend is used.
							if (is_array($item) === false
								&& method_exists(object_or_class: $item, method: 'jsonSerialize') === true
							) {
								$item = $item->jsonSerialize();
							}

							if (is_array($item) === true) {
								return $this->stripEmptyValues(data: $item);
							}

							return $item;
						},
						array: $responseData['results']
					);
				}

				// Spatial post-filter on the magic-mapped result before
				// returning. Mirrors the hook on the non-magic-mapped
				// path so geo filtering works for both register layouts.
				$responseData = $this->applyGeoQueryFilters(params: $params, result: $responseData);

				// Content negotiation: JSON-LD @graph for magic-mapped results
				// (json-ld-output).
				if ($this->wantsJsonLd() === true) {
					return $this->jsonLdCollectionResponse(
						result: $responseData,
						register: $registerEntity,
						schema: $schemaEntity
					);
				}

				// Return in expected format. Response compression is negotiated
				// at the webserver level; the controller never sets encoding headers.
				return new JSONResponse(data: $responseData);
			}//end if
		}//end if

		// Build search query with resolved numeric IDs.
		$query = $objectService->buildSearchQuery(
			requestParams: $this->request->getParams(),
			register: $resolved['register'],
			schema: $resolved['schema']
		);

		// **INTELLIGENT SOURCE SELECTION**: ObjectService automatically chooses optimal source.
		try {
			$result = $objectService->searchObjectsPaginated(
				query: $query,
				_rbac: $rbac,
				_multitenancy: $multi,
				deleted: $deleted
			);
		} catch (NotAuthorizedException $exception) {
			// RBAC denied the schema-level list read (raised by the
			// object-source parity check before the provider is consulted).
			// Mirror show(): 404, not 403/500, so denial reveals nothing
			// about the schema's contents.
			return new JSONResponse(data: ['error' => 'Not Found'], statusCode: 404);
		}

		// Strip empty values from results unless _empty=true is set.
		$includeEmpty = filter_var(
			value: $params['_empty'] ?? false,
			filter: FILTER_VALIDATE_BOOLEAN
		);
		if ($includeEmpty === false && isset($result['results']) === true && is_array($result['results']) === true) {
			$result['results'] = array_map(
				callback: function ($item) {
					// Serialize ObjectEntity instances to arrays before stripping.
					if (is_array($item) === false
						&& method_exists(object_or_class: $item, method: 'jsonSerialize') === true
					) {
						$item = $item->jsonSerialize();
					}

					if (is_array($item) === true) {
						return $this->stripEmptyValues(data: $item);
					}

					return $item;
				},
				array: $result['results']
			);
		}

		// Spatial post-filter: when ?geo.bbox= / ?geo.near=&geo.radius=
		// (or ?geo.property=) is set, parse the params via GeoFilterParser
		// and apply the filters to $result['results']. Pure-PHP fallback;
		// PostGIS push-down is tracked in `geo-spatial-queries`.
		$result = $this->applyGeoQueryFilters(params: $params, result: $result);

		// Content negotiation: emit a JSON-LD @graph when requested. Wraps the
		// already-rendered/RBAC-filtered result — no second data path
		// (json-ld-output).
		if ($this->wantsJsonLd() === true
			&& ($resolved['registerEntity'] ?? null) !== null
			&& ($resolved['schemaEntity'] ?? null) !== null
		) {
			return $this->jsonLdCollectionResponse(
				result: $result,
				register: $resolved['registerEntity'],
				schema: $resolved['schemaEntity']
			);
		}

		// Response compression is negotiated at the webserver level; the
		// controller never sets encoding headers.
		return new JSONResponse(data: $result);
	}//end index()

	/**
	 * Batched object-count endpoint — POST /api/objects/counts.
	 *
	 * Accepts a JSON body of the shape
	 * `{ "counts": [ { "register": <id|slug>, "schema": <id|slug>, "filter": <object?> } ] }`
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
	 * @param ObjectService $objectService The object service (DI).
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
	public function counts(ObjectService $objectService): JSONResponse {
		$params = $this->request->getParams();
		$entries = ($params['counts'] ?? null);

		// Missing counts key → empty success (spec: empty or missing → { results: [] }).
		if ($entries === null) {
			return new JSONResponse(data: ['results' => []]);
		}

		// A present-but-non-array counts value is a malformed request.
		if (is_array($entries) === false) {
			return new JSONResponse(
				data: ['error' => 'counts must be an array of { register, schema, filter? } entries'],
				statusCode: 400
			);
		}

		// Empty array → empty success.
		if ($entries === []) {
			return new JSONResponse(data: ['results' => []]);
		}

		// Derive the RBAC + multitenancy posture from the caller's admin
		// status — identical to the collection read (index() lines 1160-1162).
		// Never honour request-supplied rbac/multi flags on a data endpoint.
		$isAdmin = $this->isCurrentUserAdmin();
		$rbac = ($isAdmin === false);
		$multi = ($isAdmin === false);

		// Validate every entry up-front so a malformed batch is rejected
		// wholesale rather than silently skipping entries.
		foreach ($entries as $index => $entry) {
			if (is_array($entry) === false
				|| isset($entry['register']) === false
				|| isset($entry['schema']) === false
				|| is_scalar($entry['register']) === false
				|| is_scalar($entry['schema']) === false
				|| (isset($entry['filter']) === true && is_array($entry['filter']) === false)
			) {
				return new JSONResponse(
					data: [
						'error' => 'Malformed counts entry at index ' . $index
							. ': register and schema are required scalars and filter must be an object',
					],
					statusCode: 400
				);
			}
		}

		// Dedupe identical (register, schema, filter) triples: run one
		// aggregate per distinct triple, but return one result per input
		// entry in request order (duplicates share the deduped count).
		$cache = [];
		$results = [];
		foreach ($entries as $entry) {
			$register = (string)$entry['register'];
			$schema = (string)$entry['schema'];
			$filter = ($entry['filter'] ?? []);

			$cacheKey = $register . '|' . $schema . '|' . json_encode($filter);
			if (array_key_exists($cacheKey, $cache) === false) {
				$cache[$cacheKey] = $this->countPairScoped(
					register: $register,
					schema: $schema,
					filter: $filter,
					rbac: $rbac,
					multi: $multi,
					objectService: $objectService
				);
			}

			$results[] = [
				'register' => $entry['register'],
				'schema' => $entry['schema'],
				'count' => $cache[$cacheKey],
			];
		}//end foreach

		return new JSONResponse(data: ['results' => $results]);
	}//end counts()

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
	 * @param string $register Register id or slug.
	 * @param string $schema Schema id or slug.
	 * @param array $filter Object-property filters for this entry.
	 * @param bool $rbac Whether to apply RBAC (parity with the list read).
	 * @param bool $multi Whether to apply multitenancy (parity with the list read).
	 * @param ObjectService $objectService The object service (DI).
	 *
	 * @return int|null The scoped count, or null when the pair is withheld.
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	private function countPairScoped(
		string $register,
		string $schema,
		array $filter,
		bool $rbac,
		bool $multi,
		ObjectService $objectService,
	): ?int {
		try {
			// Resolve slugs/ids to numeric ids + entities (same as index()).
			$resolved = $this->resolveRegisterSchemaIds(
				register: $register,
				schema: $schema,
				objectService: $objectService
			);
		} catch (RegisterNotFoundException|SchemaNotFoundException $e) {
			// Withhold: never disclose whether the pair is missing or restricted.
			return null;
		}

		$registerEntity = ($resolved['registerEntity'] ?? null);
		$schemaEntity = ($resolved['schemaEntity'] ?? null);

		// Build the search query from the per-entry filter using the same
		// builder index() uses, scoped to the resolved numeric ids.
		$query = $objectService->buildSearchQuery(
			requestParams: $filter,
			register: $resolved['register'],
			schema: $resolved['schema']
		);

		// Magic-mapped parity: count via the magic table with RBAC /
		// multitenancy threaded into the query (index() lines 1194-1198, 1279-1283).
		if ($registerEntity !== null && $schemaEntity !== null) {
			$isMagicMapped = $registerEntity->isMagicMappingEnabledForSchema(
				schemaId: $schemaEntity->getId(),
				schemaSlug: $schemaEntity->getSlug()
			);

			if ($isMagicMapped === true && $schemaEntity->getObjectSource() === null) {
				$magicMapper = \OC::$server->get(\OCA\OpenRegister\Db\MagicMapper::class);

				$countQuery = $query;
				unset($countQuery['_limit'], $countQuery['_offset'], $countQuery['_page']);
				$countQuery['_rbac'] = $rbac;
				$countQuery['_multitenancy'] = $multi;
				$countQuery['_multitenancy_explicit'] = false;

				return (int)$magicMapper->countObjectsInRegisterSchemaTable(
					query: $countQuery,
					register: $registerEntity,
					schema: $schemaEntity
				);
			}
		}//end if

		// Database-backed parity: read the paginated total at _limit=1, which
		// is produced by the same RBAC/multitenancy-scoped query the
		// collection read runs (index() lines 1443-1448).
		$query['_limit'] = 1;
		unset($query['_offset'], $query['_page']);

		try {
			$result = $objectService->searchObjectsPaginated(
				query: $query,
				_rbac: $rbac,
				_multitenancy: $multi,
				deleted: false
			);
		} catch (NotAuthorizedException $exception) {
			// Schema-level read denied (object-source parity check):
			// the count is simply unavailable to this caller.
			return null;
		}

		$total = ($result['total'] ?? null);
		if ($total === null) {
			return null;
		}

		return (int)$total;
	}//end countPairScoped()

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
	 * @param ObjectService $objectService Object service via DI.
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
	public function geoSearch(string $register, string $schema, ObjectService $objectService): JSONResponse {
		if ($this->geoFilterParser === null || $this->geoFilterApplier === null) {
			return new JSONResponse(
				data: ['error' => 'Geo filtering primitives not configured'],
				statusCode: 501
			);
		}

		$body = $this->request->getParams();
		try {
			$filters = $this->geoFilterParser->fromGeoSearchBody(body: $body);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 422);
		}

		// Reuse the standard listing path, then post-filter.
		$listing = $this->index(register: $register, schema: $schema, objectService: $objectService);
		$payload = (array)$listing->getData();
		$rows = ($payload['results'] ?? []);
		if (is_array($rows) === false) {
			return $listing;
		}

		$filtered = $this->geoFilterApplier->applyAll(rows: $rows, filters: $filters);
		$payload['results'] = $filtered;
		if (isset($payload['total']) === true) {
			$payload['total'] = count($filtered);
		}

		return new JSONResponse(data: $payload);
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
	 * @param ObjectService $objectService Object service via DI.
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
	public function geoJson(string $register, string $schema, ObjectService $objectService): JSONResponse {
		if ($this->geoFeatureBuilder === null) {
			return new JSONResponse(data: ['error' => 'Geo feature builder not configured'], statusCode: 501);
		}

		$params = $this->request->getParams();
		$rows = $this->scopedGeoRows(register: $register, schema: $schema, objectService: $objectService);
		$fields = $this->parseFieldsParam(params: $params);
		$geoProp = ($params['geo.property'] ?? null);

		if ($geoProp !== null) {
			$geoPropertyValue = (string)$geoProp;
		} else {
			$geoPropertyValue = null;
		}

		$collection = $this->geoFeatureBuilder->buildFeatureCollection(
			rows: $rows,
			geoProperty: $geoPropertyValue,
			fields: $fields,
			includeArea: true
		);

		// Body is a valid GeoJSON FeatureCollection. The default
		// application/json content type is kept (NC's Content-Type
		// override requires the full container); the @self envelope is
		// omitted so the body is consumable directly by GIS clients.
		return new JSONResponse(data: $collection);
	}//end geoJson()

	/**
	 * WFS GetFeature-compatible endpoint (REQ-GEO-008).
	 *
	 * Returns a GeoJSON FeatureCollection compatible with WFS 2.0
	 * `outputFormat=application/json`. Supports `count`/`maxFeatures`
	 * caps. Reuses the RBAC-scoped listing path (no new IDOR surface).
	 *
	 * @param string $register Register slug or id.
	 * @param string $schema Schema slug or id.
	 * @param ObjectService $objectService Object service via DI.
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
	public function wfs(string $register, string $schema, ObjectService $objectService): JSONResponse {
		if ($this->geoFeatureBuilder === null) {
			return new JSONResponse(data: ['error' => 'Geo feature builder not configured'], statusCode: 501);
		}

		$params = $this->request->getParams();
		$rows = $this->scopedGeoRows(register: $register, schema: $schema, objectService: $objectService);

		$maxFeatures = null;
		$rawMax = ($params['count'] ?? ($params['maxFeatures'] ?? null));
		if ($rawMax !== null && is_numeric($rawMax) === true) {
			$maxFeatures = (int)$rawMax;
		}

		$geoProp = ($params['geo.property'] ?? null);

		if ($geoProp !== null) {
			$geoPropertyValue = (string)$geoProp;
		} else {
			$geoPropertyValue = null;
		}

		$response = $this->geoFeatureBuilder->buildWfsResponse(
			rows: $rows,
			geoProperty: $geoPropertyValue,
			maxFeatures: $maxFeatures
		);

		return new JSONResponse(data: $response);
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
		if ($this->pdokGeocoder === null) {
			return new JSONResponse(data: ['available' => false, 'suggestions' => []]);
		}

		$params = $this->request->getParams();
		$available = $this->pdokGeocoder->isAvailable();

		$query = ($params['q'] ?? null);
		if ($query !== null && trim((string)$query) !== '') {
			$bagOnly = filter_var(($params['bagOnly'] ?? false), FILTER_VALIDATE_BOOLEAN);
			$suggestions = $this->pdokGeocoder->geocodeFree(
				query: (string)$query,
				maxItems: 5,
				bagOnly: $bagOnly
			);
			return new JSONResponse(data: ['available' => $available, 'suggestions' => $suggestions]);
		}

		$lon = ($params['lon'] ?? null);
		$lat = ($params['lat'] ?? null);
		if (is_numeric($lon) === true && is_numeric($lat) === true) {
			$address = $this->pdokGeocoder->reverseGeocode(longitude: (float)$lon, latitude: (float)$lat);
			return new JSONResponse(data: ['available' => $available, 'address' => $address]);
		}

		return new JSONResponse(
			data: ['error' => 'geocode requires either ?q=<address> or ?lon=&lat='],
			statusCode: 422
		);

	}//end geocode()

	/**
	 * Fetch the RBAC-scoped listing rows for a register/schema.
	 *
	 * Delegates to the standard `index()` listing — which enforces
	 * per-object read access — then returns just the `results`. Geo
	 * endpoints build on this so they can never widen access.
	 *
	 * @param string $register Register slug or id.
	 * @param string $schema Schema slug or id.
	 * @param ObjectService $objectService Object service via DI.
	 *
	 * @return array The RBAC-scoped result rows.
	 *
	 * @spec openspec/specs/geo-metadata-kaart/spec.md REQ-GEO-008
	 */
	private function scopedGeoRows(string $register, string $schema, ObjectService $objectService): array {
		$listing = $this->index(register: $register, schema: $schema, objectService: $objectService);
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
		if ($this->geoFilterParser === null || $this->geoFilterApplier === null) {
			return $result;
		}

		// NC's IRequest parses `geo.bbox` etc. as a nested array
		// (`geo: {bbox: ...}`). Re-flatten back to the dotted form the
		// parser expects, so callers using either notation work.
		$flatParams = $this->flattenGeoParams(params: $params);

		$hasGeoParam = false;
		foreach (['geo.bbox', 'geo.near', 'geo.radius', 'geo.property'] as $key) {
			if (isset($flatParams[$key]) === true) {
				$hasGeoParam = true;
				break;
			}
		}

		if ($hasGeoParam === false) {
			return $result;
		}

		try {
			$filters = $this->geoFilterParser->fromQueryParams(params: $flatParams);
		} catch (\InvalidArgumentException $e) {
			// Malformed input: surface a 422-shape envelope inside the
			// existing result body so consumers see the validation error
			// without crashing the whole listing path.
			return [
				'error' => 'geo filter parse error: ' . $e->getMessage(),
				'results' => [],
				'total' => 0,
			];
		}

		if ($filters === [] || isset($result['results']) === false || is_array($result['results']) === false) {
			return $result;
		}

		$rows = $result['results'];
		$filtered = $this->geoFilterApplier->applyAll(rows: $rows, filters: $filters);
		$result['results'] = $filtered;
		// Update `total` to reflect the post-filter count when present.
		if (isset($result['total']) === true) {
			$result['total'] = count($filtered);
		}

		return $result;
	}//end applyGeoQueryFilters()

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
	 * - Aggregations: _aggregations (enable aggregations in response - SOLR only)
	 * - Debug: _debug (enable debug information in response - SOLR only)
	 * - Source: _source (force search source: 'database' or 'index'/'solr')
	 * - Sorting: _order
	 * - Empty values: _empty (boolean, default: false). When false (default), null, empty string,
	 *   and empty array values are stripped from the response to reduce payload size.
	 *   Set _empty=true to include all properties including empty values.
	 *
	 * @param ObjectService $objectService The object service
	 *
	 * @return JSONResponse A JSON response containing the list of objects with optional facets and facetable fields
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @PublicPage
	 *
	 * @psalm-return JSONResponse<200, array<string, mixed>, array<never, never>>
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Cross-table search + multi-schema routing requires branching
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	#[UserRateLimit(limit: 600, period: 60)]
	#[AnonRateLimit(limit: 120, period: 60)]
	public function objects(ObjectService $objectService): JSONResponse {
		// Check for register/schema in query parameters for magic mapper routing.
		$params = $this->request->getParams();
		$registerParam = $params['register'] ?? $params['_register'] ?? null;
		$schemaParam = $params['schema'] ?? $params['_schema'] ?? null;
		$schemasParam = $params['schemas'] ?? null;
		$registersParam = $params['registers'] ?? null;

		// If multiple schemas or registers specified, use cross-table search.
		$schemasList = [];
		$registersList = [];

		if ($schemasParam !== null) {
			$schemasList = $this->parseMultiValue(param: $schemasParam, defaultValue: $schemaParam ?? '');
		} elseif ($schemaParam !== null) {
			$schemasList = [$schemaParam];
		}

		if ($registersParam !== null) {
			$registersList = $this->parseMultiValue(param: $registersParam, defaultValue: $registerParam ?? '');
		} elseif ($registerParam !== null) {
			$registersList = [$registerParam];
		}

		// Multi-table search: multiple schemas or registers.
		if ((count($schemasList) > 1) || (count($registersList) > 1)) {
			return $this->crossTableSearch(
				registers: $registersList,
				schemas: $schemasList,
				objectService: $objectService
			);
		}

		// Single register+schema: resolve slugs/IDs to numeric IDs (same
		// resolution semantics as the path-style index() route) and check
		// whether magic mapping is enabled. The resolved numeric IDs are
		// captured in $resolvedRegisterId/$resolvedSchemaId so the fallback
		// buildSearchQuery() call below can filter correctly instead of
		// silently matching zero rows against an unresolved slug string.
		$resolvedRegisterId = null;
		$resolvedSchemaId = null;
		if ($registerParam !== null && $schemaParam !== null) {
			try {
				$resolved = $this->resolveRegisterSchemaIds(
					register: $registerParam,
					schema: $schemaParam,
					objectService: $objectService
				);

				$resolvedRegisterId = $resolved['register'];
				$resolvedSchemaId = $resolved['schema'];

				// Check if magic mapping is enabled for this register+schema.
				$registerEntity = $resolved['registerEntity'] ?? null;
				$schemaEntity = $resolved['schemaEntity'] ?? null;

				if ($registerEntity !== null && $schemaEntity !== null) {
					// Get register configuration.
					$registerConfig = $registerEntity->getConfiguration() ?? [];
					$enableMagicMapping = ($registerConfig['enableMagicMapping'] ?? false) === true;
					$magicMappingSchemas = $registerConfig['magicMappingSchemas'] ?? [];
					$schemaId = (string)$schemaEntity->getId();
					$schemaSlug = $schemaEntity->getSlug();

					// Check if this specific schema is magic-mapped.
					if ($enableMagicMapping === true
						&& (in_array($schemaId, $magicMappingSchemas, true) === true
						|| in_array($schemaSlug, $magicMappingSchemas, true) === true)
					) {
						// Use MagicMapper for magic-mapped schemas.
						$magicMapper = \OC::$server->get(\OCA\OpenRegister\Db\MagicMapper::class);

						// Build search query with resolved numeric IDs.
						$query = $objectService->buildSearchQuery(
							requestParams: $this->request->getParams(),
							register: $resolved['register'],
							schema: $resolved['schema']
						);

						// Use MagicMapper search directly.
						$results = $magicMapper->searchObjectsInRegisterSchemaTable(
							query: $query,
							register: $registerEntity,
							schema: $schemaEntity
						);

						// Redact write-only secrets before serialising (openregister#380,
						// ocon#147) — this direct-magic-mapper path bypasses renderEntity.
						// `_rbac` (false for an admin) gates only the property
						// `authorization.read` strip; writeOnly strips unconditionally (#460).
						$renderHandler = \OC::$server->get(\OCA\OpenRegister\Service\Object\RenderObject::class);
						$renderHandler->redactWriteOnlyFromRows(rows: $results, _rbac: $query['_rbac'] ?? true);

						// Convert ObjectEntity array to JSON-serializable format.
						$serializedResults = [];
						foreach ($results as $entity) {
							$serializedResults[] = $entity->jsonSerialize();
						}

						// Calculate pagination.
						$limit = $query['_limit'] ?? 20;
						$offset = $query['_offset'] ?? 0;
						$total = count($serializedResults);
						$pages = 1;
						$page = 1;
						if ($limit > 0) {
							$pages = (int)ceil($total / $limit);
							$page = (int)floor($offset / $limit) + 1;
						}

						// Strip empty values from results unless _empty=true is set.
						$includeEmpty = filter_var(
							value: $params['_empty'] ?? false,
							filter: FILTER_VALIDATE_BOOLEAN
						);
						if ($includeEmpty === false) {
							$serializedResults = array_map(
								callback: [$this, 'stripEmptyValues'],
								array: $serializedResults
							);
						}

						// Return in expected format with magic_mapper source indicator.
						return new JSONResponse(
							data: [
								'results' => $serializedResults,
								'total' => $total,
								'pages' => $pages,
								'page' => $page,
								'limit' => $limit,
								'@self' => [
									'source' => 'magic_mapper',
									'register' => $registerParam,
									'schema' => $schemaParam,
								],
							]
						);
					}//end if
				}//end if
			} catch (RegisterNotFoundException|SchemaNotFoundException $e) {
				return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: 404);
			}//end try
		}//end if

		// Build search query and execute via normal route (magic tables or SOLR).
		// Pass the already-resolved numeric register/schema IDs (when a
		// register/schema query parameter was supplied) so the query filters
		// on '@self.register' / '@self.schema' the same way the path-style
		// index() route does. Without this, buildSearchQuery() falls back to
		// treating the raw 'register'/'schema' query-string values (slugs)
		// as the metadata filter, which never matches the numeric register
		// ID column and silently returns zero results.
		$query = $objectService->buildSearchQuery(
			requestParams: $this->request->getParams(),
			register: $resolvedRegisterId,
			schema: $resolvedSchemaId
		);

		// **INTELLIGENT SOURCE SELECTION**: ObjectService automatically chooses optimal source.
		try {
			$result = $objectService->searchObjectsPaginated($query);
		} catch (NotAuthorizedException $exception) {
			// Schema-level list read denied (object-source parity check).
			// Mirror show(): 404 so denial reveals nothing.
			return new JSONResponse(data: ['error' => 'Not Found'], statusCode: 404);
		}

		// Strip empty values from results unless _empty=true is set.
		$includeEmpty = filter_var(
			value: $params['_empty'] ?? false,
			filter: FILTER_VALIDATE_BOOLEAN
		);
		if ($includeEmpty === false && isset($result['results']) === true && is_array($result['results']) === true) {
			$result['results'] = array_map(
				callback: function ($item) {
					// Serialize ObjectEntity instances to arrays before stripping.
					if (is_array($item) === false
						&& method_exists(object_or_class: $item, method: 'jsonSerialize') === true
					) {
						$item = $item->jsonSerialize();
					}

					if (is_array($item) === true) {
						return $this->stripEmptyValues(data: $item);
					}

					return $item;
				},
				array: $result['results']
			);
		}

		return new JSONResponse(data: $result);
	}//end objects()

	/**
	 * Shows a specific object from a register and schema
	 *
	 * Retrieves and returns a single object from the specified register and schema,
	 * with support for field filtering and related object extension.
	 *
	 * @param string $id The object ID
	 * @param string $register The register slug or identifier
	 * @param string $schema The schema slug or identifier
	 * @param ObjectService $objectService The object service
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
	 */
	#[UserRateLimit(limit: 600, period: 60)]
	#[AnonRateLimit(limit: 120, period: 60)]
	public function show(
		string $id,
		string $register,
		string $schema,
		ObjectService $objectService,
	): JSONResponse {
		\OCA\OpenRegister\Service\WritePhaseProbe::stamp('ctrl.show.in');

		try {
			// Resolve slugs to numeric IDs consistently and get register/schema entities.
			$resolved = $this->resolveRegisterSchemaIds(register: $register, schema: $schema, objectService: $objectService);
		} catch (RegisterNotFoundException|SchemaNotFoundException $e) {
			// Return 404 with clear error message if register or schema not found.
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: 404);
		}

		// Get request parameters for filtering and searching.
		$requestParams = $this->request->getParams();

		// Extract parameters for rendering.
		$extend = ($requestParams['extend'] ?? $requestParams['_extend'] ?? null);
		$filter = ($requestParams['filter'] ?? $requestParams['_filter'] ?? null);
		$fields = ($requestParams['fields'] ?? $requestParams['_fields'] ?? null);
		$unset = ($requestParams['unset'] ?? $requestParams['_unset'] ?? null);

		// Normalize extend parameter for backwards compatibility (@self.schema -> _schema).
		$extend = $this->normalizeExtendParameter(extend: $extend);

		// Convert fields to array if it's a string.
		if (is_string($fields) === true) {
			$fields = explode(',', $fields);
		}

		// Convert filter to array if it's a string.
		if (is_string($filter) === true) {
			$filter = explode(',', $filter);
		}

		// Convert unset to array if it's a string.
		if (is_string($unset) === true) {
			$unset = explode(',', $unset);
		}

		// Determine RBAC and multitenancy settings based on admin status.
		$isAdmin = $this->isCurrentUserAdmin();
		$rbac = $isAdmin === false;
		// If admin, disable RBAC.
		$multi = $isAdmin === false;
		// If admin, disable multitenancy.
		// Find and validate the object. Rendering is deferred to the single
		// renderEntity() call below (`_render: false`): find() previously
		// rendered the entity with the same $extend and the controller then
		// rendered it AGAIN, repeating file hydration, writeOnly redaction and
		// the expensive inverse-property resolution on every single read.
		// Permission checks and read logging inside find() still run.
		try {
			$objectEntity = $this->objectService->find(
				id: $id,
				_extend: $extend,
				files: false,
				register: $register,
				schema: $schema,
				_rbac: $rbac,
				_multitenancy: $multi,
				_render: false
			);
			if ($objectEntity === null) {
				$errorMsg = "Object with id {$id} not found";
				return new JSONResponse(data: ['error' => $errorMsg], statusCode: Http::STATUS_NOT_FOUND);
			}

			// Render the object with requested extensions, filters, fields, and unset parameters.
			// This is the ONLY render on the show() response path: writeOnly
			// redaction and read-authorization stripping (openregister#385/#386)
			// are applied here, exactly once, inside renderEntity().
			$renderedObject = $this->objectService->renderEntity(
				entity: $objectEntity,
				_extend: $extend,
				depth: 0,
				filter: $filter,
				fields: $fields,
				unset: $unset,
				_rbac: $rbac,
				_multitenancy: $multi
			);

			// Add registers, schemas, and extended objects to @self for single object responses.
			// Only include when explicitly requested via _extend parameter.
			// Supports both singular (_register, _schema) and plural (_registers, _schemas) forms.
			// Note: renderEntity returns an array (already serialized), not an ObjectEntity.
			$renderedData = $renderedObject;
			if (isset($renderedData['@self']) === true) {
				$extendArray = [];
				if (is_array($extend) === true) {
					$extendArray = $extend;
				}

				// Add registers if _registers or _register is in _extend.
				if (in_array('_registers', $extendArray, true) === true
					|| in_array('_register', $extendArray, true) === true
				) {
					$registerId = $resolved['register'];
					$registers = [];
					if ($resolved['registerEntity'] !== null) {
						$registers[$registerId] = $resolved['registerEntity']->jsonSerialize();
					}

					$renderedData['@self']['registers'] = $registers;
				}

				// Add schemas if _schemas or _schema is in _extend.
				if (in_array('_schemas', $extendArray, true) === true
					|| in_array('_schema', $extendArray, true) === true
				) {
					$schemaId = $resolved['schema'];
					$schemas = [];
					if ($resolved['schemaEntity'] !== null) {
						$schemas[$schemaId] = $resolved['schemaEntity']->jsonSerialize();
					}

					$renderedData['@self']['schemas'] = $schemas;
				}

				// Get extended objects indexed by UUID (for _extend lookups).
				// Always include objects if any _extend is requested.
				if (empty($extendArray) === false) {
					$extendedObjects = $objectService->getExtendedObjects();
					$renderedData['@self']['objects'] = $extendedObjects;
				}

				// Add names mapping if _names is in _extend.
				// This provides UUID-to-name mappings for all related objects,
				// reducing frontend calls to the names service.
				if (in_array('_names', $extendArray, true) === true) {
					$renderedData['@self']['names'] = $this->collectNamesForResponse(
						renderedData: $renderedData,
						cacheHandler: $objectService->getCacheHandler()
					);
				}
			}//end if

			// Strip empty values from response unless _empty=true is set.
			$includeEmpty = filter_var(
				value: $requestParams['_empty'] ?? false,
				filter: FILTER_VALIDATE_BOOLEAN
			);
			if ($includeEmpty === false) {
				$renderedData = $this->stripEmptyValues(data: $renderedData);
			}

			// Content negotiation: emit JSON-LD when requested. The serializer
			// wraps the already-rendered array — no second data path — so all
			// access control above remains applied (json-ld-output).
			if ($this->wantsJsonLd() === true
				&& $resolved['registerEntity'] !== null
				&& $resolved['schemaEntity'] !== null
			) {
				return $this->jsonLdObjectResponse(
					renderedObject: $renderedData,
					register: $resolved['registerEntity'],
					schema: $resolved['schemaEntity']
				);
			}

			return new JSONResponse(data: $renderedData);
		} catch (DoesNotExistException $exception) {
			return new JSONResponse(data: ['error' => 'Not Found'], statusCode: 404);
		} catch (NotAuthorizedException $exception) {
			// RBAC denied the read. Return 404 (not 500, and not 403) so an
			// unauthorized caller cannot distinguish "exists but forbidden"
			// from "does not exist" — avoids leaking object existence while
			// still denying access. Previously this exception escaped the
			// handler and surfaced as an HTTP 500.
			return new JSONResponse(data: ['error' => 'Not Found'], statusCode: 404);
		}//end try
	}//end show()

	/**
	 * Creates a new object in the specified register and schema
	 *
	 * Takes the request data, validates it against the schema, and creates a new object
	 * in the database. Handles validation errors appropriately.
	 *
	 * @param string $register The register slug or identifier
	 * @param string $schema The schema slug or identifier
	 * @param ObjectService $objectService The object service
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
	public function create(
		string $register,
		string $schema,
		ObjectService $objectService,
	): JSONResponse {
		\OCA\OpenRegister\Service\WritePhaseProbe::stamp('ctrl.create.in');
		try {
			// Resolve slugs to numeric IDs consistently.
			$resolved = $this->resolveRegisterSchemaIds(register: $register, schema: $schema, objectService: $objectService);
		} catch (RegisterNotFoundException|SchemaNotFoundException $e) {
			// Return 404 with clear error message if register or schema not found.
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: 404);
		}

		// Intercept request and send to webhooks before processing.
		// This allows external systems to validate, transform, or enrich the request.
		$object = $this->request->getParams();
		if ($this->webhookService !== null) {
			try {
				$object = $this->webhookService->interceptRequest(
					request: $this->request,
					eventType: 'object.creating'
				);
			} catch (Exception $e) {
				// Log error but continue with original request if webhook fails.
				// This ensures webhook failures don't break the API.
				if ($this->logger !== null) {
					$this->logger->error(
						message: '[ObjectsController] Webhook interception failed',
						context: [
							'file' => __FILE__,
							'line' => __LINE__,
							'error' => $e->getMessage(),
							'register' => $register,
							'schema' => $schema,
						]
					);
				}
			}//end try
		}//end if

		// Filter out special parameters and reserved fields.
		// @todo shouldn't this be part of the object service?
		// Allow @self metadata to pass through for organization activation.
		$object = array_filter(
			$object,
			fn ($key) => str_starts_with($key, '_') === false
				&& !($key !== '@self' && str_starts_with($key, '@'))
				&& in_array($key, ['uuid', 'register', 'schema']) === false,
			ARRAY_FILTER_USE_KEY
		);

		// Normalize multipart/form-data: decode JSON-encoded strings back into arrays/objects.
		$object = $this->normalizeFormDataValues(data: $object);

		// Defense-in-depth (wave-11 WF2): strip server-managed @self fields so they
		// cannot be injected via the single-object create path.
		$object = $this->sanitiseSelfMetadata(data: $object);

		// Extract uploaded files from multipart/form-data using Request object.
		$uploadedFiles = $this->extractAllUploadedFiles();

		// INSERT-ONLY opt-in (openregister#2210). `_failIfExists=true` turns this
		// create from an upsert into a strict insert: an identifier that is
		// already taken returns 409 instead of silently overwriting.
		//
		// Read from the raw request, not from $object — the body filter above
		// strips `_`-prefixed keys, which is exactly the convention for control
		// parameters that must not be persisted onto the object itself.
		$failIfExists = filter_var(
			$this->request->getParam('_failIfExists', false),
			FILTER_VALIDATE_BOOLEAN
		);

		// Determine RBAC and multitenancy settings based on admin status.
		$isAdmin = $this->isCurrentUserAdmin();
		$rbac = !$isAdmin;
		// If admin, disable RBAC.
		// Note: multitenancy is disabled for admins via $rbac flag.
		// Determine uploaded files value.
		$uploadedFilesValue = null;
		if (empty($uploadedFiles) === false) {
			$uploadedFilesValue = $uploadedFiles;
		}

		// Save the object.
		try {
			// Clear sub-objects cache before saving to ensure clean state.
			$objectService->clearCreatedSubObjects();

			// Use the object service to validate and save the object.
			// Use resolved numeric IDs instead of slugs.
			$objectToSave = $object;
			$objectEntity = $objectService->saveObject(
				object: $objectToSave,
				register: $resolved['register'],
				schema: $resolved['schema'],
				_rbac: $rbac,
				_multitenancy: true,
				uuid: null,
				uploadedFiles: $uploadedFilesValue,
				failIfExists: $failIfExists
			);

			// TODO: Unlock the object after saving using LockingHandler through ObjectService.
			// The unlockObject() method on the old ObjectEntityMapper is deprecated.
			// For now, skipping unlock to allow CRUD operations to complete.
		} catch (TranslationTargetConflictException $exception) {
			// Structured 400 per the exception's own documented contract
			// (i18n-api-language-negotiation): a language-keyed body for a
			// translatable property collided with X-Translation-Target-Language.
			// Emitted here so the { "error": { "code": ... } } shape survives
			// instead of being flattened by the generic validation handler.
			return new JSONResponse(data: $exception->toErrorBody(), statusCode: 400);
		} catch (ValidationException|CustomValidationException $exception) {
			// Handle validation errors.
			return new JSONResponse(data: $exception->getMessage(), statusCode: 400);
		} catch (\OCA\OpenRegister\Exception\HookStoppedException $exception) {
			// Handle hook rejection — return 422 with validation errors from the workflow.
			return new JSONResponse(
				data: [
					'error' => $exception->getMessage(),
					'errors' => $exception->getErrors(),
				],
				statusCode: 422
			);
		} catch (FolderAccessDeniedException $exception) {
			// MUST be caught before generic \Exception to avoid being absorbed as a 403 with
			// a non-structured body. See the `self-folder-access-control` capability spec.
			return $this->folderAccessDeniedResponse(exception: $exception);
		} catch (\OCA\OpenRegister\Exception\ObjectExistsException $exception) {
			// MUST be caught before the generic \Exception below, which flattens
			// everything to 403. A losing claim reported as "forbidden" is
			// indistinguishable from a permissions problem, and the caller cannot
			// tell it simply lost a race — which is the entire point of asking
			// for insert-only semantics.
			return new JSONResponse(
				data: [
					'error' => $exception->getMessage(),
					'uuid' => $exception->getUuid(),
				],
				statusCode: 409
			);
		} catch (\Exception $exception) {
			// Handle all other exceptions (including RBAC permission errors).
			// Sanitized external-write failures carry their own 4xx status
			// (dbal-virtual-registers-crud) — never flatten them to 403.
			if ($exception instanceof \OCA\OpenRegister\Service\ObjectSource\DbalWriteException === true) {
				return new JSONResponse(data: ['error' => $exception->getMessage()], statusCode: $exception->getStatusCode());
			}

			return new JSONResponse(data: ['error' => $exception->getMessage()], statusCode: 403);
		}//end try

		// Return the created object.
		// Note: Sub-objects are only returned when _extend is explicitly requested on GET.
		return new JSONResponse(data: $objectEntity->jsonSerialize(), statusCode: 201);
	}//end create()

	/**
	 * Updates an existing object
	 *
	 * Takes the request data, persist: validates it against the schema, silent: and updates an existing object
	 * in the database. Handles validation errors appropriately.
	 *
	 * @param string $register The register slug or identifier
	 * @param string $schema The schema slug or identifier
	 * @param string $id The object ID or UUID
	 * @param ObjectService $objectService The object service
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
	public function update(
		string $register,
		string $schema,
		string $id,
		ObjectService $objectService,
	): JSONResponse {
		\OCA\OpenRegister\Service\WritePhaseProbe::stamp('ctrl.update.in');

		try {
			// Resolve slugs to numeric IDs consistently.
			$resolved = $this->resolveRegisterSchemaIds(register: $register, schema: $schema, objectService: $objectService);
		} catch (RegisterNotFoundException|SchemaNotFoundException $e) {
			// Return 404 with clear error message if register or schema not found.
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: 404);
		}

		// Get object data from request parameters.
		$object = $this->request->getParams();

		// Filter out special parameters and reserved fields.
		// @todo shouldn't this be part of the object service?
		// Allow @self metadata to pass through for organization activation.
		$object = array_filter(
			$object,
			fn ($key) => str_starts_with($key, '_') === false
				&& !($key !== '@self' && str_starts_with($key, '@'))
				&& in_array($key, ['uuid', 'register', 'schema']) === false,
			ARRAY_FILTER_USE_KEY
		);

		// Normalize multipart/form-data: decode JSON-encoded strings back into arrays/objects.
		$object = $this->normalizeFormDataValues(data: $object);

		// Defense-in-depth (wave-11 WF2): strip server-managed @self fields.
		$object = $this->sanitiseSelfMetadata(data: $object);

		// Extract uploaded files from multipart/form-data using Request object.
		$uploadedFiles = $this->extractAllUploadedFiles();

		// Determine RBAC and multitenancy settings based on admin status.
		$isAdmin = $this->isCurrentUserAdmin();
		$rbac = $isAdmin === false;
		// If admin, disable RBAC.
		$multi = $isAdmin === false;
		// If admin, disable multitenancy.
		// Check if the object exists and can be updated (silent read - no audit trail).
		// @todo shouldn't this be part of the object service?
		try {
			// Scope the lookup to the register and schema the URL named. Passing
			// null here made this an UNSCOPED lookup, which resolves a UUID by
			// UNION-ing every magic table on the instance (2,728 of them on the
			// development instance) — the single largest cost in an update, and
			// pure waste: the check immediately below asserts the object is in
			// this exact register and schema anyway. A UUID belonging to another
			// scope now raises DoesNotExistException and returns the same 404 it
			// would have got from that check.
			$existingObject = $this->objectService->findSilent(
				id: $id,
				_extend: [],
				files: false,
				register: $resolved['register'],
				schema: $resolved['schema'],
				_rbac: $rbac,
				_multitenancy: $multi
			);

			// Get the resolved register and schema IDs from the ObjectService.
			// This ensures proper handling of both numeric IDs and slug identifiers.
			$resolvedRegisterId = $objectService->getRegister();
			// Returns the current register ID.
			$resolvedSchemaId = $objectService->getSchema();
			// Returns the current schema ID.
			// Verify that the object belongs to the specified register and schema.
			if ((int)$existingObject->getRegister() !== $resolvedRegisterId
				|| (int)$existingObject->getSchema() !== $resolvedSchemaId
			) {
				return new JSONResponse(data: ['error' => 'Object not found in specified register/schema'], statusCode: 404);
			}

			// Check if the object is locked.
			if ($existingObject->isLocked() === true
				&& $existingObject->getLockedBy() !== $this->container->get('userId')
			) {
				// Return a "locked" error with the user who has the lock.
				return new JSONResponse(
					data: [
						'error' => 'Object is locked by ' . $existingObject->getLockedBy(),
						'lockedBy' => $existingObject->getLockedBy(),
					],
					statusCode: 423
				);
			}
		} catch (DoesNotExistException $exception) {
			return new JSONResponse(data: ['error' => 'Not Found'], statusCode: 404);
		} catch (NotAuthorizedException $exception) {
			// Handle RBAC permission errors specifically.
			return new JSONResponse(data: ['error' => $exception->getMessage()], statusCode: 403);
		} catch (\Exception $exception) {
			// Log unexpected exceptions for debugging.
			$this->logger->error(
				message: '[ObjectsController] Unexpected exception in update findSilent',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'exception' => $exception->getMessage(),
					'trace' => $exception->getTraceAsString(),
				]
			);
			// SEC-CTRL-7: do not leak internal exception detail on 500.
			return $this->errorResponse(e: $exception);
		} catch (NotFoundExceptionInterface|ContainerExceptionInterface $e) {
			// If there's an issue getting the user ID, continue without the lock check.
		}//end try

		// Determine uploaded files value.
		$uploadedFilesValue = null;
		if (empty($uploadedFiles) === false) {
			$uploadedFilesValue = $uploadedFiles;
		}

		// Update the object.
		try {
			// Use the object service to validate and update the object.
			$objectEntity = $objectService->saveObject(
				register: $resolved['register'],
				schema: $resolved['schema'],
				object: $object,
				_rbac: $rbac,
				_multitenancy: $multi,
				uuid: $id,
				uploadedFiles: $uploadedFilesValue
			);

			// Unlock the object after saving — but only if it is actually locked.
			//
			// unlock() must resolve the identifier back to its register/schema
			// before it can do anything, and unscoped that is a scan across every
			// magic table on the instance. It then returns immediately when the
			// object holds no lock (LockHandler::unlock, the openregister#195
			// idempotence branch), which is the normal case for this defensive
			// post-save unlock. We already hold the saved entity, so the "is it
			// locked" question is free here and the scan is pure waste — measured
			// at ~780 ms of a ~1.3 s update.
			try {
				if ($objectEntity->isLocked() === true) {
					$this->objectService->unlockObject($objectEntity->getUuid());
				}
			} catch (\Exception $e) {
				// Ignore unlock errors since the update was successful.
				// NOTE: must be the global \Exception — the unqualified `Exception`
				// resolves to OCP\DB\Exception here (see `use` block) and would NOT
				// catch the \Exception thrown by LockHandler::unlock(), which then
				// surfaced as a spurious 403. See openregister#195.
			}

			\OCA\OpenRegister\Service\WritePhaseProbe::stamp('ctrl.update.unlocked');

			// Return the successfully saved object directly.
			$body = $objectEntity->jsonSerialize();

			\OCA\OpenRegister\Service\WritePhaseProbe::stamp('ctrl.update.out');

			return new JSONResponse(data: $body);
		} catch (AppendOnlyException $exception) {
			// Reject update on append-only schema with HTTP 405.
			return new JSONResponse(data: $exception->toResponseBody(), statusCode: Http::STATUS_METHOD_NOT_ALLOWED);
		} catch (TranslationTargetConflictException $exception) {
			// Structured 400 per the exception's own documented contract
			// (i18n-api-language-negotiation): a language-keyed body for a
			// translatable property collided with X-Translation-Target-Language.
			// Emitted here so the { "error": { "code": ... } } shape survives
			// instead of being flattened by the generic validation handler.
			return new JSONResponse(data: $exception->toErrorBody(), statusCode: 400);
		} catch (ValidationException|CustomValidationException $exception) {
			// Handle validation errors.
			return $objectService->handleValidationException(exception: $exception);
		} catch (\OCA\OpenRegister\Exception\HookStoppedException $exception) {
			return new JSONResponse(
				data: ['error' => $exception->getMessage(), 'errors' => $exception->getErrors()],
				statusCode: 422
			);
		} catch (FolderAccessDeniedException $exception) {
			// MUST be caught before generic \Exception. See `self-folder-access-control` spec.
			return $this->folderAccessDeniedResponse(exception: $exception);
		} catch (\Exception $exception) {
			// Handle all other exceptions (including RBAC permission errors).
			// Sanitized external-write failures carry their own 4xx status
			// (dbal-virtual-registers-crud) — never flatten them to 403.
			if ($exception instanceof \OCA\OpenRegister\Service\ObjectSource\DbalWriteException === true) {
				return new JSONResponse(data: ['error' => $exception->getMessage()], statusCode: $exception->getStatusCode());
			}

			return new JSONResponse(data: ['error' => $exception->getMessage()], statusCode: 403);
		}//end try
	}//end update()

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
	 * @param ObjectService $objectService The object service
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
	public function patch(
		string $register,
		string $schema,
		string $id,
		ObjectService $objectService,
	): JSONResponse {
		try {
			// Resolve slugs to numeric IDs consistently.
			$resolved = $this->resolveRegisterSchemaIds(register: $register, schema: $schema, objectService: $objectService);
		} catch (RegisterNotFoundException|SchemaNotFoundException $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 404);
		}

		// Get patch data from request and filter parameters.
		$patchData = $this->request->getParams();

		// Filter out special parameters and reserved fields.
		$patchData = array_filter(
			$patchData,
			fn ($key) => str_starts_with($key, '_') === false
				&& !($key !== '@self' && str_starts_with($key, '@'))
				&& in_array($key, ['uuid', 'register', 'schema']) === false,
			ARRAY_FILTER_USE_KEY
		);

		// Normalize multipart/form-data: decode JSON-encoded strings back into arrays/objects.
		$patchData = $this->normalizeFormDataValues(data: $patchData);

		// Defense-in-depth (wave-11 WF2): strip server-managed @self fields.
		$patchData = $this->sanitiseSelfMetadata(data: $patchData);

		// Determine RBAC and multitenancy settings based on admin status.
		$isAdmin = $this->isCurrentUserAdmin();
		$rbac = $isAdmin === false;
		$multi = $isAdmin === false;

		// Log RBAC/multitenancy settings for debugging.
		$this->logger->debug(
			message: '[ObjectsController] PATCH: RBAC/Multitenancy settings',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'id' => $id,
				'isAdmin' => $isAdmin,
				'rbac' => $rbac,
				'multi' => $multi,
			]
		);

		// Initialize mergedData before conditional assignment.
		$mergedData = $patchData;

		// Check if the object exists and can be updated.
		// Skip the existence check - let saveObject handle validation.
		// This avoids multitenancy issues when trying to read back objects with invalid organisation UUIDs.
		$existingObject = null;

		// Update the object with merged data.
		try {
			// For PATCH, we need to merge with existing data.
			// Use findSilent to get the existing object without triggering audit trail.
			try {
				$existingObject = $this->objectService->findSilent(
					id: $id,
					_extend: [],
					files: false,
					register: $resolved['registerEntity'],
					schema: $resolved['schemaEntity'],
					// Always disable RBAC for internal read.
					_rbac: false,
					// Always disable multitenancy for internal read.
					_multitenancy: false
				);
			} catch (\Exception $e) {
				// If we can't find the object, return 404.
				$this->logger->warning(
					message: '[ObjectsController] Could not find object for PATCH',
					context: [
						'file' => __FILE__,
						'line' => __LINE__,
						'id' => $id,
						'exception' => $e->getMessage(),
					]
				);
				return new JSONResponse(data: ['error' => 'Object not found'], statusCode: 404);
			}//end try

			// Optimistic concurrency (fix-object-patch-lost-update): PATCH is a
			// read-merge-write, so two concurrent PATCHes can silently clobber each
			// other's untouched fields. A caller that read the object may pass the
			// `updated` value it saw as `_expectedUpdated` (If-Match semantics); if
			// the stored object changed since, the caller is working from stale data
			// and the write is rejected with 409 instead of overwriting the newer
			// version. Opt-in: callers that omit `_expectedUpdated` behave as before.
			// Read from the raw request: the patchData filter strips `_`-prefixed keys.
			$expectedUpdated = $this->request->getParam('_expectedUpdated');
			if ($expectedUpdated !== null) {
				$currentUpdated = $existingObject->getUpdated()?->format(\DateTimeInterface::ATOM);
				if ((string)$currentUpdated !== (string)$expectedUpdated) {
					return new JSONResponse(
						data: [
							'error' => 'Conflict: the object was modified since it was read. Re-read and retry.',
							'expectedUpdated' => (string)$expectedUpdated,
							'currentUpdated' => (string)$currentUpdated,
						],
						statusCode: 409
					);
				}
			}

			// Get the existing object data and merge with patch data.
			$existingData = $existingObject->getObject();
			$mergedData = array_merge($existingData ?? [], $patchData);
			// Use the object service to validate and update the object.
			$objectEntity = $objectService->saveObject(
				register: $resolved['register'],
				schema: $resolved['schema'],
				object: $mergedData,
				_rbac: $rbac,
				_multitenancy: $multi,
				uuid: $id
			);

			$this->logger->debug(
				message: '[ObjectsController] PATCH: saveObject succeeded',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'uuid' => $objectEntity->getUuid(),
					'status' => $objectEntity->getObject()['status'] ?? 'unknown',
				]
			);

			// Unlock the object after saving — but only if it is actually locked.
			//
			// unlock() must resolve the identifier back to its register/schema
			// before it can do anything, and unscoped that is a scan across every
			// magic table on the instance. It then returns immediately when the
			// object holds no lock (LockHandler::unlock, the openregister#195
			// idempotence branch), which is the normal case for this defensive
			// post-save unlock. We already hold the saved entity, so the "is it
			// locked" question is free here and the scan is pure waste — measured
			// at ~780 ms of a ~1.3 s update.
			try {
				if ($objectEntity->isLocked() === true) {
					$this->objectService->unlockObject($objectEntity->getUuid());
				}
			} catch (\Exception $e) {
				// Ignore unlock errors since the update was successful (e.g., magic table objects).
				$this->logger->debug(
					message: '[ObjectsController] Failed to unlock after patch',
					context: [
						'file' => __FILE__,
						'line' => __LINE__,
						'exception' => $e->getMessage(),
					]
				);
			}

			$this->logger->debug(
				message: '[ObjectsController] PATCH: Starting to prepare response',
				context: ['file' => __FILE__, 'line' => __LINE__]
			);

			// Return the successfully saved object directly.
			// We already have it in memory from saveObject(), no need to re-fetch.
			return new JSONResponse(data: $objectEntity->jsonSerialize());
		} catch (AppendOnlyException $exception) {
			// Reject patch on append-only schema with HTTP 405.
			return new JSONResponse(data: $exception->toResponseBody(), statusCode: Http::STATUS_METHOD_NOT_ALLOWED);
		} catch (TranslationTargetConflictException $exception) {
			// Structured 400 per the exception's own documented contract
			// (i18n-api-language-negotiation): a language-keyed body for a
			// translatable property collided with X-Translation-Target-Language.
			// Emitted here so the { "error": { "code": ... } } shape survives
			// instead of being flattened by the generic validation handler.
			return new JSONResponse(data: $exception->toErrorBody(), statusCode: 400);
		} catch (ValidationException|CustomValidationException $exception) {
			// Handle validation errors.
			$this->logger->warning(
				message: '[ObjectsController] Validation exception in patch',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'exception' => $exception->getMessage(),
				]
			);
			return $objectService->handleValidationException(exception: $exception);
		} catch (\OCA\OpenRegister\Exception\HookStoppedException $exception) {
			return new JSONResponse(
				data: ['error' => $exception->getMessage(), 'errors' => $exception->getErrors()],
				statusCode: 422
			);
		} catch (FolderAccessDeniedException $exception) {
			// MUST be caught before generic \Exception. See `self-folder-access-control` spec.
			return $this->folderAccessDeniedResponse(exception: $exception);
		} catch (\Exception $exception) {
			// Handle all other exceptions (including RBAC permission errors).
			$this->logger->error(
				message: '[ObjectsController] Unexpected exception in patch',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'exception' => $exception->getMessage(),
					'trace' => $exception->getTraceAsString(),
				]
			);
			// SEC-CTRL-7: do not leak internal exception detail on 500.
			return $this->errorResponse(e: $exception);
		}//end try
	}//end patch()

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
	 * @param ObjectService $objectService The object service
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
	public function postPatch(
		string $register,
		string $schema,
		string $id,
		ObjectService $objectService,
	): JSONResponse {
		try {
			$resolved = $this->resolveRegisterSchemaIds(register: $register, schema: $schema, objectService: $objectService);
		} catch (RegisterNotFoundException|SchemaNotFoundException $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 404);
		}

		// Get patch data from request and filter parameters.
		$patchData = $this->request->getParams();
		$patchData = array_filter(
			$patchData,
			fn ($key) => str_starts_with($key, '_') === false
				&& !($key !== '@self' && str_starts_with($key, '@'))
				&& in_array($key, ['uuid', 'register', 'schema', 'id']) === false,
			ARRAY_FILTER_USE_KEY
		);

		// Normalize multipart/form-data: decode JSON-encoded strings back into arrays/objects.
		$patchData = $this->normalizeFormDataValues(data: $patchData);

		// Defense-in-depth (wave-11 WF2): strip server-managed @self fields.
		$patchData = $this->sanitiseSelfMetadata(data: $patchData);

		// Extract uploaded files — works because this is a POST request.
		$uploadedFiles = $this->extractAllUploadedFiles();
		if (empty($uploadedFiles) === false) {
			$uploadedFilesValue = $uploadedFiles;
		} else {
			$uploadedFilesValue = null;
		}

		// Determine RBAC and multitenancy settings based on admin status.
		$isAdmin = $this->isCurrentUserAdmin();
		$rbac = $isAdmin === false;
		$multi = $isAdmin === false;

		try {
			// Find the existing object to merge with.
			try {
				$existingObject = $this->objectService->findSilent(
					id: $id,
					_extend: [],
					files: false,
					register: $resolved['registerEntity'],
					schema: $resolved['schemaEntity'],
					_rbac: false,
					_multitenancy: false
				);
			} catch (\Exception $e) {
				return new JSONResponse(data: ['error' => 'Object not found'], statusCode: 404);
			}

			// Merge existing data with patch data (patch semantics).
			$existingData = $existingObject->getObject();
			$mergedData = array_merge($existingData ?? [], $patchData);

			$objectService->clearCreatedSubObjects();

			$objectEntity = $objectService->saveObject(
				register: $resolved['register'],
				schema: $resolved['schema'],
				object: $mergedData,
				_rbac: $rbac,
				_multitenancy: $multi,
				uuid: $id,
				uploadedFiles: $uploadedFilesValue
			);

			// Unlock the object after saving — but only if it is actually locked.
			//
			// unlock() must resolve the identifier back to its register/schema
			// before it can do anything, and unscoped that is a scan across every
			// magic table on the instance. It then returns immediately when the
			// object holds no lock (LockHandler::unlock, the openregister#195
			// idempotence branch), which is the normal case for this defensive
			// post-save unlock. We already hold the saved entity, so the "is it
			// locked" question is free here and the scan is pure waste — measured
			// at ~780 ms of a ~1.3 s update.
			try {
				if ($objectEntity->isLocked() === true) {
					$this->objectService->unlockObject($objectEntity->getUuid());
				}
			} catch (\Exception $e) {
				// Ignore unlock errors since the update was successful.
			}

			return new JSONResponse(data: $objectEntity->jsonSerialize());
		} catch (AppendOnlyException $exception) {
			// Reject post-patch on append-only schema with HTTP 405.
			return new JSONResponse(data: $exception->toResponseBody(), statusCode: Http::STATUS_METHOD_NOT_ALLOWED);
		} catch (TranslationTargetConflictException $exception) {
			// Structured 400 per the exception's own documented contract
			// (i18n-api-language-negotiation): a language-keyed body for a
			// translatable property collided with X-Translation-Target-Language.
			// Emitted here so the { "error": { "code": ... } } shape survives
			// instead of being flattened by the generic validation handler.
			return new JSONResponse(data: $exception->toErrorBody(), statusCode: 400);
		} catch (ValidationException|CustomValidationException $exception) {
			return $objectService->handleValidationException(exception: $exception);
		} catch (\OCA\OpenRegister\Exception\HookStoppedException $exception) {
			return new JSONResponse(
				data: ['error' => $exception->getMessage(), 'errors' => $exception->getErrors()],
				statusCode: 422
			);
		} catch (FolderAccessDeniedException $exception) {
			// MUST be caught before generic \Exception so a @self.folder
			// denial on the post-patch path returns 403 with the structured
			// body (no folder-id oracle) — same contract as create/update/patch.
			// See the `self-folder-access-control` spec.
			return $this->folderAccessDeniedResponse(exception: $exception);
		} catch (\Exception $exception) {
			// SEC-CTRL-7: do not leak internal exception detail on 500.
			return $this->errorResponse(e: $exception);
		}//end try
	}//end postPatch()

	/**
	 * Deletes an object
	 *
	 * This method deletes an object based on its ID.
	 *
	 * @param string $id The ID/UUID of the object to delete
	 * @param string $register The register ID
	 * @param string $schema The schema ID
	 * @param ObjectService $objectService The object service
	 *
	 * @return JSONResponse JSON response with success or error.
	 *
	 * @throws Exception
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	#[UserRateLimit(limit: 300, period: 60)]
	#[AnonRateLimit(limit: 30, period: 60)]
	public function destroy(string $id, string $register, string $schema, ObjectService $objectService): JSONResponse {
		\OCA\OpenRegister\Service\WritePhaseProbe::stamp('ctrl.destroy.in');

		try {
			// Set the register and schema context for ObjectService.
			$objectService->setRegister(register: $register);
			$objectService->setSchema(schema: $schema);

			// Determine RBAC and multitenancy settings based on admin status.
			$isAdmin = $this->isCurrentUserAdmin();
			$rbac = !$isAdmin;
			// If admin, _rbac: disable RBAC.
			$multi = !$isAdmin;
			// If admin, _multitenancy: disable multitenancy.
			// Use ObjectService to delete the object (includes RBAC permission checks,
			// persist: audit trail, silent: and soft delete).
			//
			// Pass the scope EXPLICITLY. deleteObject() decides whether to scope
			// its lookup from its own arguments ($hasScope), not from the
			// register/schema set on the service above — so omitting them here
			// resolved the UUID by UNION-ing every magic table on the instance.
			// It also meant the URL's scope was never enforced: an object could
			// be deleted through a register/schema it does not belong to.
			$deleteResult = $objectService->deleteObject(
				uuid: $id,
				register: $register,
				schema: $schema,
				_rbac: $rbac,
				_multitenancy: $multi
			);

			if ($deleteResult === false) {
				// If delete operation failed, return error.
				return new JSONResponse(data: ['error' => 'Failed to delete object'], statusCode: 500);
			}

			// Return 204 No Content for successful delete (REST convention).
			return new JSONResponse(data: null, statusCode: 204);
		} catch (AppendOnlyException $exception) {
			// Reject delete on append-only schema with HTTP 405.
			return new JSONResponse(data: $exception->toResponseBody(), statusCode: Http::STATUS_METHOD_NOT_ALLOWED);
		} catch (ReferentialIntegrityException $exception) {
			return new JSONResponse(
				data: $exception->toResponseBody(),
				statusCode: 409
			);
		} catch (\OCA\OpenRegister\Exception\HookStoppedException $exception) {
			return new JSONResponse(
				data: ['error' => $exception->getMessage(), 'errors' => $exception->getErrors()],
				statusCode: 422
			);
		} catch (DoesNotExistException $exception) {
			// Absent objects (native or external) are a uniform 404.
			return new JSONResponse(data: ['error' => 'Not Found'], statusCode: 404);
		} catch (\Exception $exception) {
			// Handle all exceptions (including RBAC permission errors and object not found).
			// Sanitized external-write failures carry their own 4xx status
			// (dbal-virtual-registers-crud) — never flatten them to 403.
			if ($exception instanceof \OCA\OpenRegister\Service\ObjectSource\DbalWriteException === true) {
				return new JSONResponse(data: ['error' => $exception->getMessage()], statusCode: $exception->getStatusCode());
			}

			return new JSONResponse(data: ['error' => $exception->getMessage()], statusCode: 403);
		} catch (\Throwable $throwable) {
			// Safety net for fatal errors (\Error/\TypeError) that do NOT extend
			// \Exception and would otherwise escape as an HTML 500 fatal-error page
			// to API clients. Log the full trace and return a clean JSON 500 so the
			// contract is always machine-readable. \Throwable MUST be caught last so
			// the specific catches above keep their dedicated status codes.
			$this->logger?->error(
				message: '[ObjectsController] Unexpected fatal error while deleting object',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'register' => $register,
					'schema' => $schema,
					'id' => $id,
					'exception' => $throwable->getMessage(),
					'trace' => $throwable->getTraceAsString(),
				]
			);
			return new JSONResponse(
				data: ['error' => 'An unexpected error occurred while deleting the object'],
				statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}//end try
	}//end destroy()

	/**
	 * Check if an object can be deleted (pre-flight referential integrity analysis).
	 *
	 * Returns the full deletion analysis without performing any mutations.
	 *
	 * @param string $id The ID/UUID of the object to check
	 * @param string $register The register slug or identifier
	 * @param string $schema The schema slug or identifier
	 * @param ObjectService $objectService The object service
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
		ObjectService $objectService,
	): JSONResponse {
		try {
			$objectService->setRegister(register: $register);
			$objectService->setSchema(schema: $schema);

			$objectEntity = $objectService->find(
				id: $id,
				register: $register,
				schema: $schema,
				_rbac: false,
				_multitenancy: false
			);
			if ($objectEntity === null) {
				return new JSONResponse(data: ['error' => 'Object not found'], statusCode: 404);
			}

			$deleteHandler = $objectService->getDeleteHandler();
			$analysis = $deleteHandler->canDelete($objectEntity);

			return new JSONResponse(data: $analysis->toArray(), statusCode: 200);
		} catch (\OCP\AppFramework\Db\DoesNotExistException $exception) {
			return new JSONResponse(data: ['error' => 'Object not found'], statusCode: 404);
		} catch (\Exception $exception) {
			return new JSONResponse(data: ['error' => $exception->getMessage()], statusCode: 403);
		}//end try
	}//end canDelete()

	/**
	 * Retrieves call logs for a object
	 *
	 * This method returns all the call logs associated with a object based on its ID.
	 *
	 * @param string $id The ID/UUID of the object to retrieve logs for
	 * @param string $register The register slug or identifier
	 * @param string $schema The schema slug or identifier
	 * @param ObjectService $objectService The object service
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
	public function contracts(string $id, string $register, string $schema, ObjectService $objectService): JSONResponse {
		// Set the schema and register to the object service.
		$objectService->setSchema(schema: $schema);
		$objectService->setRegister(register: $register);

		// Get request parameters for filtering.
		$requestParams = $this->request->getParams();

		// Extract specific parameters.
		$limit = (int)($requestParams['limit'] ?? $requestParams['_limit'] ?? 20);

		// Determine offset value.
		$offset = null;
		if (isset($requestParams['_offset']) === true) {
			$offset = (int)$requestParams['_offset'];
		}

		if (isset($requestParams['offset']) === true) {
			$offset = (int)$requestParams['offset'];
		}

		// Determine page value.
		$page = null;
		if (isset($requestParams['_page']) === true) {
			$page = (int)$requestParams['_page'];
		}

		if (isset($requestParams['page']) === true) {
			$page = (int)$requestParams['page'];
		}

		// Build filters array.
		$filters = [
			'limit' => $limit,
			'offset' => $offset,
			'page' => $page,
		];

		// Use ObjectService delegation to handler.
		$result = $objectService->getObjectContracts(objectId: $id, filters: $filters);

		// Stamp resourceUrls (deep-links) before paginating.
		$result = $this->stampObjectUrls(result: $result);

		// Return empty paginated response.
		return new JSONResponse(
			data: $this->paginate(
				results: $result['results'] ?? [],
				total: $result['total'] ?? 0,
				limit: $limit,
				offset: $offset,
				page: $page
			)
		);
	}//end contracts()

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
		if ($this->deepLinkRegistry === null
			|| isset($result['results']) === false
			|| is_array($result['results']) === false
		) {
			return $result;
		}

		foreach ($result['results'] as $index => $record) {
			// Normalise entities to their serialized array form.
			if (is_object($record) === true && method_exists($record, 'jsonSerialize') === true) {
				$record = $record->jsonSerialize();
			}

			if (is_array($record) === false) {
				continue;
			}

			$self = ($record['@self'] ?? []);
			$uuid = ($self['id'] ?? ($record['id'] ?? null));
			$registerId = ($self['register'] ?? null);
			$schemaId = ($self['schema'] ?? null);

			if ($uuid === null || is_numeric($registerId) === false || is_numeric($schemaId) === false) {
				$result['results'][$index] = $record;
				continue;
			}

			try {
				$flat = array_merge(
					$record,
					[
						'uuid' => $uuid,
						'id' => $uuid,
						'register' => $registerId,
						'schema' => $schemaId,
					]
				);

				$url = $this->deepLinkRegistry->resolveUrl(
					registerId: (int)$registerId,
					schemaId: (int)$schemaId,
					objectData: $flat
				);

				if ($url === null && $this->relationUrlGenerator !== null) {
					$url = $this->relationUrlGenerator->linkToRoute(
						'openregister.objects.show',
						[
							'register' => $registerId,
							'schema' => $schemaId,
							'id' => $uuid,
						]
					);
				}

				if ($url !== null) {
					$record['url'] = $url;
				}
			} catch (\Throwable $e) {
				// Defensive: never let URL resolution break the relation response.
				$this->logger?->debug('Relation URL resolution failed: ' . $e->getMessage());
			}//end try

			$result['results'][$index] = $record;
		}//end foreach

		return $result;
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
	 * @param ObjectService $objectService The object service
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with related objects
	 *
	 * @psalm-return JSONResponse<200,
	 *     array{results: list<ObjectEntity>, total: int<0, max>,
	 *     limit: 30|mixed, offset: 0|mixed},
	 *     array<never, never>>
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	public function uses(string $id, string $register, string $schema, ObjectService $objectService): JSONResponse {
		// Set the register and schema context first.
		$objectService->setRegister(register: $register);
		$objectService->setSchema(schema: $schema);

		// Build search query from request parameters.
		$queryParams = $this->request->getParams();
		$searchQuery = $queryParams;

		// Clean up unwanted parameters.
		unset($searchQuery['id'], $searchQuery['_route']);

		// Use ObjectService delegation to handler.
		$result = $objectService->getObjectUses(
			objectId: $id,
			query: $searchQuery,
			_rbac: true,
			_multitenancy: true
		);

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
	 * @param ObjectService $objectService The object service
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with objects that use this object
	 *
	 * @psalm-return JSONResponse<200,
	 *     array{results: array<never, never>, total: 0, limit: 30|mixed,
	 *     offset: 0|mixed, message?: string},
	 *     array<never, never>>
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	public function used(string $id, string $register, string $schema, ObjectService $objectService): JSONResponse {
		// Set the schema and register to the object service.
		$objectService->setSchema(schema: $schema);
		$objectService->setRegister(register: $register);

		// Build search query from request parameters.
		$queryParams = $this->request->getParams();
		$searchQuery = $queryParams;

		// Clean up unwanted parameters.
		unset($searchQuery['id'], $searchQuery['_route']);

		// Use ObjectService delegation to handler.
		$result = $objectService->getObjectUsedBy(
			objectId: $id,
			query: $searchQuery,
			_rbac: true,
			_multitenancy: true
		);

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
	 * @param ObjectService $objectService The object service
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
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Audit log retrieval with pagination + access checks requires branching
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	public function logs(string $id, string $register, string $schema, ObjectService $objectService): JSONResponse {
		// Set the register and schema context first.
		$objectService->setRegister(register: $register);
		$objectService->setSchema(schema: $schema);

		// Try to fetch the object by ID/UUID only (no register/schema filter yet).
		try {
			$object = $objectService->find(id: $id);
			if ($object === null) {
				return new JSONResponse(data: ['message' => 'Object not found'], statusCode: 404);
			}
		} catch (Exception $e) {
			return new JSONResponse(data: ['message' => 'Object not found'], statusCode: 404);
		}

		// Normalize and compare register.
		$objectRegister = $object->getRegister();
		// Could be ID or slug.
		$objectSchema = $object->getSchema();
		// Could be ID, schema: slug, _extend: or array/object.
		// Normalize requested register.
		$requestedRegister = $register;
		$requestedSchema = $schema;

		// If objectSchema is an array/object, files: get slug and id.
		// Initialize before conditional assignment.
		$objectSchemaId = '';
		$objectSchemaSlug = null;
		if (is_array($objectSchema) === true && (($objectSchema['id'] ?? null) !== null)) {
			$objectSchemaId = (string)$objectSchema['id'];
			$objectSchemaSlug = null;
			if (isset($objectSchema['slug']) === true) {
				$objectSchemaSlug = strtolower($objectSchema['slug']);
			}
		}

		if (is_object($objectSchema) === true && (($objectSchema->id ?? null) !== null)) {
			$objectSchemaId = (string)$objectSchema->id;
			$objectSchemaSlug = null;
			if (isset($objectSchema->slug) === true) {
				$objectSchemaSlug = strtolower($objectSchema->slug);
			}
		}

		if (is_array($objectSchema) === false && is_object($objectSchema) === false) {
			$objectSchemaId = (string)$objectSchema;
		}

		// Normalize requested schema.
		$requestedSchemaNorm = strtolower($requestedSchema);
		$objectSchemaIdNorm = strtolower((string)$objectSchemaId);
		// $objectSchemaSlug is already lowercase from lines 1154/1157.
		$objectSchemaSlugNorm = $objectSchemaSlug;

		// Check schema match (by id or slug).
		$schemaMatch = (
			$requestedSchemaNorm === $objectSchemaIdNorm
			|| ($objectSchemaSlugNorm && $requestedSchemaNorm === $objectSchemaSlugNorm)
		);

		// Register normalization (string compare).
		$objectRegisterNorm = strtolower((string)$objectRegister);
		$reqRegisterNorm = strtolower($requestedRegister);
		$registerMatch = ($objectRegisterNorm === $reqRegisterNorm);

		if ($schemaMatch === false || $registerMatch === false) {
			$msg = 'Object does not belong to specified register/schema';
			return new JSONResponse(data: ['message' => $msg], statusCode: 404);
		}

		// Get config and fetch logs.
		$config = $this->getConfig(_register: $register, _schema: $schema);
		$logs = $objectService->getLogs(uuid: $id, filters: $config['filters']);

		// Get total count of logs.
		$total = count($logs);

		// Return paginated results.
		return new JSONResponse(
			data: $this->paginate(
				results: $logs,
				total: $total,
				limit: $config['limit'],
				offset: $config['offset'],
				page: $config['page']
			)
		);
	}//end logs()

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
			$this->objectService->setSchema(schema: $schema);
			$this->objectService->setRegister(register: $register);

			$data = $this->request->getParams();
			$process = ($data['process'] ?? null);
			// Check if duration is set in the request data.
			$duration = null;
			if (($data['duration'] ?? null) !== null) {
				$duration = (int)$data['duration'];
			}

			$lockResult = $this->objectService->lockObject(
				identifier: $id,
				process: $process,
				duration: $duration
			);

			// Return response with locked status for test compatibility.
			return new JSONResponse(data: array_merge($lockResult, ['locked' => true]));
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			return new JSONResponse(data: ['error' => 'Object not found'], statusCode: 404);
		} catch (\Throwable $e) {
			// SEC-CTRL-7: do not leak internal exception detail on 500.
			return $this->errorResponse(e: $e);
		}//end try
	}//end lock()

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
			$this->objectService->unlockObject($id);
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			return new JSONResponse(data: ['error' => 'Object not found'], statusCode: 404);
		} catch (\Exception $e) {
			$message = $e->getMessage();
			if (str_contains($message, 'does not have permission to unlock') === true) {
				return new JSONResponse(data: ['error' => $message], statusCode: 403);
			}

			return new JSONResponse(data: ['error' => $message], statusCode: 500);
		}

		// Return response with locked status for test compatibility.
		return new JSONResponse(
			data: [
				'message' => 'Object unlocked successfully',
				'locked' => false,
				'uuid' => $id,
			]
		);
	}//end unlock()

	/**
	 * Export objects to specified format
	 *
	 * @param string $register The register slug or identifier
	 * @param string $schema The schema slug or identifier
	 * @param ObjectService $objectService The object service
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
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 * @spec openspec/specs/export-pdf-format/spec.md
	 */
	public function export(string $register, string $schema, ObjectService $objectService): DataDownloadResponse|JSONResponse {
		// Set the register and schema context.
		$objectService->setRegister(register: $register);
		$objectService->setSchema(schema: $schema);

		// Get filters and type from request.
		$filters = $this->request->getParams();
		unset($filters['_route']);
		$type = $this->request->getParam(key: 'format') ?? $this->request->getParam(key: 'type', default: 'excel');

		// Get register and schema entities.
		// Bypass multi-tenancy since the user already has access via setRegister/setSchema above,
		// and this lookup is only needed for the export filename and metadata.
		$registerEntity = $this->registerMapper->find($register, _multitenancy: false);
		$schemaEntity = $this->schemaMapper->find($schema, _multitenancy: false);

		// Generate filename base.
		$filenameBase = sprintf(
			'%s_%s_%s',
			$registerEntity->getSlug() ?? 'register',
			$schemaEntity->getSlug() ?? 'schema',
			(new DateTime())->format('Y-m-d_His')
		);

		// Call ExportService directly (bypassing ObjectService which has circular dependency issues).
		if ($type === 'csv') {
			$content = $this->exportService->exportToCsv(
				register: $registerEntity,
				schema: $schemaEntity,
				filters: $filters,
				currentUser: $this->userSession->getUser()
			);

			return new DataDownloadResponse(
				data: $content,
				filename: "{$filenameBase}.csv",
				contentType: 'text/csv'
			);
		}

		if ($type === 'json') {
			$content = $this->exportService->exportToJson(
				register: $registerEntity,
				schema: $schemaEntity,
				filters: $filters
			);

			return new DataDownloadResponse(
				data: $content,
				filename: "{$filenameBase}.json",
				contentType: 'application/json'
			);
		}

		if ($type === 'pdf') {
			try {
				$content = $this->exportService->exportToPdf(
					register: $registerEntity,
					schema: $schemaEntity,
					filters: $filters,
					currentUser: $this->userSession->getUser()
				);
			} catch (ExportTooLargeException $e) {
				return new JSONResponse(
					data: [
						'error' => 'export_too_large',
						'message' => $e->getMessage(),
						'rowCount' => $e->getRowCount(),
						'maxRows' => $e->getMaxRows(),
					],
					statusCode: ExportTooLargeException::HTTP_STATUS
				);
			}

			return new DataDownloadResponse(
				data: $content,
				filename: "{$filenameBase}.pdf",
				contentType: 'application/pdf'
			);
		}//end if

		// Default to Excel.
		$spreadsheet = $this->exportService->exportToExcel(
			register: $registerEntity,
			schema: $schemaEntity,
			filters: $filters,
			currentUser: $this->userSession->getUser()
		);

		// Create Excel writer and get content.
		$writer = new Xlsx($spreadsheet);
		ob_start();
		$writer->save('php://output');
		$content = ob_get_clean();

		return new DataDownloadResponse(
			data: $content,
			filename: "{$filenameBase}.xlsx",
			contentType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
		);
	}//end export()

	/**
	 * Merge two objects
	 *
	 * This method merges object A into object B within the same register and schema.
	 * It handles merging of properties, files, and relations based on user preferences.
	 *
	 * @param string $id The ID of object A (source object to merge from)
	 * @param string $register The register slug or identifier
	 * @param string $schema The schema slug or identifier
	 * @param ObjectService $objectService The object service
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
		ObjectService $objectService,
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
			if (isset($requestParams['target']) === false) {
				return new JSONResponse(data: ['error' => 'Target object ID is required'], statusCode: 400);
			}

			if (($requestParams['object'] ?? null) === null || empty($requestParams['object']) === true) {
				return new JSONResponse(data: ['error' => 'Object data is required'], statusCode: 400);
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
	 * Migrate objects between registers and/or schemas
	 *
	 * This method migrates multiple objects from one register/schema combination
	 * to another register/schema combination with property mapping.
	 *
	 * @param ObjectService $objectService The object service
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with migration result or error
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	public function migrate(ObjectService $objectService): JSONResponse {
		try {
			// Get migration parameters from request.
			$requestParams = $this->request->getParams();
			$sourceRegister = $requestParams['sourceRegister'] ?? null;
			$sourceSchema = $requestParams['sourceSchema'] ?? null;
			$targetRegister = $requestParams['targetRegister'] ?? null;
			$targetSchema = $requestParams['targetSchema'] ?? null;
			$objectIds = $requestParams['objects'] ?? [];
			$mapping = $requestParams['mapping'] ?? [];

			// Validate required parameters.
			if ($sourceRegister === null || $sourceSchema === null) {
				return new JSONResponse(data: ['error' => 'Source register and schema are required'], statusCode: 400);
			}

			if ($targetRegister === null || $targetSchema === null) {
				return new JSONResponse(data: ['error' => 'Target register and schema are required'], statusCode: 400);
			}

			if (empty($objectIds) === true) {
				return new JSONResponse(data: ['error' => 'At least one object ID is required'], statusCode: 400);
			}

			if (empty($mapping) === true) {
				return new JSONResponse(data: ['error' => 'Property mapping is required'], statusCode: 400);
			}

			// Perform the migration operation.
			$migrationResult = $objectService->migrateObjects(
				sourceRegister: $sourceRegister,
				sourceSchema: $sourceSchema,
				targetRegister: $targetRegister,
				targetSchema: $targetSchema,
				objectIds: $objectIds,
				mapping: $mapping
			);

			return new JSONResponse(data: $migrationResult);
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
	 * Download all files of an object as a ZIP archive
	 *
	 * This method creates a ZIP file containing all files associated with a specific object
	 * and returns it as a downloadable file. The ZIP file includes all files stored in the
	 * object's folder with their original names.
	 *
	 * @param string $id The identifier of the object to download files for
	 * @param string $register The register (identifier or slug) to search within
	 * @param string $schema The schema (identifier or slug) to search within
	 * @param ObjectService $objectService The object service for handling object operations
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
		ObjectService $objectService,
	): JSONResponse|DataDownloadResponse {
		try {
			// Set the context for the object service.
			$objectService->setRegister(register: $register);
			$objectService->setSchema(schema: $schema);

			// Get the object to ensure it exists and we have access.
			$object = $objectService->find(id: $id);

			/*
			 * Get the FileService from the container.
			 * @var FileService $fileService
			 */

			$fileService = $this->container->get(FileService::class);

			// Optional: get custom filename from query parameters.
			$customFilename = $this->request->getParam(key: 'filename');

			// Create the ZIP archive.
			$zipInfo = $fileService->createObjectFilesZip(object: $object, zipName: $customFilename);

			// Read the ZIP file content.
			$zipContent = file_get_contents($zipInfo['path']);
			if ($zipContent === false) {
				// Clean up temporary file.
				if (file_exists($zipInfo['path']) === true) {
					unlink($zipInfo['path']);
				}

				throw new Exception('Failed to read ZIP file content');
			}

			// Clean up temporary file after reading.
			if (file_exists($zipInfo['path']) === true) {
				unlink($zipInfo['path']);
			}

			// Audit the bulk download as ONE entry tied to the parent object.
			// Best-effort: an audit-trail failure must not break the download.
			try {
				$files = $fileService->getFiles($object);
				$fileIds = [];
				$fileNames = [];
				foreach ($files as $f) {
					$fileIds[] = $f->getId();
					$fileNames[] = $f->getName();
				}

				$fileService->getAuditHandler()->logBulkDownload(
					$object,
					$fileIds,
					$fileNames,
					$zipInfo['filename'],
					$zipInfo['size'] ?? null
				);
			} catch (\Throwable $auditError) {
				// Silently swallow — audit-trail must never break the response.
			}

			// Return the ZIP file as a download response.
			return new DataDownloadResponse(
				$zipContent,
				$zipInfo['filename'],
				$zipInfo['mimeType']
			);
		} catch (DoesNotExistException $exception) {
			return new JSONResponse(data: ['error' => 'Object not found'], statusCode: 404);
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
			$result = $this->objectService->vectorizeBatchObjects(
				_views: $views,
				_batchSize: $batchSize
			);

			return new JSONResponse(
				data: [
					'success' => true,
					'data' => $result,
				]
			);
		} catch (Exception $e) {
			return new JSONResponse(
				data: [
					'success' => false,
					'error' => 'Internal server error',
				],
				statusCode: 500
			);
		}//end try
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

			return new JSONResponse(
				data: [
					'success' => true,
					'stats' => $stats,
				]
			);
		} catch (Exception $e) {
			return new JSONResponse(
				data: [
					'success' => false,
					'error' => 'Internal server error',
				],
				statusCode: 500
			);
		}//end try
	}//end getObjectVectorizationStats()

	/**
	 * Get count of objects for vectorization
	 *
	 * @return JSONResponse Object count
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @psalm-suppress NoValue
	 *
	 * @psalm-return JSONResponse<200|500, array{success: bool, error?: string, count?: mixed}, array<never, never>>
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	public function getObjectVectorizationCount(): JSONResponse {
		try {
			// Get schemas parameter if provided.
			$schemas = $this->request->getParam(key: 'schemas');
			if (is_string($schemas) === true) {
				$schemas = json_decode($schemas, true);
			}

			// Use ObjectService delegation to handler.
			$count = $this->objectService->getVectorizationCount(_schemas: $schemas);

			return new JSONResponse(
				data: [
					'success' => true,
					'count' => $count,
				]
			);
		} catch (Exception $e) {
			return new JSONResponse(
				data: [
					'success' => false,
					'error' => 'Internal server error',
				],
				statusCode: 500
			);
		}//end try
	}//end getObjectVectorizationCount()

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
			// Get request parameters.
			$register = $this->request->getParam(key: 'register');
			$schemaId = $this->request->getParam(key: 'schema');
			$limit = $this->request->getParam(key: 'limit');
			$offset = $this->request->getParam(key: 'offset');

			if ($register === null || $schemaId === null) {
				return new JSONResponse(
					data: [
						'success' => false,
						'error' => 'Register and schema parameters are required',
					],
					statusCode: 400
				);
			}

			// Parse limit/offset with sensible defaults for chunked processing.
			if ($limit !== null) {
				$limitInt = (int)$limit;
			} else {
				$limitInt = null;
			}

			if ($offset !== null) {
				$offsetInt = (int)$offset;
			} else {
				$offsetInt = 0;
			}

			$this->logger->info(
				message: '[ObjectsController] Starting bulk validation for schema',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'register' => $register,
					'schema' => $schemaId,
					'limit' => $limitInt,
					'offset' => $offsetInt,
				]
			);

			// Validate and save objects in the schema to update metadata.
			$result = $this->objectService->validateAndSaveObjectsBySchema(
				registerId: (int)$register,
				schemaId: (int)$schemaId,
				limit: $limitInt,
				offset: $offsetInt
			);

			$this->logger->info(
				message: '[ObjectsController] Bulk validation and save completed',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'register' => $register,
					'schema' => $schemaId,
					'processed' => $result['processed'] ?? 0,
					'updated' => $result['updated'] ?? 0,
					'failed' => $result['failed'] ?? 0,
				]
			);

			return new JSONResponse(
				data: [
					'success' => true,
					'message' => 'Validation completed successfully',
					'statistics' => [
						'processed' => $result['processed'] ?? 0,
						'updated' => $result['updated'] ?? 0,
						'failed' => $result['failed'] ?? 0,
						'total' => $result['total'] ?? null,
					],
					'pagination' => [
						'limit' => $limitInt,
						'offset' => $offsetInt,
					],
					'errors' => $result['errors'] ?? [],
				]
			);
		} catch (Exception $e) {
			$this->logger->error(
				message: '[ObjectsController] Bulk validation failed',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				]
			);

			return new JSONResponse(
				data: [
					'success' => false,
					'error' => $e->getMessage(),
					'message' => 'Validation failed',
				],
				statusCode: 500
			);
		}//end try
	}//end validate()

	/**
	 * Collect UUID-to-name mappings for all related objects in a response.
	 *
	 * This method extracts all UUIDs from the response data (relations, extended objects)
	 * and resolves them to human-readable names using the CacheHandler.
	 *
	 * @param array $renderedData The rendered object data.
	 * @param \OCA\OpenRegister\Service\Object\CacheHandler|null $cacheHandler The cache handler for name resolution.
	 *
	 * @return array<string, string> Map of UUID to name.
	 */
	private function collectNamesForResponse(
		array $renderedData,
		?\OCA\OpenRegister\Service\Object\CacheHandler $cacheHandler,
	): array {
		if ($cacheHandler === null) {
			return [];
		}

		$uuids = [];

		// Collect UUIDs from @self.relations.
		$relations = $renderedData['@self']['relations'] ?? [];
		if (is_array($relations) === true) {
			foreach ($relations as $relation) {
				if (is_string($relation) === true && $this->isUuid(value: $relation) === true) {
					$uuids[] = $relation;
				} elseif (is_array($relation) === true) {
					// Handle nested relation arrays.
					foreach ($relation as $uuid) {
						if (is_string($uuid) === true && $this->isUuid(value: $uuid) === true) {
							$uuids[] = $uuid;
						}
					}
				}
			}
		}

		// Collect UUIDs from object properties (for extended relations).
		$objectData = $renderedData['@self']['object'] ?? $renderedData;
		if (is_array($objectData) === true) {
			$this->collectUuidsFromArray(data: $objectData, uuids: $uuids);
		}

		// Remove duplicates.
		$uuids = array_unique($uuids);

		if (empty($uuids) === true) {
			return [];
		}

		// Resolve all UUIDs to names using CacheHandler.
		return $cacheHandler->getMultipleObjectNames($uuids);
	}//end collectNamesForResponse()

	/**
	 * Recursively collect UUIDs from an array structure.
	 *
	 * @param array $data The array to scan for UUIDs.
	 * @param array $uuids Reference to array collecting UUIDs.
	 *
	 * @return void
	 */
	private function collectUuidsFromArray(array $data, array &$uuids): void {
		foreach ($data as $key => $value) {
			// Skip metadata keys.
			if ($key === '@self' || $key === 'id' || $key === '_id') {
				continue;
			}

			if (is_string($value) === true && $this->isUuid(value: $value) === true) {
				$uuids[] = $value;
			} elseif (is_array($value) === true) {
				// Check if it's an array of UUIDs.
				foreach ($value as $item) {
					if (is_string($item) === true && $this->isUuid(value: $item) === true) {
						$uuids[] = $item;
					} elseif (is_array($item) === true) {
						// Recurse into nested arrays.
						$this->collectUuidsFromArray(data: $item, uuids: $uuids);
					}
				}
			}
		}
	}//end collectUuidsFromArray()

	/**
	 * Check if a string is a valid UUID format.
	 *
	 * @param string $value The value to check.
	 *
	 * @return bool True if the value is a UUID format.
	 */
	private function isUuid(string $value): bool {
		return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1;
	}//end isUuid()

	/**
	 * Recursively strips empty values (null, empty string, empty array) from an array.
	 *
	 * Used to reduce API response payload by omitting properties that have no value.
	 * Values of 0, false, and "0" are preserved as they are meaningful.
	 *
	 * By default, responses are stripped. Pass _empty=true in the query to include
	 * empty values in the response for debugging or schema-aware consumers.
	 *
	 * @param array $data The data array to strip empty values from.
	 *
	 * @return array The data with empty values removed.
	 */
	private function stripEmptyValues(array $data): array {
		$result = [];
		foreach ($data as $key => $value) {
			// Recursively strip nested arrays (but not sequential arrays like relations/files).
			if (is_array($value) === true) {
				// Check if this is a sequential (indexed) array.
				$isSequential = array_is_list($value);

				if ($isSequential === true) {
					// For sequential arrays, strip each element if it's an associative array.
					$stripped = [];
					foreach ($value as $item) {
						if (is_array($item) === true) {
							$stripped[] = $this->stripEmptyValues(data: $item);
						} else {
							$stripped[] = $item;
						}
					}

					// Only include non-empty sequential arrays.
					if (empty($stripped) === false) {
						$result[$key] = $stripped;
					}

					continue;
				}

				// For associative arrays, recurse.
				$stripped = $this->stripEmptyValues(data: $value);
				if (empty($stripped) === false) {
					$result[$key] = $stripped;
				}

				continue;
			}//end if

			// Skip null and empty strings.
			if ($value === null || $value === '') {
				continue;
			}

			// Keep everything else (including 0, false, "0").
			$result[$key] = $value;
		}//end foreach

		return $result;
	}//end stripEmptyValues()

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
		$this->logger?->info(
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
}//end class
