<?php

/**
 * Unit tests for TimelineEntrySearchService — entries searched across objects.
 *
 * Two things are asserted separately here because they live in different
 * places for different reasons: the VISIBILITY filter must reach the
 * STATEMENT, and the OBJECT ACCESS check must drop hits the searcher may not
 * read. A test that only counted results could pass with either one missing.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Timeline
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/timeline-entries-are-records/specs/unified-search-provider/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Timeline;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\TimelineEntry;
use OCA\OpenRegister\Db\TimelineEntryMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Timeline\TimelineEntrySearchService;
use OCA\OpenRegister\Service\TimelineVisibilityService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * TimelineEntrySearchServiceTest.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class TimelineEntrySearchServiceTest extends TestCase {
	/**
	 * Record mapper mock.
	 *
	 * @var TimelineEntryMapper&MockObject
	 */
	private TimelineEntryMapper&MockObject $entries;

	/**
	 * Object service mock.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService&MockObject $objects;

	/**
	 * Visibility service mock.
	 *
	 * @var TimelineVisibilityService&MockObject
	 */
	private TimelineVisibilityService&MockObject $visibility;

	/**
	 * Service under test.
	 *
	 * @var TimelineEntrySearchService
	 */
	private TimelineEntrySearchService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->entries = $this->createMock(TimelineEntryMapper::class);
		$this->objects = $this->createMock(ObjectService::class);
		$this->visibility = $this->createMock(TimelineVisibilityService::class);
		$this->visibility->method('normalise')->willReturnCallback(
			static function (?string $value): string {
				if ($value === 'public') {
					return 'public';
				}

				return 'internal';
			}
		);

		$this->service = new TimelineEntrySearchService(
			$this->entries,
			$this->objects,
			$this->visibility,
			$this->createMock(LoggerInterface::class)
		);
	}

	private function entry(string $uuid, string $objectUuid, string $visibility = 'internal'): TimelineEntry {
		$entry = new TimelineEntry();
		$entry->setUuid($uuid);
		$entry->setObjectUuid($objectUuid);
		$entry->setVisibility($visibility);
		$entry->setMessage('betreft de heer Jansen');

		return $entry;
	}

	private function object(string $uuid): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);

		return $object;
	}

	/**
	 * Let every object resolve.
	 *
	 * @param array<int,string> $readable The uuids the searcher may read.
	 *
	 * @return void
	 */
	private function readable(array $readable): void {
		$this->objects->method('find')->willReturnCallback(
			function (mixed $id) use ($readable): ?ObjectEntity {
				if (in_array((string)$id, $readable, true) === false) {
					return null;
				}

				return $this->object((string)$id);
			}
		);
	}

	public function testAWooRequestFindsEveryMentionOfASubject(): void {
		$this->entries->method('search')->willReturn(
			[
				$this->entry('e1', 'case-1'),
				$this->entry('e2', 'case-2'),
				$this->entry('e3', 'case-3'),
			]
		);
		$this->readable(['case-1', 'case-2', 'case-3']);

		$hits = $this->service->search('Jansen');

		$this->assertCount(3, $hits);
		$this->assertSame(
			['case-1', 'case-2', 'case-3'],
			array_map(static fn (array $hit): string => (string)$hit['object']->getUuid(), $hits)
		);
	}

	public function testAHitNamesBothTheObjectAndTheEntry(): void {
		$this->entries->method('search')->willReturn([$this->entry('e1', 'case-1')]);
		$this->readable(['case-1']);

		$rows = $this->service->searchAsArrays('Jansen');

		$this->assertSame('e1', $rows[0]['id']);
		$this->assertSame('case-1', $rows[0]['object']['uuid']);
	}

	public function testAnEntryOnAnUnreadableObjectIsAbsent(): void {
		$this->entries->method('search')->willReturn(
			[$this->entry('e1', 'case-1'), $this->entry('e2', 'forbidden')]
		);
		$this->readable(['case-1']);

		$hits = $this->service->search('Jansen');

		$this->assertCount(1, $hits);
		$this->assertSame('e1', $hits[0]['entry']->getUuid());
	}

	public function testAnInternalEntryStaysOutOfAPublicReadersResults(): void {
		// The assertion is on the ARGUMENT, not on the returned rows: the
		// filter has to reach the statement. A service that fetched everything
		// and then dropped the internal rows would return the same list here
		// and still ship the wrong count and a short page.
		$this->entries->expects($this->once())->method('search')
			->with(
				$this->anything(),
				null,
				'public',
				$this->anything(),
				$this->anything()
			)
			->willReturn([]);

		$this->service->search('Jansen', 'public');
	}

	public function testAnUnfilteredSearchPassesNoVisibilityCondition(): void {
		$this->entries->expects($this->once())->method('search')
			->with($this->anything(), null, null, $this->anything(), $this->anything())
			->willReturn([]);

		$this->service->search('Jansen');
	}

	public function testTheStatementOverFetchesSoTheAccessCheckCanDropRows(): void {
		$this->entries->expects($this->once())->method('search')
			->with(
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->anything(),
				(10 * TimelineEntrySearchService::OVERFETCH)
			)
			->willReturn([]);

		$this->service->search('Jansen', null, null, 10);
	}

	public function testAnObjectIsResolvedOncePerRequestHoweverManyEntriesMatched(): void {
		$this->entries->method('search')->willReturn(
			[
				$this->entry('e1', 'case-1'),
				$this->entry('e2', 'case-1'),
				$this->entry('e3', 'case-1'),
			]
		);
		$this->objects->expects($this->once())->method('find')->willReturn($this->object('case-1'));

		$this->assertCount(3, $this->service->search('Jansen'));
	}

	public function testARefusalIsRememberedAsFirmlyAsAnAdmission(): void {
		$this->entries->method('search')->willReturn(
			[$this->entry('e1', 'forbidden'), $this->entry('e2', 'forbidden')]
		);
		// One refused read, not two: without memoing the refusal, a search
		// matching forty entries on one forbidden case runs forty refused reads.
		$this->objects->expects($this->once())->method('find')->willReturn(null);

		$this->assertSame([], $this->service->search('Jansen'));
	}

	public function testThePageStopsAtTheLimitEvenWhenMoreResolved(): void {
		$this->entries->method('search')->willReturn(
			[
				$this->entry('e1', 'case-1'),
				$this->entry('e2', 'case-2'),
				$this->entry('e3', 'case-3'),
			]
		);
		$this->readable(['case-1', 'case-2', 'case-3']);

		$this->assertCount(2, $this->service->search('Jansen', null, null, 2));
	}

	public function testAnEmptyTermSearchesNothing(): void {
		$this->entries->expects($this->never())->method('search');

		$this->assertSame([], $this->service->search('   '));
	}

	public function testAReadThatThrowsIsARefusalRatherThanAFailedSearch(): void {
		$this->entries->method('search')->willReturn([$this->entry('e1', 'case-1')]);
		$this->objects->method('find')->willThrowException(new \RuntimeException('forbidden'));

		$this->assertSame([], $this->service->search('Jansen'));
	}
}
