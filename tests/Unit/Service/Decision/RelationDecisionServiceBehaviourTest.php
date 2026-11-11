<?php

/**
 * Behaviour tests for RelationDecisionService::decide(): ownership, successor decisions, notes and rollback.
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
use OCA\OpenRegister\Db\DecisionMetadataEdit;
use OCA\OpenRegister\Db\EntityRelation;
use OCA\OpenRegister\Exception\CustomValidationException;
use OCA\OpenRegister\Exception\DecisionConflictException;
use OCA\OpenRegister\Files\Db\EntityRelationMapperInterface;
use OCA\OpenRegister\Service\Decision\DecisionOwnedException;
use OCA\OpenRegister\Service\Decision\DecisionOwnership;
use OCA\OpenRegister\Service\Decision\DecisionQuestion;
use OCA\OpenRegister\Service\Decision\DecisionService;
use OCA\OpenRegister\Service\Decision\RelationDecisionService;
use OCA\OpenRegister\Service\Decision\RelationDecisionStores;
use OCA\OpenRegister\Service\Decision\ReviewPolicy;
use OCA\OpenRegister\Service\Decision\ReviewPolicyResolver;
use OCP\IDBConnection;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Decision\RelationDecisionService
 * @uses   \OCA\OpenRegister\Service\Decision\RelationDecisionStores
 * @uses   \OCA\OpenRegister\Service\Decision\DecisionOwnedException
 * @uses   \OCA\OpenRegister\Service\Decision\ReviewPolicy
 * @uses   \OCA\OpenRegister\Exception\CustomValidationException
 * @uses   \OCA\OpenRegister\Db\Decision
 * @uses   \OCA\OpenRegister\Db\EntityRelation
 */
class RelationDecisionServiceBehaviourTest extends TestCase
{
    /** @var EntityRelationMapperInterface&MockObject */
    private EntityRelationMapperInterface $relations;

    /** @var DecisionService&MockObject */
    private DecisionService $decisions;

    /** @var IDBConnection&MockObject */
    private IDBConnection $db;

    /** @var DecisionOwnership&MockObject */
    private DecisionOwnership $ownership;

    /** @var ReviewPolicyResolver&MockObject */
    private ReviewPolicyResolver $policies;

    /** @var list<string> Transaction calls in order. */
    private array $tx = [];

    /** @var list<Decision> */
    private array $recorded = [];

    /** @var list<array{0: string, 1: ?string, 2: bool}> */
    private array $links = [];

    protected function setUp(): void
    {
        $this->relations = $this->createMock(EntityRelationMapperInterface::class);
        $this->decisions = $this->createMock(DecisionService::class);
        $this->db        = $this->createMock(IDBConnection::class);
        $this->ownership = $this->createMock(DecisionOwnership::class);
        $this->policies  = $this->createMock(ReviewPolicyResolver::class);
        foreach (['beginTransaction', 'commit', 'rollBack'] as $call) {
            $this->db->method($call)->willReturnCallback(
                function () use ($call): bool {
                    $this->tx[] = $call;
                    return true;
                }
            );
        }

        $this->policies->method('forRelation')->willReturn(
            ['policy' => ReviewPolicy::fromDeclaration(declaration: ['ownership' => 'decider']), 'subject' => ['subjectId' => '5']]
        );
        $this->relations->method('updateDecisionMetadata')->willReturnCallback(
            static function (DecisionMetadataEdit $edit): EntityRelation {
                [$relation, $fields] = [$edit->relation, $edit->fields];
                if (array_key_exists('bases', $fields) === true) {
                    $relation->setBases($fields['bases']);
                }

                if (array_key_exists('skipAnonymization', $fields) === true) {
                    $relation->setSkipAnonymization($fields['skipAnonymization']);
                }

                return $relation;
            }
        );
        $this->decisions->method('record')->willReturnCallback(
            function (Decision $decision): Decision {
                $decision->setUuid('new-'.(count($this->recorded) + 1));
                $this->recorded[] = $decision;
                return $decision;
            }
        );
    }//end setUp()

    private function linkSucceeds(): void
    {
        $this->relations->method('linkDecision')->willReturnCallback(
            function (EntityRelation $relation, string $uuid, ?string $expected, bool $guarded): EntityRelation {
                $this->links[] = [$uuid, $expected, $guarded];
                $relation->setDecisionUuid($uuid);
                return $relation;
            }
        );
    }//end linkSucceeds()

    private function service(): RelationDecisionService
    {
        return new RelationDecisionService(
            new RelationDecisionStores($this->relations, $this->decisions, $this->db),
            $this->ownership,
            $this->policies
        );
    }//end service()

    private function relation(?string $decisionUuid, ?array $bases = null, bool $skip = false): EntityRelation
    {
        $relation = new EntityRelation();
        $relation->setId(5);
        $relation->setDecisionUuid($decisionUuid);
        $relation->setBases($bases);
        $relation->setSkipAnonymization($skip);
        return $relation;
    }//end relation()

