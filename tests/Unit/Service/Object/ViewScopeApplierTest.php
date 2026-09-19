<?php

declare(strict_types=1);

/**
 * A view-scoped search either applies its view or refuses to run.
 *
 * The defect these cover reached anonymous callers on a #[PublicPage] route.
 * AccessLinkReader::readView() searches with `_rbac: false` and
 * `_multitenancy: false`, so the view filter is the ONLY thing bounding the
 * read — and it was silently dropped: ViewMapper::find() ran an RBAC check that
 * denies without a user, and the view merge logged the refusal and carried
 * on with the query unmodified. No RBAC, no multitenancy, no view: up to 200
 * arbitrary objects from any organisation on the instance.
 *
 * The tolerant behaviour is still correct for ordinary callers — an
 * authenticated search whose view has gone should not 500 — so these assert
 * BOTH halves: flagged callers fail closed, unflagged callers are untouched.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use Exception;
use OCA\OpenRegister\Db\View;
use OCA\OpenRegister\Db\ViewMapper;
use OCA\OpenRegister\Service\Object\ViewScopeApplier;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Fail-closed tests for ViewScopeApplier::apply().
 */
class ViewScopeApplierTest extends TestCase {
	private ViewMapper $viewMapper;

	protected function setUp(): void {
		$this->viewMapper = $this->createMock(ViewMapper::class);
	}//end setUp()

	private function applier(): ViewScopeApplier {
		return new ViewScopeApplier(
			$this->viewMapper,
			$this->createMock(LoggerInterface::class)
		);
	}//end applier()

	/**
	 * A real View: getQuery() is an Entity magic accessor PHPUnit cannot stub.
	 *
	 * @param array<string, mixed> $query The stored view query.
	 */
	private function view(array $query): View {
		$view = new View();
		$view->setQuery($query);

		return $view;
	}//end view()

	public function testAnUnresolvableViewThrowsWhenTheViewIsTheOnlyBound(): void {
		// THE REGRESSION TEST. Before the fix this returned $query untouched,
		// and the caller ran an unbounded search with RBAC and multitenancy off.
		$this->viewMapper->method('find')->willThrowException(new RuntimeException('denied'));

		$this->expectException(Exception::class);

		$this->applier()->apply(
			query: ['_limit' => 200],
			viewIds: ['view-uuid'],
			_viewScopeRequired: true
		);
	}//end testAnUnresolvableViewThrowsWhenTheViewIsTheOnlyBound()

	public function testAnUnresolvableViewIsStillToleratedForEveryOtherCaller(): void {
		$this->viewMapper->method('find')->willThrowException(new RuntimeException('denied'));

		$query = $this->applier()->apply(
			query: ['_limit' => 200],
			viewIds: ['view-uuid']
		);

		$this->assertSame(['_limit' => 200], $query);
	}//end testAnUnresolvableViewIsStillToleratedForEveryOtherCaller()

	public function testAViewThatNarrowsNothingThrows(): void {
		// "Applied successfully" and "no bound at all" are the same outcome when
		// the view is the only bound, so an empty view query is a refusal too.
		$this->viewMapper->method('find')->willReturn($this->view([]));

		$this->expectException(Exception::class);

		$this->applier()->apply(
			query: [],
			viewIds: ['view-uuid'],
			_viewScopeRequired: true
		);
	}//end testAViewThatNarrowsNothingThrows()

	public function testASearchTermOnlyViewThrowsBecauseItSetsNoRegisterOrSchema(): void {
		// It narrows - the terms are merged into _search - but it sets neither
		// register nor schema, so the query reaches the mapper with no context at
		// all. That is inert today only because MagicMapper takes its no-context
		// branch and returns []; "correct outcome reached by accident" is not the
		// contract this class advertises, and the accident is one
		// register-resolution change away from being a real unbounded read.
		$this->viewMapper->method('find')->willReturn($this->view(['searchTerms' => 'invoice']));

		$this->expectException(Exception::class);

		$this->applier()->apply(
			query: [],
			viewIds: ['view-uuid'],
			_viewScopeRequired: true
		);
	}//end testASearchTermOnlyViewThrowsBecauseItSetsNoRegisterOrSchema()

	public function testASearchTermOnlyViewIsStillAppliedForEveryOtherCaller(): void {
		// Unflagged callers are already bounded by RBAC and their organisation,
		// so a search-term view remains an ordinary convenience there.
		$this->viewMapper->method('find')->willReturn($this->view(['searchTerms' => 'invoice']));

		$query = $this->applier()->apply(query: [], viewIds: ['view-uuid']);

		$this->assertSame('invoice', $query['_search']);
	}//end testASearchTermOnlyViewIsStillAppliedForEveryOtherCaller()

	public function testNoViewAtAllThrowsRatherThanRunningUnbounded(): void {
		$this->expectException(Exception::class);

		$this->applier()->apply(
			query: ['_limit' => 200],
			viewIds: [],
			_viewScopeRequired: true
		);
	}//end testNoViewAtAllThrowsRatherThanRunningUnbounded()

	public function testAResolvableViewStillNarrowsTheQuery(): void {
		$this->viewMapper->method('find')->willReturn($this->view(['registers' => [7]]));

		$query = $this->applier()->apply(
			query: [],
			viewIds: ['view-uuid'],
			_viewScopeRequired: true
		);

		$this->assertContains(7, $query['@self']['register']);
	}//end testAResolvableViewStillNarrowsTheQuery()

	public function testTheViewIsResolvedExemptSoAnAnonymousCallerCanOpenTheLink(): void {
		// The link IS the authorization. Exempting RBAC alone is not enough:
		// an anonymous caller has no active organisation, so the tenancy filter
		// appends `1 = 0` and the view would never resolve.
		$this->viewMapper->expects($this->once())
			->method('find')
			->with('view-uuid', false, false)
			->willReturn($this->view(['registers' => [7]]));

		$this->applier()->apply(
			query: [],
			viewIds: ['view-uuid'],
			_viewScopeRequired: true
		);
	}//end testTheViewIsResolvedExemptSoAnAnonymousCallerCanOpenTheLink()
}//end class
