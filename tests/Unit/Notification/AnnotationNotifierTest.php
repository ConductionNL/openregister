<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Notification;

use OCA\OpenRegister\Notification\AnnotationNotifier;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\IAction;
use OCP\Notification\INotification;
use OCP\Notification\UnknownNotificationException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Covers the rich rendering of annotation/object-lifecycle notifications:
 * localised subject (custom _text wins, else canonical), object-detail action
 * link, and declining subjects this notifier does not own.
 */
class AnnotationNotifierTest extends TestCase {
	private IFactory&MockObject $factory;
	private IURLGenerator&MockObject $urlGenerator;
	private AnnotationNotifier $notifier;

	protected function setUp(): void {
		parent::setUp();
		$this->factory = $this->createMock(IFactory::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->notifier = new AnnotationNotifier($this->factory, $this->urlGenerator);
	}

	public function testGetId(): void {
		$this->assertSame('openregister', $this->notifier->getID());
	}

	public function testThrowsForWrongApp(): void {
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn('other_app');

		$this->expectException(UnknownNotificationException::class);
		$this->notifier->prepare($notification, 'en');
	}

	public function testDeclinesUnownedSubjectWithoutText(): void {
		// configuration_update_available is rendered by Notifier, not here —
		// with no _text and a non-object subject this notifier must decline
		// so the manager passes it on untouched.
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn('openregister');
		$notification->method('getSubject')->willReturn('configuration_update_available');
		$notification->method('getSubjectParameters')->willReturn([]);

		$this->expectException(UnknownNotificationException::class);
		$this->notifier->prepare($notification, 'en');
	}

	public function testRendersCustomTextWhenPresent(): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$this->factory->method('get')->willReturn($l10n);
		$this->urlGenerator->method('imagePath')->willReturn('/apps/openregister/img/app.svg');
		$this->urlGenerator->method('linkToRouteAbsolute')->willReturn('https://example.com/apps/openregister');

		$action = $this->createMock(IAction::class);
		$action->method('setLabel')->willReturnSelf();
		$action->method('setPrimary')->willReturnSelf();
		$action->method('setLink')->willReturnSelf();

		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn('openregister');
		$notification->method('getSubject')->willReturn('object_created');
		$notification->method('getSubjectParameters')->willReturn([
			'_text' => 'Nieuwe lead: Acme',
			'registerId' => 'r',
			'schemaId' => 's',
			'objectUuid' => 'uuid-1',
		]);
		$notification->method('createAction')->willReturn($action);
		$notification->method('setIcon')->willReturnSelf();
		$notification->method('addAction')->willReturnSelf();

		// Custom _text is shown verbatim; a deep-link action is added.
		$notification->expects($this->once())->method('setParsedSubject')->with('Nieuwe lead: Acme')->willReturnSelf();
		$notification->expects($this->once())->method('addAction');

		$result = $this->notifier->prepare($notification, 'nl');
		$this->assertSame($notification, $result);
	}

	public function testSetsParsedMessageWhenPresent(): void {
		// A non-empty `_message` becomes the notification body via
		// setParsedMessage(); the title still comes from `_text`.
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$this->factory->method('get')->willReturn($l10n);
		$this->urlGenerator->method('imagePath')->willReturn('/apps/openregister/img/app.svg');
		$this->urlGenerator->method('linkToRouteAbsolute')->willReturn('https://example.com/apps/openregister');

		$action = $this->createMock(IAction::class);
		$action->method('setLabel')->willReturnSelf();
		$action->method('setPrimary')->willReturnSelf();
		$action->method('setLink')->willReturnSelf();

		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn('openregister');
		$notification->method('getSubject')->willReturn('object_created');
		$notification->method('getSubjectParameters')->willReturn([
			'_text' => 'Incoming call from Acme',
			'_message' => 'Open in OpenTalk.',
			'registerId' => 'r',
			'schemaId' => 's',
			'objectUuid' => 'uuid-1',
		]);
		$notification->method('createAction')->willReturn($action);
		$notification->method('setIcon')->willReturnSelf();
		$notification->method('addAction')->willReturnSelf();
		$notification->method('setParsedSubject')->willReturnSelf();

		$notification->expects($this->once())->method('setParsedMessage')->with('Open in OpenTalk.')->willReturnSelf();

		$result = $this->notifier->prepare($notification, 'en');
		$this->assertSame($notification, $result);
	}

	public function testDoesNotSetParsedMessageWhenAbsent(): void {
		// Back-compat: no `_message` → setParsedMessage() is never called.
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$this->factory->method('get')->willReturn($l10n);
		$this->urlGenerator->method('imagePath')->willReturn('/apps/openregister/img/app.svg');
		$this->urlGenerator->method('linkToRouteAbsolute')->willReturn('https://example.com/apps/openregister');

		$action = $this->createMock(IAction::class);
		$action->method('setLabel')->willReturnSelf();
		$action->method('setPrimary')->willReturnSelf();
		$action->method('setLink')->willReturnSelf();

		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn('openregister');
		$notification->method('getSubject')->willReturn('object_created');
		$notification->method('getSubjectParameters')->willReturn([
			'_text' => 'Plain subject',
			'registerId' => 'r',
			'schemaId' => 's',
			'objectUuid' => 'uuid-1',
		]);
		$notification->method('createAction')->willReturn($action);
		$notification->method('setIcon')->willReturnSelf();
		$notification->method('addAction')->willReturnSelf();
		$notification->method('setParsedSubject')->willReturnSelf();

		$notification->expects($this->never())->method('setParsedMessage');

		$result = $this->notifier->prepare($notification, 'en');
		$this->assertSame($notification, $result);
	}

	public function testRendersCanonicalLocalizedWhenNoText(): void {
		$l10n = $this->createMock(IL10N::class);
		// Echo back the interpolated template so we can assert substitution.
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $args = []): string => vsprintf($text, $args)
		);
		$this->factory->method('get')->with('openregister', 'en')->willReturn($l10n);
		$this->urlGenerator->method('imagePath')->willReturn('/apps/openregister/img/app.svg');
		$this->urlGenerator->method('linkToRouteAbsolute')->willReturn('https://example.com/apps/openregister');

		$action = $this->createMock(IAction::class);
		$action->method('setLabel')->willReturnSelf();
		$action->method('setPrimary')->willReturnSelf();
		$action->method('setLink')->willReturnSelf();

		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn('openregister');
		$notification->method('getSubject')->willReturn('object_created');
		$notification->method('getSubjectParameters')->willReturn([
			'objectTitle' => 'Acme',
			'registerName' => 'Leads',
			'registerId' => 'r',
			'schemaId' => 's',
			'objectUuid' => 'uuid-1',
		]);
		$notification->method('createAction')->willReturn($action);
		$notification->method('setIcon')->willReturnSelf();
		$notification->method('addAction')->willReturnSelf();

		$notification->expects($this->once())
			->method('setParsedSubject')
			->with('Object "Acme" created in register "Leads"')
			->willReturnSelf();

		$this->notifier->prepare($notification, 'en');
	}
}
