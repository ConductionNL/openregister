<?php

/**
 * The parts of a live schema the shipped-configuration guard compares, as stored.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ShippedBaseline
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ShippedBaseline;

use OCA\OpenRegister\Db\Schema;

/**
 * One reading of the live definition, shared by the reset and the divergence report.
 *
 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
 */
final class LiveDescriptor {

	/**
	 * The guarded parts of the schema as stored.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return array<string, mixed> The live definition.
	 *
	 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
	 */
	public static function of(Schema $schema): array {
		return [
			'properties' => $schema->getProperties(),
			'required' => $schema->getRequired(),
			'authorization' => ($schema->getAuthorization() ?? []),
			GuardedAnnotations::PART => (new GuardedAnnotations())->ofStored(configuration: $schema->getConfiguration()),
		];
	}//end of()
}//end class
