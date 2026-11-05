<?php

/**
 * The object a `_meta.languageMeta` envelope describes.
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

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;

/**
 * An entity with its schema, its register's default language and its object data before translations were resolved.
 */
final readonly class LanguageMetaSubject {

	/**
	 * Constructor.
	 *
	 * @param ObjectEntity         $entity          The rendered entity.
	 * @param Schema               $schema          The entity's schema.
	 * @param string               $registerDefault The register's default language ('nl' without a register).
	 * @param array<string, mixed> $preResolveData  The object data with its `_translationMeta` block intact.
	 */
	public function __construct(
		public ObjectEntity $entity,
		public Schema $schema,
		public string $registerDefault,
		public array $preResolveData,
	) {
	}//end __construct()
}//end class
