<?php

/**
 * ErasureSchemaResolver — the schema an erasure reads an object's scope under.
 *
 * The preview and the run both hand the destruction scope an object's schema,
 * and both have to agree on what "no schema" means: an object whose schema id
 * is empty, or whose schema row cannot be read, is scoped without one. One
 * resolver keeps the preview's count and the run's act on the same answer.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Gdpr\Erasure
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Gdpr\Erasure;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use Throwable;

/**
 * Resolves an object's schema for the erasure path, or null.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Gdpr\Erasure
 *
 * @spec openspec/specs/gdpr-data-subject-rights/spec.md
 */
class ErasureSchemaResolver {
	/**
	 * Wire the resolver.
	 *
	 * @param SchemaMapperInterface $schemaMapper Schema rows.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SchemaMapperInterface $schemaMapper,
	) {
	}//end __construct()

	/**
	 * Resolve an object's schema, or null when it does not resolve.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return Schema|null The schema.
	 *
	 * @spec openspec/specs/gdpr-data-subject-rights/spec.md
	 */
	public function schemaOf(ObjectEntity $object): ?Schema {
		$schemaId = (string)($object->getSchema() ?? '');
		if ($schemaId === '') {
			return null;
		}

		try {
			return $this->schemaMapper->find((int)$schemaId);
		} catch (Throwable) {
			return null;
		}
	}//end schemaOf()
}//end class
