<?php

/**
 * Unit tests for DecisionVisibility: who may see a decision taken under a hide-others review policy.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Decision
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Decision;

use OCA\OpenRegister\Db\Decision;
use OCA\OpenRegister\Db\EntityRelation;
use OCA\OpenRegister\Event\DecisionVisibilityRequestedEvent;
use OCA\OpenRegister\Service\Decision\DecisionService;
use OCA\OpenRegister\Service\Decision\DecisionVisibility;
use OCA\OpenRegister\Service\Decision\DecisionVisibilityPlatform;
use OCA\OpenRegister\Service\Decision\ReviewPolicy;
use OCA\OpenRegister\Service\Decision\ReviewPolicyResolver;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IGroupManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Decision\DecisionVisibility
 * @uses   \OCA\OpenRegister\Service\Decision\DecisionVisibilityPlatform
 * @uses   \OCA\OpenRegister\Service\Decision\ReviewPolicy
 * @uses   \OCA\OpenRegister\Event\DecisionVisibilityRequestedEvent
 * @uses   \OCA\OpenRegister\Event\AbstractDecisionRequestEvent
 * @uses   \OCA\OpenRegister\Db\Decision
 * @uses   \OCA\OpenRegister\Db\EntityRelation
 * @uses   \OCA\OpenRegister\Db\TypedFieldsJson
 * @uses \OCA\OpenRegister\Service\Decision\DecisionQuestion
 */
class DecisionVisibilityTest extends TestCase
{
    /** @var ReviewPolicyResolver&MockObject */
    private ReviewPolicyResolver $resolver;

    /** @var DecisionService&MockObject */
    private DecisionService $decisions;

    /** @var IGroupManager&MockObject */
    private IGroupManager $groups;

    /** @var IEventDispatcher&MockObject */
    private IEventDispatcher $dispatcher;

    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    /** @var list<DecisionVisibilityRequestedEvent> */
    private array $events = [];

    protected function setUp(): void
    {
        $this->resolver   = $this->createMock(ReviewPolicyResolver::class);
        $this->decisions  = $this->createMock(DecisionService::class);
        $this->groups     = $this->createMock(IGroupManager::class);
        $this->dispatcher = $this->createMock(IEventDispatcher::class);
        $this->logger     = $this->createMock(LoggerInterface::class);
    }//end setUp()

    private function visibility(): DecisionVisibility
    {
        return new DecisionVisibility(
            $this->resolver,
            $this->decisions,
            $this->groups,
            new DecisionVisibilityPlatform($this->dispatcher, $this->logger)
        );
    }//end visibility()

    private function relation(?string $decisionUuid): EntityRelation
    {
        $relation = new EntityRelation();
        $relation->setDecisionUuid($decisionUuid);
        return $relation;
    }//end relation()

    private function decidedBy(string $userId): Decision
    {
        $decision = new Decision();
        $decision->setUuid('dec-1');
        $decision->setDecidedBy($userId);
        return $decision;
    }//end decidedBy()

    /**
     * @param array<string, mixed> $declaration
     */
    private function policy(array $declaration): void
    {
        $this->resolver->method('forRelation')->willReturn(
            ['policy' => ReviewPolicy::fromDeclaration(declaration: $declaration), 'subject' => ['subjectId' => '7']]
        );
    }//end policy()

    private function hiding(array $supervisors = []): void
    {
        $this->policy(['ownership' => 'decider', 'hideOthers' => true, 'supervisors' => $supervisors]);
    }//end hiding()

    private function listenerAnswers(bool $allow): void
    {
        $this->dispatcher->method('dispatchTyped')->willReturnCallback(
            function (DecisionVisibilityRequestedEvent $event) use ($allow): void {
                $this->events[] = $event;
                if ($allow === true) {
                    $event->allow();
                }
            }
        );
    }//end listenerAnswers()

    public function testARelationWithoutADecisionIsNeverHiddenAndNoPolicyIsRead(): void
    {
        $this->resolver->expects($this->never())->method('forRelation');

        $this->assertFalse($this->visibility()->isHidden($this->relation(null), 'bob'));
        $this->assertFalse($this->visibility()->isHidden($this->relation(''), 'bob'));
    }//end testARelationWithoutADecisionIsNeverHiddenAndNoPolicyIsRead()

    public function testAPolicyThatDoesNotHideOthersShowsTheDecision(): void
    {
        $this->policy(['ownership' => 'decider', 'hideOthers' => false]);
        $this->decisions->expects($this->never())->method('find');

        $this->assertFalse($this->visibility()->isHidden($this->relation('dec-1'), 'bob'));
    }//end testAPolicyThatDoesNotHideOthersShowsTheDecision()

    public function testAnAnonymousCallerIsHiddenFromWithoutAsking(): void
    {
        $this->hiding();
        $this->decisions->expects($this->never())->method('find');
        $this->dispatcher->expects($this->never())->method('dispatchTyped');

        $this->assertTrue($this->visibility()->isHidden($this->relation('dec-1'), ''));
    }//end testAnAnonymousCallerIsHiddenFromWithoutAsking()

    public function testTheDeciderSeesTheirOwnDecision(): void
    {
        $this->hiding();
        $this->decisions->method('find')->with('dec-1')->willReturn($this->decidedBy('alice'));
        $this->groups->expects($this->never())->method('isAdmin');

        $this->assertFalse($this->visibility()->isHidden($this->relation('dec-1'), 'alice'));
    }//end testTheDeciderSeesTheirOwnDecision()

