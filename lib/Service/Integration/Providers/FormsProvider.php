<?php

/**
 * FormsProvider — exposes NC Forms entities (forms + submissions)
 * linked to an OpenRegister object via the IntegrationProvider
 * contract.
 *
 * Linking convention until a dedicated `openregister_form_links`
 * link-table ships: an OR-aware author seeds the form's `description`
 * field with the substring `[or:{objectUuid}]`. The provider resolves
 * forms matching that marker via NC Forms' own `FormMapper`, then
 * lists submissions per matched form via `SubmissionMapper` so the
 * registry tab can show both pending forms and historical responses
 * for the object. Mapper lookups are resolved lazily through the NC
 * server container so the file loads even when the Forms app is not
 * installed (AD-23: graceful degradation).
 *
 * Storage strategy is `link-table` — the link lives in the upstream
 * app's tables (`forms_v2_forms.description` + `forms_v2_submissions`),
 * not in OR. The marker-in-description convention is the bridge until
 * the bespoke link-table + FormResponseService + FormResponsesController
 * land (tracked in tasks.md; out of scope for this Bucket-A stub
 * completion).
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration\Providers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/integration-forms/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration\Providers;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- self-documenting IntegrationProvider metadata getters mirror the contract in the interface.

use OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider;
use OCP\App\IAppManager;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\Server;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Throwable;

/**
 * Forms (NC Forms) integration provider.
 *
 * Always-on metadata: id='forms', group='workflow', requiredApp='forms',
 * storage='link-table'. The provider is list-only — mutation (creating
 * forms, recording submissions) belongs to NC Forms itself; the
 * registry's job is to surface what's already there.
 */
class FormsProvider extends AbstractIntegrationProvider
{

    /**
     * NC app id required for this integration.
     *
     * @var string
     */
    private const REQUIRED_APP = 'forms';

    /**
     * Marker prefix seeded into a form's `description` field by the
     * OR-aware author to link the form to an OR object. Full marker
     * shape: `[or:{objectUuid}]`.
     *
     * @var string
     */
    private const MARKER_PREFIX = '[or:';

    /**
     * Maximum number of submissions to surface per linked form. The
     * registry tab is a quick-look surface, not a full submissions
     * browser; the NC Forms results page handles the long tail.
     *
     * @var int
     */
    private const SUBMISSIONS_PER_FORM = 25;

    /**
     * Optional server container override. Defaults to NC's global
     * `\OCP\Server` container — tests inject a mock so the FormMapper
     * lookup is exerciseable without the Forms app on the classpath.
     *
     * @var ContainerInterface|null
     */
    private ?ContainerInterface $container;

    /**
     * Constructor.
     *
     * Required args mirror the greenfield-provider DI signature
     * (`db, appManager, l10n`) so the shared `Application.php`
     * registration block still wires this provider correctly. The
     * optional container override is for unit tests only; production
     * uses `\OCP\Server::get(...)` via {@see resolveContainer()}.
     *
     * @param IDBConnection           $db         NC DB connection (unused at runtime since
     *                                            mapper lookups go through the container —
     *                                            kept for parity with the greenfield
     *                                            registration block).
     * @param IAppManager             $appManager NC app manager — drives `isEnabled()`.
     * @param IL10N                   $l10n       Localisation.
     * @param ContainerInterface|null $container  Optional server-container override
     *                                            (tests only).
     *
     * @return void
     */
    public function __construct(
        private IDBConnection $db,
        private IAppManager $appManager,
        private IL10N $l10n,
        ?ContainerInterface $container=null,
    ) {
        $this->container = $container;
    }//end __construct()

    public function getId(): string
    {
        return 'forms';
    }//end getId()

    public function getLabel(): string
    {
        return $this->l10n->t('Forms');
    }//end getLabel()

    public function getIcon(): string
    {
        return 'ClipboardText';
    }//end getIcon()

    public function getGroup(): ?string
    {
        return 'workflow';
    }//end getGroup()

    public function getRequiredApp(): ?string
    {
        return self::REQUIRED_APP;
    }//end getRequiredApp()

