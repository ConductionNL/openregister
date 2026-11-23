<?php

/**
 * ExposureDeclaration
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Relation
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Relation;

/**
 * A relation type's declared `exposes`, as a schema save checks it against the linked schema.
 *
 * @spec openspec/changes/relations-that-travel-and-what-they-expose/specs/referential-integrity/spec.md
 */
final class ExposureDeclaration {
	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $relationType  The relation type descriptor.
	 * @param array<int, string>   $farProperties The far schema's declared properties.
	 * @param string               $typeName      The type, for the message.
	 */
	public function __construct(
		public readonly array $relationType,
		public readonly array $farProperties,
		public readonly string $typeName,
	) {
	}//end __construct()
}//end class
