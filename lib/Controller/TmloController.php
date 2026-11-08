<?php

/**
 * TmloController
 *
 * Controller for TMLO (Toepassingsprofiel Metadatastandaard Lokale Overheden)
 * metadata operations including MDTO XML export and archival status summary.
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use Exception;
use InvalidArgumentException;
use OCA\OpenRegister\Controller\Support\TmloControllerServices;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\SchemaNotInRegisterException;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Export\ExportGate;
use OCA\OpenRegister\Service\RegisterScopedSchemaResolver;
use OCA\OpenRegister\Service\TmloServiceInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Controller for TMLO metadata export and query operations
 *
 * @package OCA\OpenRegister\Controller
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class TmloController extends Controller {

	/**
	 * The archiefstatus values a summary counts, in response order.
	 *
	 * @var array<int, string>
	 */
	private const ARCHIEFSTATUSES = [
		TmloServiceInterface::ARCHIEFSTATUS_ACTIEF,
		TmloServiceInterface::ARCHIEFSTATUS_SEMI_STATISCH,
		TmloServiceInterface::ARCHIEFSTATUS_OVERGEBRACHT,
		TmloServiceInterface::ARCHIEFSTATUS_VERNIETIGD,
	];

	/**
	 * The shared register-scoped schema resolver.
	 *
	 * Built here rather than injected: it is a stateless collaborator over the
	 * `RegisterMapper` + `SchemaMapper` this class already holds, so constructing
	 * it directly keeps every existing unit test — all of which mock those two
	 * mappers — exercising the REAL resolution path instead of a mock of the very
	 * thing under test.
	 *
	 * @var RegisterScopedSchemaResolver
	 */
	private readonly RegisterScopedSchemaResolver $scopedSchemaResolver;


	/**
	 * TMLO metadata service
	 *
	 * @var TmloServiceInterface
	 */
	private readonly TmloServiceInterface $tmloService;

	/**
	 * Object service for querying objects
	 *
	 * @var ObjectOperationsInterface
	 */
	private readonly ObjectOperationsInterface $objectService;

	/**
	 * Register mapper
	 *
	 * @var RegisterMapperInterface
	 */
	private readonly RegisterMapperInterface $registerMapper;

	/**
	 * Logger interface
	 *
	 * @var LoggerInterface
	 */
	private readonly LoggerInterface $logger;

	/**
	 * The export verb, checked before any archival metadata leaves.
	 *
	 * @var ExportGate
	 */
	private readonly ExportGate $exportGate;

	/**
	 * Constructor.
	 *
	 * @param string $appName The app name
	 * @param IRequest $request The request object
	 * @param TmloControllerServices $services The tmlo controller services.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-tmlo-metadata/tasks.md#task-1
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		TmloControllerServices $services,
	) {
		parent::__construct(appName: $appName, request: $request);
		$records = $services->records;
		$this->tmloService = $records->tmloService;
		$this->objectService = $records->objectService;
		$this->registerMapper = $records->registerMapper;
		$this->logger = $services->guards->logger;
		$this->exportGate = $services->guards->exportGate;
		$this->scopedSchemaResolver = new RegisterScopedSchemaResolver(registerMapper: $records->registerMapper, schemaMapper: $records->schemaMapper);
	}//end __construct()

	/**
	 * Export a single object as MDTO-compliant XML.
	 *
	 * @param string $register The register ID or slug
	 * @param string $schema The schema ID or slug
	 * @param string $id The object UUID
	 *
	 * @return Response The MDTO XML response
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-tmlo-metadata/tasks.md#task-5
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function exportSingle(string $register, string $schema, string $id): Response {
		try {
			$scope = $this->exportScope(register: $register, schema: $schema, profile: 'tmlo-single');
			if ($scope instanceof Response) {
				return $scope;
			}

			// `id:`, not `identifier:` — the parameter is `$id`, and a named
			// argument that names nothing raises `Error: Unknown named
			// parameter`, which is not an `Exception` and so escapes every
			// catch block as a 500. IDs, not entities: `find()` types these
			// `string|int|null`, so passing the Register/Schema objects is a
			// TypeError even once the name is right.
			$object = $this->objectService->find(id: $id, register: $scope[0]->getId(), schema: $scope[1]->getId());

			return $this->xml(xml: $this->tmloService->generateMdtoXml($object));
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (Throwable $e) {
			return $this->failure(e: $e, prefix: 'MDTO export failed');
		}
	}//end exportSingle()

	/**
	 * Export multiple objects as MDTO-compliant XML.
	 *
	 * @param string $register The register ID or slug
	 * @param string $schema The schema ID or slug
	 *
	 * @return Response The MDTO XML response
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-tmlo-metadata/tasks.md#task-5
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function exportBatch(string $register, string $schema): Response {
		try {
			return $this->batchExport(register: $register, schema: $schema);
		} catch (Throwable $e) {
			return $this->failure(e: $e, prefix: 'MDTO batch export failed');
		}
	}//end exportBatch()

	/**
	 * The gated MDTO XML of every object the query parameters select.
	 *
	 * @param string $register The register ID or slug
	 * @param string $schema The schema ID or slug
	 *
	 * @return Response The XML or the export refusal.
	 */
	private function batchExport(string $register, string $schema): Response {
		$scope = $this->exportScope(register: $register, schema: $schema, profile: 'tmlo-batch');
		if ($scope instanceof Response) {
			return $scope;
		}

		// `findAll(array $config, …)` takes ONE array, and the register and
		// schema travel INSIDE `filters` — that is where `prepareFindAllConfig()`
		// reads them to set the service context.
		$result = $this->objectService->findAll(
			config: ['filters' => $this->batchFilters(registerId: $scope[0]->getId(), schemaId: $scope[1]->getId())]
		);

		return $this->xml(xml: $this->tmloService->generateBatchMdtoXml(($result['results'] ?? $result)));
	}//end batchExport()

	/**
	 * The `tmlo.*` and `_*` query parameters, scoped to the register and schema.
	 *
	 * @param int|null $registerId The register id.
	 * @param int|null $schemaId The schema id.
	 *
	 * @return array<string, mixed> The findAll filters.
	 */
	private function batchFilters(?int $registerId, ?int $schemaId): array {
		$filters = [];
		foreach ($this->request->getParams() as $key => $value) {
			if ($this->isFilterKey(key: $key) === true) {
				$filters[$key] = $value;
			}
		}

		return array_merge($filters, ['register' => $registerId, 'schema' => $schemaId]);
	}//end batchFilters()

	/**
	 * Whether a query parameter narrows a batch export: a `tmlo.*` or `_*` key.
	 *
	 * @param string $key The parameter name.
	 *
	 * @return bool True for a filter key.
	 */
	private function isFilterKey(string $key): bool {
		return str_starts_with($key, 'tmlo.') === true || str_starts_with($key, '_') === true;
	}//end isFilterKey()

	/**
	 * Resolve the register and the schema within it, then ask the export gate.
	 *
	 * REGISTER-SCOPED: the schema ref resolves among the ids this register
	 * carries, never instance-wide, so a `{register}`/`{schema}` pair cannot
	 * export the archival metadata of another app's same-slug schema.
	 *
	 * REQ-EXP-001: archival metadata IS the object's data, shaped for an
	 * archive, so taking it off the instance is an export and is gated on the
	 * export verb like every other export path. Asked after the register and
	 * schema resolve, because the rule lives on the schema.
	 *
	 * @param string $register The register ID or slug
	 * @param string $schema The schema ID or slug
	 * @param string $profile The export profile the gate is asked about.
	 *
	 * @return array{0: Register, 1: Schema}|Response The register and schema, or the refusal.
	 */
	private function exportScope(string $register, string $schema, string $profile): array|Response {
		$registerEntity = $this->registerMapper->find($register);
		$schemaEntity = $this->scopedSchemaResolver->resolveSchemaWithin(register: $registerEntity, schemaRef: $schema);

		$refusal = $this->exportGate->refusalFor(schema: $schemaEntity, profile: $profile, registerId: $registerEntity->getId());
		if ($refusal !== null) {
			return $refusal;
		}

		return [$registerEntity, $schemaEntity];
	}//end exportScope()

	/**
	 * An MDTO XML document as a 200 response.
	 *
	 * @param string $xml The document.
	 *
	 * @return DataResponse The response.
	 */
	private function xml(string $xml): DataResponse {
		$response = new DataResponse($xml, Http::STATUS_OK);
		$response->addHeader('Content-Type', 'application/xml; charset=UTF-8');
		return $response;
	}//end xml()

	/**
	 * Get archival status summary for a register/schema combination.
	 *
	 * Returns counts of objects per archiefstatus.
	 *
	 * @param string $register The register ID or slug
	 * @param string $schema The schema ID or slug
	 *
	 * @return JSONResponse The summary response
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-tmlo-metadata/tasks.md#task-5
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function summary(string $register, string $schema): JSONResponse {
		try {
			return $this->statusSummary(register: $register, schema: $schema);
		} catch (Throwable $e) {
			return $this->failure(e: $e, prefix: 'TMLO summary failed');
		}
	}//end summary()

	/**
	 * The per-archiefstatus counts of a TMLO-enabled register's schema.
	 *
	 * @param string $register The register ID or slug
	 * @param string $schema The schema ID or slug
	 *
	 * @return JSONResponse The counts, or a 400 when TMLO is off for the register.
	 */
	private function statusSummary(string $register, string $schema): JSONResponse {
		$registerEntity = $this->registerMapper->find($register);

		if ($this->tmloService->isTmloEnabled($registerEntity) === false) {
			return new JSONResponse(
				['error' => 'TMLO is not enabled on this register'],
				Http::STATUS_BAD_REQUEST
			);
		}

		// REGISTER-SCOPED — see exportScope().
		$schemaEntity = $this->scopedSchemaResolver->resolveSchemaWithin(register: $registerEntity, schemaRef: $schema);

		return new JSONResponse($this->countsByStatus(register: $registerEntity, schema: $schemaEntity), Http::STATUS_OK);
	}//end statusSummary()

	/**
	 * Count a register/schema's objects for each archiefstatus.
	 *
	 * `count()` reads the service's CURRENT context — without the two set calls
	 * MagicMapper::countAll() sums every register/schema table on the instance
	 * and each status would report the global object total. `count()`, not
	 * `findAll()`: it returns the int directly, where `findAll()` returns
	 * rendered entities and no `total`.
	 *
	 * @param Register $register The register.
	 * @param Schema $schema The schema within it.
	 *
	 * @return array<string, int> The count per status.
	 */
	private function countsByStatus(Register $register, Schema $schema): array {
		$this->objectService->setRegister(register: $register);
		$this->objectService->setSchema(schema: $schema);

		$counts = [];
		foreach (self::ARCHIEFSTATUSES as $status) {
			$counts[$status] = $this->objectService->count(
				config: ['filters' => ['tmlo.archiefstatus' => $status]]
			);
		}

		return $counts;
	}//end countsByStatus()

	/**
	 * Map what an action threw onto the TMLO error responses; anything but an Exception escapes.
	 *
	 * The register-scoped refusal carries a diagnosis — which register, how many
	 * same-slug schemas exist elsewhere, the relink-schemas repair command.
	 * Flattening it into the generic 'not found' would read as "your slug is
	 * wrong", the one conclusion that is certainly false when duplicates
	 * demonstrably exist. An unknown register/schema slug or id is a clean 404
	 * instead of leaking the internal DBAL SQL through the generic 500.
	 *
	 * @param Throwable $e The failure.
	 * @param string $prefix The log and response prefix for an unexpected failure.
	 *
	 * @return JSONResponse The mapped error.
	 *
	 * @throws Throwable When the failure is not an Exception (it is rethrown unchanged).
	 */
	private function failure(Throwable $e, string $prefix): JSONResponse {
		if ($e instanceof SchemaNotInRegisterException) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		}

		if ($e instanceof DoesNotExistException) {
			return new JSONResponse(['error' => 'Register or schema not found'], Http::STATUS_NOT_FOUND);
		}

		if ($e instanceof Exception === false) {
			throw $e;
		}

		$this->logger->error($prefix . ': ' . $e->getMessage(), ['exception' => $e]);
		return new JSONResponse(['error' => $prefix . ': ' . $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
	}//end failure()
}//end class
