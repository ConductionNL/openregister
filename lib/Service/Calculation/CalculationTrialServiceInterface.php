<?php

/**
 * CalculationTrialServiceInterface: what callers outside the component use of CalculationTrialService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Calculation
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

namespace OCA\OpenRegister\Service\Calculation;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;

/**
 * Try a calculation on a sample or a stored object.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface CalculationTrialServiceInterface {

	/**
	 * Evaluate an unsaved declaration against a sample payload.
	 *
	 * @param array<string, mixed> $declaration The `{type, expression}` declaration.
	 * @param array<string, mixed> $sample The sample object payload to evaluate against.
	 *
	 * @return array{ok: bool, value?: mixed, dependencies: array<int, string>, error?: array{code: string, message: string}} The trial result.
	 *
	 * @spec openspec/specs/computed-fields/spec.md
	 */
	public function trySample(array $declaration, array $sample): array;

	/**
	 * Evaluate an unsaved declaration against an object that already exists.
	 *
	 * The object is read, never written: the value comes back in the response
	 * and nothing is persisted. The caller resolves the object with RBAC and
	 * tenancy on, so only an object the acting user may read arrives here.
	 *
	 * @param array<string, mixed> $declaration The `{type, expression}` declaration.
	 * @param ObjectEntity $object The object, already resolved for the acting user.
	 * @param Schema $schema The object's schema.
	 *
	 * @return array{ok: bool, value?: mixed, dependencies: array<int, string>, error?: array{code: string, message: string}} The trial result.
	 *
	 * @spec openspec/specs/computed-fields/spec.md
	 */
	public function tryObject(array $declaration, ObjectEntity $object, Schema $schema): array;

	/**
	 * The refusal for a named object that is absent or that the caller may not read.
	 *
	 * Both cases answer the same way, so the trial cannot be used to learn
	 * whether an object the caller may not read exists.
	 *
	 * @param array<string, mixed> $declaration The declaration under trial.
	 * @param string $objectId The object id the caller named.
	 * @param string $reason Why the lookup failed.
	 *
	 * @return array{ok: bool, dependencies: array<int, string>, error: array{code: string, message: string}} The trial result.
	 *
	 * @spec openspec/specs/computed-fields/spec.md
	 */
	public function objectNotFound(array $declaration, string $objectId, string $reason): array;
}//end interface
