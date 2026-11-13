<?php

/**
 * TablesController
 *
 * Controller for managing database tables view and magic table operations.
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
 * @version   GIT: <git-id>
 * @link      https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Controller;

use Exception;
use OCA\OpenRegister\Controller\Support\TablesControllerServices;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\RegisterScopedSchemaResolverInterface;
use OCA\OpenRegister\Service\RegisterScopedSchemaResolvers;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * TablesController class.
 *
 * Controller for managing table operations including magic table synchronization.
 *
 * @psalm-suppress                                UnusedClass
 */
class TablesController extends Controller {
	/**
	 * Column changes in the sync report, keyed by report name, with the sync result key.
	 *
	 * @var array<string, string>
	 */
	private const COLUMN_CHANGES = [
		'added' => 'columnsAdded',
		'removed' => 'columnsDropped',
		'deRequired' => 'columnsDeRequired',
		'reRequired' => 'columnsReRequired',
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
	 * @var RegisterScopedSchemaResolverInterface
	 */
	private readonly RegisterScopedSchemaResolverInterface $scopedSchemaResolver;


	/**
	 * Magic mapper for table operations
	 *
	 * @var MagicMapperInterface
	 */
	private readonly MagicMapperInterface $magicMapper;

	/**
	 * Register mapper
	 *
	 * @var RegisterMapperInterface
	 */
	private readonly RegisterMapperInterface $registerMapper;

	/**
	 * Constructor
	 *
	 * @param string $appName Application name
	 * @param IRequest $request Request object
	 * @param TablesControllerServices $services The tables controller services.
	 * @param LoggerInterface $logger Logger
	 * @param IUserSession $userSession Current user session
	 * @param IGroupManager $groupManager Group manager for the admin check
	 */
	public function __construct(
		$appName,
		IRequest $request,
		TablesControllerServices $services,
		private readonly LoggerInterface $logger,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
	) {
		parent::__construct(appName: $appName, request: $request);
		$this->magicMapper = $services->magicMapper;
		$this->registerMapper = $services->registerMapper;
		$this->scopedSchemaResolver = RegisterScopedSchemaResolvers::over(
			registerMapper: $services->registerMapper,
			schemaMapper: $services->schemaMapper
		);
	}//end __construct()

	/**
	 * Whether the current user is a Nextcloud administrator.
	 *
	 * Table sync changes database table structure, so it is admin-only.
	 *
	 * @return bool True if a user is signed in and belongs to the admin group.
	 */
	private function isCurrentUserAdmin(): bool {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return false;
		}

		return $this->groupManager->isAdmin($user->getUID());
	}//end isCurrentUserAdmin()

	/**
	 * Sync magic table for a register/schema combination.
	 *
	 * This triggers the magic table update process which:
	 * - Adds missing columns
	 * - De-requires columns that are no longer required in schema
	 * - Drops duplicate camelCase columns when snake_case exists
	 * - Makes obsolete columns nullable
	 * - Updates indexes for relations and facetable fields
	 *
	 * @param int|string $registerId The register ID or slug.
	 * @param int|string $schemaId The schema ID or slug.
	 *
	 * @auth admin-only syncing changes database table structure, so only an administrator runs it
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-ctrl-misc/tasks.md#task-8
	 */
	public function sync(int|string $registerId, int|string $schemaId): JSONResponse {
		if ($this->isCurrentUserAdmin() === false) {
			return new JSONResponse(['success' => false, 'error' => 'Admin privileges required'], 403);
		}

		try {
			return $this->syncWithin(register: $this->registerOf(registerId: $registerId), schemaId: $schemaId);
		} catch (Exception $e) {
			$this->logger->error(
				message: '[TablesController] Magic table sync failed',
				context: ['file' => __FILE__, 'line' => __LINE__, 'registerId' => $registerId, 'schemaId' => $schemaId, 'error' => $e->getMessage()]
			);

			return $this->failure(error: 'Failed to sync magic table', exception: $e);
		}
	}//end sync()

	/**
	 * Find a register by numeric id or by slug.
	 *
	 * @param int|string $registerId The register ID or slug.
	 *
	 * @return Register The register
	 *
	 * @throws DoesNotExistException When neither an id nor a slug matches; sync() answers with its failure response.
	 */
	private function registerOf(int|string $registerId): Register {
		if (is_numeric($registerId) === true) {
			return $this->registerMapper->find((int)$registerId);
		}

		return $this->registerMapper->find($registerId);
	}//end registerOf()

	/**
	 * Sync the table of one schema of a register and report what changed.
	 *
	 * The schema is resolved within the register: syncing reshapes the register's own
	 * table, so another register's same-slug schema must never resolve here.
	 *
	 * @param Register   $register The register
	 * @param int|string $schemaId The schema ID or slug.
	 *
	 * @return JSONResponse The sync report, or 404 when the register does not carry the schema
	 */
	private function syncWithin(Register $register, int|string $schemaId): JSONResponse {
		try {
			$schema = $this->scopedSchemaResolver->resolveSchemaWithin(register: $register, schemaRef: $schemaId);
		} catch (\Throwable $e) {
			return new JSONResponse(['error' => $e->getMessage()], 404);
		}

		$result = $this->syncTable(register: $register, tableSchema: $schema);
		$report = ['success' => true, 'message' => 'Magic table synchronized successfully'];
		$report += $this->tableOf(register: $register, tableSchema: $schema);
		$report['statistics'] = $this->statisticsOf(result: $result);
		return new JSONResponse($report);
	}//end syncWithin()

