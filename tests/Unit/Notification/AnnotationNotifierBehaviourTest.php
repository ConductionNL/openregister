<?php

/**
 * Declared notification actions are sanitised, and an owning app's absolute link is kept as given.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

namespace OCA\OpenRegister\Tests\Unit\Notification;

use OCA\OpenRegister\Notification\AnnotationNotifier;
use OCA\OpenRegister\Notifications\Service\ObjectDeepLinks;
use OCA\OpenRegister\Service\DeepLinkResolverInterface;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\IAction;
use OCP\Notification\INotification;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Behaviour of AnnotationNotifier on declared actions and owned links.
 */
class AnnotationNotifierBehaviourTest extends TestCase
{

    private const BASE = 'https://cloud.example.org';

    /**
     * Rendered actions as label => [parsedLabel, link, method, primary].
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: bool}>
     */
    private array $actions = [];

    private ?string $link = null;


    protected function setUp(): void
    {
        parent::setUp();
        $this->actions = [];
        $this->link    = null;

    }//end setUp()


    public function testIdentifiesAsTheOpenRegisterNotifier(): void
    {
        $notifier = $this->notifier(deepLinks: null);

        $this->assertSame('openregister', $notifier->getID());
        $this->assertSame('OpenRegister', $notifier->getName());

    }//end testIdentifiesAsTheOpenRegisterNotifier()


    public function testMalformedDeclaredActionsAreSkippedOrNormalised(): void
    {
        $params = [
            'objectTitle' => 'Case 1',
            '_actions'    => [
                'not-an-action',
                ['url' => ''],
                ['url' => 'https://elsewhere.example/a', 'label' => 'not-a-map', 'method' => 'patch'],
                ['url' => '/apps/x/approve', 'label' => ['de' => 'Genehmigen', 'nl' => 'Goedkeuren'], 'method' => 'post', 'primary' => true],
                ['url' => '/apps/x/reject', 'label' => ['de' => 'Ablehnen']],
            ],
        ];

        $this->notifier(deepLinks: null)->prepare($this->notification(params: $params), 'nl');

        $this->assertSame(
            [
                'action-0' => ['Open', 'https://elsewhere.example/a', 'GET', false],
                'action-1' => ['Goedkeuren', self::BASE.'/apps/x/approve', 'POST', true],
                'action-2' => ['Ablehnen', self::BASE.'/apps/x/reject', 'GET', false],
            ],
            $this->actions
        );

    }//end testMalformedDeclaredActionsAreSkippedOrNormalised()


    public function testOnlyUnusableDeclaredActionsFallBackToTheViewAction(): void
    {
        $params = [
            'registerId' => '1',
            'schemaId'   => '2',
            'objectUuid' => 'u-1',
            '_actions'   => ['junk', ['url' => '']],
        ];
        $deepLinks = $this->createMock(DeepLinkResolverInterface::class);
        $deepLinks->method('resolveUrl')->willReturn(null);

        $this->notifier(deepLinks: $deepLinks)->prepare($this->notification(params: $params), 'en');

        $this->assertSame(['view'], array_keys($this->actions));
        $this->assertSame(self::BASE.'/index.php/apps/openregister/objects/1/2/u-1', $this->actions['view'][1]);

    }//end testOnlyUnusableDeclaredActionsFallBackToTheViewAction()


    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function ownedLinks(): array
    {
        return [
            'https link kept'        => ['https://pipelinq.example/client/u-1', 'https://pipelinq.example/client/u-1'],
            'http link kept'         => ['http://pipelinq.example/client/u-1', 'http://pipelinq.example/client/u-1'],
            'relative link absolute' => ['/apps/pipelinq/client/u-1', self::BASE.'/apps/pipelinq/client/u-1'],
        ];

    }//end ownedLinks()


