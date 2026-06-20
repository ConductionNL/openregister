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

use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaChangelogMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\SchemaRunEntryMapper;
use OCA\OpenRegister\Db\SchemaRunMapper;
use OCA\OpenRegister\Exception\SchemaRunConcurrencyException;
use OCA\OpenRegister\Service\Schema\SchemaMigrationService;
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
class SchemaMigrationController extends Controller
{
    /**
     * Constructor.
     *
     * @param string                    $appName             App name.
     * @param IRequest                  $request             Request.
     * @param SchemaMapper              $schemaMapper        Schema lookup.
     * @param RegisterMapper            $registerMapper      Register lookup (resolve population register).
     * @param SchemaChangelogMapper     $changelogMapper     Changelog read.
     * @param SchemaRunMapper           $runMapper           Run read.
     * @param SchemaRunEntryMapper      $runEntryMapper      Run entry read.
     * @param SchemaRevalidationService $revalidationService Revalidation engine.
     * @param SchemaMigrationService    $migrationService    Migration engine.
     * @param IJobList                  $jobList             Job list (enqueue runs).
     * @param IUserSession              $userSession         Current user.
     * @param LoggerInterface           $logger              Logger.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly SchemaMapper $schemaMapper,
        private readonly RegisterMapper $registerMapper,
        private readonly SchemaChangelogMapper $changelogMapper,
        private readonly SchemaRunMapper $runMapper,
        private readonly SchemaRunEntryMapper $runEntryMapper,
        private readonly SchemaRevalidationService $revalidationService,
        private readonly SchemaMigrationService $migrationService,
        private readonly IJobList $jobList,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct(appName: $appName, request: $request);

    }//end __construct()

    /**
     * Get a schema's classified changelog, newest-first.
     *
     * @param int $id The schema id.
     *
     * @return JSONResponse The changelog entries.
     *
     * @spec openspec/changes/schema-versioning-and-object-migration/specs/schema-migration/spec.md
     */
    public function changelog(int $id): JSONResponse
    {
        $limit  = $this->intParam('_limit');
        $offset = $this->intParam('_offset');

        $entries = $this->changelogMapper->findBySchema($id, $limit, $offset);

        return new JSONResponse(['results' => array_map(static fn($e) => $e->jsonSerialize(), $entries)]);

    }//end changelog()

    /**
     * Start a revalidation (impact-analysis) run for a schema.
     *
     * @param int $id The schema id.
     *
     * @return JSONResponse The created run, or an error.
     *
     * @spec openspec/changes/schema-versioning-and-object-migration/specs/schema-migration/spec.md
     */
    public function revalidate(int $id): JSONResponse
    {
        try {
            $this->schemaMapper->find($id);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Schema not found'], 404);
        }

        $registerId = $this->resolveRegisterId($id);
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
     * @spec openspec/changes/schema-versioning-and-object-migration/specs/schema-migration/spec.md
     */
    public function runs(int $id): JSONResponse
    {
        $runs = $this->runMapper->findBySchema($id, $this->intParam('_limit'), $this->intParam('_offset'));

        return new JSONResponse(['results' => array_map(static fn($r) => $r->jsonSerialize(), $runs)]);

    }//end runs()

    /**
     * Get a single run's status + report (with per-object entries).
     *
     * @param int $id  The schema id.
     * @param int $run The run id.
     *
     * @return JSONResponse The run + entries.
     *
     * @spec openspec/changes/schema-versioning-and-object-migration/specs/schema-migration/spec.md
     */
    public function run(int $id, int $run): JSONResponse
    {
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
            $run,
            $outcome,
            $this->intParam('_limit'),
            $this->intParam('_offset')
        );

        $payload            = $entity->jsonSerialize();
        $payload['entries'] = array_map(static fn($e) => $e->jsonSerialize(), $entries);

        return new JSONResponse($payload);

    }//end run()

    /**
     * Preview a migration plan against a bounded sample.
     *
     * @param int $id The schema id.
     *
     * @return JSONResponse Before/after pairs, or a plan-validation error.
     *
     * @spec openspec/changes/schema-versioning-and-object-migration/specs/schema-migration/spec.md
     */
    public function previewMigration(int $id): JSONResponse
    {
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

        $registerId = $this->resolveRegisterId($id);
        if ($registerId === null) {
            return new JSONResponse(['error' => 'No register contains this schema'], 422);
        }

        $sample = (int) ($this->request->getParam('sample') ?? SchemaMigrationService::DEFAULT_PREVIEW_SAMPLE);

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
     * @spec openspec/changes/schema-versioning-and-object-migration/specs/schema-migration/spec.md
     */
    public function migrate(int $id): JSONResponse
    {
        try {
            $this->schemaMapper->find($id);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Schema not found'], 404);
        }

        $plan = $this->request->getParam('plan');
        if (is_array($plan) === false) {
            return new JSONResponse(['error' => 'A "plan" array is required'], 422);
        }

        $registerId = $this->resolveRegisterId($id);
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
     * @param int $id  The schema id.
     * @param int $run The migration run id.
     *
     * @return JSONResponse The rolled-back run, or an error.
     *
     * @spec openspec/changes/schema-versioning-and-object-migration/specs/schema-migration/spec.md
     */
    public function rollback(int $id, int $run): JSONResponse
    {
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
     * Resolve a register id that contains the given schema.
     *
     * @param int $schemaId The schema id.
     *
     * @return int|null A register id, or null when none contains the schema.
     */
    private function resolveRegisterId(int $schemaId): ?int
    {
        $explicit = $this->request->getParam('registerId');
        if ($explicit !== null && is_numeric($explicit) === true) {
            return (int) $explicit;
        }

        $registers = $this->registerMapper->findAll(_rbac: false, _multitenancy: false);
        foreach ($registers as $register) {
            $schemas = array_map('strval', ($register->getSchemas() ?? []));
            if (in_array((string) $schemaId, $schemas, true) === true) {
                return (int) $register->getId();
            }
        }

        return null;

    }//end resolveRegisterId()

    /**
     * Read an optional integer query parameter.
     *
     * @param string $name The parameter name.
     *
     * @return int|null The value, or null when absent.
     */
    private function intParam(string $name): ?int
    {
        $value = $this->request->getParam($name);
        if ($value === null || is_numeric($value) === false) {
            return null;
        }

        return (int) $value;

    }//end intParam()

    /**
     * The current user id, or null.
     *
     * @return string|null The uid.
     */
    private function currentUid(): ?string
    {
        $user = $this->userSession->getUser();
        if ($user !== null) {
            return $user->getUID();
        }

        return null;

    }//end currentUid()
}//end class
