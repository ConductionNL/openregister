<?php

/**
 * The bulk slug pass decides against the table the rows are written to (#4037).
 *
 * Review findings on PR #4774: rows were grouped by their advisory
 * `@self.register`, while persistChunk() writes every row of a single-schema
 * call into the caller's register; an unresolvable register let a client slug
 * through unchecked; and the fast path lost a top-level client `slug`.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/object-slugs-configurable-and-unique/specs/object-lifecycle/spec.md#requirement-bulk-save-must-apply-the-same-slug-rules-as-a-single-save
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use Exception;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugHandler;
use OCA\OpenRegister\Service\Object\SaveObjects;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;

/**
 * Tests for SaveObjects::applyObjectSlugs().
 */
class SaveObjectsObjectSlugTest extends TestCase {

	private const UUID_A = '00000000-0000-0000-0000-00000000000a';

	/** @var ObjectSlugHandler&MockObject */
	private ObjectSlugHandler $slugHandler;

	/** @var RegisterMapper&MockObject */
	private RegisterMapper $registerMapper;

	private SaveObjects $saveObjects;

	/** @var list<array{rows: array, register: Register}> decideBatch() calls. */
	private array $calls = [];

	protected function setUp(): void {
		parent::setUp();

		$this->slugHandler = $this->createMock(ObjectSlugHandler::class);
		$this->slugHandler->method('isManaged')->willReturn(true);
		$this->slugHandler->method('decideBatch')->willReturnCallback(
			function (array $rows, Register $register, Schema $schema): array {
				$this->calls[] = ['rows' => $rows, 'register' => $register];
				return array_map(static fn (): string => 'decided', $rows);
			}
		);
		$saveHandler = $this->createMock(SaveObject::class);
		$saveHandler->method('getSlugHandler')->willReturn($this->slugHandler);

		$schema = new Schema();
		$schema->setId(12);
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturn($schema);

		$this->registerMapper = $this->createMock(RegisterMapper::class);

		$this->saveObjects = (new ReflectionClass(SaveObjects::class))->newInstanceWithoutConstructor();
		foreach ([
			'saveHandler' => $saveHandler,
			'schemaMapper' => $schemaMapper,
			'registerMapper' => $this->registerMapper,
			'logger' => $this->createMock(LoggerInterface::class),
		] as $property => $value) {
			$reflection = new \ReflectionProperty(SaveObjects::class, $property);
			$reflection->setValue($this->saveObjects, $value);
		}
	}//end setUp()

	/**
	 * Run applyObjectSlugs() on rows with a caller register and schema.
	 *
	 * @param array      $rows     The prepared rows.
	 * @param mixed      $register The caller register.
	 * @param mixed      $schema   The caller schema.
	 *
	 * @return array The rows after the pass.
	 */
	private function apply(array $rows, mixed $register, mixed $schema): array {
		$method = new ReflectionMethod(SaveObjects::class, 'applyObjectSlugs');
		$method->invokeArgs($this->saveObjects, [&$rows, $register, $schema]);
		return $rows;
	}//end apply()

	public function testTheFastPathDecidesAgainstTheCallersRegisterNotTheRowsAdvisoryOne(): void {
		$callerRegister = new Register();
		$callerRegister->setId(5);
		$this->registerMapper->expects($this->never())->method('find');

		$rows = $this->apply(
			[['uuid' => self::UUID_A, 'register' => '3', 'schema' => '12', 'object' => ['title' => 'Alpha']]],
			$callerRegister,
			12
		);

		$this->assertCount(1, $this->calls);
		$this->assertSame(5, $this->calls[0]['register']->getId());
		$this->assertSame('decided', $rows[0]['slug']);
	}

	public function testAnUnresolvableRegisterLeavesTheRowsWithoutASlug(): void {
		$this->registerMapper->method('find')->willThrowException(new Exception('no such register'));

		$rows = $this->apply(
			[['uuid' => self::UUID_A, 'register' => '999', 'schema' => '12', 'slug' => 'sent-by-client', 'object' => []]],
			null,
			null
		);

		$this->assertSame([], $this->calls);
		$this->assertNull($rows[0]['slug']);
	}

	public function testATopLevelClientSlugParkedByThePreparationReachesTheDecisionAndIsRemoved(): void {
		$callerRegister = new Register();
		$callerRegister->setId(5);

		$rows = $this->apply(
			[['uuid' => self::UUID_A, 'register' => '5', 'schema' => '12', '__clientSlug' => 'integration-slug', 'object' => ['title' => 'Alpha']]],
			$callerRegister,
			12
		);

		$this->assertSame('integration-slug', $this->calls[0]['rows'][0]['clientSlug']);
		$this->assertArrayNotHasKey('__clientSlug', $rows[0]);
	}
}//end class
