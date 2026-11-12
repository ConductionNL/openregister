<?php

/**
 * OpenRegister HandoffSourceRestore
 *
 * What compensation needs to put an already-mutated handoff source back.
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

/**
 * What compensation needs to put an already-mutated handoff source back.
 *
 * Passed between the write helpers of {@see HandoffService}.
 *
 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
 */
final class HandoffSourceRestore {

	/**
	 * Name the source to restore.
	 *
	 * @param ObjectEntity         $source      The source object, already mutated by `onSuccess.set`.
	 * @param array<string, mixed> $snapshot    The source data before `onSuccess.set`.
	 * @param string               $registerRef The source register reference for the revert write.
	 * @param string               $schemaRef   The source schema reference for the revert write.
	 *
	 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
	 */
	public function __construct(
		public readonly ObjectEntity $source,
		public readonly array $snapshot,
		public readonly string $registerRef,
		public readonly string $schemaRef,
	) {
	}//end __construct()
}//end class