	/**
	 * Update the table structure to the schema, without dropping it, and log the result.
	 *
	 * @param Register $register    The register
	 * @param Schema   $tableSchema The schema
	 *
	 * @return array The sync result
	 */
	private function syncTable(Register $register, Schema $tableSchema): array {
		$result = $this->magicMapper->syncTableForRegisterSchema(register: $register, schema: $tableSchema);
		$this->logger->info(
			message: '[TablesController] Magic table sync completed',
			context: ['file' => __FILE__, 'line' => __LINE__, 'registerId' => $register->getId(), 'schemaId' => $tableSchema->getId(), 'result' => $result]
		);

		return $result;
	}//end syncTable()

	/**
	 * The register, schema and table name of a synced table.
	 *
	 * @param Register $register    The register
	 * @param Schema   $tableSchema The schema
	 *
	 * @return array The table part of the sync report
	 */
	private function tableOf(Register $register, Schema $tableSchema): array {
		return [
			'register' => ['id' => $register->getId(), 'title' => $register->getTitle()],
			'schema' => ['id' => $tableSchema->getId(), 'title' => $tableSchema->getTitle()],
			'tableName' => 'openregister_objects_' . $register->getId() . '_' . $tableSchema->getId(),
		];
	}//end tableOf()

	/**
	 * The statistics part of the sync report.
	 *
	 * @param array $result The sync result
	 *
	 * @return array The property and column counts
	 */
	private function statisticsOf(array $result): array {
		return [
			'metadata' => [
				'count' => $result['metadataProperties'] ?? 0,
				'description' => 'Built-in system columns (id, uuid, register, schema, etc.)',
			],
			'properties' => ['count' => $result['regularProperties'] ?? 0, 'description' => 'Schema-defined properties'],
			'columns' => $this->columnsOf(result: $result),
		];
	}//end statisticsOf()

	/**
	 * The column counts and lists of the sync report.
	 *
	 * @param array $result The sync result
	 *
	 * @return array The column changes
	 */
	private function columnsOf(array $result): array {
		$columns = [];
		foreach (self::COLUMN_CHANGES as $name => $key) {
			$columns[$name] = ['count' => $result[$key] ?? 0, 'list' => $result[$key . 'List'] ?? []];
		}

		$columns['deRequired']['description'] = 'Columns made nullable (no longer required)';
		$columns['reRequired']['description'] = 'Columns made NOT NULL (now required)';
		$columns['unchanged'] = ['count' => $result['columnsUnchanged'] ?? 0];
		$columns['total'] = $result['totalProperties'] ?? 0;
		return $columns;
	}//end columnsOf()

	/**
	 * Sync all magic tables for all register/schema combinations.
	 *
	 * @auth admin-only syncing changes database table structure, so only an administrator runs it
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-ctrl-misc/tasks.md#task-8
	 */
	public function syncAll(): JSONResponse {
		if ($this->isCurrentUserAdmin() === false) {
			return new JSONResponse(['success' => false, 'error' => 'Admin privileges required'], 403);
		}

		try {
			$outcomes = [];
			foreach ($this->registerMapper->findAll() as $register) {
				$outcomes = array_merge($outcomes, $this->syncRegister(register: $register));
			}

			return $this->syncAllReport(outcomes: $outcomes);
		} catch (Exception $e) {
			return $this->failure(error: 'Failed to sync magic tables', exception: $e);
		}
	}//end syncAll()

	/**
	 * Sync the table of every schema a register carries.
	 *
	 * @param Register $register The register
	 *
	 * @return list<array> One outcome per schema reference
	 */
	private function syncRegister(Register $register): array {
		$outcomes = [];
		foreach ($register->getSchemas() as $schemaRef) {
			$outcomes[] = $this->syncReference(register: $register, schemaRef: $schemaRef);
		}

		return $outcomes;
	}//end syncRegister()

	/**
	 * Sync the table of one schema reference (an ID, a slug or an array with an id).
	 *
	 * @param Register $register  The register
	 * @param mixed    $schemaRef The schema reference from the register's schemas list
	 *
	 * @return array The outcome: a status on success, an error otherwise
	 */
	private function syncReference(Register $register, mixed $schemaRef): array {
		$schemaId = $schemaRef;
		if (is_array($schemaRef) === true) {
			$schemaId = ($schemaRef['id'] ?? $schemaRef);
		}

		try {
			$schema = $this->scopedSchemaResolver->resolveSchemaWithin(register: $register, schemaRef: $schemaId);
			$this->magicMapper->syncTableForRegisterSchema(register: $register, schema: $schema);
			return ['register' => $register->getId(), 'schema' => $schema->getId(), 'status' => 'success'];
		} catch (Exception $e) {
			return ['register' => $register->getId(), 'schema' => $schemaId, 'error' => $e->getMessage()];
		}
	}//end syncReference()

	/**
	 * The report of a sync over all tables.
	 *
	 * @param list<array> $outcomes One outcome per schema reference
	 *
	 * @return JSONResponse The synced tables and the errors
	 */
	private function syncAllReport(array $outcomes): JSONResponse {
		$errors = array_values(array_filter($outcomes, static fn (array $outcome): bool => array_key_exists('error', $outcome) === true));
		$results = array_values(array_filter($outcomes, static fn (array $outcome): bool => array_key_exists('error', $outcome) === false));
		return new JSONResponse(
			[
				'success' => count($errors) === 0,
				'message' => 'Sync completed for ' . count($results) . ' tables',
				'synced' => $results,
				'errors' => $errors,
				'totalSynced' => count($results),
				'totalErrors' => count($errors),
			]
		);
	}//end syncAllReport()

	/**
	 * A 500 response carrying the failure and the exception message.
	 *
	 * @param string    $error     The failure
	 * @param Exception $exception The exception
	 *
	 * @return JSONResponse The error response
	 */
	private function failure(string $error, Exception $exception): JSONResponse {
		return new JSONResponse(['error' => $error, 'message' => $exception->getMessage()], 500);
	}//end failure()
}//end class