    public function getStorageStrategy(): string
    {
        return 'link-table';
    }//end getStorageStrategy()

    public function isEnabled(): bool
    {
        return $this->appManager->isInstalled(self::REQUIRED_APP);
    }//end isEnabled()

    /**
     * List NC Forms (and their submissions) linked to an OR object.
     *
     * Flow:
     *   1. Return `[]` immediately when the Forms app isn't installed.
     *   2. Look up `OCA\Forms\Db\FormMapper` from the server container.
     *      The class only exists when the Forms app is enabled, so the
     *      lookup is wrapped in a `Throwable` catch — schema mismatches
     *      and classpath misses both degrade to an empty list (AD-23).
     *   3. Pull every form whose `description` contains the
     *      `[or:{objectId}]` marker. Forms's own `findById` /
     *      `findAllByOwnerId` aren't marker-aware so we run the LIKE
     *      query directly via the FormMapper's table name.
     *   4. For each matched form, fetch up to {@see SUBMISSIONS_PER_FORM}
     *      submissions via the lazily-resolved `SubmissionMapper` and
     *      flatten them into the leaf-row contract alongside the form.
     *
     * Row shape:
     *   - `type`        — `'form'` or `'submission'`
     *   - `id`          — form id (string) or "{formId}/{submissionId}"
     *   - `title`       — form title (forms) or submission timestamp
     *                     fallback (submissions)
     *   - `description` — form description with marker stripped (forms)
     *                     or the user that submitted (submissions)
     *   - `url`         — `/index.php/apps/forms/{hash}` for forms or
     *                     `/index.php/apps/forms/{hash}/results` for
     *                     submissions
     *   - `data`        — the raw upstream row, useful for the widget
     *
     * @param string              $register Register slug or numeric id (unused — link convention
     *                                      is per-object).
     * @param string              $schema   Schema slug or numeric id (unused).
     * @param string              $objectId Owning object uuid.
     * @param array<string,mixed> $filters  Reserved.
     *
     * @return array<int,array<string,mixed>>
     */
    public function list(string $register, string $schema, string $objectId, array $filters=[]): array
    {
        if ($this->isEnabled() === false) {
            return [];
        }

        try {
            $formMapper = $this->lookup(serviceName: 'OCA\\Forms\\Db\\FormMapper');
        } catch (Throwable $e) {
            // Forms app classpath not loaded, or unexpected DI failure
            // — registry tabs degrade to the empty state per AD-23.
            return [];
        }

        $forms = $this->findFormsByMarker(formMapper: $formMapper, objectId: $objectId);
        if (count($forms) === 0) {
            return [];
        }

        // Submission lookup is best-effort — a Forms install that's
        // missing the submissions table (older NC Forms versions) is
        // still surfaced as a forms-only list.
        $submissionMapper = null;
        try {
            $submissionMapper = $this->lookup(serviceName: 'OCA\\Forms\\Db\\SubmissionMapper');
        } catch (Throwable $e) {
            $submissionMapper = null;
        }

        $rows = [];
        foreach ($forms as $form) {
            $formRow = $this->normaliseForm(form: $form, objectId: $objectId);
            $rows[]  = $formRow;
            $rows    = array_merge(
                $rows,
                $this->collectSubmissions(
                    submissionMapper: $submissionMapper,
                    formId: (int) $formRow['data']['id'],
                    formHash: (string) $formRow['data']['hash'],
                    formTitle: (string) $formRow['title']
                )
            );
        }

        return $rows;
    }//end list()

    /**
     * Provider health descriptor.
     *
     * Mirrors the umbrella registry's missing-app shape — status
     * `'unavailable'` + a human-readable message — when the NC Forms
     * app isn't installed. Otherwise the provider self-reports as
     * configured + ok; auth is implicit (the integration uses the
     * current NC user's permissions).
     *
     * @return array<string,mixed>
     */
    public function health(): array
    {
        $available = $this->isEnabled();
        return [
            'status'     => $available === true ? 'ok' : 'unavailable',
            'authStatus' => 'configured',
            'message'    => $available === true ? null : 'NC Forms app is not installed',
        ];
    }//end health()

