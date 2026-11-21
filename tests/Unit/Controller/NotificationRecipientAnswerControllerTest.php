<?php

/**
 * The administrator's per-recipient notification answer over HTTP.
 *
 * The method carries no `#[NoAdminRequired]`, so Nextcloud's middleware
 * refuses a caller who is not an administrator before the controller runs;
 * one test pins that posture by reflection, because it is the only thing that
 * keeps an ordinary user from reading somebody else's preferences.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
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
 * @spec openspec/changes/notification-kinds-an-administrator-forces/specs/notificatie-engine/spec.md#requirement-an-administrator-can-read-what-one-recipient-will-get-for-one-kind-req-nkf-003
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Notifications\Controller\NotificationRecipientAnswerController;
use OCA\OpenRegister\Notifications\Service\Notification\RecipientKindAnswer;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\IRequest;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * @covers \OCA\OpenRegister\Notifications\Controller\NotificationRecipientAnswerController
 *
 * @spec openspec/changes/notification-kinds-an-administrator-forces/specs/notificatie-engine/spec.md#requirement-an-administrator-can-read-what-one-recipient-will-get-for-one-kind-req-nkf-003
 */
class NotificationRecipientAnswerControllerTest extends TestCase {

	/**
	 * The answer service double.
	 *
	 * @var RecipientKindAnswer&MockObject
	 */
	private RecipientKindAnswer $answers;

	/**
	 * The user manager double.
	 *
	 * @var IUserManager&MockObject
	 */
	private IUserManager $users;

	protected function setUp(): void {
		parent::setUp();
		$this->answers = $this->createMock(RecipientKindAnswer::class);
		$this->users = $this->createMock(IUserManager::class);
	}//end setUp()

	public function testTheAnswerIsReturnedForAKnownUserAndDeclaredKind(): void {
		$answer = ['user' => 'behandelaar', 'channels' => ['nc-notification'], 'layer' => 'group-default'];
		$this->users->method('userExists')->with('behandelaar')->willReturn(true);
		$this->answers->expects($this->once())->method('answer')
			->with('behandelaar', 'zaak', 'assigned', ['register:zaken'])
			->willReturn($answer);

		$response = $this->controller(['user' => ' behandelaar ', 'schema' => 'zaak', 'notification' => 'assigned', 'scope' => 'register:zaken'])->show();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame($answer, $response->getData());
	}//end testTheAnswerIsReturnedForAKnownUserAndDeclaredKind()

	public function testWithoutAScopeTheGlobalPreferenceIsRead(): void {
		$this->users->method('userExists')->willReturn(true);
		$this->answers->expects($this->once())->method('answer')->with('u', 'zaak', 'assigned', [])->willReturn(['user' => 'u']);

		$this->assertSame(200, $this->controller(['user' => 'u', 'schema' => 'zaak', 'notification' => 'assigned', 'scope' => ''])->show()->getStatus());
	}//end testWithoutAScopeTheGlobalPreferenceIsRead()

	public function testAMissingParameterIs422AndNothingIsRead(): void {
		$this->users->expects($this->never())->method('userExists');
		$this->answers->expects($this->never())->method('answer');

		foreach ([['schema' => 'zaak', 'notification' => 'n'], ['user' => 'u', 'notification' => 'n'], ['user' => 'u', 'schema' => 'zaak', 'notification' => ['n']]] as $params) {
			$response = $this->controller($params)->show();
			$this->assertSame(422, $response->getStatus());
			$this->assertSame(['error' => '"user", "schema" and "notification" are all required'], $response->getData());
		}
	}//end testAMissingParameterIs422AndNothingIsRead()

	public function testAnUnknownUserIs404(): void {
		$this->users->method('userExists')->with('ghost')->willReturn(false);
		$this->answers->expects($this->never())->method('answer');

		$response = $this->controller(['user' => 'ghost', 'schema' => 'zaak', 'notification' => 'assigned'])->show();

		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['error' => 'Unknown user'], $response->getData());
	}//end testAnUnknownUserIs404()

	public function testAKindNoSchemaDeclaresIs404(): void {
		$this->users->method('userExists')->willReturn(true);
		$this->answers->method('answer')->willReturn(null);

		$response = $this->controller(['user' => 'u', 'schema' => 'zaak', 'notification' => 'unknown'])->show();

		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['error' => 'No schema declares this notification'], $response->getData());
	}//end testAKindNoSchemaDeclaresIs404()

	public function testOnlyAnAdministratorReachesTheRead(): void {
		$method = new ReflectionMethod(NotificationRecipientAnswerController::class, 'show');

		$this->assertSame([], $method->getAttributes(NoAdminRequired::class));
		$this->assertStringNotContainsString('@NoAdminRequired', (string)$method->getDocComment());
		$this->assertCount(1, $method->getAttributes(NoCSRFRequired::class));
	}//end testOnlyAnAdministratorReachesTheRead()

	/**
	 * The controller over request parameters.
	 *
	 * @param array<string, mixed> $params The query parameters.
	 *
	 * @return NotificationRecipientAnswerController
	 */
	private function controller(array $params): NotificationRecipientAnswerController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default)
		);

		return new NotificationRecipientAnswerController(request: $request, answers: $this->answers, users: $this->users);
	}//end controller()
}//end class
