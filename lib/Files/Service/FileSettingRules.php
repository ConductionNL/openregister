<?php

/**
 * FileSettingRules: the rules the stored file settings follow.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Files\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service;

use OCA\OpenRegister\Files\Service\File\MaskForm;
use OCA\OpenRegister\Files\Service\TextExtraction\PatternSet\JurisdictionPatternSets;
use OCA\OpenRegister\Kernel\Contracts\Files\FileSettingRulesInterface;
use OCA\OpenRegister\Service\File\ArchiveBounds;

/**
 * Answers the file setting rules from MaskForm, ArchiveBounds and JurisdictionPatternSets, delegating unchanged.
 *
 * @spec openspec/changes/functional-areas-depend-in-one-direction/contracts/files.md
 *
 * @SuppressWarnings(PHPMD.StaticAccess) The rules are static on the classes that own them; this class is their instance face.
 */
final class FileSettingRules implements FileSettingRulesInterface {

	/**
	 * The mask forms as MaskForm::normalise() stores them.
	 *
	 * @param array<mixed> $maskForms The submitted `anonymisation.maskForms` value.
	 *
	 * @return array<string, array{form: string, n?: int}> Entity type to form.
	 *
	 * @throws \OCA\OpenRegister\Exception\UnknownMaskFormException When a form is unknown or n is below 1.
	 *
	 * @spec openspec/changes/anonymisation-placeholder-id-scope/specs/entity-relation-grondslagen/spec.md#requirement-a-detection-may-be-masked-in-part-with-the-form-set-per-entity-type-req-pis-010
	 */
	public function normaliseMaskForms(array $maskForms): array {
		return MaskForm::normalise(maskForms: $maskForms);
	}//end normaliseMaskForms()

	/**
	 * The archive bounds of the file settings, as ArchiveBounds::fromSettings() reads them.
	 *
	 * @param array<string, mixed> $fileSettings The file settings.
	 *
	 * @return array{maxMembers: int, maxTotalBytes: int, maxRatio: int, maxDepth: int}
	 *
	 * @spec openspec/changes/archive-members-are-searchable/specs
	 */
	public function archiveBounds(array $fileSettings): array {
		return ArchiveBounds::fromSettings(fileSettings: $fileSettings)->toArray();
	}//end archiveBounds()

	/**
	 * The default archive bounds, ArchiveBounds::DEFAULTS.
	 *
	 * @return array{maxMembers: int, maxTotalBytes: int, maxRatio: int, maxDepth: int}
	 *
	 * @spec openspec/changes/archive-members-are-searchable/specs
	 */
	public function archiveDefaults(): array {
		return ArchiveBounds::DEFAULTS;
	}//end archiveDefaults()

	/**
	 * The known pattern set codes, as JurisdictionPatternSets::codes() lists them.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/detection-dutch-licence-plates/specs/pii-entity-detection/spec.md
	 */
	public function patternSetCodes(): array {
		return JurisdictionPatternSets::codes();
	}//end patternSetCodes()

	/**
	 * The codes that name no known set, as JurisdictionPatternSets::unknown() finds them.
	 *
	 * @param array<int, mixed> $codes The codes.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/detection-dutch-licence-plates/specs/pii-entity-detection/spec.md
	 */
	public function unknownPatternSets(array $codes): array {
		return JurisdictionPatternSets::unknown(codes: $codes);
	}//end unknownPatternSets()
}//end class
