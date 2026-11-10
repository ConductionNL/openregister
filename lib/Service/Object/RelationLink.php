<?php

/**
 * OpenRegister RelationLink
 *
 * Which property a related row came in through, on which schema, from which side.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/linked-entity-types/spec.md
 */

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Relation\RelationTypeResolver;

/**
 * The schema that declares a relation property, the stored path, and the direction it is read in.
 *
 * @spec openspec/specs/linked-entity-types/spec.md
 */
final class RelationLink {

	/**
	 * One of RelationTypeResolver::DIRECTION_*; outgoing unless switched with incoming().
	 *
	 * @var string
	 */
	public string $direction = RelationTypeResolver::DIRECTION_OUTGOING;

	/**
	 * Constructor.
	 *
	 * @param Schema|null $schema The schema that declares the property.
	 * @param string|null $path   The stored relation path.
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function __construct(
		public readonly ?Schema $schema,
		public readonly ?string $path,
	) {
	}//end __construct()

	/**
	 * Read the link from the referenced object back to who uses it.
	 *
	 * @return self
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function incoming(): self {
		$this->direction = RelationTypeResolver::DIRECTION_INCOMING;
		return $this;
	}//end incoming()
}//end class
