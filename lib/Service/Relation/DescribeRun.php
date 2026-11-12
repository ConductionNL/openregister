<?php

/**
 * One reference property being described by the relation-type resolver.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Relation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Relation;

/**
 * The property a descriptor is built for, the language its labels resolve in,
 * and its declaration merged over the vocabulary entry it names.
 *
 * Built and used by RelationTypeResolver only; it never leaves that class.
 *
 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
 */
final class DescribeRun {

	/**
	 * The property's declaration over its vocabulary entry; empty until merged.
	 *
	 * @var array<string, mixed>
	 */
	public array $merged = [];

	/**
	 * Start describing one property.
	 *
	 * @param string $name     The property name.
	 * @param mixed  $property The property definition.
	 * @param string $language The BCP-47 tag labels resolve in.
	 */
	public function __construct(
		public readonly string $name,
		public readonly mixed $property,
		public readonly string $language,
	) {
	}//end __construct()
}//end class
