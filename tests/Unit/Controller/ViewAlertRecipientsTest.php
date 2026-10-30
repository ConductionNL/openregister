<?php

/**
 * Who may be named as a recipient of a saved view's count alert.
 *
 * An alert sends the view's name and count to its recipients. These tests run
 * the real controller over the real ViewService and reach resolver; only the
 * mapper and the Nextcloud collaborators are doubles.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/saved-search-views/spec.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

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
 * @covers \OCA\OpenRegister\Service\Rbac\ViewerReachResolver
 * @uses \OCA\OpenRegister\Db\View
 * @uses \OCA\OpenRegister\Exception\InvalidViewAlertException
 * @uses \OCA\OpenRegister\Exception\ViewAlertRecipientException
 * @uses \OCA\OpenRegister\Service\Rbac\ViewShareListValidator
 * @uses \OCA\OpenRegister\Service\Rbac\ViewShareResolver
 * @uses \OCA\OpenRegister\Service\ViewService
 * @uses \OCA\OpenRegister\Service\View\ViewAlert
 */
class ViewAlertRecipientsTest extends TestCase {

	/**
	 * Group membership on this instance: uid => groups.
	 */
	private const MEMBERS = [
		'owner'    => [],
		'member'   => ['sales'],
		'reader'   => ['finance'],
		'stranger' => ['marketing'],
		'admin'    => ['admin'],
	];

	/** @var IRequest&MockObject The request. */
	private IRequest&MockObject $request;

	/** @var ViewMapper&MockObject The mapper. */
	private ViewMapper&MockObject $mapper;

