<?php

/**
 * RelationTypeResolverInterface: what callers outside the component use of RelationTypeResolver.
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
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Relation;

use OCA\OpenRegister\Db\Schema;

/**
 * Declares only what callers outside the component use of RelationTypeResolver; the rest
 * of the class stays its own.
 */
interface RelationTypeResolverInterface {

	/**
	 * The row travels from the object being read towards the object it names.
	 *
	 * @var string
	 */
	public const DIRECTION_OUTGOING = 'outgoing';

	/**
	 * Every relation descriptor a schema declares, keyed by property name.
	 *
	 * Every `$ref` property gets a descriptor, annotated or not, because a
	 * caller that has to ask "is there one" per row is a caller that will
	 * forget to. An unannotated property resolves to its own title and the
	 * generic fallback, which is exactly what the spec says it reads as.
	 *
	 * @param Schema|null $schema The schema owning the properties.
	 * @param string $language The BCP-47 tag to resolve labels in.
	 *
	 * @return array<string, array<string, mixed>> Descriptors, keyed by property name.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function descriptors(?Schema $schema, string $language = 'nl'): array;
}//end interface
