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
use OCA\OpenRegister\Db\FormLinkMapperInterface;
use OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider;
use OCP\App\IAppManager;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use Throwable;

class FormsProvider extends AbstractIntegrationProvider {
	use NcAppLinkTableProvider;
	use MarkerSourceFallback;
	use MarkerLookupTrait;
	use FirstRowQuery;

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
	 * Where the legacy marker lives: `forms_v2_forms.title`.
	 *
	 * @var array{table: string, column: string, extra: list<string>, id: string}
	 */
	private const MARKER_SOURCE = [
		'table' => 'forms_v2_forms',
		'column' => 'title',
		'extra' => ['description', 'hash', 'expires', 'state'],
		'id' => 'id',
	];

	/**
	 * NC DB connection.
	 *
	 * @var IDBConnection
	 */
	private IDBConnection $db;

	/**
	 * NC app manager.
	 *
	 * @var IAppManager
	 */
	private IAppManager $appManager;

	/**
	 * Localisation.
	 *
	 * @var IL10N
	 */
	private IL10N $l10n;

	/**
	 * Constructor.
	 *
	 * @param LeafProviderPlatform    $platform       The DB connection, app manager and localisation.
	 * @param FormLinkMapperInterface $formLinkMapper Form-link mapper (Tier-2 link table).
	 * @param LoggerInterface         $logger         Logger for the degraded marker-scan path.
	 */
	public function __construct(
		LeafProviderPlatform $platform,
		private FormLinkMapperInterface $formLinkMapper,
		private LoggerInterface $logger,
	) {
		$this->db = $platform->db;
		$this->appManager = $platform->appManager;
		$this->l10n = $platform->l10n;
	}//end __construct()

	public function getId(): string {
		return 'forms';
	}//end getId()

	public function getLabel(): string {
		return $this->l10n->t('Forms');
	}//end getLabel()

	public function getIcon(): string {
		return 'ClipboardText';
	}//end getIcon()

	public function getGroup(): ?string {
		return 'workflow';
	}//end getGroup()

	/**
	 * The link mapper this provider reads first.
	 *
	 * @return FormLinkMapperInterface
	 */
	protected function linkMapper(): FormLinkMapperInterface {
		return $this->formLinkMapper;
	}//end linkMapper()

