<?php
/**
 * Unit tests for BagBulkLoader — the national BAG load as an upsert.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Bag
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Bag;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.
use InvalidArgumentException;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\BulkSaveOptions;
use OCA\OpenRegister\Service\Bag\BagAddressLookup;
use OCA\OpenRegister\Service\Bag\BagBulkLoader;
use OCA\OpenRegister\Service\Bag\BagExtractReader;
use OCA\OpenRegister\Service\Bag\BagExtractSource;
use OCP\AppFramework\Db\Entity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(BagBulkLoader::class)]
#[UsesClass(BagAddressLookup::class)]
#[UsesClass(BagExtractReader::class)]
#[UsesClass(BulkSaveOptions::class)]
#[UsesClass(\OCA\OpenRegister\Kernel\Contracts\Value\BulkBatchDefaults::class)]
#[UsesClass(AuditTrail::class)]
final class BagBulkLoaderTest extends TestCase {

	private const SAMPLE = __DIR__ . '/../../../fixtures/bag/nummeraanduiding-sample-10000.csv';

	/**
	 * The stored addresses the register answers, by identificatie.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $stored = [];

	/**
	 * The batches saved.
	 *
	 * @var list<array{objects: array, options: BulkSaveOptions, rbac: bool, multitenancy: bool}>
	 */
	private array $saved = [];

	/**
	 * The audit rows written.
	 *
	 * @var list<AuditTrail>
	 */
	private array $audited = [];

	/**
	 * The statistics each save answers.
	 *
	 * @var array<string, int>
	 */
	private array $statistics = [];

	private function loader(?BagExtractSource $source = null): BagBulkLoader {
		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->method('searchObjectsBySlug')->willReturnCallback(
			function (string $registerSlug, string $schemaSlug, array $filters = [], bool $_rbac = true, bool $_multitenancy = true): array {
				$this->assertSame(['bag', 'nummeraanduiding', false, false], [$registerSlug, $schemaSlug, $_rbac, $_multitenancy]);
				$this->assertSame(count($filters['identificatie']), $filters['_limit']);
				$rows = [];
				foreach ($filters['identificatie'] as $id) {
					if (isset($this->stored[$id]) === true) {
						$rows[] = $this->stored[$id];
					}
				}

				// A store that ignores the filter must not make another address count.
				$rows[] = ['@self' => ['id' => 'other-uuid'], 'identificatie' => '9999999999999999'];
				return $rows;
			}
		);
		$objects->method('saveObjects')->willReturnCallback(
			function (array $objects, BulkSaveOptions $options, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->saved[] = ['objects' => $objects, 'options' => $options, 'rbac' => $_rbac, 'multitenancy' => $_multitenancy];
				return ['statistics' => $this->statistics];
			}
		);
		$audit = $this->createMock(AuditTrailMapperInterface::class);
		$audit->method('insert')->willReturnCallback(
			function (Entity $entity): Entity {
				$this->audited[] = $entity;
				return $entity;
			}
		);

		return new BagBulkLoader(
			$objects,
			new BagExtractReader(),
			($source ?? $this->createMock(BagExtractSource::class)),
			$audit,
			$this->createMock(LoggerInterface::class)
		);
	}

	private static function row(string $id, array $fields = []): array {
		return $fields + [
			'identificatie' => $id,
			'huisnummer' => '12',
			'postcode' => '2611AB',
			'openbareRuimteNaam' => 'Lindelaan',
			'woonplaatsNaam' => 'Zuiddrecht',
			'status' => 'Naamgeving uitgegeven',
		];
	}

	private function store(string $id, array $fields = []): void {
		$this->stored[$id] = ['@self' => ['id' => 'uuid-' . $id]] + BagBulkLoader::normalise(self::row($id, $fields));
	}

	public function testAFirstLoadOfTheSampleCreatesEveryAddressInChunksAndAuditsOnce(): void {
		$counts = $this->loader()->load((new BagExtractReader())->rows(self::SAMPLE), '/srv/bag/nummeraanduiding-sample-10000.csv');

		$this->assertSame(['created' => 10000, 'updated' => 0, 'withdrawn' => 0, 'unchanged' => 0, 'invalid' => 0, 'failed' => 0], $counts);
		$this->assertSame([5000, 5000], array_map(static fn (array $batch): int => count($batch['objects']), $this->saved));
		$first = $this->saved[0];
		$this->assertSame([false, false], [$first['rbac'], $first['multitenancy']]);
		$this->assertSame(['bag', 'nummeraanduiding'], [$first['options']->defaults->register, $first['options']->defaults->schema]);
		$this->assertSame([false, false, false], [$first['options']->validation, $first['options']->events, $first['options']->audit]);
		$this->assertSame('1000AA', $first['objects'][0]['postcode']);
		$this->assertSame('A', $first['objects'][0]['huisletter']);
		$this->assertSame(1, $first['objects'][0]['huisnummer']);
		$this->assertSame(['id' => BagBulkLoader::uuidFor('0599200000000000'), 'slug' => '0599200000000000'], $first['objects'][0]['@self']);

		$this->assertCount(1, $this->audited);
		$this->assertSame('bag.load', $this->audited[0]->getAction());
		$this->assertSame($counts + ['source' => 'nummeraanduiding-sample-10000.csv'], $this->audited[0]->getChanged());
	}

	public function testARefreshWritesOnlyTheChangedAndTheWithdrawnAddress(): void {
		$this->store('0599200000000001');
		$this->store('0599200000000002');
		$this->store('0599200000000003', ['postcode' => '2611 ab']);

		$counts = $this->loader()->load(
			[
				self::row('0599200000000001'),
				self::row('0599200000000002', ['openbareRuimteNaam' => 'Beukenlaan']),
				self::row('0599200000000003', ['status' => BagAddressLookup::WITHDRAWN]),
			],
			'https://user:secret@bag.example/extract.csv?token=x'
		);

		$this->assertSame(['created' => 0, 'updated' => 1, 'withdrawn' => 1, 'unchanged' => 1, 'invalid' => 0, 'failed' => 0], $counts);
		$this->assertCount(1, $this->saved);
		$this->assertSame(['uuid-0599200000000002', 'uuid-0599200000000003'], array_column(array_column($this->saved[0]['objects'], '@self'), 'id'));
		$this->assertSame('Beukenlaan', $this->saved[0]['objects'][0]['openbareRuimteNaam']);
		$this->assertSame(BagAddressLookup::WITHDRAWN, $this->saved[0]['objects'][1]['status']);
		$this->assertSame('https://bag.example/extract.csv', $this->audited[0]->getChanged()['source']);
	}

	public function testAnAlreadyWithdrawnAddressThatChangesCountsAsUpdated(): void {
		$this->store('0599200000000001', ['status' => BagAddressLookup::WITHDRAWN]);

		$counts = $this->loader()->load([self::row('0599200000000001', ['status' => BagAddressLookup::WITHDRAWN, 'woonplaatsNaam' => 'Noordkoog'])], 'x.csv');

		$this->assertSame(1, $counts['updated']);
		$this->assertSame(0, $counts['withdrawn']);
	}

	public function testANewWithdrawnAddressCountsAsWithdrawn(): void {
		$counts = $this->loader()->load([self::row('0599200000000001', ['status' => BagAddressLookup::WITHDRAWN])], 'x.csv');

		$this->assertSame(['created' => 0, 'updated' => 0, 'withdrawn' => 1, 'unchanged' => 0, 'invalid' => 0, 'failed' => 0], $counts);
	}

	public function testRowsThatAreNoAddressAreCountedAndNeverSaved(): void {
		$counts = $this->loader()->load(
			[
				self::row('12345'),
				self::row('0599200000000001', ['postcode' => '0123AB']),
				self::row('0599200000000002', ['huisnummer' => 'twelve']),
				self::row('0599200000000003', ['huisletter' => 'AB']),
			],
			'x.csv'
		);

		$this->assertSame(4, $counts['invalid']);
		$this->assertSame([], $this->saved);
		$this->assertCount(1, $this->audited);
	}

	public function testAddressesTheSaveRefusesAreCountedAsFailed(): void {
		$this->statistics = ['invalid' => 1, 'errors' => 2];

		$counts = $this->loader()->load([self::row('0599200000000001'), self::row('0599200000000002')], 'x.csv');

		$this->assertSame(3, $counts['failed']);
		$this->assertSame(2, $counts['created']);
	}

	public function testTheLastRowForAnIdentificatieWins(): void {
		$this->loader()->load([self::row('0599200000000001'), self::row('0599200000000001', ['huisnummer' => '14'])], 'x.csv');

		$this->assertCount(1, $this->saved[0]['objects']);
		$this->assertSame(14, $this->saved[0]['objects'][0]['huisnummer']);
	}

	public function testNormaliseStoresTheLookupsFormAndDefaultsTheStatus(): void {
		$this->assertSame(
			[
				'identificatie' => '0599200000000001',
				'huisnummer' => 7,
				'huisletter' => 'B',
				'huisnummertoevoeging' => 'BIS',
				'postcode' => '2611AB',
				'status' => BagBulkLoader::ISSUED,
				'openbareRuimteNaam' => 'Lindelaan',
			],
			BagBulkLoader::normalise(['identificatie' => ' 0599200000000001 ', 'huisnummer' => ' 7', 'huisletter' => 'b', 'huisnummertoevoeging' => 'bis', 'postcode' => '2611 ab', 'openbareRuimteNaam' => ' Lindelaan ', 'woonplaatsNaam' => ''])
		);
	}

	public function testTheUuidOfAnAddressIsTheSameOnEveryLoad(): void {
		$this->assertSame(BagBulkLoader::uuidFor('0599200000000001'), BagBulkLoader::uuidFor('0599200000000001'));
		$this->assertNotSame(BagBulkLoader::uuidFor('0599200000000001'), BagBulkLoader::uuidFor('0599200000000002'));
		$this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', BagBulkLoader::uuidFor('0599200000000001'));
	}

	public function testLoadFromReadsTheLocalFileAndReleasesItEvenWhenTheReadFails(): void {
		$path   = (string) tempnam(sys_get_temp_dir(), 'bag');
		file_put_contents($path, "identificatie;huisnummer;postcode\n0599200000000001;12;2611AB\n");
		$source = $this->createMock(BagExtractSource::class);
		$source->expects($this->exactly(2))->method('localPath')->willReturnOnConsecutiveCalls($path, $path . '.missing');
		$source->expects($this->exactly(2))->method('release');

		$counts = $this->loader($source)->loadFrom('https://bag.example/extract.csv');
		$this->assertSame(1, $counts['created']);
		unlink($path);

		$this->expectException(InvalidArgumentException::class);
		$this->loader($source)->loadFrom('https://bag.example/extract.csv');
	}
}
