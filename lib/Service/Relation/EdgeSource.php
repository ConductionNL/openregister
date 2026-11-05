<?php

/**
 * EdgeSource
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Relation;

use OCA\OpenRegister\Db\Schema;

/**
 * The object end of a property edge in the relation graph: its uuid, the
 * language labels are read in, its schema and the relation path.
 *
 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
 */
final class EdgeSource {
	/**
	 * The schema of the object, null when it cannot be read.
	 *
	 * @var Schema|null
	 */
	private ?Schema $schema = null;

	/**
	 * The relation path on the object.
	 *
	 * @var string
	 */
	private string $path = '';

	/**
	 * Constructor.
	 *
	 * @param string $uuid     The uuid of the object.
	 * @param string $language The language labels are read in.
	 */
	public function __construct(
		public readonly string $uuid,
		public readonly string $language,
	) {
	}//end __construct()

	/**
	 * This source with the object's schema.
	 *
	 * @param Schema|null $schema The schema, null when it cannot be read.
	 *
	 * @return self
	 */
	public function withSchema(?Schema $schema): self {
		$source = clone $this;
		$source->schema = $schema;

		return $source;
	}//end withSchema()

	/**
	 * This source at one relation path.
	 *
	 * @param string $path The relation path.
	 *
	 * @return self
	 */
	public function withPath(string $path): self {
		$source = clone $this;
		$source->path = $path;

		return $source;
	}//end withPath()

	/**
	 * The schema of the object, null when it cannot be read.
	 *
	 * @return Schema|null
	 */
	public function schema(): ?Schema {
		return $this->schema;
	}//end schema()

	/**
	 * The relation path on the object.
	 *
	 * @return string
	 */
	public function path(): string {
		return $this->path;
	}//end path()
}//end class
