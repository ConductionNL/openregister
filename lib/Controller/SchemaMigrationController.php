<?php

/**
 * SchemaMigrationController — REST surface for schema versioning & object
 * migration.
 *
 * Exposes the schema changelog, revalidation (impact-analysis) runs,
 * migration plan preview/execute, run status/report listing, and migration
 * rollback. Every action is admin-gated by Nextcloud framework default (no
 * `@NoAdminRequired`/`@PublicPage`): managing a schema's evolution requires
 * the same authority as editing the schema, and the SecurityMiddleware
 * rejects non-admins before the controller runs.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Db\SchemaChangelogMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\SchemaRunEntryMapper;
use OCA\OpenRegister\Db\SchemaRunMapper;
use OCA\OpenRegister\Exception\SchemaRunConcurrencyException;
use OCA\OpenRegister\Service\Schema\PropertyConversionService;
use OCA\OpenRegister\Service\Schema\SchemaMigrationService;
use OCA\OpenRegister\Service\Schema\SchemaObjectReader;
use OCA\OpenRegister\Service\Schema\SchemaRevalidationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\BackgroundJob\IJobList;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Controller for schema versioning, revalidation and migration.
 */
class SchemaMigrationController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string $appName App name.
	 * @param IRequest $request Request.
	 * @param SchemaMapper $schemaMapper Schema lookup.
	 * @param SchemaChangelogMapper $changelogMapper Changelog read.
	 * @param SchemaRunMapper $runMapper Run read.
	 * @param SchemaRunEntryMapper $runEntryMapper Run entry read.
	 * @param SchemaRevalidationService $revalidationService Revalidation engine.
	 * @param SchemaMigrationService $migrationService Migration engine.
	 * @param SchemaObjectReader $objectReader Locates a schema's register and reads its stored values.
	 * @param IJobList $jobList Job list (enqueue runs).
	 * @param IUserSession $userSession Current user.
	 * @param LoggerInterface $logger Logger.
	 * @param PropertyConversionService|null $conversions Publishes and previews a property type change.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly SchemaMapper $schemaMapper,
		private readonly SchemaChangelogMapper $changelogMapper,
		private readonly SchemaRunMapper $runMapper,
		private readonly SchemaRunEntryMapper $runEntryMapper,
		private readonly SchemaRevalidationService $revalidationService,
		private readonly SchemaMigrationService $migrationService,
		private readonly SchemaObjectReader $objectReader,
		private readonly IJobList $jobList,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
		private readonly ?PropertyConversionService $conversions = null,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * The property-type conversions the system supports.
	 *
	 * Published as a list rather than implied by trial and error: an
	 * administrator planning a schema change needs to know what is possible
	 * before designing around it, and a conversion that is not on this list is
	 * refused rather than attempted (REQ-CLH-005).
	 *
	 * @return JSONResponse The supported conversions.
	 *
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/code-list-lifecycle-and-hierarchy/specs/runtime-schema-api/spec.md
	 */
	public function conversions(): JSONResponse {
		if ($this->conversions === null) {
			return new JSONResponse(['results' => [], 'total' => 0]);
		}

		$supported = $this->conversions->supported();

		return new JSONResponse(['results' => $supported, 'total' => count($supported)]);
	}//end conversions()

	/**
	 * Preview what converting one property's type would cost.
	 *
	 * Answers over the values the schema's objects actually hold: how many
	 * convert, how many do not, and a sample of the ones that do not. An
	 * unsupported conversion is refused here with its reason and nothing is
	 * attempted, which is the difference between a decision and a data-loss
	 * incident discovered months later.
	 *
	 * @param int $id The schema id.
	 *
	 * @return JSONResponse The preview, or a refusal with its reason.
	 *
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/code-list-lifecycle-and-hierarchy/specs/runtime-schema-api/spec.md
	 */
	public function previewConversion(int $id): JSONResponse {
		if ($this->conversions === null || $this->objectReader->canReadValues() === false) {
			return new JSONResponse(['error' => 'Property conversion is not configured'], 501);
		}

		try {
			$schema = $this->schemaMapper->find($id);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(['error' => 'Schema not found'], 404);
		}

		$property = trim((string)$this->request->getParam('property', ''));
		$target = trim((string)$this->request->getParam('to', ''));
		if ($property === '' || $target === '') {
			return new JSONResponse(['error' => 'Both "property" and "to" are required'], 422);
		}

		$properties = ($schema->getProperties() ?? []);
		$definition = ($properties[$property] ?? null);
		if (is_array($definition) === false) {
			return new JSONResponse(
				['error' => sprintf('The schema has no property "%s"', $property)],
				404
			);
		}

		$current = trim((string)($definition['type'] ?? 'string'));

		if ($this->conversions->isSupported(from: $current, to: $target) === false) {
			return new JSONResponse(
				[
					'error' => $this->conversions->refusalReason(from: $current, to: $target),
					'property' => $property,
					'from' => $current,
					'to' => $target,
					'supported' => false,
				],
				422
			);
		}

		$values = $this->objectReader->storedValues(schemaId: $id, property: $property);
		$preview = $this->conversions->preview(values: $values, from: $current, to: $target);
		$preview['property'] = $property;
		$preview['from'] = $current;
		$preview['to'] = $target;

		return new JSONResponse($preview);
	}//end previewConversion()

	/**
	 * Get a schema's classified changelog, newest-first.
	 *
	 * @param int $id The schema id.
	 *
	 * @return JSONResponse The changelog entries.
	 *
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/schema-migration/spec.md
	 */
	public function changelog(int $id): JSONResponse {
		$limit = $this->intParam(name: '_limit');
		$offset = $this->intParam(name: '_offset');

		$entries = $this->changelogMapper->findBySchema(schemaId: $id, limit: $limit, offset: $offset);

		return new JSONResponse(['results' => array_map(static fn ($e) => $e->jsonSerialize(), $entries)]);
	}//end changelog()

	/**
	 * Start a revalidation (impact-analysis) run for a schema.
	 *
	 * @param int $id The schema id.
	 *
	 * @return JSONResponse The created run, or an error.
	 *
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/schema-migration/spec.md
	 */
	public function revalidate(int $id): JSONResponse {
		try {
			$this->schemaMapper->find($id);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(['error' => 'Schema not found'], 404);
		}

		$registerId = $this->objectReader->resolveRegisterId(schemaId: $id);
		if ($registerId === null) {
			return new JSONResponse(['error' => 'No register contains this schema'], 422);
		}

		$proposed = $this->request->getParam('proposedDefinition');
		if (is_array($proposed) === false) {
			$proposed = null;
		}

		try {
			$run = $this->revalidationService->start(
				schemaId: $id,
				registerId: $registerId,
				proposedDefinition: $proposed,
				startedBy: $this->currentUid()
			);
		} catch (SchemaRunConcurrencyException $e) {
			return new JSONResponse(['error' => $e->getMessage()], 409);
		}

		$this->jobList->add(\OCA\OpenRegister\BackgroundJob\SchemaRunJob::class, ['run_id' => $run->getId()]);

		return new JSONResponse($run->jsonSerialize(), 201);
	}//end revalidate()

	/**
	 * List runs for a schema.
	 *
	 * @param int $id The schema id.
	 *
	 * @return JSONResponse The runs.
	 *
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/schema-migration/spec.md
	 */
	public function runs(int $id): JSONResponse {
		$runs = $this->runMapper->findBySchema(
			schemaId: $id,
			limit: $this->intParam(name: '_limit'),
			offset: $this->intParam(name: '_offset')
		);

		return new JSONResponse(['results' => array_map(static fn ($r) => $r->jsonSerialize(), $runs)]);
	}//end runs()

	/**
	 * Get a single run's status + report (with per-object entries).
	 *
	 * @param int $id The schema id.
	 * @param int $run The run id.
	 *
	 * @return JSONResponse The run + entries.
	 *
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/schema-migration/spec.md
	 */
	public function run(int $id, int $run): JSONResponse {
		try {
			$entity = $this->runMapper->find($run);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(['error' => 'Run not found'], 404);
		}

		if ($entity->getSchemaId() !== $id) {
			return new JSONResponse(['error' => 'Run does not belong to this schema'], 404);
		}

		$outcome = $this->request->getParam('outcome');
		if (is_string($outcome) === false) {
			$outcome = null;
		}

		$entries = $this->runEntryMapper->findByRun(
			runId: $run,
			outcome: $outcome,
			limit: $this->intParam(name: '_limit'),
			offset: $this->intParam(name: '_offset')
		);

		$payload = $entity->jsonSerialize();
		$payload['entries'] = array_map(static fn ($e) => $e->jsonSerialize(), $entries);

		return new JSONResponse($payload);
	}//end run()

	/**
	 * Preview a migration plan against a bounded sample.
	 *
	 * @param int $id The schema id.
	 *
	 * @return JSONResponse Before/after pairs, or a plan-validation error.
	 *
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/schema-migration/spec.md
	 */
	public function previewMigration(int $id): JSONResponse {
		try {
			$this->schemaMapper->find($id);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(['error' => 'Schema not found'], 404);
		}

		$plan = $this->request->getParam('plan');
		if (is_array($plan) === false) {
			return new JSONResponse(['error' => 'A "plan" array is required'], 422);
		}

		$problems = $this->migrationService->validatePlan($plan);
		if (count($problems) > 0) {
			return new JSONResponse(['error' => 'Invalid migration plan', 'problems' => $problems], 422);
		}

		$registerId = $this->objectReader->resolveRegisterId(schemaId: $id);
		if ($registerId === null) {
			return new JSONResponse(['error' => 'No register contains this schema'], 422);
		}

		$sample = (int)($this->request->getParam('sample') ?? SchemaMigrationService::DEFAULT_PREVIEW_SAMPLE);

		$pairs = $this->migrationService->preview($id, $registerId, $plan, $sample);

		return new JSONResponse(['results' => $pairs]);
	}//end previewMigration()

	/**
	 * Execute a migration plan over a schema's population (background).
	 *
	 * @param int $id The schema id.
	 *
	 * @return JSONResponse The created run, or an error.
	 *
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/schema-migration/spec.md
	 */
	public function migrate(int $id): JSONResponse {
		try {
			$this->schemaMapper->find($id);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(['error' => 'Schema not found'], 404);
		}

		$plan = $this->request->getParam('plan');
		if (is_array($plan) === false) {
			return new JSONResponse(['error' => 'A "plan" array is required'], 422);
		}

		$registerId = $this->objectReader->resolveRegisterId(schemaId: $id);
		if ($registerId === null) {
			return new JSONResponse(['error' => 'No register contains this schema'], 422);
		}

		$options = $this->request->getParam('options');
		if (is_array($options) === false) {
			$options = [];
		}

		try {
			$run = $this->migrationService->start(
				schemaId: $id,
				registerId: $registerId,
				plan: $plan,
				options: $options,
				startedBy: $this->currentUid()
			);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['error' => 'Invalid migration plan', 'problems' => [$e->getMessage()]], 422);
		} catch (SchemaRunConcurrencyException $e) {
			return new JSONResponse(['error' => $e->getMessage()], 409);
		}

		$this->jobList->add(\OCA\OpenRegister\BackgroundJob\SchemaRunJob::class, ['run_id' => $run->getId()]);

		return new JSONResponse($run->jsonSerialize(), 201);
	}//end migrate()

	/**
	 * Roll a migration run back.
	 *
	 * @param int $id The schema id.
	 * @param int $run The migration run id.
	 *
	 * @return JSONResponse The rolled-back run, or an error.
	 *
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/schema-migration/spec.md
	 */
	public function rollback(int $id, int $run): JSONResponse {
		try {
			$entity = $this->runMapper->find($run);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(['error' => 'Run not found'], 404);
		}

		if ($entity->getSchemaId() !== $id) {
			return new JSONResponse(['error' => 'Run does not belong to this schema'], 404);
		}

		try {
			$result = $this->migrationService->rollback($run, $this->currentUid());
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], 422);
		} catch (SchemaRunConcurrencyException $e) {
			return new JSONResponse(['error' => $e->getMessage()], 409);
		}

		return new JSONResponse($result->jsonSerialize());
	}//end rollback()

	/**
	 * Read an optional integer query parameter.
	 *
	 * @param string $name The parameter name.
	 *
	 * @return int|null The value, or null when absent.
	 */
	private function intParam(string $name): ?int {
		$value = $this->request->getParam($name);
		if ($value === null || is_numeric($value) === false) {
			return null;
		}

		return (int)$value;
	}//end intParam()

	/**
	 * The current user id, or null.
	 *
	 * @return string|null The uid.
	 */
	private function currentUid(): ?string {
		$user = $this->userSession->getUser();
		if ($user !== null) {
			return $user->getUID();
		}

		return null;
	}//end currentUid()
}//end class
