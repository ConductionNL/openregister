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

use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Controller\Support\MigrationStart;
use OCA\OpenRegister\Controller\Support\PropertyConversionChange;
use OCA\OpenRegister\Controller\Support\RetentionPlatform;
use OCA\OpenRegister\Controller\Support\SchemaMigrationControllerServices;
use OCA\OpenRegister\Controller\Support\SchemaMigrationDomain;
use OCA\OpenRegister\Controller\Support\SchemaMigrationMappers;
use OCA\OpenRegister\Db\ListQuery;
use OCA\OpenRegister\Db\OutcomeFilter;
use OCA\OpenRegister\Db\QueryPage;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaRun;
use OCA\OpenRegister\Exception\SchemaRunConcurrencyException;
use OCA\OpenRegister\Service\Schema\MigrationRequest;
use OCA\OpenRegister\Service\Schema\PropertyConversionServiceInterface;
use OCA\OpenRegister\Service\Schema\RevalidationRequest;
use OCA\OpenRegister\Service\Schema\SchemaMigrationServiceInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Controller for schema versioning, revalidation and migration.
 */
class SchemaMigrationController extends Controller {

	/**
	 * Schema, changelog, run and run-entry lookups.
	 *
	 * @var SchemaMigrationMappers
	 */
	private readonly SchemaMigrationMappers $mappers;

	/**
	 * Revalidation, migration, value reading and type conversion.
	 *
	 * @var SchemaMigrationDomain
	 */
	private readonly SchemaMigrationDomain $domain;

	/**
	 * Job list, current user and logger.
	 *
	 * @var RetentionPlatform
	 */
	private readonly RetentionPlatform $platform;

