<?php

/**
 * Characterisation of the FederatedShare entity: its registered field types and its
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
use OCA\OpenRegister\Db\FederatedShare;
use PHPUnit\Framework\TestCase;

/**
 * Golden field types and serialisation for FederatedShare.
 *
 * @covers \OCA\OpenRegister\Db\FederatedShare
 */
final class FederatedShareSerialisationGoldenTest extends TestCase {

	/**
	 * The registered field types, in registration order.
	 *
	 * @var array<string, string>
	 */
	private const FIELD_TYPES = [
		'id' => 'integer',
		'uuid' => 'string',
		'direction' => 'string',
		'remoteInstanceUrl' => 'string',
		'remoteProviderId' => 'string',
		'shareToken' => 'string',
		'scope' => 'string',
		'register' => 'string',
		'schema' => 'string',
		'objectUri' => 'string',
		'queryFilter' => 'json',
		'permissions' => 'string',
		'organisation' => 'string',
		'sharedWith' => 'string',
		'status' => 'string',
		'owner' => 'string',
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
		'direction' => null,
		'remoteInstanceUrl' => null,
		'remoteProviderId' => null,
		'shareToken' => null,
		'scope' => null,
		'register' => null,
		'schema' => null,
		'objectUri' => null,
		'queryFilter' => null,
		'permissions' => null,
		'organisation' => null,
		'sharedWith' => null,
		'status' => null,
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
		'direction' => 'direction-v',
		'remoteInstanceUrl' => 'remoteInstanceUrl-v',
		'remoteProviderId' => 'remoteProviderId-v',
		'shareToken' => 'shareToken-v',
		'scope' => 'scope-v',
		'register' => 'register-v',
		'schema' => 'schema-v',
		'objectUri' => 'objectUri-v',
		'queryFilter' => [
			'k' => 'queryFilter',
		],
		'permissions' => 'permissions-v',
		'organisation' => 'organisation-v',
		'sharedWith' => 'sharedWith-v',
		'status' => 'status-v',
		'created' => '2026-01-02T03:04:05+00:00',
		'updated' => '2026-01-02T03:04:05+00:00',
	];

	/**
	 * Field types are registered under the same names, types and order.
	 *
	 * @return void
	 */
	public function testFieldTypesAreRegisteredInOrder(): void {
		$this->assertSame(self::FIELD_TYPES, (new FederatedShare())->getFieldTypes());
	}//end testFieldTypesAreRegisteredInOrder()

	/**
	 * A fresh entity serialises to the pinned shape.
	 *
	 * @return void
	 */
	public function testEmptyEntitySerialises(): void {
		$this->assertSame(self::EMPTY_JSON, (new FederatedShare())->jsonSerialize());
	}//end testEmptyEntitySerialises()

	/**
	 * A fully set entity serialises to the pinned shape.
	 *
	 * @return void
	 */
	public function testPopulatedEntitySerialises(): void {
		$this->assertSame(self::POPULATED_JSON, $this->populated(entity: new FederatedShare())->jsonSerialize());
	}//end testPopulatedEntitySerialises()

	/**
	 * Set every registered field to a value derived from its name and type.
	 *
	 * @param FederatedShare $entity The entity to fill.
	 *
	 * @return FederatedShare The filled entity.
	 */
	private function populated(FederatedShare $entity): FederatedShare {
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
}//end class
