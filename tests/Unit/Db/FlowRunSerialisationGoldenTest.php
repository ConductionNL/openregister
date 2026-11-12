<?php

/**
 * Characterisation of the FlowRun entity: its registered field types and its
 * serialised shape, empty and with every field set, pinned key for key and in order.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\FlowRun;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Golden field types and serialisation for FlowRun.
 *
 * @covers \OCA\OpenRegister\Db\FlowRun
 */
final class FlowRunSerialisationGoldenTest extends TestCase {

	/**
	 * The registered field types, in registration order.
	 *
	 * @var array<string, string>
	 */
	private const FIELD_TYPES = [
		'id' => 'integer',
		'uuid' => 'string',
		'flowId' => 'string',
		'flowVersion' => 'integer',
		'status' => 'string',
		'marking' => 'json',
		'placeItems' => 'json',
		'firings' => 'integer',
		'items' => 'json',
		'context' => 'json',
		'log' => 'json',
		'subjectUuid' => 'string',
		'subjects' => 'json',
		'subjectRegister' => 'string',
		'subjectSchema' => 'string',
		'triggeredBy' => 'string',
		'runAs' => 'string',
		'trigger' => 'string',
		'organisation' => 'string',
		'error' => 'string',
		'resumeAt' => 'datetime',
		'parentRunUuid' => 'string',
		'correlationKey' => 'string',
		'created' => 'datetime',
		'updated' => 'datetime',
	];

	/**
	 * The serialised shape of a fresh entity.
	 *
	 * @var array<string, mixed>
	 */
	private const EMPTY_JSON = [
		'id' => null,
		'uuid' => null,
		'flowId' => null,
		'flowVersion' => null,
		'status' => null,
		'outcome' => '',
		'marking' => [],
		'placeItems' => null,
		'firings' => 0,
		'items' => [],
		'context' => [],
		'log' => [],
		'subjectUuid' => null,
		'subjects' => [
			'(object)' => [],
		],
		'subjectRegister' => null,
		'subjectSchema' => null,
		'triggeredBy' => null,
		'runAs' => null,
		'trigger' => null,
		'organisation' => null,
		'error' => null,
		'resumeAt' => null,
		'parentRunUuid' => null,
		'correlationKey' => null,
		'created' => null,
		'updated' => null,
	];

	/**
	 * The serialised shape with every field set.
	 *
	 * @var array<string, mixed>
	 */
	private const POPULATED_JSON = [
		'id' => 2,
		'uuid' => 'uuid-v',
		'flowId' => 'flowId-v',
		'flowVersion' => 11,
		'status' => 'status-v',
		'outcome' => 'status-v',
		'marking' => [
			'k' => 'marking',
		],
		'placeItems' => [
			'k' => 'placeItems',
		],
		'firings' => 7,
		'items' => [
			'k' => 'items',
		],
		'context' => [
			'k' => 'context',
		],
		'log' => [
			'k' => 'log',
		],
		'subjectUuid' => 'subjectUuid-v',
		'subjects' => [
			'(object)' => [
				'k' => 'subjects',
			],
		],
		'subjectRegister' => 'subjectRegister-v',
		'subjectSchema' => 'subjectSchema-v',
		'triggeredBy' => 'triggeredBy-v',
		'runAs' => 'runAs-v',
		'trigger' => 'trigger-v',
		'organisation' => 'organisation-v',
		'error' => 'error-v',
		'resumeAt' => '2026-01-02T03:04:05+00:00',
		'parentRunUuid' => 'parentRunUuid-v',
		'correlationKey' => 'correlationKey-v',
		'created' => '2026-01-02T03:04:05+00:00',
		'updated' => '2026-01-02T03:04:05+00:00',
	];

	/**
	 * Field types are registered under the same names, types and order.
	 *
	 * @return void
	 */
	public function testFieldTypesAreRegisteredInOrder(): void {
		$this->assertSame(self::FIELD_TYPES, (new FlowRun())->getFieldTypes());
	}//end testFieldTypesAreRegisteredInOrder()

	/**
	 * A fresh entity serialises to the pinned shape.
	 *
	 * @return void
	 */
	public function testEmptyEntitySerialises(): void {
		$this->assertSame(self::EMPTY_JSON, $this->normalised(json: (new FlowRun())->jsonSerialize()));
	}//end testEmptyEntitySerialises()

	/**
	 * A fully set entity serialises to the pinned shape.
	 *
	 * @return void
	 */
	public function testPopulatedEntitySerialises(): void {
		$this->assertSame(self::POPULATED_JSON, $this->normalised(json: $this->populated(entity: new FlowRun())->jsonSerialize()));
	}//end testPopulatedEntitySerialises()

	/**
	 * Set every registered field to a value derived from its name and type.
	 *
	 * @param FlowRun $entity The entity to fill.
	 *
	 * @return FlowRun The filled entity.
	 */
	private function populated(FlowRun $entity): FlowRun {
		foreach ($entity->getFieldTypes() as $field => $type) {
			$entity->{'set'.ucfirst($field)}($this->valueFor(field: $field, type: $type));
		}

		return $entity;
	}//end populated()

	/**
	 * A deterministic value for one field.
	 *
	 * @param string $field The field name.
	 * @param string $type  The registered entity type.
	 *
	 * @return mixed The value.
	 */
	private function valueFor(string $field, string $type): mixed {
		return match ($type) {
			'integer', 'int' => strlen($field),
			'boolean', 'bool' => true,
			'json' => ['k' => $field],
			'datetime' => new DateTime('2026-01-02T03:04:05+00:00'),
			'float', 'double' => 1.5,
			default => $field.'-v',
		};
	}//end valueFor()

	/**
	 * Replace each stdClass value by a marked array so the shape can be compared with assertSame.
	 *
	 * @param array $json The serialised entity.
	 *
	 * @return array The comparable form.
	 */
	private function normalised(array $json): array {
		foreach ($json as $key => $value) {
			if ($value instanceof stdClass) {
				$json[$key] = ['(object)' => (array) $value];
			}
		}

		return $json;
	}//end normalised()
}//end class
