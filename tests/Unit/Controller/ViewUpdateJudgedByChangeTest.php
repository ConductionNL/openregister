<?php

/**
 * A view update is judged by what changed, for every caller the view reaches.
 *
 * A `write` member of a shared view could not save it at all: the update path
 * looked the view up as the caller's OWN, so a member got 404. And the field
 * guard judged every field the body carried, so the edit screen, which sends
 * the whole view, would have been refused on four fields nobody touched.
 * These tests run the REAL controller over the REAL ViewService, reach
 * resolver and share resolver; only the mapper and Nextcloud are doubles.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/saved-search-views/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\ViewsController;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\View;
use OCA\OpenRegister\Db\ViewMapper;
use OCA\OpenRegister\Service\Rbac\ViewerReachResolver;
use OCA\OpenRegister\Service\ViewPresentationService;
use OCA\OpenRegister\Service\ViewService;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\OpenRegister\Controller\ViewsController
 * @covers \OCA\OpenRegister\Service\ViewService
 * @covers \OCA\OpenRegister\Service\Rbac\ViewShareResolver
 */
class ViewUpdateJudgedByChangeTest extends TestCase {

	private const SHARES = [['group' => 'sales', 'mode' => 'write'], ['group' => 'audit', 'mode' => 'read']];

	private IRequest&MockObject $request;

	private ViewMapper&MockObject $mapper;

	private ?View $written = null;

	/**
	 * The controller for one caller in the given groups.
	 *
	 * @param string   $uid    The caller.
	 * @param string[] $groups The caller's groups.
	 *
	 * @return ViewsController
	 */
	private function controllerFor(string $uid, array $groups): ViewsController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('groupExists')->willReturn(true);
		$groupManager->method('getUserGroupIds')->willReturn($groups);
		$groupManager->method('isAdmin')->willReturn(false);

