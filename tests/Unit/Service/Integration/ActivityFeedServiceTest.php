<?php

/**
 * The merged feed as it is actually assembled: what is fetched, and what is
 * handed in.
 *
 * 🔴 A SOURCE THAT COULD NOT BE READ IS NAMED, NOT MERGED AS NOTHING. An empty
 * audit list and an unreadable one render identically, and only one of them
 * means the object has no history. The `degraded` list is the difference, and
 * a feed that swallowed the failure would tell a reader an object was never
 * touched on exactly the day the trail was unavailable.
 *
 * 🔴 THE SUMMARY NAMES FIELDS, NEVER VALUES. An audit entry carries what
 * changed; printing the values would put the contents of a protected field
 * into a feed read by everyone who can read the object, which on a schema
 * that configures no authorization is everyone.
 *
 * 🔴 READS ARE FETCHED AND FILTERED LATER, so the toggle can bring them back
 * without a second, differently shaped query. The test asserts the query is
 * NOT narrowed on the action.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/activity-leaf/specs/integration-activity/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use DateTime;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Service\Audit\ObjectHistoryForReaders;
use OCA\OpenRegister\Service\Integration\ActivityFeedMerge;
use OCA\OpenRegister\Service\Integration\ActivityFeedObject;
use OCA\OpenRegister\Service\Integration\ActivityFeedService;
use OCA\OpenRegister\Service\Integration\Providers\ActivityProvider;
use OCA\OpenRegister\Tests\Support\BuildsReaderHistory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The feed assembly.
 *
 * @spec openspec/changes/activity-leaf/specs/integration-activity/spec.md
 */
class ActivityFeedServiceTest extends TestCase {
	use BuildsReaderHistory;

	/**
	 * One audit entry.
	 *
	 * @param string      $uuid    Its uuid.
	 * @param string      $action  Its action.
	 * @param int         $at      Its moment.
	 * @param array       $changed What it changed.
	 *
	 * @return AuditTrail The entry.
	 */
	private function entry(string $uuid, string $action, int $at, array $changed = []): AuditTrail {
		$entry = new AuditTrail();
		$entry->setUuid($uuid);
		$entry->setAction($action);
		$entry->setUserName('alice');
		$entry->setChanged($changed);
		$entry->setCreated((new DateTime())->setTimestamp($at));

		return $entry;
	}//end entry()

	/**
	 * The service over a trail and a provider we dictate.
	 *
	 * `onlyMethods` so a double cannot invent a method the real class lacks:
	 * a feed that passed against an imagined `findAllForObject()` would 500
	 * the first time it ran.
	 *
	 * @param array                 $entries What the object's history answers.
	 * @param array                 $rows    What the Activity provider answers.
	 * @param array|null            $capture Filled with what the history was asked for.
	 *
	 * @return ActivityFeedService The service.
	 */
	private function service(array $entries, array $rows = [], ?array &$capture = null): ActivityFeedService {
		$history = $this->getMockBuilder(ObjectHistoryForReaders::class)
			->disableOriginalConstructor()
			->onlyMethods(['page'])
			->getMock();
		$history->method('page')->willReturnCallback(
			static function (string $register, string $schema, string $id, array $params) use ($entries, &$capture): array {
				$capture = ['register' => $register, 'schema' => $schema, 'id' => $id, 'params' => $params];

				return [
					'results' => array_map(static fn (AuditTrail $entry): array => $entry->jsonSerialize(), $entries),
					'total' => count($entries),
				];
			}
		);

		return $this->serviceOver(history: $history, rows: $rows);
	}//end service()

	/**
	 * The service over a given history and the Activity rows.
	 *
	 * @param ObjectHistoryForReaders $history The object's history.
	 * @param array                   $rows    What the Activity provider answers.
	 *
	 * @return ActivityFeedService The service.
	 */
	private function serviceOver(ObjectHistoryForReaders $history, array $rows = []): ActivityFeedService {

		$provider = $this->getMockBuilder(ActivityProvider::class)
			->disableOriginalConstructor()
			->onlyMethods(['list'])
			->getMock();
		$provider->method('list')->willReturn($rows);

		return new ActivityFeedService(
			new ActivityFeedMerge(),
			$history,
			$provider,
			$this->createMock(LoggerInterface::class),
		);
	}//end serviceOver()

	public function testTheTrailAndTheActivityRowsMergeIntoOneOrder(): void {
		$service = $this->service(
			[$this->entry('a1', 'update', 100)],
			[['id' => 'act1', 'timestamp' => 300, 'affecteduser' => 'bob', 'subject' => 'gedeeld']],
		);

		$page = $service->page(new ActivityFeedObject('dossiq', 'case', 'obj-1'));

		$this->assertSame(['activity', 'audit'], array_column($page['rows'], 'kind'));
		$this->assertSame([], $page['degraded']);
	}//end testTheTrailAndTheActivityRowsMergeIntoOneOrder()

	public function testRowsHandedInByTheCallerAreMergedBeside(): void {
		$service = $this->service([$this->entry('a1', 'update', 100)]);

		$page = $service->page(new ActivityFeedObject('dossiq', 'case', 'obj-1'), new \OCA\OpenRegister\Service\Integration\ActivityFeedOptions(handedIn: [
			'note' => [['id' => 'n1', 'timestamp' => 400, 'summary' => 'gebeld']],
			'file' => [['id' => 'f1', 'timestamp' => 200, 'summary' => 'gevel.jpg']],
		]));

		$this->assertSame(['note', 'file', 'audit'], array_column($page['rows'], 'kind'));
	}//end testRowsHandedInByTheCallerAreMergedBeside()

