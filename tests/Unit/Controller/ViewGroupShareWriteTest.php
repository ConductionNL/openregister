<?php

/**
 * A view's group shares are written through the real create and update path.
 *
 * View::setSharedWith() had no caller: ViewService::create() and update()
 * never set it, the controller never read it, and the edit screen sent
 * `sharedGroups`, which nothing reads. ViewShareResolver::validateShares()
 * had a full test suite and no call site. These tests run the REAL controller
 * over the REAL ViewService and reach resolver; only the mapper and the
 * Nextcloud collaborators are doubles.
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
 */
class ViewGroupShareWriteTest extends TestCase {

	private ViewsController $controller;

	private IRequest&MockObject $request;

	private ViewMapper&MockObject $mapper;

	/** @var View|null The row the mapper last wrote. */
	private ?View $written = null;

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->mapper = $this->createMock(ViewMapper::class);
		$this->mapper->method('insert')->willReturnCallback(fn (View $view): View => $this->written = $view);
		$this->mapper->method('update')->willReturnCallback(fn (View $view): View => $this->written = $view);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('owner');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('groupExists')->willReturnCallback(fn (string $gid): bool => in_array($gid, ['sales', 'finance'], true));
		$groups->method('getUserGroupIds')->willReturn([]);
		$groups->method('isAdmin')->willReturn(false);

		$logger = new NullLogger();
		$this->controller = new ViewsController(
			'openregister',
			$this->request,
			new ViewService(viewMapper: $this->mapper, logger: $logger, schemaMapper: $this->createMock(SchemaMapper::class)),
			$this->createMock(ViewPresentationService::class),
			$logger,
			new ViewerReachResolver(userSession: $session, groupManager: $groups, logger: $logger)
		);
	}//end setUp()

	/**
	 * An owned view as the mapper holds it.
	 *
	 * @param array $sharedWith Its current shares.
	 *
	 * @return View
	 */
	private function stored(array $sharedWith = []): View {
		$view = new View();
		$view->setId(7);
		$view->setName('Pipeline');
		$view->setDescription('');
		$view->setOwner('owner');
		$view->setIsPublic(false);
		$view->setIsDefault(false);
		$view->setQuery([]);
		$view->setSharedWith($sharedWith);
		$this->mapper->method('find')->willReturn($view);

		return $view;
	}//end stored()

	/**
	 * Creating a view with a group share stores the share.
	 */
	public function testCreateStoresTheGroupShares(): void {
		$this->request->method('getParams')->willReturn(
			['name' => 'Pipeline', 'query' => [], 'sharedWith' => [['group' => 'sales', 'mode' => 'write']]]
		);

		$response = $this->controller->create();

		$this->assertSame(201, $response->getStatus());
		$this->assertSame([['group' => 'sales', 'mode' => 'write']], $this->written?->getSharedWith());
	}//end testCreateStoresTheGroupShares()

	/**
	 * The owner updating a view's shares stores them.
	 */
	public function testUpdateStoresTheGroupShares(): void {
		$this->stored();
		$this->request->method('getParams')->willReturn(
			['name' => 'Pipeline', 'query' => [], 'sharedWith' => [['group' => 'finance', 'mode' => 'read']]]
		);

		$response = $this->controller->update('7');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame([['group' => 'finance', 'mode' => 'read']], $this->written?->getSharedWith());
	}//end testUpdateStoresTheGroupShares()

	/**
	 * An update that does not mention sharedWith keeps the shares it had.
	 */
	public function testAnUpdateWithoutSharedWithKeepsTheShares(): void {
		$this->stored([['group' => 'sales', 'mode' => 'read']]);
		$this->request->method('getParams')->willReturn(['name' => 'Renamed', 'query' => []]);

		$this->controller->update('7');

		$this->assertSame([['group' => 'sales', 'mode' => 'read']], $this->written?->getSharedWith());
	}//end testAnUpdateWithoutSharedWithKeepsTheShares()

	/**
	 * A share with a group that does not exist is refused with 400, and nothing is written.
	 */
	public function testAShareWithAnUnknownGroupIsRefused(): void {
		$this->stored();
		$this->request->method('getParams')->willReturn(
			['name' => 'Pipeline', 'query' => [], 'sharedWith' => [['group' => 'nobody', 'mode' => 'read']]]
		);

		$response = $this->controller->update('7');

		$this->assertSame(400, $response->getStatus());
		$this->assertNotEmpty($response->getData()['findings'] ?? []);
		$this->assertNull($this->written);
	}//end testAShareWithAnUnknownGroupIsRefused()

	/**
	 * PATCH writes the shares too.
	 */
	public function testPatchStoresTheGroupShares(): void {
		$this->stored();
		$this->request->method('getParams')->willReturn(['sharedWith' => [['group' => 'sales', 'mode' => 'read']]]);

		$response = $this->controller->patch('7');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame([['group' => 'sales', 'mode' => 'read']], $this->written?->getSharedWith());
	}//end testPatchStoresTheGroupShares()
}//end class
