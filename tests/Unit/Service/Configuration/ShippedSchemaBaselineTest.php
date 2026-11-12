<?php

/**
 * The shipped baseline of a schema an app import updates or creates: no
 * guard, no app or no slug leaves the data alone; a guarded upgrade merges
 * the guard's definition and reports the parts kept local; and what ships is
 * recorded as the new baseline.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Configuration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Configuration\ImportedSchemaUpdate;
use OCA\OpenRegister\Service\Configuration\PendingSchemaBaseline;
use OCA\OpenRegister\Service\Configuration\SchemaImportOptions;
use OCA\OpenRegister\Service\Configuration\ShippedSchemaBaseline;
use OCA\OpenRegister\Service\ShippedBaseline\SchemaUpgrade;
use OCA\OpenRegister\Service\ShippedBaseline\SchemaUpgradeOptions;
use OCA\OpenRegister\Service\ShippedBaseline\ShippedConfigurationGuard;
use OCA\OpenRegister\Service\ShippedBaseline\ShippedDefinition;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * ShippedSchemaBaseline.
 *
 * @covers \OCA\OpenRegister\Service\Configuration\ShippedSchemaBaseline
 * @uses \OCA\OpenRegister\Service\Configuration\ImportedSchemaUpdate
 * @uses \OCA\OpenRegister\Service\Configuration\PendingSchemaBaseline
 * @uses \OCA\OpenRegister\Service\Configuration\SchemaImportOptions
 * @uses \OCA\OpenRegister\Service\ShippedBaseline\SchemaUpgrade
 * @uses \OCA\OpenRegister\Service\ShippedBaseline\SchemaUpgradeOptions
 * @uses \OCA\OpenRegister\Service\ShippedBaseline\ShippedDefinition
 * @uses \OCA\OpenRegister\Db\Schema
 */
class ShippedSchemaBaselineTest extends TestCase {

