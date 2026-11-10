<?php

/**
 * The organisation and subject object a case-widget run read is anchored to.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-runs-subject-scope/specs/flow-runs-subject-scope/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * The tenant and the subject object a case-widget run read is anchored to.
 *
 * The organisation is the authorisation; the subject only ever narrows inside
 * it. A null or empty value means "not given": the live-runs read then drops
 * that predicate, the completed-runs read refuses to answer at all.
 *
 * @spec openspec/changes/flow-runs-subject-scope/specs/flow-runs-subject-scope/spec.md
 */
final readonly class FlowRunSubjectScope {
	/**
	 * Constructor.
	 *
	 * @param string|null $organisation The caller's organisation uuid.
	 * @param string|null $subject      The subject object uuid.
	 *
	 * @return void
	 */
	public function __construct(
		public ?string $organisation = null,
		public ?string $subject = null,
	) {
	}//end __construct()
}//end class
