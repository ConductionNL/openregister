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
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Service\Integration\ActivityFeedMerge;
use OCA\OpenRegister\Service\Integration\ActivityFeedService;
use OCA\OpenRegister\Service\Integration\Providers\ActivityProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The feed assembly.
 *
 * @spec openspec/changes/activity-leaf/specs/integration-activity/spec.md
 */
class ActivityFeedServiceTest extends TestCase {

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
	 * @param array                 $entries What the trail answers.
	 * @param array                 $rows    What the Activity provider answers.
	 * @param array|null            $capture Filled with the filters the trail was asked for.
	 *
	 * @return ActivityFeedService The service.
	 */
	private function service(array $entries, array $rows = [], ?array &$capture = null): ActivityFeedService {
		$audit = $this->getMockBuilder(AuditTrailMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['findAll'])
			->getMock();
		$audit->method('findAll')->willReturnCallback(
			static function (?int $limit = null, ?int $offset = null, ?array $filters = [], ?array $sort = null, ?string $search = null) use ($entries, &$capture): array {
				$capture = ['limit' => $limit, 'filters' => $filters, 'sort' => $sort];

				return $entries;
			}
		);

		$provider = $this->getMockBuilder(ActivityProvider::class)
			->disableOriginalConstructor()
			->onlyMethods(['list'])
			->getMock();
		$provider->method('list')->willReturn($rows);

		return new ActivityFeedService(
			new ActivityFeedMerge(),
			$audit,
			$provider,
			$this->createMock(LoggerInterface::class),
		);
	}//end service()

	public function testTheTrailAndTheActivityRowsMergeIntoOneOrder(): void {
		$service = $this->service(
			[$this->entry('a1', 'update', 100)],
			[['id' => 'act1', 'timestamp' => 300, 'affecteduser' => 'bob', 'subject' => 'gedeeld']],
		);

		$page = $service->page('dossiq', 'case', 'obj-1');

		$this->assertSame(['activity', 'audit'], array_column($page['rows'], 'kind'));
		$this->assertSame([], $page['degraded']);
	}//end testTheTrailAndTheActivityRowsMergeIntoOneOrder()

	public function testRowsHandedInByTheCallerAreMergedBeside(): void {
		$service = $this->service([$this->entry('a1', 'update', 100)]);

		$page = $service->page('dossiq', 'case', 'obj-1', [], [
			'note' => [['id' => 'n1', 'timestamp' => 400, 'summary' => 'gebeld']],
			'file' => [['id' => 'f1', 'timestamp' => 200, 'summary' => 'gevel.jpg']],
		]);

		$this->assertSame(['note', 'file', 'audit'], array_column($page['rows'], 'kind'));
	}//end testRowsHandedInByTheCallerAreMergedBeside()

	public function testAnUnreadableSourceIsNamedRatherThanMergedAsNothing(): void {
		$audit = $this->getMockBuilder(AuditTrailMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['findAll'])
			->getMock();
		$audit->method('findAll')->willThrowException(new RuntimeException('the trail is unavailable'));

		$provider = $this->getMockBuilder(ActivityProvider::class)
			->disableOriginalConstructor()
			->onlyMethods(['list'])
			->getMock();
		$provider->method('list')->willReturn([]);

		$service = new ActivityFeedService(
			new ActivityFeedMerge(),
			$audit,
			$provider,
			$this->createMock(LoggerInterface::class),
		);

		$page = $service->page('dossiq', 'case', 'obj-1');

		// Empty AND degraded: an object with no history and an object whose
		// history could not be read must not look the same.
		$this->assertSame([], $page['rows']);
		$this->assertSame(['audit'], $page['degraded']);
	}//end testAnUnreadableSourceIsNamedRatherThanMergedAsNothing()

	public function testTheTrailIsAskedForThisObjectBoundedAndNewestFirst(): void {
		$capture = null;
		$service = $this->service([$this->entry('a1', 'update', 100)], [], $capture);

		$service->page('dossiq', 'case', 'obj-1', ['pageSize' => 10]);

		$this->assertSame(10, $capture['limit']);
		$this->assertSame(['objectUuid' => 'obj-1'], $capture['filters']);
		$this->assertSame(['created' => 'DESC'], $capture['sort']);
		// NOT narrowed on the action: the reads toggle has to be able to bring
		// them back without a second, differently shaped query.
		$this->assertArrayNotHasKey('action', $capture['filters']);
	}//end testTheTrailIsAskedForThisObjectBoundedAndNewestFirst()

	public function testReadsAreStillHiddenByDefaultOnceMerged(): void {
		$service = $this->service([
			$this->entry('r1', 'read', 300),
			$this->entry('w1', 'update', 100),
		]);

		$this->assertSame(['w1'], array_column($service->page('dossiq', 'case', 'obj-1')['rows'], 'id'));
		$this->assertSame(
			['r1', 'w1'],
			array_column($service->page('dossiq', 'case', 'obj-1', ['includeReads' => true])['rows'], 'id')
		);
	}//end testReadsAreStillHiddenByDefaultOnceMerged()

	public function testTheSummaryNamesTheFieldsAndNeverTheirValues(): void {
		$service = $this->service([
			$this->entry('a1', 'update', 100, ['bsn' => ['old' => '123456782', 'new' => '987654321']]),
		]);

		$summary = $service->page('dossiq', 'case', 'obj-1')['rows'][0]['summary'];

		$this->assertStringContainsString('bsn', $summary);
		// The value of a protected field must not travel into a feed that
		// everyone who can read the object can read.
		$this->assertStringNotContainsString('123456782', $summary);
		$this->assertStringNotContainsString('987654321', $summary);
	}//end testTheSummaryNamesTheFieldsAndNeverTheirValues()

	public function testAnEntryWithNoChangesStillSaysWhatItDid(): void {
		$service = $this->service([$this->entry('a1', 'create', 100)]);

		$this->assertSame('create', $service->page('dossiq', 'case', 'obj-1')['rows'][0]['summary']);
	}//end testAnEntryWithNoChangesStillSaysWhatItDid()
}//end class
