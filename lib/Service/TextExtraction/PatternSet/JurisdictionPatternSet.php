<?php

/**
 * OpenRegister JurisdictionPatternSet
 *
 * The identifiers that belong to one country, recognised as one set.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\TextExtraction\PatternSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\TextExtraction\PatternSet;

/**
 * A pattern set for the identifiers of one jurisdiction.
 *
 * The generic patterns (e-mail, phone, IBAN) run everywhere. An identifier
 * that belongs to one country, such as the Dutch BSN, is recognised by that
 * country's set, which finds candidates and confirms each with the validator
 * in `lib/Formats/` rather than carrying its own copy of the rule.
 *
 * @spec openspec/changes/detection-dutch-licence-plates/tasks.md#task-2.2
 */
interface JurisdictionPatternSet {
	/**
	 * The jurisdiction code, ISO 3166-1 alpha-2 in lower case.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/detection-dutch-licence-plates/tasks.md#task-2.2
	 */
	public function getCode(): string;

	/**
	 * Detect this jurisdiction's identifiers in the text.
	 *
	 * @param string $text The text to scan.
	 *
	 * @return array<int, array{type: string, value: string, category: string, position_start: int, position_end: int, confidence: float}>
	 *               The entities found, in the shape EntityRecognitionHandler::detectWithRegex() builds.
	 *
	 * @spec openspec/changes/detection-dutch-licence-plates/tasks.md#task-2.2
	 */
	public function detect(string $text): array;
}//end interface
