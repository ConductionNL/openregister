<?php

/**
 * Unit tests for GrantExportWhereReadIsGranted — the upgrade default.
 *
 * A new verb that ships denied breaks every instance on the morning of the
 * upgrade. This step is what keeps that from happening while still making the
 * verb visible enough for an administrator to narrow, so the case that matters
 * most is the one where somebody has already narrowed it: that schema must be
 * left exactly as it is.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Repair
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/export-as-its-own-right/specs/authorization-rbac/spec.md
 */

declare(strict_types=1);

namespace Unit\Repair;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Repair\GrantExportWhereReadIsGranted;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class GrantExportWhereReadIsGrantedTest extends TestCase {
	/**
	 * The schemas the mapper was asked to write.
	 *
	 * @var array<int, Schema>
	 */
	private array $written = [];

	private function schema(?array $authorization): Schema {
		$schema = new Schema();
		$schema->setSlug('zaken');
		$schema->setAuthorization($authorization);

		return $schema;
	}//end schema()

	private function sweep(array $schemas): void {
		$this->written = [];

		$mapper = $this->createMock(SchemaMapper::class);
		$mapper->method('findAll')->willReturn($schemas);
		$mapper->method('update')->willReturnCallback(
			function (...$args) {
				$this->written[] = $args[0];

				return $args[0];
			}
		);

		(new GrantExportWhereReadIsGranted($mapper, new NullLogger()))
			->run($this->createMock(IOutput::class));
	}//end sweep()

	public function testAReadGrantBecomesAnExportGrantToo(): void {
		$schema = $this->schema(['read' => ['behandelaars'], 'update' => ['behandelaars']]);

		$this->sweep([$schema]);

		self::assertCount(1, $this->written);
		self::assertSame(['behandelaars'], $schema->getAuthorization()['export']);
		self::assertSame(['behandelaars'], $schema->getAuthorization()['read']);
	}//end testAReadGrantBecomesAnExportGrantToo()

	public function testANarrowedExportGrantIsLeftAlone(): void {
		// The whole point of the verb is that an administrator can take it away
		// from a reader. An upgrade that widened it back would undo that.
		$schema = $this->schema(['read' => ['behandelaars'], 'export' => ['recordmanagers']]);

		$this->sweep([$schema]);

		self::assertSame([], $this->written);
		self::assertSame(['recordmanagers'], $schema->getAuthorization()['export']);
	}//end testANarrowedExportGrantIsLeftAlone()

	public function testAnExportGrantThatIsDeliberatelyEmptyStaysEmpty(): void {
		$schema = $this->schema(['read' => ['behandelaars'], 'export' => []]);

		$this->sweep([$schema]);

		self::assertSame([], $this->written);
		self::assertSame([], $schema->getAuthorization()['export']);
	}//end testAnExportGrantThatIsDeliberatelyEmptyStaysEmpty()

	public function testASchemaWithNoBlockIsNotGivenOne(): void {
		// Nothing is narrowed there, so nothing has to be widened. Writing a
		// block onto every schema would turn a default-open schema into a
		// default-closed one, which is the opposite of what this step promises.
		$schema = $this->schema([]);

		$this->sweep([$schema]);

		self::assertSame([], $this->written);
	}//end testASchemaWithNoBlockIsNotGivenOne()

	public function testASchemaWithNoReadGrantIsLeftAlone(): void {
		$schema = $this->schema(['update' => ['behandelaars']]);

		$this->sweep([$schema]);

		self::assertSame([], $this->written);
	}//end testASchemaWithNoReadGrantIsLeftAlone()

	public function testAMapperThatCannotListSchemasDoesNotAbortTheUpgrade(): void {
		$mapper = $this->createMock(SchemaMapper::class);
		$mapper->method('findAll')->willThrowException(new \RuntimeException('database busy'));

		(new GrantExportWhereReadIsGranted($mapper, new NullLogger()))
			->run($this->createMock(IOutput::class));

		self::assertTrue(true);
	}//end testAMapperThatCannotListSchemasDoesNotAbortTheUpgrade()
}//end class
