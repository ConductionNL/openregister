<?php

/**
 * OpenRegister CaseSentryMoment
 *
 * The moment a case's sentries are evaluated in: the plan, the event being
 * handled, and the document an if-part reads.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Case
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-sentries-are-entry-and-exit-criteria-over-existing-engine-primitives
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Case;

/**
 * The plan, the event and the evaluation document one sentry pass reads.
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-sentries-are-entry-and-exit-criteria-over-existing-engine-primitives
 */
final class CaseSentryMoment {

	/**
	 * Constructor.
	 *
	 * @param CasePlanTree         $tree  The plan.
	 * @param string|null          $event The event being handled, or null.
	 * @param array<string, mixed> $data  The document an if-part is evaluated against.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-sentries-are-entry-and-exit-criteria-over-existing-engine-primitives
	 */
	public function __construct(
		public readonly CasePlanTree $tree,
		public readonly ?string $event,
		public readonly array $data,
	) {
	}//end __construct()
}//end class