	/**
	 * Constructor.
	 *
	 * @param IRequest $request Request.
	 * @param SchemaMigrationControllerServices $services The schema migration controller services.
	 */
	public function __construct(
		IRequest $request,
		SchemaMigrationControllerServices $services,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
		$this->mappers = $services->mappers;
		$this->domain = $services->domain;
		$this->platform = $services->platform;

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
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	public function conversions(): JSONResponse {
		if ($this->domain->conversions === null) {
			return new JSONResponse(['results' => [], 'total' => 0]);
		}

		$supported = $this->domain->conversions->supported();

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
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	public function previewConversion(int $id): JSONResponse {
		$conversions = $this->domain->conversions;
		if ($conversions === null || $this->domain->objectReader->canReadValues() === false) {
			return new JSONResponse(['error' => 'Property conversion is not configured'], 501);
		}

		$schema = $this->findSchema(id: $id);
		if ($schema instanceof JSONResponse) {
			return $schema;
		}

		$change = $this->requestedConversion(schema: $schema, schemaId: $id);
		if ($change instanceof JSONResponse) {
			return $change;
		}

		return $this->conversionPreview(conversions: $conversions, change: $change);
	}//end previewConversion()

	/**
	 * The property and target type the caller asked to convert, with the current type.
	 *
	 * @param Schema $schema   The schema the property belongs to.
	 * @param int    $schemaId The schema id the caller named.
	 *
	 * @return PropertyConversionChange|JSONResponse The change, or a 422/404 refusal.
	 */
	private function requestedConversion(Schema $schema, int $schemaId): PropertyConversionChange|JSONResponse {
		$property = trim((string)$this->request->getParam('property', ''));
		$target = trim((string)$this->request->getParam('to', ''));
		if ($property === '' || $target === '') {
			return new JSONResponse(['error' => 'Both "property" and "to" are required'], 422);
		}

		$definition = (($schema->getProperties() ?? [])[$property] ?? null);
		if (is_array($definition) === false) {
			return new JSONResponse(['error' => sprintf('The schema has no property "%s"', $property)], 404);
		}

		$from = trim((string)($definition['type'] ?? 'string'));

		return new PropertyConversionChange(schemaId: $schemaId, property: $property, from: $from, to: $target);
	}//end requestedConversion()

	/**
	 * Preview a supported conversion over the stored values, or refuse an unsupported one.
	 *
	 * @param PropertyConversionServiceInterface $conversions The conversion service.
	 * @param PropertyConversionChange           $change      The requested change.
	 *
	 * @return JSONResponse The preview, or a 422 refusal with its reason.
	 */
	private function conversionPreview(PropertyConversionServiceInterface $conversions, PropertyConversionChange $change): JSONResponse {
		if ($conversions->isSupported(from: $change->from, to: $change->to) === false) {
			return new JSONResponse(
				array_merge(['error' => $conversions->refusalReason(from: $change->from, to: $change->to)], $change->toArray(), ['supported' => false]),
				422
			);
		}

		$values = $this->domain->objectReader->storedValues(schemaId: $change->schemaId, property: $change->property);
		$preview = $conversions->preview(values: $values, from: $change->from, to: $change->to);

		return new JSONResponse(array_replace($preview, $change->toArray()));
	}//end conversionPreview()

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

		$entries = $this->mappers->changelogMapper->findBySchema(
			schemaId: $id,
			query: new ListQuery(limit: $limit ?? ListQuery::UNBOUNDED, offset: $offset)
		);

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
		$registerId = $this->registerIdFor(id: $id);
		if ($registerId instanceof JSONResponse) {
			return $registerId;
		}

		try {
			$run = $this->domain->revalidationService->startRevalidation(
				request: new RevalidationRequest(schemaId: $id, registerId: $registerId, proposedDefinition: $this->proposedDefinition()),
				startedBy: $this->currentUid()
			);
		} catch (SchemaRunConcurrencyException $e) {
			return new JSONResponse(['error' => $e->getMessage()], 409);
		}

		return $this->enqueued(run: $run);
	}//end revalidate()

	/**
	 * The proposed definition a revalidation runs against, or null for the current one.
	 *
	 * @return array<string, mixed>|null The proposed definition.
	 */
	private function proposedDefinition(): ?array {
		$proposed = $this->request->getParam('proposedDefinition');
		if (is_array($proposed) === false) {
			return null;
		}

		return $proposed;
	}//end proposedDefinition()

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
		$runs = $this->mappers->runMapper->findBySchema(
			schemaId: $id,
			query: new ListQuery(limit: $this->intParam(name: '_limit') ?? ListQuery::UNBOUNDED, offset: $this->intParam(name: '_offset'))
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
		$entity = $this->runOfSchema(id: $id, run: $run);
		if ($entity instanceof JSONResponse) {
			return $entity;
		}

		$outcome = $this->request->getParam('outcome');
		if (is_string($outcome) === false) {
			$outcome = null;
		}

		$page    = new QueryPage(limit: $this->intParam(name: '_limit'), offset: $this->intParam(name: '_offset'));
		$entries = $this->mappers->runEntryMapper->findByRun(runId: $run, filter: new OutcomeFilter(outcome: $outcome, page: $page));

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
		$plan = $this->requestedPlan(id: $id);
		if ($plan instanceof JSONResponse) {
			return $plan;
		}

		$problems = $this->domain->migrationService->validatePlan($plan);
		if (count($problems) > 0) {
			return new JSONResponse(['error' => 'Invalid migration plan', 'problems' => $problems], 422);
		}

		$registerId = $this->registerIdOf(id: $id);
		if ($registerId instanceof JSONResponse) {
			return $registerId;
		}

		$request = new MigrationRequest(schemaId: $id, registerId: $registerId, plan: $plan);
		return new JSONResponse(['results' => $this->domain->migrationService->previewSample(request: $request, sampleSize: $this->sampleSize())]);
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
		$plan = $this->requestedPlan(id: $id);
		if ($plan instanceof JSONResponse) {
			return $plan;
		}

		$registerId = $this->registerIdOf(id: $id);
		if ($registerId instanceof JSONResponse) {
			return $registerId;
		}

		return $this->startMigration(start: new MigrationStart(schemaId: $id, registerId: $registerId, plan: $plan));
	}//end migrate()

	/**
	 * How many objects a migration preview samples.
	 *
	 * @return int The sample size.
	 */
	private function sampleSize(): int {
		return (int)($this->request->getParam('sample') ?? SchemaMigrationServiceInterface::DEFAULT_PREVIEW_SAMPLE);
	}//end sampleSize()

	/**
	 * Start a migration run with the requested options and enqueue it.
	 *
	 * @param MigrationStart $start The schema, its register and the migration plan.
	 *
	 * @return JSONResponse The created run, or a 422/409 error.
	 */
	private function startMigration(MigrationStart $start): JSONResponse {
		try {
			$run = $this->domain->migrationService->startMigration(
				request: new MigrationRequest(schemaId: $start->schemaId, registerId: $start->registerId, plan: $start->plan, options: $this->migrationOptions()),
				startedBy: $this->currentUid()
			);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['error' => 'Invalid migration plan', 'problems' => [$e->getMessage()]], 422);
		} catch (SchemaRunConcurrencyException $e) {
			return new JSONResponse(['error' => $e->getMessage()], 409);
		}

		return $this->enqueued(run: $run);
	}//end startMigration()