    private function actor(string $uid): IUser
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        return $user;
    }//end actor()

    private function current(string $decidedBy, ?string $note): Decision
    {
        $decision = new Decision();
        $decision->setUuid('cur-1');
        $decision->setDecidedBy($decidedBy);
        $decision->setNote($note);
        return $decision;
    }//end current()

    public function testAFirstDecisionRecordsARedactVerdictWithTrimmedNoteAndLinksItGuarded(): void
    {
        $this->decisions->method('find')->with(null)->willReturn(null);
        $this->ownership->expects($this->never())->method('refusal');
        $this->linkSucceeds();

        $result = $this->service()->decide(
            $this->relation(null),
            ['bases' => ['art6'], 'decisionNote' => '  checked  '],
            $this->actor('alice')
        );

        $this->assertCount(1, $this->recorded);
        $decision = $this->recorded[0];
        $this->assertSame(
            ['entity-relation', '5', 'redact', 'alice', 'checked', null],
            [$decision->getSubjectType(), $decision->getSubjectId(), $decision->getVerdict(), $decision->getDecidedBy(), $decision->getNote(), $decision->getSupersedes()]
        );
        $this->assertSame([['new-1', null, true]], $this->links);
        $this->assertSame(['beginTransaction', 'commit'], $this->tx);
        $this->assertSame($decision, $result['decision']);
        $this->assertSame('new-1', $result['relation']->getDecisionUuid());
    }//end testAFirstDecisionRecordsARedactVerdictWithTrimmedNoteAndLinksItGuarded()

    public function testAnEmptyFirstBodyRecordsNothing(): void
    {
        $this->decisions->method('find')->willReturn(null);
        $this->decisions->expects($this->never())->method('record');
        $this->relations->expects($this->never())->method('linkDecision');

        $result = $this->service()->decide($this->relation(null), [], $this->actor('alice'));

        $this->assertNull($result['decision']);
        $this->assertSame(['beginTransaction', 'commit'], $this->tx);
    }//end testAnEmptyFirstBodyRecordsNothing()

    public function testTheSameReviewerChangingTheVerdictKeepsTheirNoteAndSupersedesTheCurrent(): void
    {
        $this->decisions->method('find')->with('cur-1')->willReturn($this->current('alice', 'old note'));
        $this->ownership->method('refusal')->willReturn(null);
        $this->linkSucceeds();

        $this->service()->decide($this->relation('cur-1', ['art6']), ['skipAnonymization' => true], $this->actor('alice'));

        $decision = $this->recorded[0];
        $this->assertSame('release', $decision->getVerdict());
        $this->assertSame('old note', $decision->getNote());
        $this->assertSame('cur-1', $decision->getSupersedes());
        $this->assertSame([['new-1', 'cur-1', true]], $this->links);
    }//end testTheSameReviewerChangingTheVerdictKeepsTheirNoteAndSupersedesTheCurrent()

    public function testASupervisorChangingTheVerdictDoesNotInheritTheDecidersNote(): void
    {
        $this->decisions->method('find')->willReturn($this->current('alice', 'old note'));
        $this->ownership->method('refusal')->willReturn(null);
        $this->linkSucceeds();

        $this->service()->decide($this->relation('cur-1'), ['skipAnonymization' => true], $this->actor('sup'));

        $this->assertNull($this->recorded[0]->getNote());
        $this->assertSame('sup', $this->recorded[0]->getDecidedBy());
    }//end testASupervisorChangingTheVerdictDoesNotInheritTheDecidersNote()

    public function testAnUnchangedVerdictWithTheSameNoteRecordsNothing(): void
    {
        $current = $this->current('alice', 'same');
        $this->decisions->method('find')->willReturn($current);
        $this->ownership->method('refusal')->willReturn(null);
        $this->decisions->expects($this->never())->method('record');

        $result = $this->service()->decide(
            $this->relation('cur-1', ['art6']),
            ['bases' => ['art6'], 'decisionNote' => ' same '],
            $this->actor('alice')
        );

        $this->assertSame($current, $result['decision']);
        $this->assertSame(['beginTransaction', 'commit'], $this->tx);
    }//end testAnUnchangedVerdictWithTheSameNoteRecordsNothing()

    public function testAnUnchangedVerdictWithANewNoteRecordsASuccessor(): void
    {
        $this->decisions->method('find')->willReturn($this->current('alice', 'old'));
        $this->ownership->method('refusal')->willReturn(null);
        $this->linkSucceeds();

        $this->service()->decide($this->relation('cur-1', ['art6']), ['decisionNote' => 'new'], $this->actor('alice'));

        $this->assertSame('new', $this->recorded[0]->getNote());
    }//end testAnUnchangedVerdictWithANewNoteRecordsASuccessor()

    public function testAnUnchangedVerdictWithoutANoteRecordsNothing(): void
    {
        $this->decisions->method('find')->willReturn($this->current('alice', 'old'));
        $this->ownership->method('refusal')->willReturn(null);
        $this->decisions->expects($this->never())->method('record');

        $this->service()->decide($this->relation('cur-1', ['art6']), ['bases' => ['art6']], $this->actor('alice'));

        $this->assertSame(['beginTransaction', 'commit'], $this->tx);
    }//end testAnUnchangedVerdictWithoutANoteRecordsNothing()

    public function testAnOwnershipRefusalStopsBeforeAnyWrite(): void
    {
        $current = $this->current('alice', null);
        $this->decisions->method('find')->willReturn($current);
        $this->ownership->expects($this->once())
            ->method('refusal')
            ->with(
                $current,
                $this->callback(
                    static fn(DecisionQuestion $q): bool => $q->actorId === 'bob' && $q->policy instanceof ReviewPolicy && $q->subject === ['subjectId' => '5']
                )
            )
            ->willReturn(['error' => 'decision-owned', 'decision' => 'cur-1']);
        $this->db->expects($this->never())->method('beginTransaction');

        try {
            $this->service()->decide($this->relation('cur-1'), ['skipAnonymization' => true], $this->actor('bob'));
            $this->fail('A refused change must throw.');
        } catch (DecisionOwnedException $e) {
            $this->assertSame(['error' => 'decision-owned', 'decision' => 'cur-1'], $e->getRefusal());
        }
    }//end testAnOwnershipRefusalStopsBeforeAnyWrite()

    public function testALinkedButMissingDecisionIsCheckedAsOwnedByNobody(): void
    {
        $this->decisions->method('find')->with('ghost')->willReturn(null);
        $this->ownership->expects($this->once())
            ->method('refusal')
            ->with(
                $this->callback(static fn(Decision $d): bool => $d->getUuid() === 'ghost' && $d->getDecidedBy() === ''),
                $this->callback(static fn(DecisionQuestion $q): bool => $q->actorId === 'bob')
            )
            ->willReturn(['error' => 'decision-owned', 'decision' => 'ghost']);

        $this->expectException(DecisionOwnedException::class);
        $this->service()->decide($this->relation('ghost'), ['skipAnonymization' => true], $this->actor('bob'));
    }//end testALinkedButMissingDecisionIsCheckedAsOwnedByNobody()

    public function testALostLinkRaceRollsBackAndIsReportedAsOwned(): void
    {
        $this->decisions->method('find')->willReturn(null);
        $this->relations->method('linkDecision')->willThrowException(new DecisionConflictException('taken'));

        try {
            $this->service()->decide($this->relation(null), ['bases' => ['art6']], $this->actor('alice'));
            $this->fail('A lost race must throw.');
        } catch (DecisionOwnedException $e) {
            $this->assertSame(['error' => 'decision-owned', 'decision' => null], $e->getRefusal());
        }

        $this->assertSame(['beginTransaction', 'rollBack'], $this->tx);
    }//end testALostLinkRaceRollsBackAndIsReportedAsOwned()

    public function testAnyOtherFailureRollsBackAndIsRethrownAsIs(): void
    {
        $this->decisions->method('find')->willReturn(null);
        $failure = new RuntimeException('db gone');
        $this->relations->method('linkDecision')->willThrowException($failure);

        try {
            $this->service()->decide($this->relation(null), ['bases' => ['art6']], $this->actor('alice'));
            $this->fail('The failure must be rethrown.');
        } catch (RuntimeException $e) {
            $this->assertSame($failure, $e);
        }

        $this->assertSame(['beginTransaction', 'rollBack'], $this->tx);
    }//end testAnyOtherFailureRollsBackAndIsRethrownAsIs()

    public function testANoteThatIsNotAStringIsRejectedBeforeAnyLookup(): void
    {
        $this->decisions->expects($this->never())->method('find');

        try {
            $this->service()->decide($this->relation(null), ['decisionNote' => ['x']], $this->actor('alice'));
            $this->fail('A non-string note must be rejected.');
        } catch (CustomValidationException $e) {
            $this->assertSame(['field' => 'decisionNote', 'reason' => 'must_be_string_or_null'], $e->getErrors());
        }
    }//end testANoteThatIsNotAStringIsRejectedBeforeAnyLookup()

    public function testABlankNoteIsStoredAsNullAndStillCountsAsGiven(): void
    {
        $this->decisions->method('find')->willReturn(null);
        $this->linkSucceeds();

        $this->service()->decide($this->relation(null), ['decisionNote' => '   '], $this->actor('alice'));

        $this->assertCount(1, $this->recorded);
        $this->assertNull($this->recorded[0]->getNote());
    }//end testABlankNoteIsStoredAsNullAndStillCountsAsGiven()
}//end class
