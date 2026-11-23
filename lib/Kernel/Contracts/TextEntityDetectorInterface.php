<?php

/**
 * TextEntityDetectorInterface: detect entities in a text with one backend method.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

/**
 * The detection an evaluation run scores, so the Compliance area reaches it
 * without naming the Files EntityRecognitionHandler (ADR-013 Rule 1), which
 * implements it.
 */
interface TextEntityDetectorInterface {

	/**
	 * Detect entities in a text with one backend method, at the organisation's
	 * confidence threshold, without storing anything.
	 *
	 * @param string $text   The text.
	 * @param string $method The detection method.
	 *
	 * @return array<int, array> Detected entities with type, value, category, position and confidence.
	 *
	 * @spec openspec/changes/anonymisation-discloses-itself/specs/anonymisation-disclosure/spec.md#requirement-precision-and-recall-are-measured-over-a-named-corpus-and-published-with-the-detector-version-req-adi-002
	 */
	public function detectInText(string $text, string $method): array;
}//end interface
