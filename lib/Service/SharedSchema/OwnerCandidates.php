<?php

/**
 * The registers that may own one shared schema, and the operator's overrides.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\SharedSchema
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\SharedSchema;

/**
 * What {@see SchemaAttribution::resolveOwner()} weighs besides the verdict: the shared
 * schema id, the registers that reference it and the parsed `--keep` overrides.
 *
 * @spec openspec/changes/archive/2026-10-30-dedupe-shared-schemas/proposal.md
 */
final class OwnerCandidates {

	/**
	 * Constructor.
	 *
	 * @param int                  $schemaId    The shared schema id.
	 * @param int[]                $registerIds The referencing registers.
	 * @param array<string, mixed> $keep        The parsed overrides.
	 */
	public function __construct(
		public readonly int $schemaId,
		public readonly array $registerIds,
		public readonly array $keep,
	) {
	}//end __construct()
}//end class
