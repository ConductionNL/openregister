<?php

/**
 * OpenRegister validated write
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rules
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rules;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;

/**
 * The object a schema's administered validations are run over, with that schema.
 *
 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/object-lifecycle/spec.md
 */
final class ValidatedWrite {
	/**
	 * Name the write.
	 *
	 * @param ObjectEntity $object The object being saved.
	 * @param Schema       $schema Its schema.
	 */
	public function __construct(
		public readonly ObjectEntity $object,
		public readonly Schema $schema,
	) {
	}//end __construct()

	/**
	 * The schema's slug, empty when it has none.
	 *
	 * @return string The slug.
	 *
	 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/object-lifecycle/spec.md
	 */
	public function schemaSlug(): string {
		return (string)($this->schema->getSlug() ?? '');
	}//end schemaSlug()

	/**
	 * The schema's configuration, empty when it has none.
	 *
	 * @return array<string, mixed> The configuration.
	 *
	 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/object-lifecycle/spec.md
	 */
	public function configuration(): array {
		return ($this->schema->getConfiguration() ?? []);
	}//end configuration()
}//end class
