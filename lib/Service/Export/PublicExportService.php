<?php

/**
 * OpenRegister PublicExportService
 *
 * An export for a caller without a session: open formats, public rows, one page at a time.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Export
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/export-open-formats-and-public-download/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Export;

use OCA\OpenRegister\Service\ExportService;

/**
 * Serves an export to the public principal (design D-2 to D-5).
 *
 * The schema's effective export grant must name `public`
 * ({@see ExportRightService::refusalForAnonymous()}); only the open formats are
 * written; the rows are read as the anonymous caller, so RBAC leaves only the
 * rows and columns the public may read; and at most one page of
 * {@see ExportPage::PUBLIC_SIZE} rows leaves per response. Every served and
 * every refused export is written to the audit trail with actor `anonymous`.
 *
 * @spec openspec/changes/export-open-formats-and-public-download/specs/data-import-export/spec.md
 */
class PublicExportService {

	/**
	 * The formats an anonymous caller may ask for.
	 *
	 * @var list<string>
	 */
	public const FORMATS = ['csv', 'tsv', 'json', 'xml'];

	/**
	 * The actor an anonymous export is recorded under.
	 *
	 * @var string
	 */
	public const ACTOR = 'anonymous';

	/**
	 * Wire the collaborators.
	 *
	 * @param ExportService                $exports The export writer.
	 * @param ExportRightService           $rights  Decides whether the public may export the schema.
	 * @param ExportAuditRecorderInterface $audit   The export audit trail.
	 *
	 * @spec openspec/changes/export-open-formats-and-public-download/specs/data-import-export/spec.md
	 */
	public function __construct(
		private readonly ExportService $exports,
		private readonly ExportRightService $rights,
		private readonly ExportAuditRecorderInterface $audit,
	) {
	}//end __construct()

	/**
	 * Export one page for the public, or the refusal.
	 *
	 * The right is checked before the format, so a private schema answers 401
	 * whatever was asked for and a visitor learns nothing about its formats.
	 *
	 * @param ExportRequest $request The register, schema, filters and page; it runs as no user.
	 * @param string        $format  The requested format.
	 *
	 * @return PublicExportResult The page, or the refusal.
	 *
	 * @spec openspec/changes/export-open-formats-and-public-download/specs/data-import-export/spec.md
	 */
	public function export(ExportRequest $request, string $format): PublicExportResult {
		$request = new ExportRequest(
			register: $request->register,
			schema: $request->schema,
			filters: $request->filters,
			currentUser: null,
			page: ($request->page ?? new ExportPage())
		);

		$refusal = $this->refusal(request: $request, format: $format);
		if ($refusal !== null) {
			return $refusal;
		}

		$content = $this->content(request: $request, format: $format);
		$total   = $this->exports->countExportTotal(request: $request);
		$page    = $request->page ?? new ExportPage();
		$rows    = max(0, min($page->size, ($total - (($page->number - 1) * $page->size))));

		$this->audit->recordCompleted(
			profile: 'ad-hoc',
			rowCount: $rows,
			format: $format,
			valueMode: null,
			register: $request->register?->getId(),
			schema: $request->schema?->getId(),
			actorId: self::ACTOR
		);

		return new PublicExportResult(statusCode: 200, refusal: null, content: $content, page: $page, total: $total, rowCount: $rows);
	}//end export()

	/**
	 * The refusal of an anonymous export, recorded on the trail, or null when it may run.
	 *
	 * @param ExportRequest $request The export.
	 * @param string        $format  The requested format.
	 *
	 * @return PublicExportResult|null The refusal, or null.
	 */
	private function refusal(ExportRequest $request, string $format): ?PublicExportResult {
		// A register-wide export has no single schema grant to read: refused.
		$refused = new ExportRefusedException(
			rule: 'not-public',
			reason: 'Exporting this schema requires an account.',
			statusCode: 401
		);
		if ($request->schema !== null) {
			$refused = $this->rights->refusalForAnonymous(schema: $request->schema);
		}

		if ($refused === null && in_array($format, self::FORMATS, true) === false) {
			$refused = new ExportRefusedException(
				rule: 'format-not-open',
				reason: 'Without an account an export is available as ' . implode(', ', self::FORMATS) . '.',
				statusCode: 406,
				context: ['formats' => self::FORMATS]
			);
		}

		if ($refused === null) {
			return null;
		}

		$this->recordRefusal(request: $request, refused: $refused);

		return PublicExportResult::refused(statusCode: $refused->getStatusCode(), body: $refused->toResponseBody());
	}//end refusal()

	/**
	 * Write the refusal to the trail, under actor `anonymous`.
	 *
	 * @param ExportRequest          $request The export.
	 * @param ExportRefusedException $refused The refusal.
	 *
	 * @return void
	 */
	private function recordRefusal(ExportRequest $request, ExportRefusedException $refused): void {
		$this->audit->recordRefused(
			profile: 'ad-hoc',
			rule: $refused->getRule(),
			reason: $refused->getMessage(),
			register: $request->register?->getId(),
			schema: $request->schema?->getId(),
			actorId: self::ACTOR
		);
	}//end recordRefusal()

	/**
	 * The page written in an open format.
	 *
	 * @param ExportRequest $request The export.
	 * @param string        $format  One of {@see self::FORMATS}.
	 *
	 * @return string The file content.
	 */
	private function content(ExportRequest $request, string $format): string {
		return match ($format) {
			'tsv' => $this->exports->tsvFor(request: $request),
			'json' => $this->exports->jsonFor(request: $request),
			'xml' => $this->exports->xmlFor(request: $request),
			default => $this->exports->csvFor(request: $request),
		};
	}//end content()
}//end class
