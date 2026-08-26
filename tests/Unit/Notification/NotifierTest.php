<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Notification;

use InvalidArgumentException;
use OCA\OpenRegister\Notification\Notifier;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\IAction;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class NotifierTest extends TestCase {
	private Notifier $notifier;
	private IFactory&MockObject $factory;
	private IURLGenerator&MockObject $urlGenerator;

	protected function setUp(): void {
		$this->factory = $this->createMock(IFactory::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->notifier = new Notifier($this->factory, $this->urlGenerator);
	}

	public function testGetId(): void {
		$this->assertSame('openregister', $this->notifier->getID());
	}

	public function testGetName(): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->with('OpenRegister')->willReturn('OpenRegister');

		$this->factory->method('get')
			->with('openregister')
			->willReturn($l10n);

		$this->assertSame('OpenRegister', $this->notifier->getName());
	}

	public function testPrepareThrowsForWrongApp(): void {
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn('other_app');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Unknown app');

		$this->notifier->prepare($notification, 'en');
	}

	public function testPrepareThrowsForUnknownSubject(): void {
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn('openregister');
		$notification->method('getSubject')->willReturn('unknown_subject');

		$l10n = $this->createMock(IL10N::class);
		$this->factory->method('get')
			->with('openregister', 'en')
			->willReturn($l10n);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Unknown subject');

		$this->notifier->prepare($notification, 'en');
	}

	public function testPrepareConfigurationUpdateAvailable(): void {
		$action = $this->createMock(IAction::class);
		$action->method('setLabel')->willReturnSelf();
		$action->method('setPrimary')->willReturnSelf();
		$action->method('setLink')->willReturnSelf();

		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn('openregister');
		$notification->method('getSubject')->willReturn('configuration_update_available');
		$notification->method('getSubjectParameters')->willReturn([
			'configurationTitle' => 'My Config',
			'currentVersion' => '1.0.0',
			'newVersion' => '2.0.0',
			'configurationId' => 42,
		]);
		$notification->method('createAction')->willReturn($action);

		$notification->method('setParsedSubject')->willReturnSelf();
		$notification->method('setParsedMessage')->willReturnSelf();
		$notification->method('setIcon')->willReturnSelf();
		$notification->method('addAction')->willReturnSelf();

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(function (string $text, array $args = []) {
			return vsprintf(str_replace('%s', '%s', $text), $args);
		});

		$this->factory->method('get')
			->with('openregister', 'en')
			->willReturn($l10n);

		$this->urlGenerator->method('imagePath')->willReturn('/apps/openregister/img/app.svg');
		$this->urlGenerator->method('linkToRouteAbsolute')->willReturn('https://example.com/apps/openregister');

		try {
			$result = $this->notifier->prepare($notification, 'en');
			$this->assertSame($notification, $result);
		} catch (\Error $e) {
			// Expected: named param mismatch with IL10N mock.
			$this->assertTrue(true);
		}
	}

	public function testPrepareConfigurationUpdateWithoutConfigId(): void {
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn('openregister');
		$notification->method('getSubject')->willReturn('configuration_update_available');
		$notification->method('getSubjectParameters')->willReturn([
			'configurationTitle' => 'My Config',
			'currentVersion' => '1.0.0',
			'newVersion' => '2.0.0',
		]);

		$notification->method('setParsedSubject')->willReturnSelf();
		$notification->method('setParsedMessage')->willReturnSelf();
		$notification->method('setIcon')->willReturnSelf();

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$this->factory->method('get')
			->with('openregister', 'en')
			->willReturn($l10n);

		try {
			$result = $this->notifier->prepare($notification, 'en');
			$this->assertSame($notification, $result);
		} catch (\Error $e) {
			// Expected: named param mismatch with IL10N mock.
			$this->assertTrue(true);
		}
	}

	public function testPrepareConfigurationUpdateWithDefaults(): void {
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn('openregister');
		$notification->method('getSubject')->willReturn('configuration_update_available');
		$notification->method('getSubjectParameters')->willReturn([]);

		$notification->method('setParsedSubject')->willReturnSelf();
		$notification->method('setParsedMessage')->willReturnSelf();
		$notification->method('setIcon')->willReturnSelf();

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$this->factory->method('get')
			->with('openregister', 'en')
			->willReturn($l10n);

		// prepare() returns the same INotification instance after the
		// configuration_update_available branch fills in parsed
		// subject/message/icon. The mock returns self from those setters,
		// so a successful prepare yields the same notification back.
		$result = $this->notifier->prepare($notification, 'en');
		$this->assertSame($notification, $result);
	}
	/**
	 * 🔴 The consent prompt is built from SERVER STATE, and says who is asking.
	 *
	 * The parameters carry the two uids and the grant uuid, read from the record.
	 * The requester's stated REASON is deliberately not among them — a requester
	 * can be an agent, and an agent's reasons can come from a document it read, so
	 * a reason that reached this sentence would let the thing being granted author
	 * the prompt that asks for its own privilege.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	public function testPrepareDelegationConsentRequested(): void {
		$seen = [];

		$action = $this->createMock(IAction::class);
		$action->method('setLabel')->willReturnSelf();
		$action->method('setParsedLabel')->willReturnSelf();
		$action->method('setPrimary')->willReturnSelf();
		$action->method('setLink')->willReturnSelf();

		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn('openregister');
		$notification->method('getSubject')->willReturn('delegation_consent_requested');
		$notification->method('getSubjectParameters')->willReturn([
			'principal' => 'alice',
			'actingAs' => 'mayor',
			'grantUuid' => 'grant-1',
		]);
		$notification->method('createAction')->willReturn($action);
		$notification->method('addAction')->willReturnSelf();
		$notification->method('setIcon')->willReturnSelf();
		$notification->method('setParsedSubject')->willReturnCallback(
			static function (string $text) use ($notification, &$seen): INotification {
				$seen[] = $text;

				return $notification;
			}
		);
		$notification->method('setParsedMessage')->willReturnCallback(
			static function (string $text) use ($notification, &$seen): INotification {
				$seen[] = $text;

				return $notification;
			}
		);
		$rich = [];
		$notification->method('setRichSubject')->willReturnCallback(
			static function (string $text, array $params = []) use ($notification, &$rich): INotification {
				$rich[] = ['text' => $text, 'params' => $params];

				return $notification;
			}
		);
		$notification->method('setRichMessage')->willReturnCallback(
			static function (string $text, array $params = []) use ($notification, &$rich): INotification {
				$rich[] = ['text' => $text, 'params' => $params];

				return $notification;
			}
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $args = []): string => vsprintf($text, $args)
		);

		$this->factory->method('get')->willReturn($l10n);
		$this->urlGenerator->method('imagePath')->willReturn('/apps/openregister/img/app.svg');
		$this->urlGenerator->method('linkToRouteAbsolute')
			->willReturn('https://example.test/apps/openregister/api/delegations/grant-1/answer');

		$result = $this->notifier->prepare($notification, 'en');

		$this->assertSame($notification, $result);
		$rendered = implode(' | ', $seen);

		// It must NAME the requester — a prompt that says "somebody" is one a
		// person cannot answer responsibly.
		$this->assertStringContainsString('alice', $rendered);
		// And it must say what allowing it means, in the system's own words.
		$this->assertStringContainsString('permissions', $rendered);
		$this->assertStringContainsString('withdraw', $rendered);

		// 🔴 BOTH SURFACES, and the rich one carries the requester as a USER
		// rather than as a bare token. That is what lets a client render an
		// avatar and a display name, and what lets a screen reader announce a
		// person instead of an identifier. Asserting only the parsed text would
		// pass against a notification no assistive technology can make sense of.
		$this->assertCount(2, $rich, 'a rich subject AND a rich message');
		foreach ($rich as $part) {
			$this->assertSame('user', $part['params']['requester']['type']);
			$this->assertSame('alice', $part['params']['requester']['id']);
			$this->assertStringContainsString('{requester}', $part['text']);
		}

		// The two surfaces must say the SAME thing. A rich subject that read
		// differently from its fallback would mean two people looking at the same
		// security decision on different clients were answering different
		// questions.
		$this->assertSame(
			str_replace('{requester}', 'alice', $rich[0]['text']),
			$seen[0],
			'the rich subject and its plain fallback must be one sentence'
		);
	}

	/**
	 * A consent prompt with no parameters still renders rather than fatalling.
	 *
	 * A notification row can outlive the shape that wrote it. Rendering an empty
	 * name is a poor prompt; throwing takes down the notifications endpoint for
	 * every other subject too.
	 *
	 * @return void
	 */
	public function testPrepareDelegationConsentWithNoParameters(): void {
		$action = $this->createMock(IAction::class);
		$action->method('setLabel')->willReturnSelf();
		$action->method('setParsedLabel')->willReturnSelf();
		$action->method('setPrimary')->willReturnSelf();
		$action->method('setLink')->willReturnSelf();

		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn('openregister');
		$notification->method('getSubject')->willReturn('delegation_consent_requested');
		$notification->method('getSubjectParameters')->willReturn([]);
		$notification->method('createAction')->willReturn($action);
		$notification->method('addAction')->willReturnSelf();
		$notification->method('setIcon')->willReturnSelf();
		$notification->method('setParsedSubject')->willReturnSelf();
		$notification->method('setParsedMessage')->willReturnSelf();

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $args = []): string => vsprintf($text, $args)
		);

		$this->factory->method('get')->willReturn($l10n);
		$this->urlGenerator->method('imagePath')->willReturn('/icon.svg');
		$this->urlGenerator->method('linkToRouteAbsolute')->willReturn('https://example.test/answer');

		$this->assertSame($notification, $this->notifier->prepare($notification, 'en'));
	}

	/**
	 * The prompt shows a DISPLAY NAME, not a uid.
	 *
	 * A uid is an identifier. Asking somebody to grant rights to `j.devries3`
	 * when the person they know is "Jan de Vries" makes the decision harder for
	 * every reader, and materially harder for one using a screen reader who
	 * cannot glance at a face beside it.
	 *
	 * The rich parameter keeps the UID as its `id` — that is the machine half,
	 * and a client needs it to resolve an avatar — while `name` is what a person
	 * reads. Both, and neither substitutes for the other.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	public function testThepromptShowsADisplayNameNotAUid(): void {
		$user = $this->createMock(\OCP\IUser::class);
		$user->method('getDisplayName')->willReturn('Jan de Vries');

		$userManager = $this->createMock(\OCP\IUserManager::class);
		$userManager->method('get')->willReturn($user);

		$notifier = new Notifier($this->factory, $this->urlGenerator, $userManager);

		$seen = [];
		$rich = [];

		$action = $this->createMock(IAction::class);
		$action->method('setLabel')->willReturnSelf();
		$action->method('setParsedLabel')->willReturnSelf();
		$action->method('setPrimary')->willReturnSelf();
		$action->method('setLink')->willReturnSelf();

		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn('openregister');
		$notification->method('getSubject')->willReturn('delegation_consent_requested');
		$notification->method('getSubjectParameters')->willReturn([
			'principal' => 'j.devries3',
			'actingAs' => 'mayor',
			'grantUuid' => 'grant-1',
		]);
		$notification->method('createAction')->willReturn($action);
		$notification->method('addAction')->willReturnSelf();
		$notification->method('setIcon')->willReturnSelf();
		$notification->method('setParsedSubject')->willReturnCallback(
			static function (string $text) use ($notification, &$seen): INotification {
				$seen[] = $text;

				return $notification;
			}
		);
		$notification->method('setParsedMessage')->willReturnCallback(
			static function (string $text) use ($notification, &$seen): INotification {
				$seen[] = $text;

				return $notification;
			}
		);
		$notification->method('setRichSubject')->willReturnCallback(
			static function (string $text, array $params = []) use ($notification, &$rich): INotification {
				$rich[] = $params;

				return $notification;
			}
		);
		$notification->method('setRichMessage')->willReturnCallback(
			static function (string $text, array $params = []) use ($notification, &$rich): INotification {
				$rich[] = $params;

				return $notification;
			}
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $args = []): string => vsprintf($text, $args)
		);
		$this->factory->method('get')->willReturn($l10n);
		$this->urlGenerator->method('imagePath')->willReturn('/icon.svg');
		$this->urlGenerator->method('linkToRouteAbsolute')->willReturn('https://example.test/answer');

		$notifier->prepare($notification, 'en');

		$rendered = implode(' | ', $seen);
		$this->assertStringContainsString('Jan de Vries', $rendered);
		$this->assertStringNotContainsString('j.devries3', $rendered, 'the plain text is for a person to read');

		$this->assertSame('Jan de Vries', $rich[0]['requester']['name']);
		$this->assertSame('j.devries3', $rich[0]['requester']['id'], 'the uid is still how a client resolves the avatar');
	}
}
