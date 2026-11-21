<?php

/**
 * Golden test pinning every branch of DeletionWindowService::windowFor().
 *
 * Written on the code before windowFor() became a facade over a query
 * object (ADR-011). Every instant is an explicit UTC timestamp and `now` is
 * always injected, so nothing here depends on the clock or the timezone.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Deletion
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.openregister.app
 */

declare(strict_types=1);

namespace Unit\Service\Deletion;

use DateTimeImmutable;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Deletion\DeletionWindow;
use OCA\OpenRegister\Service\Deletion\DeletionWindowService;
use OCA\OpenRegister\Service\Settings\ObjectRetentionHandler;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use OCA\OpenRegister\Service\Deletion\DeletionWindowQuery;

final class DeletionWindowBranchesGoldenTest extends TestCase {

	private const NOW = '2026-03-20T09:00:00+00:00';

	private function service(): DeletionWindowService {
		$handler = $this->createMock(ObjectRetentionHandler::class);
		// 10 days, in milliseconds.
		$handler->method('getRetentionSettingsOnly')->willReturn(['objectDeleteRetention' => 864000000]);

		return new DeletionWindowService($handler, new NullLogger());
	}//end service()

	private function schemaWithRetention(int $days): Schema {
		$schema = new Schema();
		$schema->setArchive(['deleteRetention' => $days]);

		return $schema;
	}//end schemaWithRetention()

	private function window(array $metadata, ?Schema $schema=null): ?DeletionWindow {
		$object = new ObjectEntity();
		$object->setUuid('o-1');
		$object->setDeleted($metadata);

		return $this->service()->windowOf(new DeletionWindowQuery($object, $schema, new DateTimeImmutable(self::NOW)));
	}//end window()

	public function testAFullyRecordedWindowIsReadAsStored(): void {
		$window = $this->window(
			[
				'deletedAt' => '2026-03-19T09:00:00+00:00',
				'retentionPeriod' => 7,
				'retentionSource' => DeletionWindow::SOURCE_SCHEMA,
				'destroyableFrom' => '2026-03-26T09:00:00+00:00',
			],
			$this->schemaWithRetention(90)
		);
		self::assertNotNull($window);
		self::assertSame(
			[
				'deletedAt' => '2026-03-19T09:00:00+00:00',
				'destroyableFrom' => '2026-03-26T09:00:00+00:00',
				'daysRemaining' => 6,
				'retentionDays' => 7,
				'retentionSource' => DeletionWindow::SOURCE_SCHEMA,
				'lapsed' => false,
			],
			$window->toArray()
		);
		self::assertSame(7, $window->retentionDays());
		self::assertFalse($window->hasLapsed());
	}//end testAFullyRecordedWindowIsReadAsStored()

	public function testARecordedPeriodWithoutASourceKeepsThePeriodAndResolvesTheSource(): void {
		$window = $this->window(
			['deletedAt' => '2026-03-19T09:00:00+00:00', 'retentionPeriod' => 3],
			$this->schemaWithRetention(90)
		);
		self::assertNotNull($window);
		self::assertSame(3, $window->retentionDays());
		self::assertSame(DeletionWindow::SOURCE_SCHEMA, $window->source());
		self::assertSame('2026-03-22T09:00:00+00:00', $window->destroyableFrom()->format(DATE_ATOM));
		self::assertSame(2, $window->daysRemaining());
	}//end testARecordedPeriodWithoutASourceKeepsThePeriodAndResolvesTheSource()

	public function testARecordedSourceWithoutAPeriodResolvesBoth(): void {
		$window = $this->window(
			['deletedAt' => '2026-03-19T09:00:00+00:00', 'retentionSource' => 'schema', 'retentionPeriod' => 0]
		);
		self::assertNotNull($window);
		self::assertSame(10, $window->retentionDays());
		self::assertSame(DeletionWindow::SOURCE_INSTANCE, $window->source());
		self::assertSame('2026-03-29T09:00:00+00:00', $window->destroyableFrom()->format(DATE_ATOM));
	}//end testARecordedSourceWithoutAPeriodResolvesBoth()

	public function testLegacyKeysAreReadWhenTheCurrentOnesAreAbsent(): void {
		$window = $this->window(
			[
				'deleted' => '2026-03-01T09:00:00+00:00',
				'retentionPeriod' => 30,
				'retentionSource' => 'instance',
				'purgeDate' => '2026-03-31T09:00:00+00:00',
			]
		);
		self::assertNotNull($window);
		self::assertSame('2026-03-01T09:00:00+00:00', $window->toArray()['deletedAt']);
		self::assertSame('2026-03-31T09:00:00+00:00', $window->destroyableFrom()->format(DATE_ATOM));
		self::assertSame(11, $window->daysRemaining());
	}//end testLegacyKeysAreReadWhenTheCurrentOnesAreAbsent()

	public function testUnreadableDatesFallBackToNowAndThePeriod(): void {
		$window = $this->window(
			['deletedAt' => 'not a date', 'retentionPeriod' => 5, 'retentionSource' => 'instance', 'destroyableFrom' => 42]
		);
		self::assertNotNull($window);
		self::assertSame(self::NOW, $window->toArray()['deletedAt']);
		self::assertSame('2026-03-25T09:00:00+00:00', $window->destroyableFrom()->format(DATE_ATOM));
		self::assertSame(5, $window->daysRemaining());
	}//end testUnreadableDatesFallBackToNowAndThePeriod()

	public function testALapsedWindowAllowsDestructionAndAnOpenOneDoesNot(): void {
		$lapsed = $this->window(
			['deletedAt' => '2026-01-01T09:00:00+00:00', 'retentionPeriod' => 1, 'retentionSource' => 'instance']
		);
		self::assertNotNull($lapsed);
		self::assertTrue($lapsed->hasLapsed());
		self::assertSame(0, $lapsed->daysRemaining());

		// Mutation check: one more day of retention on a window closing now keeps it open.
		$open = $this->window(
			['deletedAt' => '2026-03-19T09:00:00+00:00', 'retentionPeriod' => 2, 'retentionSource' => 'instance']
		);
		self::assertNotNull($open);
		self::assertFalse($open->hasLapsed());
		self::assertSame(1, $open->daysRemaining());
	}//end testALapsedWindowAllowsDestructionAndAnOpenOneDoesNot()
}//end class