    /**
     * Resolve a service from the container.
     *
     * Routes through the test-injected container override when
     * present, otherwise delegates to NC's global `\OCP\Server` static
     * lookup. Encapsulating the resolution here keeps the rest of the
     * provider DI-clean and lets the unit tests inject mock mappers
     * without touching the production code path.
     *
     * @param string $serviceName Fully qualified class name to resolve.
     *
     * @return object Resolved service instance.
     *
     * @SuppressWarnings(PHPMD.StaticAccess) `\OCP\Server::get()` is the
     * NC-canonical service-locator entry point for late-bound classes;
     * see MarkerLookupTrait.php for the same pattern.
     */
    private function lookup(string $serviceName): object
    {
        if ($this->container !== null) {
            $resolved = $this->container->get($serviceName);
            if (is_object($resolved) === false) {
                throw new RuntimeException(sprintf('Container returned non-object for %s', $serviceName));
            }

            return $resolved;
        }

        return Server::get($serviceName);
    }//end lookup()

    /**
     * Find NC Forms whose `description` contains the OR object marker.
     *
     * `FormMapper` doesn't expose a marker-aware finder so we run the
     * LIKE directly via its DB connection (accessible via the public
     * `getTableName()` method). Any failure degrades to an empty list.
     *
     * @param object $formMapper NC Forms FormMapper instance (typed
     *                           loosely so the file loads without the
     *                           Forms classpath).
     * @param string $objectId   Owning object uuid.
     *
     * @return array<int,object> Matching Form entities (loose-typed
     *                           for the same reason).
     */
    private function findFormsByMarker(object $formMapper, string $objectId): array
    {
        $marker = self::MARKER_PREFIX.$objectId.']';

        try {
            $tableName = $formMapper->getTableName();
            $qb        = $this->db->getQueryBuilder();
            $qb->select('*')
                ->from($tableName)
                ->where(
                    $qb->expr()->iLike(
                        'description',
                        $qb->createNamedParameter('%'.$this->db->escapeLikeParameter($marker).'%')
                    )
                )
                ->orderBy('last_updated', 'DESC');

            $result = $qb->executeQuery();
            $rows   = [];
            $row    = $result->fetch();
            while ($row !== false) {
                $rows[] = (object) $row;
                $row    = $result->fetch();
            }

            $result->closeCursor();
            return $rows;
        } catch (Throwable $e) {
            return [];
        }//end try
    }//end findFormsByMarker()

    /**
     * Normalise a raw forms_v2_forms row into a registry leaf row.
     *
     * The `description` field is shown with the OR marker stripped so
     * the registry tab doesn't leak the linking convention into the
     * UI label.
     *
     * @param object $form     Raw form row (cast from associative array
     *                         in {@see findFormsByMarker}).
     * @param string $objectId Owning object uuid (used to strip the
     *                         marker).
     *
     * @return array<string,mixed>
     */
    private function normaliseForm(object $form, string $objectId): array
    {
        // Note: NC Forms exposes the timestamp column as snake_case
        // `last_updated` in the raw fetch — dynamic property access via
        // a variable column name avoids declaring a camelCase alias
        // that phpcs would flag.
        $lastUpdatedColumn = 'last_updated';
        $lastUpdated       = isset($form->{$lastUpdatedColumn}) === true ? (int) $form->{$lastUpdatedColumn} : null;

        $marker    = self::MARKER_PREFIX.$objectId.']';
        $rawDesc   = isset($form->description) === true ? (string) $form->description : '';
        $cleanDesc = trim(str_replace($marker, '', $rawDesc));
        $hash      = isset($form->hash) === true ? (string) $form->hash : '';
        $id        = isset($form->id) === true ? (string) $form->id : '';
        $title     = isset($form->title) === true ? (string) $form->title : '';

        return [
            'type'        => 'form',
            'id'          => $id,
            'title'       => $title,
            'description' => $cleanDesc,
            'url'         => '/index.php/apps/forms/'.$hash,
            'lastUpdated' => $lastUpdated,
            'data'        => [
                'id'          => $id,
                'hash'        => $hash,
                'title'       => $title,
                'description' => $cleanDesc,
                'lastUpdated' => $lastUpdated,
            ],
        ];
    }//end normaliseForm()

