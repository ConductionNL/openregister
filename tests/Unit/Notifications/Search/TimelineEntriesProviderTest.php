<?php

/**
 * Unit tests for TimelineEntriesProvider: the unified-search provider for
 * timeline entries.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Notifications\Search
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Notifications\Search;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Notifications\Db\TimelineEntry;
use OCA\OpenRegister\Notifications\Search\TimelineEntriesProvider;
use OCA\OpenRegister\Notifications\Service\ObjectDeepLinks;
use OCA\OpenRegister\Notifications\Service\Timeline\EntrySearch;
use OCA\OpenRegister\Notifications\Service\Timeline\TimelineEntrySearchService;
use OCA\OpenRegister\Service\DeepLinkResolverInterface;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\IFilter;
use OCP\Search\ISearchQuery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * A search by term (and optional kind) lists matching entries under their object, linked to the entry.
 */
#[CoversClass(TimelineEntriesProvider::class)]
#[UsesClass(ObjectDeepLinks::class)]
#[UsesClass(EntrySearch::class)]
#[UsesClass(TimelineEntry::class)]
#[UsesClass(ObjectEntity::class)]
final class TimelineEntriesProviderTest extends TestCase {

	private TimelineEntrySearchService&MockObject $entries;
	private IURLGenerator&MockObject $urls;

	/**
	 * The search the service was asked for.
	 *
	 * @var EntrySearch|null
	 */
	private ?EntrySearch $asked = null;

	protected function setUp(): void {
		$this->entries = $this->createMock(TimelineEntrySearchService::class);
		$this->urls    = $this->createMock(IURLGenerator::class);
	}//end setUp()

	/**
	 * The provider, with or without a deep-link resolver.
	 *
	 * @param DeepLinkResolverInterface|null $resolver The resolver.
	 *
	 * @return TimelineEntriesProvider
	 */
	private function provider(?DeepLinkResolverInterface $resolver = null): TimelineEntriesProvider {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new TimelineEntriesProvider($l10n, $this->entries, new ObjectDeepLinks(urls: $this->urls, resolver: $resolver));
	}//end provider()

	/**
	 * A query with these filter values.
	 *
	 * @param array<string, mixed> $filters Filter name to value.
	 *
	 * @return ISearchQuery
	 */
	private function query(array $filters): ISearchQuery {
		$query = $this->createMock(ISearchQuery::class);
		$query->method('getFilter')->willReturnCallback(
			function (string $name) use ($filters): ?IFilter {
				if (array_key_exists($name, $filters) === false) {
					return null;
				}

				$filter = $this->createMock(IFilter::class);
				$filter->method('get')->willReturn($filters[$name]);
				return $filter;
			}
		);
		return $query;
	}//end query()

	/**
	 * A hit: an entry on an object.
	 *
	 * @param string      $message The entry text.
	 * @param string|null $kind    The entry kind.
	 * @param string|null $name    The object's name.
	 *
	 * @return array{entry: TimelineEntry, object: ObjectEntity}
	 */
	private static function hit(string $message, ?string $kind, ?string $name): array {
		$entry = new TimelineEntry();
		$entry->setUuid('e-1');
		$entry->setMessage($message);
		$entry->setKind($kind);
		$object = new ObjectEntity();
		$object->setUuid('o-1');
		$object->setName($name);
		$object->setRegister('3');
		$object->setSchema('7');
		return ['entry' => $entry, 'object' => $object];
	}//end hit()

	/**
	 * The service records what it is asked and answers $hits.
	 *
	 * @param array<int, array{entry: TimelineEntry, object: ObjectEntity}> $hits The hits.
	 *
	 * @return void
	 */
	private function answers(array $hits): void {
		$this->entries->method('findHits')->willReturnCallback(
			function (EntrySearch $search) use ($hits): array {
				$this->asked = $search;
				return $hits;
			}
		);
	}//end answers()

	public function testItDescribesItself(): void {
		$provider = $this->provider();

		$this->assertSame('openregister_timeline_entries', $provider->getId());
		$this->assertSame('Timeline entries', $provider->getName());
		$this->assertSame(15, $provider->getOrder('files.view.index', []));
		$this->assertSame(['term', 'kind'], $provider->getSupportedFilters());
		$this->assertSame([], $provider->getAlternateIds());
		$filters = $provider->getCustomFilters();
		$this->assertCount(1, $filters);
		$this->assertSame('kind', $filters[0]->name());
	}//end testItDescribesItself()