		$logger = new NullLogger();
		return new ViewsController(
			'openregister',
			$this->request,
			new ViewService(viewMapper: $this->mapper, logger: $logger, schemaMapper: $this->createMock(SchemaMapper::class)),
			$this->createMock(ViewPresentationService::class),
			$logger,
			new ViewerReachResolver(userSession: $session, groupManager: $groupManager, logger: $logger)
		);
	}//end controllerFor()

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->mapper = $this->createMock(ViewMapper::class);
		$this->mapper->method('update')->willReturnCallback(fn (View $view): View => $this->written = $view);

		$view = new View();
		$view->setId(7);
		$view->setName('Pipeline');
		$view->setDescription(null);
		$view->setOwner('owner');
		$view->setIsPublic(false);
		$view->setIsDefault(false);
		$view->setQuery(['registers' => [1], 'schemas' => [2], 'searchTerms' => []]);
		$view->setSharedWith(self::SHARES);
		$this->mapper->method('find')->willReturn($view);
	}//end setUp()

	/**
	 * The body EditView.vue sends: the whole view, with a changed query.
	 *
	 * @param array $overrides Fields to change on top.
	 *
	 * @return array
	 */
	private function modalBody(array $overrides = []): array {
		return array_merge(
			[
				'name' => 'Pipeline',
				'description' => '',
				'isPublic' => false,
				'isDefault' => false,
				'query' => ['registers' => [1], 'schemas' => [2], 'searchTerms' => ['open']],
			],
			$overrides
		);
	}//end modalBody()

	/**
	 * A write member saves the modal's full body with only the query changed.
	 */
	public function testAWriteMemberSavesAnOrdinaryEdit(): void {
		$this->request->method('getParams')->willReturn($this->modalBody());

		$response = $this->controllerFor('member', ['sales'])->update('7');

		$this->assertSame(200, $response->getStatus(), json_encode($response->getData()));
		$this->assertSame(['open'], $this->written?->getQuery()['searchTerms']);
		$this->assertSame('owner', $this->written?->getOwner());
	}//end testAWriteMemberSavesAnOrdinaryEdit()

	/**
	 * A write member who also renames the view is refused on the name only.
	 */
	public function testAWriteMemberIsRefusedOnTheChangedNameOnly(): void {
		$this->request->method('getParams')->willReturn($this->modalBody(['name' => 'Mine now']));

		$response = $this->controllerFor('member', ['sales'])->update('7');

		$this->assertSame(403, $response->getStatus());
		$this->assertSame(['name'], $response->getData()['fields']);
		$this->assertNull($this->written);
	}//end testAWriteMemberIsRefusedOnTheChangedNameOnly()

	/**
	 * A write member cannot widen who sees the view.
	 */
	public function testAWriteMemberCannotChangeTheAudience(): void {
		$this->request->method('getParams')->willReturn(
			$this->modalBody(['isPublic' => true, 'sharedWith' => [['group' => 'everyone', 'mode' => 'write']]])
		);

		$response = $this->controllerFor('member', ['sales'])->update('7');

		$this->assertSame(403, $response->getStatus());
		$this->assertEqualsCanonicalizing(['isPublic', 'sharedWith'], $response->getData()['fields']);
		$this->assertNull($this->written);
	}//end testAWriteMemberCannotChangeTheAudience()

	/**
	 * The same shares in another order, and a pagination key, are not a change.
	 */
	public function testAReorderedShareListAndAPaginationKeyRefuseNothing(): void {
		$this->request->method('getParams')->willReturn(
			$this->modalBody(['sharedWith' => array_reverse(self::SHARES), '_limit' => 20])
		);

		$response = $this->controllerFor('member', ['sales'])->update('7');

		$this->assertSame(200, $response->getStatus(), json_encode($response->getData()));
	}//end testAReorderedShareListAndAPaginationKeyRefuseNothing()

	/**
	 * A read member changes nothing.
	 */
	public function testAReadMemberIsRefused(): void {
		$this->request->method('getParams')->willReturn($this->modalBody());

		$response = $this->controllerFor('reader', ['audit'])->update('7');

		$this->assertSame(403, $response->getStatus());
		$this->assertSame(['query'], $response->getData()['fields']);
		$this->assertNull($this->written);
	}//end testAReadMemberIsRefused()

	/**
	 * A caller the view does not reach gets 404, as for a view that does not exist.
	 */
	public function testAStrangerGetsNotFound(): void {
		$this->request->method('getParams')->willReturn($this->modalBody());

		$response = $this->controllerFor('stranger', ['other'])->update('7');

		$this->assertSame(404, $response->getStatus());
		$this->assertNull($this->written);
	}//end testAStrangerGetsNotFound()

	/**
	 * The owner changes name, audience and shares in one save.
	 */
	public function testTheOwnerChangesEverything(): void {
		$this->request->method('getParams')->willReturn(
			$this->modalBody(['name' => 'Renamed', 'isPublic' => true, 'sharedWith' => [['group' => 'sales', 'mode' => 'read']]])
		);

		$response = $this->controllerFor('owner', [])->update('7');

		$this->assertSame(200, $response->getStatus(), json_encode($response->getData()));
		$this->assertSame('Renamed', $this->written?->getName());
		$this->assertSame([['group' => 'sales', 'mode' => 'read']], $this->written?->getSharedWith());
	}//end testTheOwnerChangesEverything()

	/**
	 * PATCH lets a write member change the query too.
	 */
	public function testAWriteMemberPatchesTheQuery(): void {
		$this->request->method('getParams')->willReturn(['query' => ['registers' => [1], 'schemas' => [2], 'searchTerms' => ['x']]]);

		$response = $this->controllerFor('member', ['sales'])->patch('7');

		$this->assertSame(200, $response->getStatus(), json_encode($response->getData()));
		$this->assertSame(['x'], $this->written?->getQuery()['searchTerms']);
	}//end testAWriteMemberPatchesTheQuery()
}//end class
