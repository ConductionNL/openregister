<?php

/**
 * UniqueConstraintEvaluatorInterface: what callers outside the Service component ask of UniqueConstraintEvaluator.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Schemas
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

namespace OCA\OpenRegister\Service\Schemas;

/**
 * Unique-constraint evaluation, as the unique-constraint listener uses it.
 * Only the methods and the constant that caller uses.
 */
interface UniqueConstraintEvaluatorInterface {

	/**
	 * Refuse the save on a breach.
	 *
	 * @var string
	 */
	public const ACTION_REFUSE = 'refuse';

	/**
	 * The search filters that find another object holding the constrained combination.
	 *
	 * @param array{name:string,properties:array<int,string>,action:string} $constraint The constraint.
	 * @param array<string,mixed>                                          $object     The submitted object data.
	 *
	 * @return array<string,mixed>|null The filters, or null when the constraint does not apply.
	 */
	public function filtersFor(array $constraint, array $object): ?array;

	/**
	 * The message naming a breach.
	 *
	 * @param array{name:string,properties:array<int,string>,action:string} $constraint    The constraint.
	 * @param UniqueConflict $conflict The submitted object data and who holds the combination already.
	 *
	 * @return string The message.
	 *
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	public function describeBreach(array $constraint, UniqueConflict $conflict): string;

	/**
	 * The constraints a schema declares.
	 *
	 * @param array<string,mixed>|null $configuration The schema's configuration block.
	 * @param boolean                  $includeLegacy Whether to also read the pre-existing `unique` key.
	 *
	 * @return array<int,array{name:string,properties:array<int,string>,action:string}> The constraints.
	 */
	public function constraints(?array $configuration, bool $includeLegacy = false): array;

	/**
	 * The schema's identity: the declared `refuse` constraint that says `identity: true`.
	 *
	 * @param array<string,mixed>|null $configuration The schema's configuration block.
	 *
	 * @return array{name:string,properties:array<int,string>,action:string}|null The identity, or null when none is declared.
	 *
	 * @spec openspec/changes/modelling-composite-identity/specs/objects-crud/spec.md
	 */
	public function identity(?array $configuration): ?array;

	/**
	 * Why a schema's identity declaration must be refused at save, if it must.
	 *
	 * @param array<string,mixed>|null $configuration The schema's configuration block.
	 *
	 * @return array<int,array{code:string,message:string}> The refusals, empty when the declaration is sound.
	 *
	 * @spec openspec/changes/modelling-composite-identity/specs/objects-crud/spec.md
	 */
	public function identityRefusals(?array $configuration): array;
}//end interface
