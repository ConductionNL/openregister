<?php

/**
 * A view's count alert is validated and written through the real save path.
 *
 * ViewAlert::parse() had a full test suite and no call site on the write path:
 * create, update and patch never read `alert`, so the column could only be
 * filled by hand and the sweep had nothing to evaluate. These tests run the
 * REAL controller over the REAL ViewService and reach resolver; only the
 * mapper and the Nextcloud collaborators are doubles.
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
 * @spec openspec/changes/saved-view-count-alert/specs/saved-search-views/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use DateTime;
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
class ViewAlertOnSaveTest extends TestCase {

	private IRequest&MockObject $request;

	private ViewMapper&MockObject $mapper;

	/** @var View|null The row the mapper last wrote. */
	private ?View $written = null;

	/** @var array<string, mixed> A well-formed alert as a client sends it. */
	private const ALERT = ['operator' => 'gte', 'threshold' => 20, 'recipients' => ['user:owner']];

	/** @var array<string, mixed> The same alert as it is stored, defaults filled in. */
	private const STORED_ALERT = [
		'operator' => 'gte',
		'threshold' => 20,
		'recipients' => ['user:owner'],
		'channels' => ['nc-notification'],
		'every' => 300,
	];

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->mapper = $this->createMock(ViewMapper::class);
		$this->mapper->method('insert')->willReturnCallback(fn (View $view): View => $this->written = $view);
		$this->mapper->method('update')->willReturnCallback(fn (View $view): View => $this->written = $view);
	}//end setUp()

	/**
	 * The real controller, acting as one user.
	 *
	 * @param string $uid The caller.
	 * @param array<int, string> $groups The caller's groups.
	 *
	 * @return ViewsController
	 */
	private function controllerFor(string $uid = 'owner', array $groups = []): ViewsController {
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

	/**
	 * An owned view as the mapper holds it.
	 *
	 * @param array|null $alert      Its declared alert.
	 * @param array|null $alertState Its alert state.
	 * @param array      $sharedWith Its group shares.
	 *
	 * @return View
	 */
	private function stored(?array $alert = null, ?array $alertState = null, array $sharedWith = []): View {
		$view = new View();
		$view->setId(7);
		$view->setName('Backlog');
		$view->setDescription('');
		$view->setOwner('owner');
		$view->setIsPublic(false);
		$view->setIsDefault(false);
		$view->setQuery([]);
		$view->setSharedWith($sharedWith);
		$view->setAlert($alert);
		$view->setAlertState($alertState);
		if ($alertState !== null) {
			$view->setAlertEvaluatedAt(new DateTime('2026-10-04 12:00:00'));
		}

		$this->mapper->method('find')->willReturn($view);

		return $view;
	}//end stored()

	/**
	 * Creating a view with an alert stores it, with the defaults filled in.
	 */
	public function testCreateStoresTheAlert(): void {
		$this->request->method('getParams')->willReturn(['name' => 'Backlog', 'query' => [], 'alert' => self::ALERT]);

		$response = $this->controllerFor()->create();

		$this->assertSame(201, $response->getStatus());
		$this->assertSame(self::STORED_ALERT, $this->written?->getAlert());
		$this->assertSame(self::STORED_ALERT, $response->getData()['view']['alert'] ?? null);
	}//end testCreateStoresTheAlert()

	/**
	 * The spec's scenario: a malformed alert is refused with 422 naming `operator`, and nothing is written.
	 */
	public function testAMalformedAlertIsRefusedNamingTheField(): void {
		$this->stored();
		$this->request->method('getParams')->willReturn(
			['name' => 'Backlog', 'query' => [], 'alert' => ['operator' => 'above', 'threshold' => 10]]
		);

		$response = $this->controllerFor()->update('7');

		$this->assertSame(422, $response->getStatus());
		$this->assertSame('alert.operator', $response->getData()['field'] ?? null);
		$this->assertStringContainsString('alert.operator', (string)($response->getData()['error'] ?? ''));
		$this->assertNull($this->written);
	}//end testAMalformedAlertIsRefusedNamingTheField()

	/**
	 * A malformed alert on create is refused the same way.
	 */
	public function testAMalformedAlertOnCreateIsRefused(): void {
		$this->request->method('getParams')->willReturn(
			['name' => 'Backlog', 'query' => [], 'alert' => ['operator' => 'gte', 'threshold' => 5, 'recipients' => []]]
		);

		$response = $this->controllerFor()->create();

		$this->assertSame(422, $response->getStatus());
		$this->assertSame('alert.recipients', $response->getData()['field'] ?? null);
		$this->assertNull($this->written);
	}//end testAMalformedAlertOnCreateIsRefused()

	/**
	 * An explicit null clears the alert and its state.
	 */
	public function testANullAlertClearsItAndItsState(): void {
		$this->stored(alert: self::STORED_ALERT, alertState: ['state' => 'fired', 'lastCount' => 23]);
		$this->request->method('getParams')->willReturn(['name' => 'Backlog', 'query' => [], 'alert' => null]);

		$response = $this->controllerFor()->update('7');

		$this->assertSame(200, $response->getStatus());
		$this->assertNull($this->written?->getAlert());
		$this->assertNull($this->written?->getAlertState());
		$this->assertNull($this->written?->getAlertEvaluatedAt());
	}//end testANullAlertClearsItAndItsState()

	/**
	 * An update that does not mention the alert keeps the alert and its state.
	 */
	public function testAnUpdateWithoutAlertKeepsIt(): void {
		$state = ['state' => 'fired', 'lastCount' => 23];
		$this->stored(alert: self::STORED_ALERT, alertState: $state);
		$this->request->method('getParams')->willReturn(['name' => 'Renamed', 'query' => []]);

		$this->controllerFor()->update('7');

		$this->assertSame(self::STORED_ALERT, $this->written?->getAlert());
		$this->assertSame($state, $this->written?->getAlertState());
	}//end testAnUpdateWithoutAlertKeepsIt()

	/**
	 * The edit screen sends the whole view: resending the same alert must not re-arm a fired one.
	 *
	 * Re-arming on every save would page the recipients again for a backlog they already heard about.
	 */
	public function testResendingTheSameAlertKeepsAFiredState(): void {
		$state = ['state' => 'fired', 'lastCount' => 23];
		$this->stored(alert: self::STORED_ALERT, alertState: $state);
		$this->request->method('getParams')->willReturn(['name' => 'Backlog', 'query' => [], 'alert' => self::ALERT]);

		$this->controllerFor()->update('7');

		$this->assertSame(self::STORED_ALERT, $this->written?->getAlert());
		$this->assertSame($state, $this->written?->getAlertState());
	}//end testResendingTheSameAlertKeepsAFiredState()

	/**
	 * A changed declaration starts over: the old state belongs to a different line.
	 */
	public function testAChangedAlertResetsItsState(): void {
		$this->stored(alert: self::STORED_ALERT, alertState: ['state' => 'fired', 'lastCount' => 23]);
		$this->request->method('getParams')->willReturn(
			['name' => 'Backlog', 'query' => [], 'alert' => (['threshold' => 50] + self::ALERT)]
		);

		$this->controllerFor()->update('7');

		$this->assertSame(50, $this->written?->getAlert()['threshold'] ?? null);
		$this->assertNull($this->written?->getAlertState());
		$this->assertNull($this->written?->getAlertEvaluatedAt());
	}//end testAChangedAlertResetsItsState()

	/**
	 * PATCH writes the alert too.
	 */
	public function testPatchStoresTheAlert(): void {
		$this->stored();
		$this->request->method('getParams')->willReturn(['alert' => self::ALERT]);

		$response = $this->controllerFor()->patch('7');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(self::STORED_ALERT, $this->written?->getAlert());
	}//end testPatchStoresTheAlert()

	/**
	 * PATCH without `alert` keeps it.
	 */
	public function testPatchWithoutAlertKeepsIt(): void {
		$this->stored(alert: self::STORED_ALERT);
		$this->request->method('getParams')->willReturn(['name' => 'Renamed']);

		$this->controllerFor()->patch('7');

		$this->assertSame(self::STORED_ALERT, $this->written?->getAlert());
	}//end testPatchWithoutAlertKeepsIt()

	/**
	 * A member with write on a shared view may set its alert.
	 */
	public function testAWriteMemberMaySetTheAlert(): void {
		$this->stored(sharedWith: [['group' => 'sales', 'mode' => 'write']]);
		$this->request->method('getParams')->willReturn(['alert' => self::ALERT]);

		$response = $this->controllerFor(uid: 'member', groups: ['sales'])->patch('7');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(self::STORED_ALERT, $this->written?->getAlert());
	}//end testAWriteMemberMaySetTheAlert()

	/**
	 * A member who may only read a shared view may not set its alert.
	 */
	public function testAReadMemberMayNotSetTheAlert(): void {
		$this->stored(sharedWith: [['group' => 'sales', 'mode' => 'read']]);
		$this->request->method('getParams')->willReturn(['alert' => self::ALERT]);

		$response = $this->controllerFor(uid: 'member', groups: ['sales'])->patch('7');

		$this->assertSame(403, $response->getStatus());
		$this->assertNull($this->written);
	}//end testAReadMemberMayNotSetTheAlert()
}//end class
