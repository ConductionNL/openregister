<?php

/**
 * OpenRegister HandoffMappingSource
 *
 * What a handoff mapping reads: the source object's data and its provenance pointer.
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

/**
 * What a handoff mapping reads: the source object's data and its provenance pointer.
 *
 * Built by {@see HandoffService} and read by every expression {@see HandoffMappingEvaluator} evaluates.
 *
 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
 */
final class HandoffMappingSource {

	/**
	 * Name the mapping source.
	 *
	 * @param array<string, mixed> $data       The source object's data.
	 * @param array<string, mixed> $provenance The engine-filled source pointer `{app, register, schema, uuid}`.
	 *
	 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
	 */
	public function __construct(
		public readonly array $data,
		public readonly array $provenance,
	) {
	}//end __construct()
}//end class