	public function testNoTermSearchesNothing(): void {
		$this->entries->expects($this->never())->method('findHits');

		foreach ([[], ['term' => '   '], ['term' => 5]] as $filters) {
			$result = $this->provider()->search($this->createMock(IUser::class), $this->query($filters))->jsonSerialize();
			$this->assertSame('Timeline entries', $result['name']);
			$this->assertSame([], $result['entries']);
		}
	}//end testNoTermSearchesNothing()

	public function testATermSearchesAllKindsUnlessAKindIsGiven(): void {
		$this->answers([]);

		$this->provider()->search($this->createMock(IUser::class), $this->query(['term' => 'boiler', 'kind' => ' ']));
		$this->assertSame(['boiler', null, null, 25], [$this->asked->term, $this->asked->visibility, $this->asked->kind, $this->asked->limit]);

		$this->provider()->search($this->createMock(IUser::class), $this->query(['term' => 'boiler', 'kind' => 'phone-call']));
		$this->assertSame('phone-call', $this->asked->kind);
	}//end testATermSearchesAllKindsUnlessAKindIsGiven()

	public function testAHitIsListedUnderItsObjectWithKindExcerptAndEntryAnchor(): void {
		$this->answers([self::hit("Called about\n the   boiler.", 'phone-call', 'Lindelaan 4')]);
		$this->urls->expects($this->once())
			->method('linkToRoute')
			->with('openregister.objects.show', ['register' => 3, 'schema' => 7, 'id' => 'o-1'])
			->willReturn('/apps/openregister/objects/3/7/o-1');

		$entries = $this->provider()->search($this->createMock(IUser::class), $this->query(['term' => 'boiler']))->jsonSerialize()['entries'];

		$this->assertCount(1, $entries);
		$row = $entries[0]->jsonSerialize();
		$this->assertSame('Lindelaan 4 · phone-call', $row['title']);
		$this->assertSame('Called about the boiler.', $row['subline']);
		$this->assertSame('/apps/openregister/objects/3/7/o-1#entry-e-1', $row['resourceUrl']);
		$this->assertSame('icon-comment', $row['icon']);
	}//end testAHitIsListedUnderItsObjectWithKindExcerptAndEntryAnchor()

	public function testAnUnnamedObjectIsTitledByItsUuidAndALongMessageIsCut(): void {
		$this->answers([self::hit(str_repeat('a', 200), null, null)]);
		$this->urls->method('linkToRoute')->willReturn('/o');

		$row = $this->provider()->search($this->createMock(IUser::class), $this->query(['term' => 'a']))->jsonSerialize()['entries'][0]->jsonSerialize();

		$this->assertSame('o-1', $row['title']);
		$this->assertSame(str_repeat('a', 160).'…', $row['subline']);
	}//end testAnUnnamedObjectIsTitledByItsUuidAndALongMessageIsCut()

	public function testTheDeepLinkResolverWinsOverTheBuiltInRoute(): void {
		$this->answers([self::hit('x', null, 'Case')]);
		$resolver = $this->createMock(DeepLinkResolverInterface::class);
		$resolver->expects($this->once())
			->method('resolveUrl')
			->with(3, 7, ['uuid' => 'o-1', 'register' => 3, 'schema' => 7])
			->willReturn('/apps/dossiq/cases/o-1');
		$this->urls->expects($this->never())->method('linkToRoute');

		$row = $this->provider($resolver)->search($this->createMock(IUser::class), $this->query(['term' => 'x']))->jsonSerialize()['entries'][0]->jsonSerialize();

		$this->assertSame('/apps/dossiq/cases/o-1#entry-e-1', $row['resourceUrl']);
	}//end testTheDeepLinkResolverWinsOverTheBuiltInRoute()

	public function testAnUnresolvedDeepLinkFallsBackToTheBuiltInRoute(): void {
		$this->answers([self::hit('x', null, 'Case')]);
		$resolver = $this->createMock(DeepLinkResolverInterface::class);
		$resolver->method('resolveUrl')->willReturn(null);
		$this->urls->expects($this->once())->method('linkToRoute')->willReturn('/fallback');

		$row = $this->provider($resolver)->search($this->createMock(IUser::class), $this->query(['term' => 'x']))->jsonSerialize()['entries'][0]->jsonSerialize();

		$this->assertSame('/fallback#entry-e-1', $row['resourceUrl']);
	}//end testAnUnresolvedDeepLinkFallsBackToTheBuiltInRoute()
}//end class
