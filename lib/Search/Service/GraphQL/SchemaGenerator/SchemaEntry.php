<?php

/**
 * One schema as it appears at the root of the GraphQL graph.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Search\Service\GraphQL\SchemaGenerator
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://OpenRegister.app
 *
 * @spec openspec/specs/graphql-api/spec.md
 */

namespace OCA\OpenRegister\Search\Service\GraphQL\SchemaGenerator;

use GraphQL\Type\Definition\ObjectType;
use OCA\OpenRegister\Db\Schema as RegisterSchema;

/**
 * A schema with the names and type its root query and mutation fields use.
 */
class SchemaEntry {

	/**
	 * Constructor.
	 *
	 * @param RegisterSchema $schema The register schema
	 * @param string $singular Singular field name
	 * @param string $plural Plural field name
	 * @param ObjectType $objectType The object type
	 */
	public function __construct(
		public readonly RegisterSchema $schema,
		public readonly string $singular,
		public readonly string $plural,
		public readonly ObjectType $objectType,
	) {
	}//end __construct()

	/**
	 * The schema title, or a fallback when it has none.
	 *
	 * @param string $fallback The text to use without a title
	 *
	 * @return string The title
	 *
	 * @spec openspec/specs/graphql-api/spec.md
	 */
	public function titleOr(string $fallback): string {
		$title = ($this->schema->getTitle() ?? '');
		if ($title === '') {
			return $fallback;
		}

		return $title;
	}//end titleOr()
}//end class
