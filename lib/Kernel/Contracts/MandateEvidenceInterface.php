<?php
/**
 * MandateEvidenceInterface: whether a named person holds a grantable verb on
 * an object or schema, and what granted it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/tasks-delegation-and-substitution/specs/flow-tasks/spec.md#requirement-a-delegate-must-hold-the-tasks-mandate
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

/**
 * The mandate question a task asks of the permission layer.
 *
 * Processes asks (a delegation or substitution must go to someone holding the
 * task's mandate); Core answers through its per-user permission check and the
 * permission catalogue, and is bound to this contract.
 *
 * @spec openspec/changes/tasks-delegation-and-substitution/specs/flow-tasks/spec.md#requirement-a-delegate-must-hold-the-tasks-mandate
 */
interface MandateEvidenceInterface {

	/**
	 * Whether the permission catalogue knows the verb as grantable.
	 *
	 * @param string $verb The verb.
	 *
	 * @return bool True when grantable.
	 *
	 * @spec openspec/changes/tasks-delegation-and-substitution/specs/flow-tasks/spec.md#requirement-a-task-may-name-the-mandate-its-performer-needs
	 */
	public function isGrantable(string $verb): bool;

	/**
	 * The evidence that a person holds a verb, or null when they do not.
	 *
	 * The check runs against the subject object when one is named (its schema
	 * and register), else against the named schema; null when neither names a
	 * schema or the verb is not grantable.
	 *
	 * @param string      $verb       The verb asked.
	 * @param string      $uid        The person asked about.
	 * @param string|null $objectUuid The subject object, if any.
	 * @param int|null    $registerId The register the subject lives in, if known.
	 * @param int|null    $schemaId   The schema to check against when there is no subject.
	 *
	 * @return array{verb: string, source: string, rule: mixed, until: string|null, app?: string}|null The evidence, or null.
	 *
	 * @throws \Throwable When the check cannot answer (a subject that cannot be loaded, a failing permission check).
	 *
	 * @spec openspec/changes/tasks-delegation-and-substitution/specs/flow-tasks/spec.md#requirement-a-delegate-must-hold-the-tasks-mandate
	 */
	public function evidence(string $verb, string $uid, ?string $objectUuid, ?int $registerId, ?int $schemaId): ?array;
}//end interface
