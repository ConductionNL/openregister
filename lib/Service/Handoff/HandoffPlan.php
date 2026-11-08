<?php

/**
 * OpenRegister HandoffPlan
 *
 * A handoff request with its loaded source and declared entry.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Handoff
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Handoff;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;

/**
 * A handoff request with its loaded source object, source schema and the
 * declared handoff entry it names.
 *
 * Passed between the execution steps of {@see HandoffService}.
 *
 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
 */
final class HandoffPlan {

	/**
	 * Name the plan.
	 *
	 * @param HandoffRequest       $request      The request being executed.
	 * @param ObjectEntity         $source       The source object, loaded under the caller's RBAC.
	 * @param Schema               $sourceSchema The source object's schema.
	 * @param array<string, mixed> $entry        The declared handoff entry.
	 *
	 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
	 */
	public function __construct(
		public readonly HandoffRequest $request,
		public readonly ObjectEntity $source,
		public readonly Schema $sourceSchema,
		public readonly array $entry,
	) {
	}//end __construct()

	/**
	 * The target kind URI the declared entry names.
	 *
	 * @return string The kind URI, or '' when the entry names none.
	 *
	 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
	 */
	public function kindUri(): string {
		return (string)($this->entry['targetSemanticType'] ?? '');
	}//end kindUri()
}//end class
