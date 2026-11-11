<?php

/**
 * PurgeCandidate — an object found for a named UUID, with its schema.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Command
 * @package  OCA\OpenRegister\Command
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

namespace OCA\OpenRegister\Command;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;

/**
 * One object about to be purged: the UUID the operator named, the row and its schema.
 *
 * @category Command
 * @package  OCA\OpenRegister\Command
 *
 * @spec openspec/specs/archival-annotation-vocabulary/spec.md
 */
final class PurgeCandidate {

	/**
	 * Pair a named UUID with what it resolved to.
	 *
	 * @param string       $uuid   The UUID as the operator or the import job named it.
	 * @param ObjectEntity $object The row it resolved to.
	 * @param Schema|null  $schema The row's schema, or null when it cannot be resolved.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $uuid,
		public readonly ObjectEntity $object,
		public readonly ?Schema $schema,
	) {
	}//end __construct()

	/**
	 * Whether the schema declares the archival annotation.
	 *
	 * @return bool True for a legally retained record.
	 *
	 * @spec openspec/specs/archival-annotation-vocabulary/spec.md
	 */
	public function isArchival(): bool {
		return ($this->schema !== null && $this->schema->hasArchivalAnnotation() === true);
	}//end isArchival()

	/**
	 * The schema slug, or the schema id when the schema cannot be resolved.
	 *
	 * @return string The label for messages.
	 *
	 * @spec openspec/specs/archival-annotation-vocabulary/spec.md
	 */
	public function label(): string {
		return ($this->schema?->getSlug() ?? (string)$this->object->getSchema());
	}//end label()
}//end class