	public function testAnUnreadableSourceIsNamedRatherThanMergedAsNothing(): void {
		$history = $this->getMockBuilder(ObjectHistoryForReaders::class)
			->disableOriginalConstructor()
			->onlyMethods(['page'])
			->getMock();
		$history->method('page')->willThrowException(new RuntimeException('the trail is unavailable'));

		$service = $this->serviceOver(history: $history);

		$page = $service->page(new ActivityFeedObject('dossiq', 'case', 'obj-1'));

		// Empty AND degraded: an object with no history and an object whose
		// history could not be read must not look the same.
		$this->assertSame([], $page['rows']);
		$this->assertSame(['audit'], $page['degraded']);
	}//end testAnUnreadableSourceIsNamedRatherThanMergedAsNothing()

	public function testTheTrailIsAskedForThisObjectBoundedAndNewestFirst(): void {
		$capture = null;
		$service = $this->service([$this->entry('a1', 'update', 100)], [], $capture);

		$service->page(new ActivityFeedObject('dossiq', 'case', 'obj-1'), new \OCA\OpenRegister\Service\Integration\ActivityFeedOptions(criteria: ['pageSize' => 10]));

		$this->assertSame(['register' => 'dossiq', 'schema' => 'case', 'id' => 'obj-1', 'params' => ['limit' => 10]], $capture);
		// NOT narrowed on the action: the reads toggle has to be able to bring
		// them back without a second, differently shaped query.
		$this->assertArrayNotHasKey('filters', $capture['params']);
	}//end testTheTrailIsAskedForThisObjectBoundedAndNewestFirst()

	public function testReadsAreStillHiddenByDefaultOnceMerged(): void {
		$service = $this->service([
			$this->entry('r1', 'read', 300),
			$this->entry('w1', 'update', 100),
		]);

		$this->assertSame(['w1'], array_column($service->page(new ActivityFeedObject('dossiq', 'case', 'obj-1'))['rows'], 'id'));
		$this->assertSame(
			['r1', 'w1'],
			array_column($service->page(new ActivityFeedObject('dossiq', 'case', 'obj-1'), new \OCA\OpenRegister\Service\Integration\ActivityFeedOptions(criteria: ['includeReads' => true]))['rows'], 'id')
		);
	}//end testReadsAreStillHiddenByDefaultOnceMerged()

	public function testTheSummaryNamesTheFieldsAndNeverTheirValues(): void {
		$service = $this->service([
			$this->entry('a1', 'update', 100, ['bsn' => ['old' => '123456782', 'new' => '987654321']]),
		]);

		$summary = $service->page(new ActivityFeedObject('dossiq', 'case', 'obj-1'))['rows'][0]['summary'];

		$this->assertStringContainsString('bsn', $summary);
		// The value of a protected field must not travel into a feed that
		// everyone who can read the object can read.
		$this->assertStringNotContainsString('123456782', $summary);
		$this->assertStringNotContainsString('987654321', $summary);
	}//end testTheSummaryNamesTheFieldsAndNeverTheirValues()

	public function testTheSummaryNamesOnlyThePropertiesTheCallerMaySee(): void {
		$service = $this->serviceOver(
			history: $this->readerHistory(rows: [$this->readerEntry(objectUuid: self::$readableUuid)])
		);

		$rows = $service->page(new ActivityFeedObject('5', '7', self::$readableUuid))['rows'];

		$this->assertSame('update: titel', $rows[0]['summary']);
		$this->assertSame('Alice', $rows[0]['actor']);
		$this->assertSame((new DateTime('2026-11-03T10:00:00+00:00'))->getTimestamp(), $rows[0]['timestamp']);
	}//end testTheSummaryNamesOnlyThePropertiesTheCallerMaySee()

	public function testACallerWhoMayNotReadTheObjectGetsNoAuditRows(): void {
		$service = $this->serviceOver(
			history: $this->readerHistory(
				rows: [$this->readerEntry(objectUuid: self::$readableUuid)],
				world: ['readable' => false]
			)
		);

		$page = $service->page(new ActivityFeedObject('5', '7', self::$readableUuid));

		$this->assertSame([], $page['rows']);
		$this->assertSame([], $page['degraded']);
	}//end testACallerWhoMayNotReadTheObjectGetsNoAuditRows()

	public function testARefusedPurposeNamesTheTrailAsUnreadable(): void {
		$service = $this->serviceOver(
			history: $this->readerHistory(
				rows: [$this->readerEntry(objectUuid: self::$readableUuid)],
				world: ['purposeRefused' => true]
			)
		);

		$page = $service->page(new ActivityFeedObject('5', '7', self::$readableUuid));

		$this->assertSame([], $page['rows']);
		$this->assertSame(['audit'], $page['degraded']);
	}//end testARefusedPurposeNamesTheTrailAsUnreadable()

	public function testAnEntryWithNoChangesStillSaysWhatItDid(): void {
		$service = $this->service([$this->entry('a1', 'create', 100)]);

		$this->assertSame('create', $service->page(new ActivityFeedObject('dossiq', 'case', 'obj-1'))['rows'][0]['summary']);
	}//end testAnEntryWithNoChangesStillSaysWhatItDid()
}//end class
