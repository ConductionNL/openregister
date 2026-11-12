<?php

/**
 * OpenRegister erasure preview counters
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Gdpr\Erasure
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

namespace OCA\OpenRegister\Service\Gdpr\Erasure;

/**
 * The counts an erasure preview reports beside each erasable object.
 *
 * @spec openspec/specs/gdpr-data-subject-rights/spec.md
 */
final class ErasureCounters {
	/**
	 * Bundle the collaborators.
	 *
	 * @param CoSubjectProbe      $coSubjects   Other subjects on a record, from the PII index.
	 * @param ErasureScopeCounter $scopeCounter What goes with an erasable object.
	 * @param SubjectPartyCounter $parties      Party records naming the subject.
	 */
	public function __construct(
		public readonly CoSubjectProbe $coSubjects,
		public readonly ErasureScopeCounter $scopeCounter,
		public readonly SubjectPartyCounter $parties,
	) {
	}//end __construct()
}//end class
