<?php

/**
 * The object an action runs on, with the register and schema as the caller named them.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectAction
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-running-an-action-validates-inputs-checks-the-right-and-the-precondition-then-executes-its-body
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectAction;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;

/**
 * The target of one action run: the loaded object and schema, and the address the caller used.
 *
 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-running-an-action-validates-inputs-checks-the-right-and-the-precondition-then-executes-its-body
 */
final class ObjectActionTarget {

	/**
	 * The subject.
	 *
	 * @var ObjectEntity
	 */
	public readonly ObjectEntity $object;

	/**
	 * Its schema.
	 *
	 * @var Schema
	 */
	public readonly Schema $schema;

	/**
	 * The register as the caller named it.
	 *
	 * @var string
	 */
	private readonly string $register;

	/**
	 * The schema as the caller named it.
	 *
	 * @var string
	 */
	private readonly string $schemaRef;

	/**
	 * Name the target of a run.
	 *
	 * @param ObjectEntity $object    The subject.
	 * @param Schema       $schema    Its schema.
	 * @param string       $register  The register as the caller named it.
	 * @param string       $schemaRef The schema as the caller named it.
	 */
	public function __construct(ObjectEntity $object, Schema $schema, string $register, string $schemaRef) {
		$this->object    = $object;
		$this->schema    = $schema;
		$this->register  = $register;
		$this->schemaRef = $schemaRef;
	}//end __construct()

	/**
	 * The run's subject: `{uuid, register, schema}` as the caller named them.
	 *
	 * @return array{uuid: string, register: string, schema: string} The subject.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-running-an-action-validates-inputs-checks-the-right-and-the-precondition-then-executes-its-body
	 */
	public function subject(): array {
		return ['uuid' => (string)$this->object->getUuid(), 'register' => $this->register, 'schema' => $this->schemaRef];
	}//end subject()
}//end class