    /**
     * Collect submission rows for a single linked form.
     *
     * Submissions are surfaced with newest-first ordering (matches
     * SubmissionMapper::findByForm's default) and capped at
     * {@see SUBMISSIONS_PER_FORM}. Returns an empty array when the
     * SubmissionMapper isn't available or the lookup fails.
     *
     * @param object|null $submissionMapper SubmissionMapper instance
     *                                      (or null when unavailable).
     * @param int         $formId           Numeric id of the linked form.
     * @param string      $formHash         Form hash (for URL building).
     * @param string      $formTitle        Form title (for label fallback).
     *
     * @return array<int,array<string,mixed>>
     */
    private function collectSubmissions(
        ?object $submissionMapper,
        int $formId,
        string $formHash,
        string $formTitle
    ): array {
        if ($submissionMapper === null) {
            return [];
        }

        try {
            $submissions = $submissionMapper->findByForm(
                $formId,
                null,
                null,
                self::SUBMISSIONS_PER_FORM,
                0
            );
        } catch (Throwable $e) {
            return [];
        }

        $rows = [];
        foreach ($submissions as $submission) {
            $rows[] = $this->normaliseSubmission(
                submission: $submission,
                formId: $formId,
                formHash: $formHash,
                formTitle: $formTitle
            );
        }

        return $rows;
    }//end collectSubmissions()

    /**
     * Normalise a Submission entity (or array) into a leaf row.
     *
     * Submissions don't carry a user-facing title — we synthesise one
     * from the form title + ISO 8601 timestamp.
     *
     * @param object|array<string,mixed> $submission Submission row.
     * @param int                        $formId     Numeric id of the parent form.
     * @param string                     $formHash   Form hash for URL building.
     * @param string                     $formTitle  Form title for label.
     *
     * @return array<string,mixed>
     */
    private function normaliseSubmission(
        object|array $submission,
        int $formId,
        string $formHash,
        string $formTitle
    ): array {
        // Accept either an entity (with getters) or a plain assoc
        // array — keeps the test mocks honest without forcing them to
        // import the Forms entity class.
        $submissionId = $this->readField(row: $submission, field: 'id', getter: 'getId');
        $userId       = $this->readField(row: $submission, field: 'userId', getter: 'getUserId');
        $timestamp    = $this->readField(row: $submission, field: 'timestamp', getter: 'getTimestamp');
        $tsInt        = $timestamp !== null ? (int) $timestamp : null;

        $isoTimestamp = $tsInt !== null ? gmdate('c', $tsInt) : '';
        $titleSuffix  = $isoTimestamp !== '' ? ' — '.$isoTimestamp : '';

        return [
            'type'        => 'submission',
            'id'          => $formId.'/'.(string) $submissionId,
            'title'       => $formTitle.$titleSuffix,
            'description' => $userId !== null ? (string) $userId : '',
            'url'         => '/index.php/apps/forms/'.$formHash.'/results',
            'lastUpdated' => $tsInt,
            'data'        => [
                'id'        => $submissionId,
                'formId'    => $formId,
                'userId'    => $userId,
                'timestamp' => $tsInt,
            ],
        ];
    }//end normaliseSubmission()

    /**
     * Read a field from an entity-or-array. Falls back to the entity
     * getter when the array access yields null, so the helper survives
     * both `(object) ['userId' => 'alice']` test fixtures and real
     * `Submission` entities.
     *
     * @param object|array<string,mixed> $row    Source row.
     * @param string                     $field  Property / array key.
     * @param string                     $getter Entity getter method.
     *
     * @return mixed|null
     */
    private function readField(object|array $row, string $field, string $getter): mixed
    {
        if (is_array($row) === true) {
            return $row[$field] ?? null;
        }

        if (property_exists($row, $field) === true && $row->{$field} !== null) {
            return $row->{$field};
        }

        if (method_exists($row, $getter) === true) {
            try {
                return $row->{$getter}();
            } catch (Throwable $e) {
                return null;
            }
        }

        return null;
    }//end readField()
}//end class
