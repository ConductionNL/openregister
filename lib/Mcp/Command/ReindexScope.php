<?php

/**
 * ReindexScope
 *
 * The register and schema a contextchat:reindex run is scoped to; null
 * means every register or every schema.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Command
 * @package  OCA\OpenRegister\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/context-chat-provider/spec.md#requirement-getitemurl-and-initial-import-reuse-existing-openregister-infrastructure
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Mcp\Command;

/**
 * The resolved register and schema ids of one reindex run.
 *
 * @spec openspec/specs/context-chat-provider/spec.md#requirement-getitemurl-and-initial-import-reuse-existing-openregister-infrastructure
 */
final readonly class ReindexScope {
	/**
	 * Constructor.
	 *
	 * @param int|null $registerId The register to reindex, or null for all.
	 * @param int|null $schemaId   The schema to reindex, or null for all.
	 *
	 * @spec openspec/specs/context-chat-provider/spec.md#requirement-getitemurl-and-initial-import-reuse-existing-openregister-infrastructure
	 */
	public function __construct(
		public ?int $registerId,
		public ?int $schemaId,
	) {
	}//end __construct()
}//end class
