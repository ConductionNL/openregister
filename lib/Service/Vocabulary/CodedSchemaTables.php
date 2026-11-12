<?php

/**
 * OpenRegister CodedSchemaTables
 *
 * The schemas that declare coded properties, read from the outside: the
 * schema mapper that finds them, the factory that reads each one's coded
 * declarations, and the object tables that hold their values. The branch
 * filter expander, the declaration resolver and the concept delete guard
 * each look a schema up and read its declarations, so they are injected as
 * one aggregate (ADR-011 Rule 4).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Vocabulary
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/skos-concept-registers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Vocabulary;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;

/**
 * Finding the schemas that bind a scheme, their coded declarations and their tables.
 *
 * @spec openspec/specs/skos-concept-registers/spec.md
 */
final class CodedSchemaTables {

	/**
	 * Constructor.
	 *
	 * @param SchemaMapperInterface           $schemas      Finds and lists schemas.
	 * @param CodedPropertyDeclarationFactory $declarations Reads a schema's coded declarations.
	 * @param MagicMapperInterface            $objects      Counts the objects in a schema's table.
	 */
	public function __construct(
		public readonly SchemaMapperInterface $schemas,
		public readonly CodedPropertyDeclarationFactory $declarations,
		public readonly MagicMapperInterface $objects,
	) {

	}//end __construct()
}//end class
