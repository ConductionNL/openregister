<?php
/**
 * RedactionSettingsInterface: the organisation's detection and masking settings, as file redaction reads them.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Files
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Files;

/**
 * Reads the two settings that entity detection and redaction apply per document.
 *
 * File handling asks; the file settings handler (Configuration) answers and is
 * bound to this contract in its own registrar. Both are read at request time,
 * so a changed setting applies to the next document without a restart.
 *
 * @spec openspec/changes/anonymisation-discloses-itself/specs/anonymisation-disclosure/spec.md#requirement-the-confidence-threshold-is-an-organisation-setting-req-adi-007
 */
interface RedactionSettingsInterface {

	/**
	 * The minimum confidence a detection needs when no organisation setting says otherwise.
	 *
	 * @var float
	 */
	public const DEFAULT_CONFIDENCE_THRESHOLD = 0.5;

	/**
	 * The anonymisation setting naming how long an unredacted original is kept
	 * after its redacted copy is published: `keep` or an ISO 8601 duration.
	 *
	 * @var string
	 */
	public const ORIGINAL_RETENTION_KEY = 'originalRetention';

	/**
	 * The retention that deletes nothing: the original is kept as the record.
	 *
	 * @var string
	 */
	public const ORIGINAL_RETENTION_KEEP = 'keep';

	/**
	 * The minimum confidence a detection needs, from the organisation's file settings.
	 *
	 * @return float A number between 0 and 1; the default when none is stored.
	 *
	 * @spec openspec/changes/anonymisation-discloses-itself/specs/anonymisation-disclosure/spec.md#requirement-the-confidence-threshold-is-an-organisation-setting-req-adi-007
	 */
	public function confidenceThreshold(): float;

	/**
	 * The mask form per entity type, from the organisation's file settings.
	 *
	 * @return array<string, array{form: string, n?: int}> Entity type to form; empty when none is set.
	 *
	 * @spec openspec/changes/anonymisation-placeholder-id-scope/specs/entity-relation-grondslagen/spec.md#requirement-a-detection-may-be-masked-in-part-with-the-form-set-per-entity-type-req-pis-010
	 */
	public function maskForms(): array;

	/**
	 * Whether anonymised output waits for a person's check of every detection.
	 *
	 * @return bool True when review is required.
	 *
	 * @spec openspec/changes/redaction-release-safeguards/tasks.md#task-2.3
	 */
	public function requireReview(): bool;

	/**
	 * How long an unredacted original is kept after its redacted copy is
	 * published: `keep` (nothing is deleted) or an ISO 8601 duration.
	 *
	 * @return string The retention.
	 *
	 * @spec openspec/changes/redaction-release-safeguards/specs/redaction-output-guarantee/spec.md#requirement-the-unredacted-originals-custody-is-stated-and-its-deletion-scheduled-req-rrs-005
	 */
	public function originalRetention(): string;
}//end interface
