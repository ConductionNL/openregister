<?php

/**
 * The data subject a DSAR request looks up.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * The data subject a DSAR request looks up: the identifier, an optional GdprEntity type filter and the match mode.
 *
 * @spec openspec/changes/retrofit-2026-05-24-b-svc-report-import-link/tasks.md#task-11
 */
final class DsarSubjectQuery {
	/**
	 * Hold the values.
	 *
	 * @param string      $subject Subject identifier value (email, BSN, …).
	 * @param string|null $type    Optional GdprEntity type filter (e.g. `email`).
	 * @param string      $mode    `exact` (case-insensitive literal match) or `ilike` (case-insensitive substring).
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-report-import-link/tasks.md#task-11
	 */
	public function __construct(
		public readonly string $subject,
		public readonly string|null $type,
		public readonly string $mode,
	) {
	}//end __construct()

	/**
	 * The same query with surrounding whitespace removed from the subject.
	 *
	 * @return self
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-report-import-link/tasks.md#task-11
	 */
	public function trimmed(): self {
		return new self(subject: trim($this->subject), type: $this->type, mode: $this->mode);
	}//end trimmed()
}//end class