	/**
	 * The options a migration run is started with; none when the caller sent no array.
	 *
	 * @return array<string, mixed> The options.
	 */
	private function migrationOptions(): array {
		$options = $this->request->getParam('options');
		if (is_array($options) === false) {
			return [];
		}

		return $options;
	}//end migrationOptions()

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
		$entity = $this->runOfSchema(id: $id, run: $run);
		if ($entity instanceof JSONResponse) {
			return $entity;
		}

		try {
			$result = $this->domain->migrationService->rollback($run, $this->currentUid());
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], 422);
		} catch (SchemaRunConcurrencyException $e) {
			return new JSONResponse(['error' => $e->getMessage()], 409);
		}

		return new JSONResponse($result->jsonSerialize());
	}//end rollback()

	/**
	 * The schema, or the 404 when it does not exist.
	 *
	 * @param int $id The schema id.
	 *
	 * @return Schema|JSONResponse The schema, or the refusal.
	 */
	private function findSchema(int $id): Schema|JSONResponse {
		try {
			return $this->mappers->schemaMapper->find($id);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(['error' => 'Schema not found'], 404);
		}
	}//end findSchema()

	/**
	 * The register an existing schema's objects live in, or the refusal.
	 *
	 * @param int $id The schema id.
	 *
	 * @return int|JSONResponse The register id, or a 404/422 refusal.
	 */
	private function registerIdFor(int $id): int|JSONResponse {
		$schema = $this->findSchema(id: $id);
		if ($schema instanceof JSONResponse) {
			return $schema;
		}

		return $this->registerIdOf(id: $id);
	}//end registerIdFor()

	/**
	 * The register containing a schema, or the 422 when none does.
	 *
	 * @param int $id The schema id.
	 *
	 * @return int|JSONResponse The register id, or the refusal.
	 */
	private function registerIdOf(int $id): int|JSONResponse {
		$registerId = $this->domain->objectReader->resolveRegisterId(schemaId: $id);
		if ($registerId === null) {
			return new JSONResponse(['error' => 'No register contains this schema'], 422);
		}

		return $registerId;
	}//end registerIdOf()

	/**
	 * The migration plan the caller sent for an existing schema, or the refusal.
	 *
	 * @param int $id The schema id.
	 *
	 * @return array<int|string, mixed>|JSONResponse The plan, or a 404/422 refusal.
	 */
	private function requestedPlan(int $id): array|JSONResponse {
		$schema = $this->findSchema(id: $id);
		if ($schema instanceof JSONResponse) {
			return $schema;
		}

		$plan = $this->request->getParam('plan');
		if (is_array($plan) === false) {
			return new JSONResponse(['error' => 'A "plan" array is required'], 422);
		}

		return $plan;
	}//end requestedPlan()

	/**
	 * A run that belongs to the schema, or the 404.
	 *
	 * @param int $id The schema id.
	 * @param int $run The run id.
	 *
	 * @return SchemaRun|JSONResponse The run, or the refusal.
	 */
	private function runOfSchema(int $id, int $run): SchemaRun|JSONResponse {
		try {
			$entity = $this->mappers->runMapper->find($run);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(['error' => 'Run not found'], 404);
		}

		if ($entity->getSchemaId() !== $id) {
			return new JSONResponse(['error' => 'Run does not belong to this schema'], 404);
		}

		return $entity;
	}//end runOfSchema()

	/**
	 * Enqueue a created run for background processing and answer it as created.
	 *
	 * @param SchemaRun $run The created run.
	 *
	 * @return JSONResponse The 201 with the run.
	 */
	private function enqueued(SchemaRun $run): JSONResponse {
		$this->platform->jobList->add(\OCA\OpenRegister\BackgroundJob\JobHandles::SCHEMA_RUN, ['run_id' => $run->getId()]);

		return new JSONResponse($run->jsonSerialize(), 201);
	}//end enqueued()

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
		$user = $this->platform->userSession->getUser();
		if ($user !== null) {
			return $user->getUID();
		}

		return null;
	}//end currentUid()
}//end class
