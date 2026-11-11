<?php

/**
 * OpenRegister SchemaChangeRecord
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Schema
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/schema-migration/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Schema;

/**
 * One classified schema change as it is gated and recorded: the change set,
 * whether a breaking change was acknowledged, the resulting version and
 * where the change came from.
 *
 * @spec openspec/specs/schema-migration/spec.md
 */
final class SchemaChangeRecord {

	/**
	 * Constructor.
	 *
	 * @param SchemaChangeSet $changeSet    The classified change set.
	 * @param bool            $acknowledged Whether the change was acknowledged.
	 * @param string|null     $version      The resulting version.
	 * @param string|null     $origin       Where the change came from (import, agent tool, source merge), for the log.
	 */
	public function __construct(
		public readonly SchemaChangeSet $changeSet,
		public readonly bool $acknowledged,
		public readonly ?string $version = null,
		public readonly ?string $origin = null,
	) {
	}//end __construct()
}//end class