	/**
	 * Provider health descriptor (enabled/disabled echo).
	 *
	 * @return array<string,mixed>
	 *
	 * @spec exclude Static enabled/disabled descriptor echoing isEnabled() — no standalone health behaviour;
	 *       the health/OCS contract is owned by pluggable-integration-registry task-2.
	 */
	public function health(): array {
		return $this->installedHealth(available: $this->isEnabled(), missing: 'NC Forms app is not installed');
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
	 *
	 * @spec openspec/specs/integration-forms/spec.md
	 */
	protected function rowsFromLinks(array $links): array {
		[$forms, $submissions] = $this->splitLinks(links: $links);
		$rows = array_map(
			fn (FormLink $link): array => $this->formRow(link: $link, submissionLinks: $submissions),
			array_values($forms)
		);

		return array_merge($rows, $this->orphanSubmissionRows(submissionLinks: $submissions, forms: $forms));
	}//end rowsFromLinks()

	/**
	 * Split links into form-level links keyed by form id (the last one per form
	 * wins) and submission-level links.
	 *
	 * @param FormLink[] $links Link rows from the mapper.
	 *
	 * @return array{0: array<int,FormLink>, 1: list<FormLink>}
	 */
	private function splitLinks(array $links): array {
		$forms = [];
		$submissions = [];
		foreach ($links as $link) {
			if ($link->getSubmissionId() === null) {
				$forms[(int)$link->getFormId()] = $link;
				continue;
			}

			$submissions[] = $link;
		}

		return [$forms, $submissions];
	}//end splitLinks()

	/**
	 * One form-level link as a leaf row, with its submissions rolled up.
	 *
	 * @param FormLink   $link            Form-level link.
	 * @param FormLink[] $submissionLinks All submission-level links.
	 *
	 * @return array<string,mixed>
	 */
	private function formRow(FormLink $link, array $submissionLinks): array {
		$row = $this->linkedFormRow(link: $link);
		[$count, $lastUpdated] = $this->rollUpSubmissions(submissionLinks: $submissionLinks, formId: (int)$link->getFormId());
		$row['submissionCount'] = max($row['submissionCount'], $count);
		if ($lastUpdated !== null) {
			$row['lastUpdated'] = $lastUpdated;
		}

		return $row;
	}//end formRow()

	/**
	 * Submission links without a form-level link: a minimal parent row per
	 * submission so the submission still surfaces.
	 *
	 * @param FormLink[]           $submissionLinks All submission-level links.
	 * @param array<int,FormLink> $forms           Form-level links keyed by form id.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function orphanSubmissionRows(array $submissionLinks, array $forms): array {
		$out = [];
		foreach ($submissionLinks as $sub) {
			if (isset($forms[(int)$sub->getFormId()]) === true) {
				continue;
			}

			$row = $this->linkedFormRow(link: $sub);
			$row['submissionCount'] = max($row['submissionCount'], 1);
			$out[] = $row;
		}

		return $out;
	}//end orphanSubmissionRows()

	/**
	 * A link as a leaf row, joined with its live upstream form when there is one.
	 *
	 * @param FormLink $link Link row.
	 *
	 * @return array<string,mixed>
	 */
	private function linkedFormRow(FormLink $link): array {
		$upstream = $this->fetchUpstreamForm(formId: (int)$link->getFormId());

		return $this->normaliseForm(row: $this->mergeFormLinkAndUpstream(link: $link, upstream: $upstream));
	}//end linkedFormRow()

	/**
	 * One legacy marker-scan row in the leaf-row shape.
	 *
	 * @param array<string,mixed> $row The scanned `forms_v2_forms` row.
	 *
	 * @return array<string,mixed>
	 */
	protected function rowFromMarker(array $row): array {
		return $this->normaliseForm(row: $row);
	}//end rowFromMarker()

	/**
	 * Roll up submission links for a given form into a count and the latest linked timestamp.
	 *
	 * @param FormLink[] $submissionLinks All submission-level links across all forms.
	 * @param int $formId The form id to aggregate for.
	 *
	 * @return array{int, int|null} Tuple of [count, lastUpdatedTimestamp|null].
	 */
	private function rollUpSubmissions(array $submissionLinks, int $formId): array {
		$count = 0;
		$lastUpdated = null;

		foreach ($submissionLinks as $sub) {
			if ((int)$sub->getFormId() !== $formId) {
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
	private function fetchUpstreamForm(int $formId): ?array {
		return $this->firstRowOf(
			query: function () use ($formId): IQueryBuilder {
				$qb = $this->db->getQueryBuilder();
				return $qb->select('id', 'hash', 'title', 'description', 'expires', 'state')
					->from('forms_v2_forms')
					->where($qb->expr()->eq('id', $qb->createNamedParameter($formId)));
			}
		);
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
	private function mergeFormLinkAndUpstream(FormLink $link, ?array $upstream): array {
		$merged = $this->linkFormFields(link: $link);
		if ($upstream === null) {
			return $merged;
		}

		foreach ($merged as $key => $fallback) {
			$merged[$key] = $upstream[$key] ?? $fallback;
		}

		return $merged;
	}//end mergeFormLinkAndUpstream()

	/**
	 * The form fields a link caches, in the upstream row shape.
	 *
	 * @param FormLink $link Link row.
	 *
	 * @return array{id: int|null, hash: string|null, title: string|null, description: null, expires: int, state: int}
	 */
	private function linkFormFields(FormLink $link): array {
		return [
			'id' => $link->getFormId(),
			'hash' => $link->getFormHash(),
			'title' => $link->getTitle(),
			'description' => null,
			'expires' => $link->getExpiresAt()?->getTimestamp() ?? 0,
			'state' => (['archived' => 1, 'draft' => 2][(string)$link->getStatus()] ?? 0),
		];
	}//end linkFormFields()

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
	private function normaliseForm(array $row): array {
		$data = $this->formData(row: $row);

		return [
			'id' => (string)$data['id'],
			'title' => $data['title'],
			'description' => $data['description'],
			'url' => '/index.php/apps/forms/' . ((string)($row['hash'] ?? $row['id'] ?? '')),
			'status' => $data['status'],
			'accessible' => $data['accessible'],
			'expiresAt' => $data['expiresAt'],
			'submissionCount' => $data['submissionCount'],
			'data' => $data,
		];
	}//end normaliseForm()

	/**
	 * The `data` block of a form row: id, hash, title, description, status,
	 * accessibility, expiry and submission count.
	 *
	 * @param array<string,mixed> $row Upstream form row.
	 *
	 * @return array{id: int, hash: string, title: string, description: string, status: string,
	 *     accessible: bool, expiresAt: string|null, submissionCount: int}
	 */
	private function formData(array $row): array {
		$formId = (int)($row['id'] ?? 0);
		$expires = (int)($row['expires'] ?? 0);
		$status = $this->resolveStatus(state: (int)($row['state'] ?? 0), expires: $expires);

		return [
			'id' => $formId,
			'hash' => (string)($row['hash'] ?? ''),
			'title' => (string)($row['title'] ?? ''),
			'description' => (string)($row['description'] ?? ''),
			'status' => $status,
			'accessible' => ($status !== 'closed' && $status !== 'archived'),
			'expiresAt' => $this->atomOrNull(expires: $expires),
			'submissionCount' => $this->countSubmissions(formId: $formId),
		];
	}//end formData()

	/**
	 * An expiry as an ATOM date, or null when the form never expires.
	 *
	 * @param int $expires Unix expiry, 0 for none.
	 *
	 * @return string|null
	 */
	private function atomOrNull(int $expires): ?string {
		if ($expires > 0) {
			return gmdate(DATE_ATOM, $expires);
		}

		return null;
	}//end atomOrNull()

	/**
	 * Map upstream `state` + `expires` onto canonical status strings.
	 *
	 * @param integer $state NC Forms `state` column (0 open, 1 archived, 2 draft).
	 * @param integer $expires NC Forms `expires` epoch (0 = no expiry).
	 *
	 * @return string Canonical status.
	 */
	private function resolveStatus(int $state, int $expires): string {
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
	private function countSubmissions(int $formId): int {
		if ($formId <= 0) {
			return 0;
		}

		try {
			$result = $this->submissionCountQuery(formId: $formId)->executeQuery();
			$count = (int)$result->fetchOne();
			$result->closeCursor();
			return $count;
		} catch (Throwable $e) {
			return 0;
		}
	}//end countSubmissions()

	/**
	 * The query counting a form's submissions.
	 *
	 * @param integer $formId The NC Forms form id.
	 *
	 * @return IQueryBuilder
	 */
	private function submissionCountQuery(int $formId): IQueryBuilder {
		$qb = $this->db->getQueryBuilder();

		return $qb->select($qb->createFunction('COUNT(*)'))
			->from('forms_v2_submissions')
			->where($qb->expr()->eq('form_id', $qb->createNamedParameter($formId)));
	}//end submissionCountQuery()
}//end class
