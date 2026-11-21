<?php

/**
 * ReportReferenceLoopSchemas test
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Repair
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

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Repair;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Repair\ReportReferenceLoopSchemas;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The step names each stored schema with a reference loop and changes nothing.
 */
class ReportReferenceLoopSchemasTest extends TestCase {

	/**
	 * A schema with these properties.
	 *
	 * @param int    $id         The id.
	 * @param string $slug       The slug.
	 * @param array  $properties The properties.
	 *
	 * @return Schema
	 */
	private function schema(int $id, string $slug, array $properties): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setSlug($slug);
		$schema->setProperties($properties);

		return $schema;
	}//end schema()

	/**
	 * A container whose schema mapper lists these schemas, or fails.
	 *
	 * @param array<int, Schema>|null $schemas The schemas, or null to fail.
	 *
	 * @return ContainerInterface
	 */
	private function container(?array $schemas): ContainerInterface {
		$mapper = new class($schemas) {
			/**
			 * @param array<int, Schema>|null $schemas The schemas, or null to fail.
			 */
			public function __construct(private readonly ?array $schemas) {
			}

			/**
			 * @param bool $_rbac         Ignored.
			 * @param bool $_multitenancy Ignored.
			 *
			 * @return array<int, Schema>
			 */
			public function findAll(bool $_rbac = true, bool $_multitenancy = true): array {
				if ($this->schemas === null) {
					throw new RuntimeException('not ready');
				}

				return $this->schemas;
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($mapper);

		return $container;
	}//end container()

	public function testEachLoopingSchemaIsNamedAndTheRestAreNot(): void {
		$looping = $this->schema(id: 7, slug: 'besluit', properties: ['a' => ['$ref' => '#/properties/a']]);
		$sound = $this->schema(id: 8, slug: 'zaak', properties: ['title' => ['type' => 'string']]);

		$warnings = [];
		$output = $this->createMock(IOutput::class);
		$output->method('warning')->willReturnCallback(function (string $message) use (&$warnings): void {
			$warnings[] = $message;
		});
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		(new ReportReferenceLoopSchemas($this->container(schemas: [$looping, $sound]), $logger))->run($output);

		$this->assertCount(1, $warnings);
		$this->assertStringContainsString('Schema "besluit" (id=7) holds a reference loop', $warnings[0]);
		$this->assertSame(['a' => ['$ref' => '#/properties/a']], $looping->getProperties());
	}//end testEachLoopingSchemaIsNamedAndTheRestAreNot()

	public function testNoLoopSaysSo(): void {
		$output = $this->createMock(IOutput::class);
		$output->expects($this->never())->method('warning');
		$output->expects($this->once())->method('info')->with('[OpenRegister] No stored schema holds a reference loop.');

		(new ReportReferenceLoopSchemas($this->container(schemas: []), $this->createMock(LoggerInterface::class)))->run($output);
	}//end testNoLoopSaysSo()

	public function testAnUnavailableMapperSkipsTheScan(): void {
		$output = $this->createMock(IOutput::class);
		$output->expects($this->never())->method('warning');
		$output->expects($this->once())->method('info')->with('[OpenRegister] Schema mapper unavailable; reference loop scan skipped.');

		(new ReportReferenceLoopSchemas($this->container(schemas: null), $this->createMock(LoggerInterface::class)))->run($output);
	}//end testAnUnavailableMapperSkipsTheScan()
}//end class