	/** @var View|null The row the mapper last wrote. */
	private ?View $written = null;

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->mapper = $this->createMock(ViewMapper::class);
		$this->mapper->method('insert')->willReturnCallback(fn (View $view): View => $this->written = $view);
		$this->mapper->method('update')->willReturnCallback(fn (View $view): View => $this->written = $view);
	}//end setUp()

	/**
	 * The controller, with the given user signed in.
	 *
	 * @param string $uid The signed-in user.
	 *
	 * @return ViewsController
	 */
	private function controllerAs(string $uid): ViewsController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('groupExists')->willReturn(true);
		$groups->method('getUserGroupIds')->willReturn(self::MEMBERS[$uid] ?? []);
		$groups->method('isAdmin')->willReturnCallback(fn (string $id): bool => $id === 'admin');
		$groups->method('isInGroup')->willReturnCallback(
			fn (string $id, string $gid): bool => in_array($gid, self::MEMBERS[$id] ?? [], true)
		);

		$logger = new NullLogger();

		return new ViewsController(
			'openregister',
			$this->request,
			new ViewService(viewMapper: $this->mapper, logger: $logger, schemaMapper: $this->createMock(SchemaMapper::class)),
			$this->createMock(ViewPresentationService::class),
			$logger,
			new ViewerReachResolver(userSession: $session, groupManager: $groups, logger: $logger)
		);
	}//end controllerAs()

	/**
	 * The owner's view, shared with sales (write) and finance (read).
	 *
	 * @param array<int, string> $recipients Recipients already on its alert, none when empty.
	 * @param bool               $isPublic   Whether it is public.
	 *
	 * @return View
	 */
	private function stored(array $recipients = [], bool $isPublic = false): View {
		$view = new View();
		$view->setId(7);
		$view->setName('Queue');
		$view->setDescription('');
		$view->setOwner('owner');
		$view->setIsPublic($isPublic);
		$view->setIsDefault(false);
		$view->setQuery([]);
		$view->setSharedWith([['group' => 'sales', 'mode' => 'write'], ['group' => 'finance', 'mode' => 'read']]);
		if ($recipients !== []) {
			$view->setAlert(['operator' => 'gte', 'threshold' => 5, 'recipients' => $recipients, 'channels' => ['nc-notification']]);
		}

		$this->mapper->method('find')->willReturn($view);

		return $view;
	}//end stored()

	/**
	 * A PATCH body setting an alert for the given recipients.
	 *
	 * @param array<int, string> $recipients The recipients.
	 * @param int                $threshold  The threshold.
	 *
	 * @return array<string, mixed>
	 */
	private function alertBody(array $recipients, int $threshold = 5): array {
		return ['alert' => ['operator' => 'gte', 'threshold' => $threshold, 'recipients' => $recipients, 'channels' => ['nc-notification']]];
	}//end alertBody()

	public function testTheOwnerMayNameAUserWhoCanReadTheView(): void {
		$this->stored();
		$this->request->method('getParams')->willReturn($this->alertBody(['user:reader', 'user:owner']));

		$response = $this->controllerAs('owner')->patch('7');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['user:reader', 'user:owner'], $this->written?->getAlert()['recipients']);
	}

	public function testTheOwnerMayNotNameAUserWhoCannotReadTheView(): void {
		$this->stored();
		$this->request->method('getParams')->willReturn($this->alertBody(['user:reader', 'user:stranger']));

		$response = $this->controllerAs('owner')->patch('7');

		$this->assertSame(403, $response->getStatus());
		$this->assertSame('alert.recipients', $response->getData()['field']);
		$this->assertSame(['user:stranger'], $response->getData()['recipients']);
		$this->assertNull($this->written);
	}

	public function testTheOwnerMayNameASharedGroupButNotAnotherGroup(): void {
		$this->stored();
		$this->request->method('getParams')->willReturn($this->alertBody(['finance', 'marketing']));

		$response = $this->controllerAs('owner')->patch('7');

		$this->assertSame(403, $response->getStatus());
		$this->assertSame(['marketing'], $response->getData()['recipients']);
	}

	public function testAnyUserMayBeNamedOnAPublicView(): void {
		$this->stored(isPublic: true);
		$this->request->method('getParams')->willReturn($this->alertBody(['user:stranger']));

		$response = $this->controllerAs('owner')->patch('7');

		$this->assertSame(200, $response->getStatus());
	}

	public function testAnAdministratorMayNameAReaderOfSomeoneElsesView(): void {
		$this->stored();
		$this->request->method('getParams')->willReturn($this->alertBody(['user:reader']));

		$response = $this->controllerAs('admin')->patch('7');

		$this->assertSame(200, $response->getStatus());
	}

	public function testAnOwnerCreatingAViewIsHeldToTheSameRule(): void {
		$this->request->method('getParams')->willReturn(
			['name' => 'Queue', 'query' => [], 'sharedWith' => [['group' => 'finance', 'mode' => 'read']]] + $this->alertBody(['user:reader', 'user:stranger'])
		);

		$response = $this->controllerAs('owner')->create();

		$this->assertSame(403, $response->getStatus());
		$this->assertSame(['user:stranger'], $response->getData()['recipients']);
		$this->assertNull($this->written);
	}

	public function testAWriteMemberMayNameThemselves(): void {
		$this->stored();
		$this->request->method('getParams')->willReturn($this->alertBody(['user:member']));

		$response = $this->controllerAs('member')->patch('7');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['user:member'], $this->written?->getAlert()['recipients']);
	}

	public function testAWriteMemberMayNotNameAnybodyElse(): void {
		$this->stored();
		$this->request->method('getParams')->willReturn($this->alertBody(['user:member', 'user:reader', 'user:stranger', 'sales']));

		$response = $this->controllerAs('member')->patch('7');

		$this->assertSame(403, $response->getStatus());
		$this->assertSame(['user:reader', 'user:stranger', 'sales'], $response->getData()['recipients']);
		$this->assertNull($this->written);
	}

	public function testAWriteMemberMayChangeTheThresholdOfTheOwnersRecipients(): void {
		$this->stored(['user:owner', 'finance']);
		$this->request->method('getParams')->willReturn($this->alertBody(['user:owner', 'finance'], threshold: 10));

		$response = $this->controllerAs('member')->patch('7');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(10, $this->written?->getAlert()['threshold']);
	}

	public function testAStrangerMayNotSetAnAlertAtAll(): void {
		$this->stored(isPublic: true);
		$this->request->method('getParams')->willReturn($this->alertBody(['user:stranger']));

		$response = $this->controllerAs('stranger')->patch('7');

		$this->assertSame(403, $response->getStatus());
		$this->assertSame(['alert'], $response->getData()['fields']);
		$this->assertNull($this->written);
	}

	/**
	 * 🔴 A PUT IS JUDGED AGAINST THE VIEW IT WILL SAVE, NOT THE ONE STORED.
	 *
	 * The body omits `isPublic`, which a PUT writes as false. Judged against the
	 * stored public view, a stranger was admitted as a recipient of a view that
	 * is private once saved.
	 *
	 * @return void
	 */
	public function testAPutThatOmitsIsPublicIsJudgedAsPrivate(): void {
		$this->stored(isPublic: true);
		$this->request->method('getParams')->willReturn(
			['name' => 'Queue', 'query' => ['@self' => ['register' => 1]]] + $this->alertBody(['user:stranger'])
		);

		$response = $this->controllerAs('owner')->update('7');

		$this->assertSame(403, $response->getStatus());
		$this->assertSame(['user:stranger'], $response->getData()['recipients']);
		$this->assertNull($this->written);
	}

	/**
	 * The control: a PATCH that omits `isPublic` keeps the stored public view, so the same recipient is allowed.
	 *
	 * @return void
	 */
	public function testAPatchThatOmitsIsPublicKeepsTheStoredReach(): void {
		$this->stored(isPublic: true);
		$this->request->method('getParams')->willReturn($this->alertBody(['user:stranger']));

		$this->assertSame(200, $this->controllerAs('owner')->patch('7')->getStatus());
	}

	/**
	 * Un-publishing a view whose stored alert names a recipient reached only through it is refused.
	 *
	 * @return void
	 */
	public function testUnpublishingARecipientsOnlyWayInIsRefused(): void {
		$this->stored(recipients: ['user:stranger'], isPublic: true);
		$this->request->method('getParams')->willReturn(['isPublic' => false]);

		$response = $this->controllerAs('owner')->patch('7');

		$this->assertSame(403, $response->getStatus());
		$this->assertSame(['user:stranger'], $response->getData()['recipients']);
		$this->assertNull($this->written);
	}
}//end class
