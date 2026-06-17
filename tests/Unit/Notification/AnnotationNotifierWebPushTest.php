<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Notification;

use OCA\OpenRegister\Notification\AnnotationNotifier;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\IAction;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Covers the web-push rendering extensions of AnnotationNotifier: declared
 * action buttons rendered via addAction(), the implicit single "View" action
 * kept when no actions are declared, and the originApp-driven hex icon.
 *
 * @spec openspec/changes/openregister-web-push-engine/specs/notificatie-engine/spec.md
 */
class AnnotationNotifierWebPushTest extends TestCase
{
    private IFactory&MockObject $factory;
    private IURLGenerator&MockObject $urlGenerator;
    private AnnotationNotifier $notifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->factory      = $this->createMock(IFactory::class);
        $this->urlGenerator = $this->createMock(IURLGenerator::class);
        $this->notifier     = new AnnotationNotifier($this->factory, $this->urlGenerator);

        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);
        $this->factory->method('get')->willReturn($l10n);
    }

    /**
     * Build a chainable IAction mock that records the label it is given.
     *
     * @param array<int, string> $labels Capture sink for setLabel() calls.
     *
     * @return IAction&MockObject The action mock.
     */
    private function recordingAction(array &$labels): IAction&MockObject
    {
        $action = $this->createMock(IAction::class);
        $action->method('setLabel')->willReturnCallback(
            static function (string $label) use (&$labels, $action): IAction {
                $labels[] = $label;
                return $action;
            }
        );
        $action->method('setPrimary')->willReturnSelf();
        $action->method('setLink')->willReturnSelf();
        return $action;
    }

    public function testRendersDeclaredActionsViaAddAction(): void
    {
        $this->urlGenerator->method('linkToRouteAbsolute')->willReturn('https://example-host.test/');
        $this->urlGenerator->method('imagePath')->willReturn('/apps/openregister/img/app.svg');

        $labels = [];
        $action = $this->recordingAction($labels);

        $notification = $this->createMock(INotification::class);
        $notification->method('getApp')->willReturn('openregister');
        $notification->method('getSubject')->willReturn('object_created');
        $notification->method('getSubjectParameters')->willReturn([
            '_text'     => 'New lead: Acme',
            'originApp' => 'pipelinq',
            '_actions'  => [
                [
                    'label'   => ['en' => 'Open client', 'nl' => 'Open klant'],
                    'primary' => true,
                    'url'     => 'https://example-host.test/apps/pipelinq/#/clients/1',
                ],
            ],
        ]);
        $notification->method('createAction')->willReturn($action);
        $notification->method('setIcon')->willReturnSelf();
        $notification->method('setParsedSubject')->willReturnSelf();

        // Exactly the single declared action is added (no implicit "View").
        $notification->expects($this->once())->method('addAction')->with($action);

        $this->notifier->prepare($notification, 'en');
        $this->assertSame(['Open client'], $labels);
    }

    public function testKeepsImplicitViewWhenNoActionsDeclared(): void
    {
        $this->urlGenerator->method('linkToRouteAbsolute')->willReturn('https://example-host.test/');
        $this->urlGenerator->method('imagePath')->willReturn('/apps/openregister/img/app.svg');

        $labels = [];
        $action = $this->recordingAction($labels);

        $notification = $this->createMock(INotification::class);
        $notification->method('getApp')->willReturn('openregister');
        $notification->method('getSubject')->willReturn('object_created');
        $notification->method('getSubjectParameters')->willReturn([
            '_text'      => 'Object changed',
            'registerId' => 'r',
            'schemaId'   => 's',
            'objectUuid' => 'uuid-1',
        ]);
        $notification->method('createAction')->willReturn($action);
        $notification->method('setIcon')->willReturnSelf();
        $notification->method('setParsedSubject')->willReturnSelf();

        // The implicit "View" action is added exactly once.
        $notification->expects($this->once())->method('addAction')->with($action);

        $this->notifier->prepare($notification, 'en');
        $this->assertSame(['View'], $labels);
    }

    public function testIconIsSetFromOriginApp(): void
    {
        $this->urlGenerator->method('imagePath')->willReturn('/apps/openregister/img/app.svg');
        $this->urlGenerator->expects($this->once())
            ->method('linkToRouteAbsolute')
            ->with('openregister.webPush.hexIcon', ['app' => 'pipelinq'])
            ->willReturn('https://example-host.test/apps/openregister/webpush/icon/pipelinq');

        $action = $this->createMock(IAction::class);
        $action->method('setLabel')->willReturnSelf();
        $action->method('setPrimary')->willReturnSelf();
        $action->method('setLink')->willReturnSelf();

        $notification = $this->createMock(INotification::class);
        $notification->method('getApp')->willReturn('openregister');
        $notification->method('getSubject')->willReturn('object_created');
        $notification->method('getSubjectParameters')->willReturn([
            '_text'     => 'New lead: Acme',
            'originApp' => 'pipelinq',
        ]);
        $notification->method('createAction')->willReturn($action);
        $notification->method('setParsedSubject')->willReturnSelf();
        $notification->method('addAction')->willReturnSelf();

        // The icon points at the originApp hex-composite route, not app.svg.
        $notification->expects($this->once())
            ->method('setIcon')
            ->with('https://example-host.test/apps/openregister/webpush/icon/pipelinq')
            ->willReturnSelf();

        $this->notifier->prepare($notification, 'en');
    }
}