    #[DataProvider('ownedLinks')]
    public function testOwnedLinkIsUsedAndMadeAbsoluteOnlyWhenRelative(string $resolved, string $expected): void
    {
        $deepLinks = $this->createMock(DeepLinkResolverInterface::class);
        $deepLinks->expects($this->once())->method('resolveUrl')
            ->with(7, 12, ['uuid' => 'u-1', 'id' => 'u-1'])
            ->willReturn($resolved);
        $params = ['registerId' => '7', 'schemaId' => '12', 'objectUuid' => 'u-1'];

        $this->notifier(deepLinks: $deepLinks)->prepare($this->notification(params: $params), 'en');

        $this->assertSame($expected, $this->link);
        $this->assertSame(['View', $expected, 'GET', true], $this->actions['view']);

    }//end testOwnedLinkIsUsedAndMadeAbsoluteOnlyWhenRelative()


    private function notifier(?DeepLinkResolverInterface $deepLinks): AnnotationNotifier
    {
        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);
        $factory = $this->createMock(IFactory::class);
        $factory->method('get')->willReturn($l10n);

        $urls = $this->createMock(IURLGenerator::class);
        $urls->method('imagePath')->willReturn('/apps/openregister/img/app.svg');
        $urls->method('getAbsoluteURL')->willReturnCallback(static fn (string $url): string => self::BASE.$url);
        $urls->method('linkToRouteAbsolute')->willReturn(self::BASE.'/index.php/apps/openregister/');

        return new AnnotationNotifier($factory, new ObjectDeepLinks($urls, $deepLinks));

    }//end notifier()


    /**
     * @param array<string, mixed> $params Subject parameters
     */
    private function notification(array $params): INotification
    {
        $notification = $this->createMock(INotification::class);
        $notification->method('getApp')->willReturn('openregister');
        $notification->method('getSubject')->willReturn('object_created');
        $notification->method('getSubjectParameters')->willReturn($params);
        $notification->method('setLink')->willReturnCallback(
            function (string $link) use ($notification): INotification {
                $this->link = $link;
                return $notification;
            }
        );
        $notification->method('createAction')->willReturnCallback(fn (): IAction => $this->action());
        $notification->method('addParsedAction')->willReturnCallback(
            function (IAction $action) use ($notification): INotification {
                $this->actions[$action->getLabel()] = [
                    $action->getParsedLabel(),
                    $action->getLink(),
                    $action->getRequestType(),
                    $action->isPrimary(),
                ];
                return $notification;
            }
        );
        return $notification;

    }//end notification()


    private function action(): IAction
    {
        $state  = new \ArrayObject(['label' => '', 'parsed' => '', 'primary' => false, 'link' => '', 'type' => '']);
        $action = $this->createMock(IAction::class);
        $action->method('setLabel')->willReturnCallback(
            static function (string $label) use ($state, &$action): IAction {
                $state['label'] = $label;
                return $action;
            }
        );
        $action->method('setParsedLabel')->willReturnCallback(
            static function (string $label) use ($state, &$action): IAction {
                $state['parsed'] = $label;
                return $action;
            }
        );
        $action->method('setPrimary')->willReturnCallback(
            static function (bool $primary) use ($state, &$action): IAction {
                $state['primary'] = $primary;
                return $action;
            }
        );
        $action->method('setLink')->willReturnCallback(
            static function (string $link, string $type) use ($state, &$action): IAction {
                $state['link'] = $link;
                $state['type'] = $type;
                return $action;
            }
        );
        $action->method('getLabel')->willReturnCallback(static fn (): string => $state['label']);
        $action->method('getParsedLabel')->willReturnCallback(static fn (): string => $state['parsed']);
        $action->method('isPrimary')->willReturnCallback(static fn (): bool => $state['primary']);
        $action->method('getLink')->willReturnCallback(static fn (): string => $state['link']);
        $action->method('getRequestType')->willReturnCallback(static fn (): string => $state['type']);
        return $action;

    }//end action()
}//end class
