<?php

/**
 * LogDanglingLinkedTypes repair-step unit test.
 *
 * The step's whole product is a log line naming WHICH schema declares an
 * unregistered linkedType. That naming went through safeStringAccessor(), which
 * probed each candidate accessor with method_exists() — and Db\Schema serves
 * getSlug()/getUuid() through Entity::__call() as `@method` docblocks, while
 * getId() is a `@method` on Entity itself. So every candidate was rejected,
 * scan() logged `slug: 'unknown', id: ''` for 100% of rows, and the report could
 * not be acted on. These tests assert the identity actually reaches the log.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Repair;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Repair\LogDanglingLinkedTypes;
use OCA\OpenRegister\Service\Integration\IntegrationRegistry;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for the dangling-linkedType repair step.
 *
 * @covers \OCA\OpenRegister\Repair\LogDanglingLinkedTypes
 */
class LogDanglingLinkedTypesTest extends TestCase {

	/**
	 * Integration registry supplying the set of registered leaf ids.
	 *
	 * @var IntegrationRegistry&MockObject
	 */
	private IntegrationRegistry&MockObject $registry;

	/**
	 * Container the step lazily resolves SchemaMapper from.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Logger the warnings land in.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Build the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->registry = $this->createMock(IntegrationRegistry::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * Build the step over a mapper returning the given schemas.
	 *
	 * @param array<int, mixed> $schemas Schema entities findAll() should return.
	 *
	 * @return LogDanglingLinkedTypes
	 */
	private function stepReturning(array $schemas): LogDanglingLinkedTypes {
		$mapper = new class($schemas) {

			/**
			 * @param array<int, mixed> $schemas Rows to hand back.
			 */
			public function __construct(
				private array $schemas,
			) {
			}

			/**
			 * @return array<int, mixed>
			 */
			public function findAll(): array {
				return $this->schemas;
			}
		};

		$this->container->method('get')->willReturn($mapper);

		return new LogDanglingLinkedTypes(
			registry: $this->registry,
			container: $this->container,
			logger: $this->logger
		);
	}//end stepReturning()

	/**
	 * A REAL Db\Schema — the collaborator's own shape, magic accessors and all.
	 *
	 * @param string $slug The schema slug.
	 * @param int $id The schema primary key.
	 * @param array<int, string> $linkedTypes Declared linked types.
	 *
	 * @return Schema
	 */
	private function schema(string $slug, int $id, array $linkedTypes): Schema {
		// fromRow() is how SchemaMapper actually hydrates these rows, and it is
		// the only faithful fixture here: Schema::setConfiguration() validates
		// linkedTypes against the live IntegrationRegistry and DROPS the key when
		// an id is unknown, so building the fixture through the API-save path
		// would silently produce a schema with no dangling type at all — the
		// scenario this repair step exists to report could not be represented.
		return Schema::fromRow(
			[
				'id' => $id,
				'slug' => $slug,
				'configuration' => ['linkedTypes' => $linkedTypes],
			]
		);
	}//end schema()

	/**
	 * Collect every warning the step emits on the output handle.
	 *
	 * @param LogDanglingLinkedTypes $step The step to run.
	 *
	 * @return array<int, string>
	 */
	private function runCollectingWarnings(LogDanglingLinkedTypes $step): array {
		$warnings = [];

		$output = $this->createMock(IOutput::class);
		$output->method('warning')->willReturnCallback(
			function (string $message) use (&$warnings): void {
				$warnings[] = $message;
			}
		);

		$step->run($output);

		return $warnings;
	}//end runCollectingWarnings()

	/**
	 * The dangling type is reported against the schema that declares it, by slug
	 * and by id — not as `Schema "unknown" (id=)`.
	 *
	 * @return void
	 */
	public function testDanglingTypeIsReportedWithTheSchemaSlugAndId(): void {
		$this->registry->method('listIds')->willReturn(['files']);

		$step = $this->stepReturning([$this->schema('contactmoment', 77, ['files', 'nowhere'])]);
		$warnings = $this->runCollectingWarnings($step);

		$this->assertCount(1, $warnings);
		$this->assertStringContainsString('"contactmoment"', $warnings[0]);
		$this->assertStringContainsString('id=77', $warnings[0]);
		$this->assertStringContainsString('"nowhere"', $warnings[0]);
		$this->assertStringNotContainsString('unknown', $warnings[0]);
	}//end testDanglingTypeIsReportedWithTheSchemaSlugAndId()

	/**
	 * Fail-closed control: a schema whose linkedTypes are all registered must
	 * produce no warning at all, so the test above cannot pass by the step
	 * simply warning about everything.
	 *
	 * @return void
	 */
	public function testNoWarningWhenEveryLinkedTypeIsRegistered(): void {
		$this->registry->method('listIds')->willReturn(['files', 'nowhere']);

		$step = $this->stepReturning([$this->schema('contactmoment', 77, ['files', 'nowhere'])]);

		$this->assertSame([], $this->runCollectingWarnings($step));
	}//end testNoWarningWhenEveryLinkedTypeIsRegistered()

	/**
	 * A schema with no slug still reports its id, and only then falls back to
	 * 'unknown' for the name half. This pins that the two accessor lists are
	 * resolved independently rather than both collapsing together.
	 *
	 * @return void
	 */
	public function testSchemaWithoutASlugStillReportsItsId(): void {
		$this->registry->method('listIds')->willReturn([]);

		$schema = Schema::fromRow(['id' => 91, 'configuration' => ['linkedTypes' => ['nowhere']]]);

		$warnings = $this->runCollectingWarnings($this->stepReturning([$schema]));

		$this->assertCount(1, $warnings);
		$this->assertStringContainsString('id=91', $warnings[0]);
		$this->assertStringContainsString('"unknown"', $warnings[0]);
	}//end testSchemaWithoutASlugStillReportsItsId()

	/**
	 * A row that is not an Entity at all must not fatal — the accessor probe has
	 * to stay a membership test, not the unconditionally-true answer
	 * is_callable() gives on any __call class.
	 *
	 * @return void
	 */
	public function testNonEntityRowsAreSkippedWithoutFatal(): void {
		$this->registry->method('listIds')->willReturn([]);

		$warnings = $this->runCollectingWarnings($this->stepReturning([new \stdClass(), 'not-an-object']));

		$this->assertSame([], $warnings);
	}//end testNonEntityRowsAreSkippedWithoutFatal()
}//end class
