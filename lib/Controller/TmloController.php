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
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\SchemaNotInRegisterException;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\RegisterScopedSchemaResolver;
use OCA\OpenRegister\Service\TmloService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Controller for TMLO metadata export and query operations
 *
 * @package OCA\OpenRegister\Controller
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class TmloController extends Controller {

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
	 * Constructor.
	 *
	 * @param string $appName The app name
	 * @param IRequest $request The request object
	 * @param TmloService $tmloService TMLO metadata service
	 * @param ObjectService $objectService Object service for querying objects
	 * @param RegisterMapper $registerMapper Register mapper
	 * @param SchemaMapper $schemaMapper Schema mapper
	 * @param LoggerInterface $logger Logger interface
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-tmlo-metadata/tasks.md#task-1
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly TmloService $tmloService,
		private readonly ObjectService $objectService,
		private readonly RegisterMapper $registerMapper,
		SchemaMapper $schemaMapper,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
		$this->scopedSchemaResolver = new RegisterScopedSchemaResolver(
			registerMapper: $registerMapper,
			schemaMapper: $schemaMapper
		);
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
			$registerEntity = $this->registerMapper->find($register);
			// REGISTER-SCOPED: the schema ref resolves among the ids this register
			// carries, never instance-wide. The two used to be resolved
			// independently, so a `{register}`/`{schema}` pair could export the
			// archival metadata of another app's same-slug schema.
			$schemaEntity = $this->scopedSchemaResolver->resolveSchemaWithin(
				register: $registerEntity,
				schemaRef: $schema
			);

			// `id:`, not `identifier:` — the parameter is `$id`, and a named
			// argument that names nothing raises `Error: Unknown named
			// parameter`, which is not an `Exception` and so escapes every
			// catch block below as a 500.
			//
			// IDs, not entities: `find()` types these `string|int|null`, so
			// passing the Register/Schema objects is a TypeError even once the
			// name is right.
			$object = $this->objectService->find(
				id: $id,
				register: $registerEntity->getId(),
				schema: $schemaEntity->getId(),
			);

			$xml = $this->tmloService->generateMdtoXml($object);

			$response = new DataResponse($xml, Http::STATUS_OK);
			$response->addHeader('Content-Type', 'application/xml; charset=UTF-8');
			return $response;
		} catch (SchemaNotInRegisterException $e) {
			// The register-scoped refusal carries a diagnosis — which register, how
			// many same-slug schemas exist elsewhere, the relink-schemas repair
			// command. Flattening it into the generic 'not found' below would read
			// as "your slug is wrong", the one conclusion that is certainly false
			// when duplicates demonstrably exist.
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(
				['error' => 'Register or schema not found'],
				Http::STATUS_NOT_FOUND
			);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(
				['error' => $e->getMessage()],
				Http::STATUS_UNPROCESSABLE_ENTITY
			);
		} catch (Exception $e) {
			$this->logger->error('MDTO export failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(
				['error' => 'MDTO export failed: ' . $e->getMessage()],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}//end try
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
			$registerEntity = $this->registerMapper->find($register);
			// REGISTER-SCOPED: the schema ref resolves among the ids this register
			// carries, never instance-wide. The two used to be resolved
			// independently, so a `{register}`/`{schema}` pair could export the
			// archival metadata of another app's same-slug schema.
			$schemaEntity = $this->scopedSchemaResolver->resolveSchemaWithin(
				register: $registerEntity,
				schemaRef: $schema
			);

			// Get all query parameters for filtering.
			$params = $this->request->getParams();
			$filters = [];
			foreach ($params as $key => $value) {
				if (str_starts_with($key, 'tmlo.') === true || str_starts_with($key, '_') === true) {
					$filters[$key] = $value;
				}
			}

			// `findAll(array $config, …)` takes ONE array. The previous call
			// passed `register:`/`schema:`/`filters:` as named arguments that
			// do not exist on it, raising the same `Error` as exportSingle.
			//
			// The register and schema travel INSIDE `filters` — that is where
			// `prepareFindAllConfig()` reads them to set the service context.
			$result = $this->objectService->findAll(
				config: [
					'filters' => array_merge(
						$filters,
						[
							'register' => $registerEntity->getId(),
							'schema'   => $schemaEntity->getId(),
						]
					),
				]
			);

			$objects = ($result['results'] ?? $result);

			$xml = $this->tmloService->generateBatchMdtoXml($objects);

			$response = new DataResponse($xml, Http::STATUS_OK);
			$response->addHeader('Content-Type', 'application/xml; charset=UTF-8');
			return $response;
		} catch (SchemaNotInRegisterException $e) {
			// The register-scoped refusal carries a diagnosis — which register, how
			// many same-slug schemas exist elsewhere, the relink-schemas repair
			// command. Flattening it into the generic 'not found' below would read
			// as "your slug is wrong", the one conclusion that is certainly false
			// when duplicates demonstrably exist.
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(
				['error' => 'Register or schema not found'],
				Http::STATUS_NOT_FOUND
			);
		} catch (Exception $e) {
			$this->logger->error('MDTO batch export failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(
				['error' => 'MDTO batch export failed: ' . $e->getMessage()],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}//end try
	}//end exportBatch()

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
			$registerEntity = $this->registerMapper->find($register);

			if ($this->tmloService->isTmloEnabled($registerEntity) === false) {
				return new JSONResponse(
					['error' => 'TMLO is not enabled on this register'],
					Http::STATUS_BAD_REQUEST
				);
			}

			// REGISTER-SCOPED — see exportSingle().
			$schemaEntity = $this->scopedSchemaResolver->resolveSchemaWithin(
				register: $registerEntity,
				schemaRef: $schema
			);

			// Initialize counts.
			$counts = [
				TmloService::ARCHIEFSTATUS_ACTIEF => 0,
				TmloService::ARCHIEFSTATUS_SEMI_STATISCH => 0,
				TmloService::ARCHIEFSTATUS_OVERGEBRACHT => 0,
				TmloService::ARCHIEFSTATUS_VERNIETIGD => 0,
			];

			// Scope the counts to this register/schema. `count()` reads the
			// service's CURRENT context — without these two calls
			// MagicMapper::countAll() sums every register/schema table on the
			// instance and each status would report the global object total.
			$this->objectService->setRegister(register: $registerEntity);
			$this->objectService->setSchema(schema: $schemaEntity);

			// Count objects for each status.
			//
			// `count()`, not `findAll()`. The previous call passed named
			// arguments — `register:`, `schema:`, `filters:` — that do not exist
			// on `findAll(array $config, bool $_rbac, bool $_multitenancy)`, so
			// PHP raised `Error: Unknown named parameter $register`. `Error` is
			// not an `Exception`, so it escaped all three catch blocks below and
			// this endpoint answered 500 on every request.
			//
			// It also read `$result['total']`, a key `findAll()` never returns —
			// it returns rendered entities. So even once the call was fixed,
			// every status would have reported 0. `count()` returns the int
			// directly.
			foreach (array_keys($counts) as $status) {
				$counts[$status] = $this->objectService->count(
					config: ['filters' => ['tmlo.archiefstatus' => $status]]
				);
			}

			return new JSONResponse($counts, Http::STATUS_OK);
		} catch (SchemaNotInRegisterException $e) {
			// The register-scoped refusal carries a diagnosis — which register, how
			// many same-slug schemas exist elsewhere, the relink-schemas repair
			// command. Flattening it into the generic 'not found' below would read
			// as "your slug is wrong", the one conclusion that is certainly false
			// when duplicates demonstrably exist.
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (DoesNotExistException $e) {
			// Unknown register/schema slug or id: return a clean 404 instead of
			// leaking the internal DBAL SQL through the generic 500 handler.
			return new JSONResponse(
				['error' => 'Register or schema not found'],
				Http::STATUS_NOT_FOUND
			);
		} catch (Exception $e) {
			$this->logger->error('TMLO summary failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(
				['error' => 'TMLO summary failed: ' . $e->getMessage()],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}//end try
	}//end summary()
}//end class
