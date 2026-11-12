<?php

/**
 * McpResourcesService golden tests
 *
 * Pins the exact resources/list answer (including the failure fallback and
 * its warning) and how every openregister:// URI shape is parsed into a read.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Mcp;

use InvalidArgumentException;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaCatalog;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Mcp\Service\Mcp\McpResourcesService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden tests for McpResourcesService::listResources() and the URI parsing of readResource().
 *
 * @spec openspec/specs/mcp-discovery/spec.md
 */
class McpResourcesServiceGoldenTest extends TestCase {

	/**
	 * The register mapper.
	 *
	 * @var RegisterMapper&MockObject
	 */
	private $registerMapper;

	/**
	 * The schema mapper.
	 *
	 * @var SchemaMapper&MockObject
	 */
	private $schemaMapper;

	/**
	 * The object service.
	 *
	 * @var ObjectService&MockObject
	 */
	private $objectService;

	/**
	 * Warnings logged, as [message, context].
	 *
	 * @var list<array{0: string, 1: array}>
	 */
	private array $warnings = [];

	/**
	 * The service under test.
	 *
	 * @var McpResourcesService
	 */
	private McpResourcesService $service;

	/**
	 * Build the service over mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->objectService = $this->createMock(ObjectService::class);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->warnings[] = [$message, $context];
			}
		);

		$this->service = new McpResourcesService(
			new SchemaCatalog($this->registerMapper, $this->schemaMapper, $this->createMock(MagicMapperInterface::class)),
			$this->objectService,
			$logger
		);
	}//end setUp()

	/**
	 * A register stub.
	 *
	 * @param int        $id      The register id.
	 * @param array      $schemas The schema ids.
	 *
	 * @return Register
	 */
	private function register(int $id, array $schemas): Register {
		$register = $this->getMockBuilder(Register::class)
			->disableOriginalConstructor()
			->onlyMethods(['getSchemas'])
			->addMethods(['getId', 'getTitle'])
			->getMock();
		$register->method('getId')->willReturn($id);
		$register->method('getTitle')->willReturn('Register '.$id);
		$register->method('getSchemas')->willReturn($schemas);
		return $register;
	}//end register()

	/**
	 * A schema stub.
	 *
	 * @param int $id The schema id.
	 *
	 * @return Schema
	 */
	private function schema(int $id): Schema {
		$schema = $this->getMockBuilder(Schema::class)
			->disableOriginalConstructor()
			->onlyMethods(['jsonSerialize'])
			->addMethods(['getId', 'getTitle'])
			->getMock();
		$schema->method('getId')->willReturn($id);
		$schema->method('getTitle')->willReturn('Schema '.$id);
		$schema->method('jsonSerialize')->willReturn(['schema' => $id]);
		return $schema;
	}//end schema()

	/**
	 * The full list: static entries, then one entry per pair, skipping registers without schemas and deleted schemas.
	 *
	 * @return void
	 */
	public function testListResourcesGolden(): void {
		$this->registerMapper->method('findAll')->willReturn(
			[
				$this->register(1, []),
				$this->register(2, [20, 21, 22]),
			]
		);
		$this->schemaMapper->method('find')->willReturnCallback(
			function ($id): Schema {
				if ($id === 21) {
					throw new DoesNotExistException('gone');
				}

				return $this->schema((int) $id);
			}
		);

		$expected = [
			'resources' => [
				[
					'uri' => 'openregister://registers',
					'name' => 'All Registers',
					'description' => 'List of all registers (data containers)',
					'mimeType' => 'application/json',
				],
				[
					'uri' => 'openregister://schemas',
					'name' => 'All Schemas',
					'description' => 'List of all schemas (data definitions)',
					'mimeType' => 'application/json',
				],
				[
					'uri' => 'openregister://objects/2/20',
					'name' => 'Register 2 — Schema 20',
					'description' => 'Objects in register "Register 2" with schema "Schema 20"',
					'mimeType' => 'application/json',
				],
				[
					'uri' => 'openregister://objects/2/22',
					'name' => 'Register 2 — Schema 22',
					'description' => 'Objects in register "Register 2" with schema "Schema 22"',
					'mimeType' => 'application/json',
				],
			],
		];

		self::assertSame($expected, $this->service->listResources());
		self::assertSame([], $this->warnings);
	}//end testListResourcesGolden()

	/**
	 * A failing lookup keeps the pairs found so far and logs one warning.
	 *
	 * @return void
	 */
	public function testListResourcesFailureKeepsWhatWasFound(): void {
		$this->registerMapper->method('findAll')->willReturn([$this->register(3, [30, 31])]);
		$this->schemaMapper->method('find')->willReturnCallback(
			function ($id): Schema {
				if ($id === 31) {
					throw new RuntimeException('db down');
				}

				return $this->schema((int) $id);
			}
		);

		$result = $this->service->listResources();

		self::assertSame(
			['openregister://registers', 'openregister://schemas', 'openregister://objects/3/30'],
			array_column($result['resources'], 'uri')
		);
		self::assertSame(
			[['[MCP] Failed to list register/schema pairs for resources', ['error' => 'db down']]],
			$this->warnings
		);
	}//end testListResourcesFailureKeepsWhatWasFound()

	/**
	 * Register and schema URIs read the list without an id and one item with an (int) id.
	 *
	 * @return void
	 */
	public function testRegisterAndSchemaUris(): void {
		$this->registerMapper->expects($this->once())->method('findAll')->willReturn([]);
		$this->schemaMapper->expects($this->once())->method('find')->with(7)->willReturn($this->schema(7));
		$this->registerMapper->expects($this->once())->method('find')->with(0)
			->willThrowException(new RuntimeException('register 0'));

		self::assertSame('[]', $this->service->readResource(uri: 'openregister://registers')['contents'][0]['text']);
		self::assertSame(
			json_encode(['schema' => 7], JSON_PRETTY_PRINT),
			$this->service->readResource(uri: 'openregister://schemas/7')['contents'][0]['text']
		);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('register 0');
		$this->service->readResource(uri: 'openregister://registers/abc');
	}//end testRegisterAndSchemaUris()

	/**
	 * An objects URI with an object id scopes the service and looks that object up.
	 *
	 * @return void
	 */
	public function testObjectUriWithId(): void {
		$this->objectService->expects($this->once())->method('setRegister')->with(4);
		$this->objectService->expects($this->once())->method('setSchema')->with(9);
		$this->objectService->expects($this->once())->method('find')->with('abc-1')
			->willThrowException(new RuntimeException('find abc-1'));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('find abc-1');
		$this->service->readResource(uri: 'openregister://objects/4/9/abc-1');
	}//end testObjectUriWithId()

	/**
	 * The exact refusals of a malformed URI.
	 *
	 * @return void
	 */
	public function testRefusalMessages(): void {
		$cases = [
			'openregister://objects/1' => 'Objects URI requires register and schema IDs: openregister://objects/{registerId}/{schemaId}',
			'openregister://things/1' => 'Unknown resource type: things',
			'openregister://' => 'Unknown resource type: ',
			'https://x/registers' => 'Invalid URI scheme, expected openregister://',
		];

		$seen = [];
		foreach (array_keys($cases) as $uri) {
			try {
				$this->service->readResource(uri: $uri);
				$seen[$uri] = 'no exception';
			} catch (InvalidArgumentException $e) {
				$seen[$uri] = $e->getMessage();
			}
		}

		self::assertSame($cases, $seen);
	}//end testRefusalMessages()
}//end class
