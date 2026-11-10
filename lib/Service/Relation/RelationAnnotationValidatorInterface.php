<?php

/**
 * RelationAnnotationValidatorInterface: what callers outside the component use of RelationAnnotationValidator.
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

/**
 * Declares only what callers outside the component use of RelationAnnotationValidator; the rest
 * of the class stays its own.
 */
interface RelationAnnotationValidatorInterface {

	/**
	 * The schema-level annotation holding the named relation vocabulary.
	 *
	 * @var string
	 */
	public const VOCABULARY_ANNOTATION = 'x-openregister-relation-types';

	/**
	 * The reasons a schema's relation declarations cannot be saved.
	 *
	 * @param array<string, mixed> $schema The schema shape: `properties` and,
	 *                                     beside it, the vocabulary annotation.
	 *
	 * @return array<int, array{code: string, message: string}> The refusals,
	 *         empty when the declarations are valid.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function validate(array $schema): array;
}//end interface
