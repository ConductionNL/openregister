<?php

/**
 * OpenRegister PatternSetVersion
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Files\Service\TextExtraction\PatternSet
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

namespace OCA\OpenRegister\Files\Service\TextExtraction\PatternSet;

use OCA\OpenRegister\Kernel\Contracts\Compliance\DetectionPatternsVersionInterface;

/**
 * Says which version of the pattern set the built-in detection backend uses.
 *
 * The backend recognises with {@see NlPatternSet}, so its version and change
 * day are the ones reported.
 *
 * @spec openspec/changes/anonymisation-discloses-itself/specs/anonymisation-disclosure/spec.md#requirement-every-detection-backend-reports-what-it-is-built-from-req-adi-001
 */
final class PatternSetVersion implements DetectionPatternsVersionInterface {

	/**
	 * The version of the pattern set.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/anonymisation-discloses-itself/specs/anonymisation-disclosure/spec.md#requirement-every-detection-backend-reports-what-it-is-built-from-req-adi-001
	 */
	public function patternsVersion(): string {
		return NlPatternSet::VERSION;
	}//end patternsVersion()

	/**
	 * The day the pattern set last changed.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/anonymisation-discloses-itself/specs/anonymisation-disclosure/spec.md#requirement-the-detector-says-when-it-last-changed-req-adi-004
	 */
	public function patternsChangedAt(): string {
		return NlPatternSet::CHANGED_AT;
	}//end patternsChangedAt()
}//end class