	private ShippedConfigurationGuard&MockObject $guard;

	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		$this->guard  = $this->createMock(ShippedConfigurationGuard::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	private function existing(): Schema {
		$schema = new Schema();
		$schema->setSlug('case');
		$schema->setProperties(['title' => ['type' => 'string'], 'local' => ['type' => 'string']]);
		$schema->setRequired(['title']);

		return $schema;
	}//end existing()

	private function app(): SchemaImportOptions {
		return new SchemaImportOptions(appId: 'dossiq', version: '2.1.0');
	}//end app()

	public function testWithoutAnAppTheDataIsLeftAloneAndNothingIsGuarded(): void {
		$this->guard->expects($this->never())->method('guardUpgrade');
		$baseline = new ShippedSchemaBaseline($this->guard, $this->logger);
		$data     = ['slug' => 'case', 'properties' => []];

		$pending = $baseline->resolve(new ImportedSchemaUpdate($data, $this->existing()), new SchemaImportOptions());

		$this->assertSame($data, $pending->data);
		$this->assertNull($pending->baseline);
		$this->assertSame('', $pending->slug);
	}//end testWithoutAnAppTheDataIsLeftAloneAndNothingIsGuarded()

	public function testWithoutAGuardTheDataIsLeftAlone(): void {
		$baseline = new ShippedSchemaBaseline(null, $this->logger);

		$pending = $baseline->resolve(new ImportedSchemaUpdate(['properties' => []], $this->existing()), $this->app());

		$this->assertNull($pending->baseline);
		$this->assertSame(['properties' => []], $pending->data);
	}//end testWithoutAGuardTheDataIsLeftAlone()

	public function testTheGuardIsAskedAboutTheLiveAndShippedPartsWithoutRecording(): void {
		$this->guard->expects($this->once())->method('guardUpgrade')->with(
			$this->callback(
				function (SchemaUpgrade $upgrade): bool {
					$this->assertSame('case', $upgrade->slug);
					$this->assertSame(
						[
							'properties' => ['title' => ['type' => 'string'], 'local' => ['type' => 'string']],
							'required' => ['title'],
							'authorization' => [],
						],
						$upgrade->live
					);
					$this->assertSame(['properties' => ['title' => ['type' => 'text']], 'required' => []], $upgrade->incoming->definition);
					$this->assertSame('dossiq', $upgrade->incoming->app);
					$this->assertSame('2.1.0', $upgrade->incoming->appVersion);
					return true;
				}
			),
			$this->callback(static fn (SchemaUpgradeOptions $options): bool => $options->record === false)
		)->willReturn(['guarded' => false, 'baseline' => ['properties' => ['title' => ['type' => 'text']]], 'conflicts' => []]);
		$this->logger->expects($this->never())->method('warning');

		$data    = ['title' => 'Case', 'properties' => ['title' => ['type' => 'text']], 'required' => []];
		$pending = (new ShippedSchemaBaseline($this->guard, $this->logger))->resolve(new ImportedSchemaUpdate($data, $this->existing()), $this->app());

		$this->assertSame($data, $pending->data);
		$this->assertSame(['properties' => ['title' => ['type' => 'text']]], $pending->baseline);
		$this->assertSame('case', $pending->slug);
	}//end testTheGuardIsAskedAboutTheLiveAndShippedPartsWithoutRecording()

	public function testAGuardedUpgradeTakesTheMergedPartsAndReportsWhatWasKeptLocal(): void {
		$this->guard->method('guardUpgrade')->willReturn(
			[
				'guarded' => true,
				'baseline' => ['properties' => ['title' => ['type' => 'text']]],
				'definition' => ['properties' => ['title' => ['type' => 'string']], 'description' => 'not a guarded part'],
				'conflicts' => [['path' => 'properties.title.type'], ['path' => 'required']],
			]
		);
		$this->logger->expects($this->once())->method('warning')->with(
			'[ImportHandler] schema kept its local definition for parts the app also changed',
			$this->callback(
				static fn (array $context): bool => $context['schema_slug'] === 'renamed'
					&& $context['conflicts'] === ['properties.title.type', 'required']
			)
		);
		$baseline = new ShippedSchemaBaseline($this->guard, $this->logger);

		$data    = ['slug' => 'renamed', 'description' => 'incoming', 'properties' => ['title' => ['type' => 'text']]];
		$pending = $baseline->resolve(new ImportedSchemaUpdate($data, $this->existing()), $this->app());

		$this->assertSame(['slug' => 'renamed', 'description' => 'incoming', 'properties' => ['title' => ['type' => 'string']]], $pending->data);
		$this->assertSame('renamed', $pending->slug);
		$this->assertSame([['slug' => 'renamed', 'paths' => ['properties.title.type', 'required']]], $baseline->takeKeptLocal());
		$this->assertSame([], $baseline->takeKeptLocal());
	}//end testAGuardedUpgradeTakesTheMergedPartsAndReportsWhatWasKeptLocal()

	public function testAGuardedUpgradeWithoutConflictsReportsNothing(): void {
		$this->guard->method('guardUpgrade')->willReturn(['guarded' => true, 'baseline' => [], 'definition' => ['required' => ['x']], 'conflicts' => []]);
		$this->logger->expects($this->never())->method('warning');
		$baseline = new ShippedSchemaBaseline($this->guard, $this->logger);

		$pending = $baseline->resolve(new ImportedSchemaUpdate(['required' => []], $this->existing()), $this->app());

		$this->assertSame(['required' => ['x']], $pending->data);
		$this->assertSame([], $baseline->takeKeptLocal());
	}//end testAGuardedUpgradeWithoutConflictsReportsNothing()

	public function testAPendingBaselineIsRecordedForTheApp(): void {
		$this->guard->expects($this->once())->method('recordShippedSchema')->with(
			'case',
			$this->callback(
				static fn (ShippedDefinition $shipped): bool => $shipped->definition === ['required' => []]
					&& $shipped->app === 'dossiq' && $shipped->appVersion === ''
			)
		);

		(new ShippedSchemaBaseline($this->guard, $this->logger))->recordPending(
			new PendingSchemaBaseline(data: [], baseline: ['required' => []], slug: 'case'),
			new SchemaImportOptions(appId: 'dossiq')
		);
	}//end testAPendingBaselineIsRecordedForTheApp()

	public function testNoBaselineRecordsNothing(): void {
		$this->guard->expects($this->never())->method('recordShippedSchema');

		(new ShippedSchemaBaseline($this->guard, $this->logger))->recordPending(new PendingSchemaBaseline([], null, 'case'), $this->app());
	}//end testNoBaselineRecordsNothing()

	public function testACreatedSchemaRecordsOnlyItsGuardedParts(): void {
		$this->guard->expects($this->once())->method('recordShippedSchema')->with(
			'case',
			$this->callback(
				static fn (ShippedDefinition $shipped): bool => $shipped->definition === ['properties' => ['a' => []], 'authorization' => ['read' => []]]
					&& $shipped->appVersion === '2.1.0'
			)
		);

		(new ShippedSchemaBaseline($this->guard, $this->logger))->recordCreated(
			['slug' => 'case', 'title' => 'Case', 'properties' => ['a' => []], 'authorization' => ['read' => []]],
			$this->app()
		);
	}//end testACreatedSchemaRecordsOnlyItsGuardedParts()

	public function testACreatedSchemaWithoutSlugOrAppRecordsNothing(): void {
		$this->guard->expects($this->never())->method('recordShippedSchema');
		$baseline = new ShippedSchemaBaseline($this->guard, $this->logger);

		$baseline->recordCreated(['properties' => []], $this->app());
		$baseline->recordCreated(['slug' => 'case'], new SchemaImportOptions());
	}//end testACreatedSchemaWithoutSlugOrAppRecordsNothing()
}//end class
