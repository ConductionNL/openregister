<?php

/**
 * Where an app-shipped schema differs from what its app shipped, as the explainer reports it.
 *
 * Drives the real report, comparator and flattener; the baseline store and the
 * schema mapper are the seams (local-changes-to-app-shipped-configuration task 2.3).
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ShippedBaseline
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ShippedBaseline;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\ConfigurationDeployment\ShippedDivergenceSourceInterface;
use OCA\OpenRegister\Service\ShippedBaseline\DescriptorParts;
use OCA\OpenRegister\Service\ShippedBaseline\DivergenceComparator;
use OCA\OpenRegister\Service\ShippedBaseline\ShippedBaselineStore;
use OCA\OpenRegister\Service\ShippedBaseline\ShippedDivergenceReport;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;

/**
 * The divergence report.
 *
 * @covers \OCA\OpenRegister\Service\ShippedBaseline\ShippedDivergenceReport
 * @uses \OCA\OpenRegister\Service\ShippedBaseline\LiveDescriptor
 * @uses \OCA\OpenRegister\Service\ShippedBaseline\DivergenceComparator
 * @uses \OCA\OpenRegister\Service\ShippedBaseline\DescriptorParts
 * @uses \OCA\OpenRegister\Service\ShippedBaseline\GuardedAnnotations
 * @uses \OCA\OpenRegister\Db\Schema
 */
final class ShippedDivergenceReportTest extends TestCase {

	/**
	 * What the app shipped for `learner-profile`.
	 *
	 * @var array<string, mixed>
	 */
	private const SHIPPED = [
		'properties' => [
			'level' => ['type' => 'string', 'title' => 'Level'],
			'email' => ['type' => 'string', 'format' => 'email'],
		],
		'required' => ['level'],
		'authorization' => ['read' => ['hr']],
	];

	/**
	 * The live schema, with the given properties.
	 *
	 * @param array<string, mixed> $properties The live properties.
	 *
	 * @return Schema The schema.
	 */
	private function schema(array $properties): Schema {
		$schema = new Schema();
		$schema->setId(329);
		$schema->setSlug('learner-profile');
		$schema->setProperties($properties);
		$schema->setRequired(['level']);
		$schema->setAuthorization(['read' => ['hr']]);
		$schema->setConfiguration(['x-openregister-lifecycle' => ['initial' => 'draft']]);
		return $schema;
	}//end schema()

	/**
	 * The report over a stored baseline and a live schema.
	 *
	 * @param array|null  $baseline The stored baseline, or null for none.
	 * @param Schema|null $schema   The live schema, or null when it is gone.
	 *
	 * @return ShippedDivergenceReport The report.
	 */
	private function report(?array $baseline, ?Schema $schema): ShippedDivergenceReport {
		$store = $this->createMock(ShippedBaselineStore::class);
		$store->method('read')->willReturnCallback(
			static fn (string $subject): ?array => ($subject === 'schema:learner-profile' ? $baseline : null)
		);

		$schemas = $this->createMock(SchemaMapperInterface::class);
		if ($schema === null) {
			$schemas->method('find')->willThrowException(new DoesNotExistException('gone'));
		} else {
			$schemas->method('find')->willReturnCallback(
				static function (string|int $id, ...$rest) use ($schema): Schema {
					if ($id !== 'learner-profile') {
						throw new DoesNotExistException('no ' . $id);
					}

					return $schema;
				}
			);
		}

		return new ShippedDivergenceReport(schemas: $schemas, baselines: $store, comparator: new DivergenceComparator(parts: new DescriptorParts()));
	}//end report()

	/**
	 * The stored baseline: the shipped definition with its app, version and moment.
	 *
	 * @return array<string, mixed> The baseline.
	 */
	private function baseline(): array {
		return ['definition' => self::SHIPPED, 'app' => 'learniq', 'appVersion' => '1.4.0', 'recordedAt' => '2026-11-01T10:00:00+00:00'];
	}//end baseline()

	/**
	 * Two properties edited locally are both listed, with what was shipped and what runs.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md#scenario-an-administrator-sees-the-local-configuration-in-one-place
	 */
	public function testTwoLocallyEditedPropertiesAreListed(): void {
		$live = self::SHIPPED['properties'];
		$live['level']['title'] = 'Niveau';
		$live['email']['format'] = 'idn-email';

		$report = $this->report(baseline: $this->baseline(), schema: $this->schema(properties: $live))->forSubject(subject: 'schema:learner-profile');

		$this->assertTrue($report['shipped']);
		$this->assertTrue($report['found']);
		$this->assertSame(['learniq', '1.4.0', '2026-11-01T10:00:00+00:00'], [$report['app'], $report['appVersion'], $report['recordedAt']]);
		$this->assertCount(2, $report['divergences']);
		foreach ($report['divergences'] as $divergence) {
			$this->assertSame(DivergenceComparator::LOCAL, $divergence['state']);
		}

		$byPath = array_column($report['divergences'], null, 'path');
		$this->assertSame(['Level', 'Niveau'], [$byPath['properties.level.title']['shipped'], $byPath['properties.level.title']['live']]);
		$this->assertSame(['email', 'idn-email'], [$byPath['properties.email.format']['shipped'], $byPath['properties.email.format']['live']]);
	}//end testTwoLocallyEditedPropertiesAreListed()

	/**
	 * An untouched schema reports nothing, and a part the baseline does not hold (annotations) is not compared.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md#scenario-an-untouched-instance-reports-nothing
	 */
	public function testAnUntouchedSchemaReportsNothing(): void {
		$report = $this->report(baseline: $this->baseline(), schema: $this->schema(properties: self::SHIPPED['properties']))
			->forSubject(subject: 'schema:learner-profile');

		$this->assertTrue($report['shipped']);
		$this->assertSame([], $report['divergences']);
	}//end testAnUntouchedSchemaReportsNothing()

	/**
	 * A schema no app shipped, a shipped schema that is gone, and a subject that is not a schema.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
	 */
	public function testNoBaselineGoneSchemaAndOtherSubjects(): void {
		$none = $this->report(baseline: null, schema: $this->schema(properties: []))->forSubject(subject: 'schema:learner-profile');
		$this->assertSame(
			['schema' => 'learner-profile', 'shipped' => false, 'found' => false, 'app' => null, 'appVersion' => null, 'recordedAt' => null, 'divergences' => []],
			$none
		);

		$gone = $this->report(baseline: $this->baseline(), schema: null)->forSubject(subject: 'schema:learner-profile');
		$this->assertTrue($gone['shipped']);
		$this->assertFalse($gone['found']);
		$this->assertSame([], $gone['divergences']);

		$this->assertNull($this->report(baseline: $this->baseline(), schema: null)->forSubject(subject: 'register:zaken'));
		$this->assertSame(ShippedBaselineStore::KEY_SCHEMA, ShippedDivergenceSourceInterface::BASELINE_KEY);
	}//end testNoBaselineGoneSchemaAndOtherSubjects()
}//end class
