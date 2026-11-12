<?php

/**
 * Characterisation of the SearchTrail entity: its registered field types and its
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
use OCA\OpenRegister\Db\SearchTrail;
use PHPUnit\Framework\TestCase;

/**
 * Golden field types and serialisation for SearchTrail.
 *
 * @covers \OCA\OpenRegister\Db\SearchTrail
 */
final class SearchTrailSerialisationGoldenTest extends TestCase {

	/**
	 * The registered field types, in registration order.
	 *
	 * @var array<string, string>
	 */
	private const FIELD_TYPES = [
		'id' => 'integer',
		'uuid' => 'string',
		'searchTerm' => 'string',
		'queryParameters' => 'json',
		'resultCount' => 'integer',
		'totalResults' => 'integer',
		'register' => 'integer',
		'schema' => 'integer',
		'registerUuid' => 'string',
		'schemaUuid' => 'string',
		'user' => 'string',
		'userName' => 'string',
		'registerName' => 'string',
		'schemaName' => 'string',
		'session' => 'string',
		'ipAddress' => 'string',
		'userAgent' => 'string',
		'requestUri' => 'string',
		'httpMethod' => 'string',
		'responseTime' => 'integer',
		'page' => 'integer',
		'limit' => 'integer',
		'offset' => 'integer',
		'facetsRequested' => 'boolean',
		'facetableRequested' => 'boolean',
		'filters' => 'json',
		'sortParameters' => 'json',
		'publishedOnly' => 'boolean',
		'executionType' => 'string',
		'created' => 'datetime',
		'organisationId' => 'string',
		'organisationIdType' => 'string',
		'expires' => 'datetime',
		'size' => 'integer',
	];

	/**
	 * The serialised shape of a fresh entity.
	 *
	 * @var array<string, mixed>
	 */
	private const EMPTY_JSON = [
		'id' => null,
		'uuid' => null,
		'searchTerm' => null,
		'queryParameters' => null,
		'resultCount' => null,
		'totalResults' => null,
		'register' => null,
		'schema' => null,
		'registerUuid' => null,
		'schemaUuid' => null,
		'user' => null,
		'userName' => null,
		'registerName' => null,
		'schemaName' => null,
		'session' => null,
		'ipAddress' => null,
		'userAgent' => null,
		'requestUri' => null,
		'httpMethod' => null,
		'responseTime' => null,
		'page' => null,
		'limit' => null,
		'offset' => null,
		'facetsRequested' => null,
		'facetableRequested' => null,
		'filters' => null,
		'sortParameters' => null,
		'executionType' => null,
		'created' => null,
		'organisationId' => null,
		'organisationIdType' => null,
		'expires' => null,
		'size' => null,
	];

	/**
	 * The serialised shape with every field set.
	 *
	 * @var array<string, mixed>
	 */
	private const POPULATED_JSON = [
		'id' => 2,
		'uuid' => 'uuid-v',
		'searchTerm' => 'searchTerm-v',
		'queryParameters' => [
			'k' => 'queryParameters',
		],
		'resultCount' => 11,
		'totalResults' => 12,
		'register' => 8,
		'schema' => 6,
		'registerUuid' => 'registerUuid-v',
		'schemaUuid' => 'schemaUuid-v',
		'user' => 'user-v',
		'userName' => 'userName-v',
		'registerName' => 'registerName-v',
		'schemaName' => 'schemaName-v',
		'session' => 'session-v',
		'ipAddress' => 'ipAddress-v',
		'userAgent' => 'userAgent-v',
		'requestUri' => 'requestUri-v',
		'httpMethod' => 'httpMethod-v',
		'responseTime' => 12,
		'page' => 4,
		'limit' => 5,
		'offset' => 6,
		'facetsRequested' => true,
		'facetableRequested' => true,
		'filters' => [
			'k' => 'filters',
		],
		'sortParameters' => [
			'k' => 'sortParameters',
		],
		'executionType' => 'executionType-v',
		'created' => '2026-01-02T03:04:05+00:00',
		'organisationId' => 'organisationId-v',
		'organisationIdType' => 'organisationIdType-v',
		'expires' => '2026-01-02T03:04:05+00:00',
		'size' => 4,
	];

	/**
	 * Field types are registered under the same names, types and order.
	 *
	 * @return void
	 */
	public function testFieldTypesAreRegisteredInOrder(): void {
		$this->assertSame(self::FIELD_TYPES, (new SearchTrail())->getFieldTypes());
	}//end testFieldTypesAreRegisteredInOrder()

	/**
	 * A fresh entity serialises to the pinned shape.
	 *
	 * @return void
	 */
	public function testEmptyEntitySerialises(): void {
		$this->assertSame(self::EMPTY_JSON, (new SearchTrail())->jsonSerialize());
	}//end testEmptyEntitySerialises()

	/**
	 * A fully set entity serialises to the pinned shape.
	 *
	 * @return void
	 */
	public function testPopulatedEntitySerialises(): void {
		$this->assertSame(self::POPULATED_JSON, $this->populated(entity: new SearchTrail())->jsonSerialize());
	}//end testPopulatedEntitySerialises()

	/**
	 * Set every registered field to a value derived from its name and type.
	 *
	 * @param SearchTrail $entity The entity to fill.
	 *
	 * @return SearchTrail The filled entity.
	 */
	private function populated(SearchTrail $entity): SearchTrail {
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
