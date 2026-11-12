<?php

/**
 * OpenRegister ConsentEvidenceStamp
 *
 * What every newly appended consent entry is stamped with: the property's
 * declared purpose and who wrote it from where.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Consent
 *
 * @author	Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/consent-evidence-envelope/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Consent;

/**
 * The evidence one consent write stamps on each entry it appends.
 *
 * Built once per write by {@see ConsentEnvelopeEvaluator::evaluate()}; the
 * purpose feeds the content hash, the source fills `by`, `ip` and `userAgent`.
 */
final class ConsentEvidenceStamp {

	/**
	 * Name the stamp.
	 *
	 * @param string				$purpose The property's declared purpose.
	 * @param ConsentEvidenceSource $source  Who writes and from where.
	 *
	 * @spec openspec/specs/consent-evidence-envelope/spec.md
	 */
	public function __construct(
		public readonly string $purpose,
		public readonly ConsentEvidenceSource $source,
	) {
	}//end __construct()
}//end class
