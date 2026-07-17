<?php

/**
 * FormsProvider — exposes NC Forms entities linked to an OpenRegister
 * object via the Tier-2 `openregister_form_links` table.
 *
 * Pre-Tier-2 the provider matched a `[or:{objectUuid}]` marker
 * embedded in the entity's `title` field. Tier-2 (this file) reads
 * the dedicated link table instead — the marker convention is
 * retained as a backwards-compat fallback for forms that pre-date
 * the link table.
 *
 * normaliseForm() emits the canonical leaf-row shape including the
 * top-level `status` / `accessible` / `expiresAt` / `submissionCount`
 * keys flagged in phase C-1 of the bespoke UI work (CnFormsTab reads
 * these defensively today and lights up the moment they arrive).
 *
 * Storage strategy is `link-table` — the link rows live in OR; the
 * upstream `forms_v2_forms` table is only read for live status /
 * expiry / hash data.
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration\Providers;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing

use OCA\OpenRegister\Db\FormLink;
use OCA\OpenRegister\Db\FormLinkMapper;
use OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider;
use OCP\App\IAppManager;
use OCP\IDBConnection;
use OCP\IL10N;
use Throwable;

class FormsProvider extends AbstractIntegrationProvider
{
    use MarkerLookupTrait;

    /**
     * NC Forms app id.
     *
     * @var string
     */
    private const REQUIRED_APP = 'forms';

    /**
     * Legacy marker prefix (pre-Tier-2 forms linked via `[or:{uuid}]`
     * in the title field) — retained as a backwards-compat fallback.
     *
     * @var string
     */
    private const MARKER_PREFIX = '[or:';

    /**
     * Constructor.
     *
     * @param IDBConnection  $db             NC DB connection.
     * @param IAppManager    $appManager     NC app manager.
     * @param IL10N          $l10n           Localisation.
     * @param FormLinkMapper $formLinkMapper Form-link mapper (Tier-2 link table).
     */
    public function __construct(
        private IDBConnection $db,
        private IAppManager $appManager,
        private IL10N $l10n,
        private FormLinkMapper $formLinkMapper,
    ) {

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
     * List linked forms (and submissions) for an OR object.
     *
     * Reads the Tier-2 link table first; if no link rows exist it
     * falls back to the legacy `[or:{uuid}]` marker scan so forms
     * that pre-date the link table still surface.
     *
     * The emitted rows widen the registry leaf row contract with
     * top-level `status` / `accessible` / `expiresAt` /
     * `submissionCount` (per phase C-1 widening flag).
     *
     * @param string $register Register slug for the parent object.
     * @param string $schema   Schema slug for the parent object.
     * @param string $objectId UUID of the OR object whose rows we want.
     * @param array  $filters  Optional registry filters (unused).
     *
     * @return array<int,array<string,mixed>>
     *
     * @spec openspec/specs/integration-forms/spec.md
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) $register, $schema and $filters are required by the
     *   IntegrationProvider interface signature (IntegrationProvider::list) — this provider only
     *   uses $objectId but cannot alter the shared contract.
     */
    public function list(string $register, string $schema, string $objectId, array $filters=[]): array
    {
        if ($this->isEnabled() === false) {
            return [];
        }

        // Tier-2 path: read from the link table.
        try {
            $linkRows = $this->formLinkMapper->findByObjectUuid($objectId);
        } catch (Throwable $e) {
            $linkRows = [];
        }

        if (count($linkRows) > 0) {
            return $this->rowsFromLinks(links: $linkRows);
        }

        // Backwards-compat fallback: scan the legacy `[or:{uuid}]`
        // marker in `forms_v2_forms.title`.
        $marker = self::MARKER_PREFIX.$objectId.']';
        $rows   = $this->findByMarker(
            db: $this->db,
            table: 'forms_v2_forms',
            markerColumn: 'title',
            marker: $marker,
            extraColumns: ['description', 'hash', 'expires', 'state'],
            idColumn: 'id',
        );

        return array_map(
            fn (array $row): array => $this->normaliseForm(row: $row),
            $rows
        );

    }//end list()

    /**
     * Provider health descriptor (enabled/disabled echo).
     *
     * @return array<string,mixed>
     *
     * @spec exclude Static enabled/disabled descriptor echoing isEnabled() — no standalone health behaviour;
     *       the health/OCS contract is owned by pluggable-integration-registry task-2.
     */
    public function health(): array
    {
        $available = $this->isEnabled();
        $status    = 'unavailable';
        $message   = 'NC Forms app is not installed';
        if ($available === true) {
            $status  = 'ok';
            $message = null;
        }

        return [
            'status'     => $status,
            'authStatus' => 'configured',
            'message'    => $message,
        ];

    }//end health()

    /**
     * Convert link-table rows into the registry leaf-row shape, joining
     * each form-level row with its live upstream metadata (title /
     * status / expiry / hash) and rolling submission-level rows
     * underneath their parent form.
     *
     * @param FormLink[] $links Link rows from the mapper.
     *
     * @return array<int,array<string,mixed>>
     */
    private function rowsFromLinks(array $links): array
    {
        $byForm          = [];
        $submissionLinks = [];

        foreach ($links as $link) {
            if ($link->getSubmissionId() === null) {
                $byForm[(int) $link->getFormId()] = $link;
                continue;
            }

            $submissionLinks[] = $link;
        }

        $out = [];
        foreach ($byForm as $formId => $link) {
            $upstream = $this->fetchUpstreamForm(formId: $formId);
            $row      = $this->normaliseForm(
                row: $this->mergeFormLinkAndUpstream(link: $link, upstream: $upstream)
            );

            [$count, $lastUpdated] = $this->rollUpSubmissions(
                submissionLinks: $submissionLinks,
                formId: $formId
            );

            $row['submissionCount'] = max($row['submissionCount'], $count);
            if ($lastUpdated !== null) {
                $row['lastUpdated'] = $lastUpdated;
            }

            $out[] = $row;
        }//end foreach

        // Submission-only orphans (no form-level link): synthesise a
        // minimal parent row so the submission still surfaces.
        foreach ($submissionLinks as $sub) {
            $formId = (int) $sub->getFormId();
            if (isset($byForm[$formId]) === true) {
                continue;
            }

            $upstream = $this->fetchUpstreamForm(formId: $formId);
            $row      = $this->normaliseForm(
                row: $this->mergeFormLinkAndUpstream(link: $sub, upstream: $upstream)
            );
            $row['submissionCount'] = max($row['submissionCount'], 1);
            $out[] = $row;
        }

        return $out;

    }//end rowsFromLinks()

    /**
     * Roll up submission links for a given form into a count and the latest linked timestamp.
     *
     * @param FormLink[] $submissionLinks All submission-level links across all forms.
     * @param int        $formId          The form id to aggregate for.
     *
     * @return array{int, int|null} Tuple of [count, lastUpdatedTimestamp|null].
     */
    private function rollUpSubmissions(array $submissionLinks, int $formId): array
    {
        $count       = 0;
        $lastUpdated = null;

        foreach ($submissionLinks as $sub) {
            if ((int) $sub->getFormId() !== $formId) {
                continue;
            }

            $count++;
            $timestamp = $sub->getLinkedAt()->getTimestamp();
            if ($lastUpdated === null || $timestamp > $lastUpdated) {
                $lastUpdated = $timestamp;
            }
        }

        return [$count, $lastUpdated];

    }//end rollUpSubmissions()

    /**
     * Fetch the upstream `forms_v2_forms` row for a given form id.
     *
     * Defensive: returns null when Forms isn't installed or the row
     * has been deleted upstream.
     *
     * @param integer $formId The NC Forms form id.
     *
     * @return array<string,mixed>|null
     */
    private function fetchUpstreamForm(int $formId): ?array
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('id', 'hash', 'title', 'description', 'expires', 'state')
                ->from('forms_v2_forms')
                ->where($qb->expr()->eq('id', $qb->createNamedParameter($formId)));
            $result = $qb->executeQuery();
            $row    = $result->fetch();
            $result->closeCursor();
            if ($row === false) {
                return null;
            }

            return $row;
        } catch (Throwable $e) {
            return null;
        }//end try

    }//end fetchUpstreamForm()

    /**
     * Combine a FormLink row with the upstream `forms_v2_forms` row
     * (when available) into the flat shape that normaliseForm() expects.
     *
     * The upstream row wins for fields that change post-link (status,
     * expiry, hash, title); the link's cached fields fill the gaps.
     *
     * @param FormLink                 $link     Link row.
     * @param array<string,mixed>|null $upstream Upstream form row.
     *
     * @return array<string,mixed>
     */
    private function mergeFormLinkAndUpstream(FormLink $link, ?array $upstream): array
    {
        $expiresAt = $link->getExpiresAt();
        $expires   = 0;
        if ($expiresAt !== null) {
            $expires = $expiresAt->getTimestamp();
        }

        $state = 0;
        if ($link->getStatus() === 'archived') {
            $state = 1;
        } else if ($link->getStatus() === 'draft') {
            $state = 2;
        }

        $defaults = [
            'id'          => $link->getFormId(),
            'hash'        => $link->getFormHash(),
            'title'       => $link->getTitle(),
            'description' => null,
            'expires'     => $expires,
            'state'       => $state,
        ];

        if ($upstream === null) {
            return $defaults;
        }

        // Upstream wins for live fields; link fields are the fallback.
        return [
            'id'          => $upstream['id'] ?? $defaults['id'],
            'hash'        => $upstream['hash'] ?? $defaults['hash'],
            'title'       => $upstream['title'] ?? $defaults['title'],
            'description' => $upstream['description'] ?? $defaults['description'],
            'expires'     => $upstream['expires'] ?? $defaults['expires'],
            'state'       => $upstream['state'] ?? $defaults['state'],
        ];

    }//end mergeFormLinkAndUpstream()

    /**
     * Normalise an upstream form row (or merged link + upstream row)
     * into the registry leaf-row shape.
     *
     * Adds top-level `status`, `accessible`, `expiresAt`, and
     * `submissionCount` per the phase C-1 widening flag — the bespoke
     * CnFormsTab + CnFormsCard read these defensively so they light
     * up the moment the provider ships them.
     *
     * @param array<string,mixed> $row Upstream form row.
     *
     * @return array<string,mixed>
     */
    private function normaliseForm(array $row): array
    {
        $formId     = (int) ($row['id'] ?? 0);
        $expires    = (int) ($row['expires'] ?? 0);
        $state      = (int) ($row['state'] ?? 0);
        $status     = $this->resolveStatus(state: $state, expires: $expires);
        $accessible = ($status !== 'closed' && $status !== 'archived');
        $expiresAt  = null;
        if ($expires > 0) {
            $expiresAt = gmdate(DATE_ATOM, $expires);
        }

        $submissionCount = $this->countSubmissions(formId: $formId);
        $url = '/index.php/apps/forms/'.((string) ($row['hash'] ?? $row['id'] ?? ''));

        return [
            'id'              => (string) $formId,
            'title'           => (string) ($row['title'] ?? ''),
            'description'     => (string) ($row['description'] ?? ''),
            'url'             => $url,
            // Top-level widened fields (phase C-1).
            'status'          => $status,
            'accessible'      => $accessible,
            'expiresAt'       => $expiresAt,
            'submissionCount' => $submissionCount,
            'data'            => [
                'id'              => $formId,
                'hash'            => (string) ($row['hash'] ?? ''),
                'title'           => (string) ($row['title'] ?? ''),
                'description'     => (string) ($row['description'] ?? ''),
                'status'          => $status,
                'accessible'      => $accessible,
                'expiresAt'       => $expiresAt,
                'submissionCount' => $submissionCount,
            ],
        ];

    }//end normaliseForm()

    /**
     * Map upstream `state` + `expires` onto canonical status strings.
     *
     * @param integer $state   NC Forms `state` column (0 open, 1 archived, 2 draft).
     * @param integer $expires NC Forms `expires` epoch (0 = no expiry).
     *
     * @return string Canonical status.
     */
    private function resolveStatus(int $state, int $expires): string
    {
        if ($state === 1) {
            return 'archived';
        }

        if ($state === 2) {
            return 'draft';
        }

        if ($expires > 0 && $expires <= time()) {
            return 'closed';
        }

        return 'open';

    }//end resolveStatus()

    /**
     * Best-effort submission count for a given form id.
     *
     * Defensive: returns 0 when the query fails.
     *
     * @param integer $formId The NC Forms form id.
     *
     * @return integer
     */
    private function countSubmissions(int $formId): int
    {
        if ($formId <= 0) {
            return 0;
        }

        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->createFunction('COUNT(*)'))
                ->from('forms_v2_submissions')
                ->where($qb->expr()->eq('form_id', $qb->createNamedParameter($formId)));
            $result = $qb->executeQuery();
            $count  = (int) $result->fetchOne();
            $result->closeCursor();
            return $count;
        } catch (Throwable $e) {
            return 0;
        }

    }//end countSubmissions()
}//end class
