<?php

/**
 * FileSettingRulesInterface: the rules the stored file settings follow.
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
 * Normalises and bounds the file settings Configuration stores: mask forms, archive bounds and pattern sets.
 *
 * Files answers (FileSettingRules); the file settings handlers ask.
 *
 * @spec openspec/changes/functional-areas-depend-in-one-direction/contracts/files.md
 */
interface FileSettingRulesInterface {

	/**
	 * The mask forms as they are stored: each entry checked and reduced to its form and keep count.
	 *
	 * @param array<mixed> $maskForms The submitted `anonymisation.maskForms` value.
	 *
	 * @return array<string, array{form: string, n?: int}> Entity type to form.
	 *
	 * @throws \OCA\OpenRegister\Exception\UnknownMaskFormException When a form is unknown or n is below 1.
	 *
	 * @spec openspec/changes/anonymisation-placeholder-id-scope/specs/entity-relation-grondslagen/spec.md#requirement-a-detection-may-be-masked-in-part-with-the-form-set-per-entity-type-req-pis-010
	 */
	public function normaliseMaskForms(array $maskForms): array;

	/**
	 * The archive bounds of the file settings, defaults filled in and the depth capped.
	 *
	 * @param array<string, mixed> $fileSettings The file settings.
	 *
	 * @return array{maxMembers: int, maxTotalBytes: int, maxRatio: int, maxDepth: int}
	 *
	 * @spec openspec/changes/archive-members-are-searchable/specs
	 */
	public function archiveBounds(array $fileSettings): array;

	/**
	 * The default archive bounds, as the file settings return them.
	 *
	 * @return array{maxMembers: int, maxTotalBytes: int, maxRatio: int, maxDepth: int}
	 *
	 * @spec openspec/changes/archive-members-are-searchable/specs
	 */
	public function archiveDefaults(): array;

	/**
	 * The codes of the known detection pattern sets.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/detection-dutch-licence-plates/specs/pii-entity-detection/spec.md
	 */
	public function patternSetCodes(): array;

	/**
	 * The codes of a list that name no known pattern set.
	 *
	 * @param array<int, mixed> $codes The codes.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/detection-dutch-licence-plates/specs/pii-entity-detection/spec.md
	 */
	public function unknownPatternSets(array $codes): array;
}//end interface
