<?php

/**
 * A SchemaCatalogInterface over a register and a schema mapper, for controller tests.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use LogicException;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaCatalogInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;

/**
 * The catalog the object controllers read: registers and schemas only.
 */
final class MapperBackedSchemaCatalog implements SchemaCatalogInterface {
	/**
	 * Wrap the two mappers.
	 *
	 * @param RegisterMapperInterface $registers The register mapper.
	 * @param SchemaMapperInterface   $schemas   The schema mapper.
	 */
	public function __construct(
		private readonly RegisterMapperInterface $registers,
		private readonly SchemaMapperInterface $schemas,
	) {
	}

	/**
	 * The register mapper.
	 *
	 * @return RegisterMapperInterface
	 */
	public function registers(): RegisterMapperInterface {
		return $this->registers;
	}

	/**
	 * The schema mapper.
	 *
	 * @return SchemaMapperInterface
	 */
	public function schemas(): SchemaMapperInterface {
		return $this->schemas;
	}

	/**
	 * Not read by the object controllers.
	 *
	 * @return MagicMapperInterface
	 */
	public function tables(): MagicMapperInterface {
		throw new LogicException('The object controllers do not read magic tables');
	}
}
