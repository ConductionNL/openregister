<?php

declare(strict_types=1);

namespace Unit\Service\Object;

use OCA\OpenRegister\Db\View;
use OCA\OpenRegister\Db\ViewMapper;
use OCA\OpenRegister\Service\Object\ViewScopeApplier;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Guards the `_search` merge in ViewScopeApplier::apply().
 *
 * The block is commented "Merge with existing search if present", but it used to
 * assign `$query['_search'] = $searchTerms` FIRST and only then test
 * `isset($query['_search'])` — a condition that could only ever see the value it
 * had just written. Two defects fell out of that ordering:
 *
 *   1. the caller's own `_search` was overwritten, so the merge never happened;
 *   2. the view's terms were appended to themselves, yielding "invoice invoice".
 *
 * PHPStan surfaced it only as "Offset '_search' … always exists", which reads
 * like a redundant-isset nit rather than a search returning the wrong rows.
 *
 * Both tests below fail against the pre-fix code — 1 on the discarded caller
 * term, 2 on the doubled view term.
 */
class ViewScopeApplierSearchMergeTest extends TestCase {

	/**
	 * An applier whose ViewMapper returns one view carrying the given query.
	 *
	 * @param array<string, mixed> $viewQuery The view's stored query.
	 *
	 * @return ViewScopeApplier
	 */
	private function makeApplier(array $viewQuery): ViewScopeApplier {
		// A real View, not a mock: getQuery() is an Entity magic accessor, and
		// PHPUnit cannot configure it — mocking it errors with "method ... does
		// not exist", which would make these tests LOOK like they fail against
		// the unfixed code while actually proving nothing.
		$view = new View();
		$view->setQuery($viewQuery);

		$viewMapper = $this->createMock(ViewMapper::class);
		$viewMapper->method('find')->willReturn($view);

		return new ViewScopeApplier(
			$viewMapper,
			$this->createMock(LoggerInterface::class)
		);

	}//end makeApplier()

	/**
	 * The caller's own search term must survive the view being applied.
	 *
	 * @return void
	 */
	public function testExistingSearchTermIsMergedNotDiscarded(): void {
		$result = $this->makeApplier(['searchTerms' => 'invoice'])
			->apply(['_search' => 'urgent'], [1]);

		$this->assertStringContainsString(
			'urgent',
			$result['_search'],
			"the caller's existing _search must not be discarded by the view"
		);
		$this->assertStringContainsString(
			'invoice',
			$result['_search'],
			"the view's own search terms must still be applied"
		);

	}//end testExistingSearchTermIsMergedNotDiscarded()

	/**
	 * A view's terms must appear once, not be appended to themselves.
	 *
	 * @return void
	 */
	public function testViewSearchTermIsNotDuplicated(): void {
		$result = $this->makeApplier(['searchTerms' => 'invoice'])
			->apply([], [1]);

		$this->assertSame(
			'invoice',
			$result['_search'],
			'a view search term must be applied exactly once'
		);
		$this->assertSame(
			1,
			substr_count($result['_search'], 'invoice'),
			'the term must not be appended to itself'
		);

	}//end testViewSearchTermIsNotDuplicated()

	/**
	 * An array of view terms is joined, and still merged with the caller's.
	 *
	 * @return void
	 */
	public function testArrayViewTermsAreJoinedAndMerged(): void {
		$result = $this->makeApplier(['searchTerms' => ['alpha', 'beta']])
			->apply(['_search' => 'gamma'], [1]);

		foreach (['gamma', 'alpha', 'beta'] as $term) {
			$this->assertStringContainsString(
				$term,
				$result['_search'],
				"'{$term}' must survive the merge"
			);
			$this->assertSame(1, substr_count($result['_search'], $term), "'{$term}' must appear once");
		}

	}//end testArrayViewTermsAreJoinedAndMerged()
}//end class
