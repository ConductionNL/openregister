<?php

/**
 * StateFieldRuleResolverInterface: what callers outside the component use of StateFieldRuleResolver.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Lifecycle
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

namespace OCA\OpenRegister\Service\Lifecycle;

use OCA\OpenRegister\Db\Schema;

/**
 * Resolve the field rules a lifecycle state declares for an object.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface StateFieldRuleResolverInterface {

	/**
	 * The lifecycle annotation of a schema, or null when it declares none.
	 *
	 * @param Schema $schema The schema to read.
	 *
	 * @return array<string, mixed>|null The annotation.
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md
	 */
	public function annotationOf(Schema $schema): ?array;

	/**
	 * The state an object is in, read off its own data.
	 *
	 * @param array<string, mixed> $annotation The lifecycle annotation.
	 * @param array<string, mixed> $data The object's data.
	 *
	 * @return string|null The state, or null when the object carries no value.
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md
	 */
	public function stateOf(array $annotation, array $data): ?string;

	/**
	 * The rules that apply, read straight from an annotation.
	 *
	 * Separate from {@see self::resolve()} because the validator, the trial
	 * surface and the unit tests hold an annotation rather than a stored
	 * schema, and building a Schema entity to ask one question would put the
	 * mapper in the way of a pure decision.
	 *
	 * @param array<string, mixed> $annotation The lifecycle annotation.
	 * @param array<string, mixed> $data The object's data.
	 * @param string|null $state The state to resolve for; defaults to the object's own.
	 *
	 * @return StateFieldRules The three lists, already decided.
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Each branch is one way the declaration
	 *   can legitimately say "nothing applies"; see the class docblock.
	 * @SuppressWarnings(PHPMD.NPathComplexity) Same reason.
	 */
	public function resolveFromAnnotation(array $annotation, array $data, ?string $state = null): StateFieldRules;
}//end interface