    public function testAnAdminSeesAnotherReviewersDecision(): void
    {
        $this->hiding();
        $this->decisions->method('find')->willReturn($this->decidedBy('alice'));
        $this->groups->method('isAdmin')->with('root')->willReturn(true);
        $this->dispatcher->expects($this->never())->method('dispatchTyped');

        $this->assertFalse($this->visibility()->isHidden($this->relation('dec-1'), 'root'));
    }//end testAnAdminSeesAnotherReviewersDecision()

    public function testAMemberOfASupervisorGroupSeesTheDecision(): void
    {
        $this->hiding(['privacy-officers', 'legal']);
        $this->decisions->method('find')->willReturn($this->decidedBy('alice'));
        $this->groups->method('isAdmin')->willReturn(false);
        $this->groups->method('isInGroup')->willReturnCallback(
            static fn(string $user, string $group): bool => $user === 'carol' && $group === 'legal'
        );
        $this->dispatcher->expects($this->never())->method('dispatchTyped');

        $this->assertFalse($this->visibility()->isHidden($this->relation('dec-1'), 'carol'));
    }//end testAMemberOfASupervisorGroupSeesTheDecision()

    public function testAnotherReviewerIsHiddenFromWhenNoListenerAllows(): void
    {
        $this->hiding(['legal']);
        $decision = $this->decidedBy('alice');
        $this->decisions->method('find')->willReturn($decision);
        $this->groups->method('isAdmin')->willReturn(false);
        $this->groups->method('isInGroup')->willReturn(false);
        $this->listenerAnswers(allow: false);

        $this->assertTrue($this->visibility()->isHidden($this->relation('dec-1'), 'bob'));
        $this->assertCount(1, $this->events);
        $this->assertSame('bob', $this->events[0]->getActorId());
        $this->assertSame(['subjectId' => '7'], $this->events[0]->getSubject());
        $this->assertSame($decision, $this->events[0]->getDecision());
    }//end testAnotherReviewerIsHiddenFromWhenNoListenerAllows()

    public function testAListenerMayShowTheDecisionEvenWhenItIsNoLongerFound(): void
    {
        $this->hiding();
        $this->decisions->method('find')->willReturn(null);
        $this->groups->method('isAdmin')->willReturn(false);
        $this->listenerAnswers(allow: true);

        $this->assertFalse($this->visibility()->isHidden($this->relation('dec-1'), 'bob'));
        $this->assertNull($this->events[0]->getDecision());
    }//end testAListenerMayShowTheDecisionEvenWhenItIsNoLongerFound()

    public function testAFailingListenerKeepsTheDecisionHiddenAndIsLogged(): void
    {
        $this->hiding();
        $this->decisions->method('find')->willReturn($this->decidedBy('alice'));
        $this->groups->method('isAdmin')->willReturn(false);
        $this->dispatcher->method('dispatchTyped')->willThrowException(new RuntimeException('listener broke'));
        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                '[DecisionVisibility] A listener failed; the decision stays hidden.',
                ['decision' => 'dec-1', 'actor' => 'bob', 'error' => 'listener broke']
            );

        $this->assertTrue($this->visibility()->isHidden($this->relation('dec-1'), 'bob'));
    }//end testAFailingListenerKeepsTheDecisionHiddenAndIsLogged()

    public function testPresentMasksOnlyTheDecisionFieldsThatArePresent(): void
    {
        $this->hiding();
        $this->decisions->method('find')->willReturn($this->decidedBy('alice'));
        $this->groups->method('isAdmin')->willReturn(false);
        $this->listenerAnswers(allow: false);

        $presented = $this->visibility()->present(
            $this->relation('dec-1'),
            'bob',
            ['id' => 3, 'decision' => 'dec-1', 'bases' => ['art6'], 'skipAnonymization' => true, 'anonymized' => false, 'role' => 'name']
        );

        $this->assertSame(
            ['id' => 3, 'decision' => DecisionVisibility::HIDDEN, 'bases' => null, 'skipAnonymization' => null, 'anonymized' => null, 'role' => 'name'],
            $presented
        );
    }//end testPresentMasksOnlyTheDecisionFieldsThatArePresent()

    public function testPresentLeavesAVisibleRelationUntouched(): void
    {
        $this->policy([]);
        $given = ['decision' => 'dec-1', 'bases' => ['art6']];

        $this->assertSame($given, $this->visibility()->present($this->relation('dec-1'), 'bob', $given));
    }//end testPresentLeavesAVisibleRelationUntouched()

    public function testPresentAllSerialisesEachRelationAndMasksOnlyTheHiddenOnes(): void
    {
        $this->hiding();
        $this->decisions->method('find')->willReturn($this->decidedBy('alice'));
        $this->groups->method('isAdmin')->willReturn(false);
        $this->listenerAnswers(allow: false);
        $open   = $this->relation(null);
        $hidden = $this->relation('dec-1');
        $hidden->setBases(['art9']);

        $presented = $this->visibility()->presentAll([$open, $hidden], 'bob');

        $this->assertCount(2, $presented);
        $this->assertNull($presented[0]['decision']);
        $this->assertSame(DecisionVisibility::HIDDEN, $presented[1]['decision']);
        $this->assertNull($presented[1]['bases']);
        $this->assertSame([], $this->visibility()->presentAll([], 'bob'));
    }//end testPresentAllSerialisesEachRelationAndMasksOnlyTheHiddenOnes()
}//end class
