<?php

/**
 * RegisterService::findAll() and findAllSerialized() hand the mapper the same query.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/register-service-extensions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\RegisterService;
use OCA\OpenRegister\Service\RegisterServiceHelpers;
use OCA\OpenRegister\Service\RegisterServiceStorage;
use OCA\OpenRegister\Service\Serializer\RegisterSerializer;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins the mapper arguments, including the multitenancy flag, of the register list methods.
 */
class RegisterServiceFindAllGoldenTest extends TestCase {

	/**
	 * The register mapper double.
	 *
	 * @var RegisterMapper&MockObject
	 */
	private RegisterMapper&MockObject $registerMapper;

	/**
	 * The arguments each mapper findAll() call received.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $calls = [];

	/**
	 * The service under test.
	 *
	 * @var RegisterService
	 */
	private RegisterService $service;

	/**
	 * Build the service with a recording register mapper.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->registerMapper->method('findAll')->willReturnCallback(
			function (...$args): array {
				$this->calls[] = $args;
				$register = new Register();
				$register->setId(1);
				$register->setTitle('One');

				return [$register];
			}
		);
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$logger = $this->createMock(LoggerInterface::class);

		$this->service = new RegisterService(
			storage: new RegisterServiceStorage(registerMapper: $this->registerMapper, schemaMapper: $schemaMapper, db: $this->createMock(IDBConnection::class)),
			helpers: new RegisterServiceHelpers($this->createMock(FileService::class), new RegisterSerializer($schemaMapper, $logger)),
			organisationService: $this->createMock(OrganisationService::class),
			logger: $logger
		);
	}//end setUp()

	/**
	 * No limit asks the mapper for every row; the multitenancy flag is handed on as given.
	 *
	 * @return void
	 */
	public function testFindAllHandsTheMapperTheQuery(): void {
		$this->service->findAll();
		$this->service->findAll(limit: 10, offset: 5, filters: ['title' => 'x'], searchConditions: ['a = :a'], searchParams: ['a' => 1], _multitenancy: false);
		$this->service->findAll(filters: null, searchConditions: null, searchParams: null);

		$this->assertSame(
			[
				[null, null, [], [], [], true, true],
				[10, 5, ['title' => 'x'], ['a = :a'], ['a' => 1], true, false],
				[null, null, [], [], [], true, true],
			],
			$this->normalised()
		);
	}//end testFindAllHandsTheMapperTheQuery()

	/**
	 * The serialized list runs the same query and serializes every register.
	 *
	 * @return void
	 */
	public function testFindAllSerializedHandsTheMapperTheQuery(): void {
		$out = $this->service->findAllSerialized(limit: 3, offset: 0, filters: ['slug' => 's'], _multitenancy: false);

		$this->assertSame([[3, 0, ['slug' => 's'], [], [], true, false]], $this->normalised());
		$this->assertSame(1, $out[0]['id']);
	}//end testFindAllSerializedHandsTheMapperTheQuery()

	/**
	 * The recorded mapper calls with the mapper defaults filled in and null lists as empty lists.
	 *
	 * @return array<int, array<int, mixed>> One list of seven arguments per call.
	 */
	private function normalised(): array {
		$names = ['limit', 'offset', 'filters', 'searchConditions', 'searchParams', '_rbac', '_multitenancy'];
		$defaults = [null, null, [], [], [], true, true];
		$out = [];
		foreach ($this->calls as $args) {
			$row = [];
			foreach ($names as $index => $name) {
				$value = ($args[$name] ?? $args[$index] ?? $defaults[$index]);
				if ($index >= 2 && $index <= 4 && $value === null) {
					$value = [];
				}

				$row[] = $value;
			}

			$out[] = $row;
		}

		return $out;
	}//end normalised()
}//end class
