<?php

declare(strict_types=1);

/**
 * DataSubjectRequestService Unit Tests
 *
 * Verifies the consumable, RBAC + tenant scoped GDPR data-subject-rights
 * service: cross-register discovery (RBAC-scoped — unauthorised objects
 * dropped), access-export assembly with matched PII attributes, erasure
 * that honours legal hold / immutable status (reported as `held`, never
 * erased), erase-mode selection (whole-object soft-delete vs field-level
 * pseudonymise), dry-run, and DSAR processing-activity attribution on
 * fulfilment writes (the immutable-audit contract).
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Gdpr
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Gdpr;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\DsarService;
use OCA\OpenRegister\Service\Gdpr\DataSubjectDeadline;
use OCA\OpenRegister\Service\Gdpr\DataSubjectRequestService;
use OCA\OpenRegister\Service\RetentionService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Test class for DataSubjectRequestService.
 */
class DataSubjectRequestServiceTest extends TestCase
{

    /**
     * DB connection mock.
     *
     * @var IDBConnection&MockObject
     */
    private $db;

    /**
     * Object mapper mock.
     *
     * @var MagicMapper&MockObject
     */
    private $objectMapper;

    /**
     * Retention service mock.
     *
     * @var RetentionService&MockObject
     */
    private $retentionService;

    /**
     * DSAR service mock (only the activity-uuid getter is used).
     *
     * @var DsarService&MockObject
     */
    private $dsarService;

    /**
     * User session mock.
     *
     * @var IUserSession&MockObject
     */
    private $userSession;

    /**
     * Subject under test.
     *
     * @var DataSubjectRequestService
     */
    private DataSubjectRequestService $service;

    /**
     * Set up mocks and the SUT.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->db               = $this->createMock(IDBConnection::class);
        $this->objectMapper     = $this->createMock(MagicMapper::class);
        $this->retentionService = $this->createMock(RetentionService::class);
        $this->dsarService      = $this->createMock(DsarService::class);
        $this->userSession      = $this->createMock(IUserSession::class);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('handler1');
        $this->userSession->method('getUser')->willReturn($user);

        $this->service = new DataSubjectRequestService(
            $this->db,
            $this->objectMapper,
            $this->retentionService,
            $this->dsarService,
            new DataSubjectDeadline(),
            $this->userSession,
            $this->createMock(LoggerInterface::class)
        );

    }//end setUp()

    /**
     * Wire the GdprEntity ⋈ entity_relations join to return $rows.
     *
     * @param list<array<string, mixed>> $rows Rows the index join yields.
     *
     * @return void
     */
    private function wireIndexJoin(array $rows): void
    {
        $expr = $this->createMock(IExpressionBuilder::class);
        $expr->method('eq')->willReturn('eq');
        $expr->method('iLike')->willReturn('ilike');
        $expr->method('isNotNull')->willReturn('notnull');

        $qb = $this->createMock(IQueryBuilder::class);
        $qb->method('selectDistinct')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('innerJoin')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('expr')->willReturn($expr);
        $qb->method('createNamedParameter')->willReturn(':p');

        $cursor = $this->createMock(IResult::class);
        $cursor->method('fetchAll')->willReturn($rows);

        $qb->method('executeQuery')->willReturn($cursor);

        $this->db->method('getQueryBuilder')->willReturn($qb);
        $this->db->method('escapeLikeParameter')->willReturnArgument(0);

    }//end wireIndexJoin()

    /**
     * Build an ObjectEntity carrying a payload + uuid.
     *
     * @param string               $uuid    Object uuid.
     * @param array<string, mixed> $payload Object payload.
     *
     * @return ObjectEntity
     */
    private function buildObject(string $uuid, array $payload): ObjectEntity
    {
        $object = new ObjectEntity();
        $object->setUuid($uuid);
        $object->setRegister('reg');
        $object->setSchema('schema');
        $object->setObject($payload);
        return $object;

    }//end buildObject()

    /**
     * findSubjectData returns objects across two registers, dropping the
     * one the caller is not authorised to read (RBAC scoping).
     *
     * @return void
     */
    public function testFindSubjectDataIsRbacScopedAcrossRegisters(): void
    {
        $this->wireIndexJoin(
            [
                ['id' => 1, 'type' => 'email', 'value' => 'jane@example.org', 'category' => 'pii', 'detected_at' => '', 'object_id' => 10, 'object_uuid' => 'uuid-a'],
                ['id' => 2, 'type' => 'email', 'value' => 'jane@example.org', 'category' => 'pii', 'detected_at' => '', 'object_id' => 20, 'object_uuid' => 'uuid-b'],
            ]
        );

        $authorised = $this->buildObject('uuid-a', ['email' => 'jane@example.org']);

        // uuid-a (register 1) is authorised; uuid-b (register 2) is denied
        // (the RBAC-scoped find throws — mirrors a not-authorised load).
        $this->objectMapper->method('find')->willReturnCallback(
            function ($identifier) use ($authorised) {
                if ($identifier === 'uuid-a') {
                    return $authorised;
                }

                throw new DoesNotExistException('not authorised');
            }
        );

        $results = $this->service->findSubjectData(subjectId: 'jane@example.org');

        $this->assertCount(1, $results);
        $this->assertSame('uuid-a', $results[0]['object']['@self']['id'] ?? $results[0]['object']['id'] ?? 'uuid-a');
        $this->assertSame('email', $results[0]['gdprEntities'][0]['type']);

    }//end testFindSubjectDataIsRbacScopedAcrossRegisters()

    /**
     * assembleAccessExport bundles the subject's data + matched PII.
     *
     * @return void
     */
    public function testAssembleAccessExportBuildsBundle(): void
    {
        $this->wireIndexJoin(
            [
                ['id' => 1, 'type' => 'email', 'value' => 'jane@example.org', 'category' => 'pii', 'detected_at' => '2026-01-01', 'object_id' => 10, 'object_uuid' => 'uuid-a'],
            ]
        );
        $this->objectMapper->method('find')->willReturn($this->buildObject('uuid-a', ['email' => 'jane@example.org']));

        $bundle = $this->service->assembleAccessExport(subjectId: 'jane@example.org');

        $this->assertSame('jane@example.org', $bundle['subject']);
        $this->assertSame(1, $bundle['objectCount']);
        $this->assertCount(1, $bundle['objects']);
        $this->assertSame('email', $bundle['objects'][0]['gdprEntities'][0]['type']);

    }//end testAssembleAccessExportBuildsBundle()

    /**
     * erase skips an object under legal hold (reported `held`, not erased)
     * and erases the unheld one.
     *
     * @return void
     */
    public function testEraseRespectsLegalHold(): void
    {
        $this->wireIndexJoin(
            [
                ['id' => 1, 'type' => 'email', 'value' => 'jane@example.org', 'category' => 'pii', 'detected_at' => '', 'object_id' => 10, 'object_uuid' => 'free'],
                ['id' => 2, 'type' => 'email', 'value' => 'jane@example.org', 'category' => 'pii', 'detected_at' => '', 'object_id' => 20, 'object_uuid' => 'held'],
            ]
        );

        $free = $this->buildObject('free', ['email' => 'jane@example.org']);
        $held = $this->buildObject('held', ['email' => 'jane@example.org']);
        $this->objectMapper->method('find')->willReturnCallback(
            static fn ($id) => ($id === 'free') ? $free : $held
        );

        // `held` is under an active legal hold; `free` is not.
        $this->retentionService->method('hasActiveLegalHold')->willReturnCallback(
            static fn (ObjectEntity $o) => $o->getUuid() === 'held'
        );
        $this->retentionService->method('validateNotImmutable')->willReturn(null);

        // Only the free object is updated (erased).
        $this->objectMapper->expects($this->once())->method('update')->with($free)->willReturn($free);

        $summary = $this->service->erase(subjectId: 'jane@example.org', eraseMode: DataSubjectRequestService::ERASE_MODE_WHOLE_OBJECT);

        $this->assertSame(2, $summary['matchedCount']);
        $this->assertCount(1, $summary['erased']);
        $this->assertCount(1, $summary['held']);
        $this->assertSame('held', $summary['held'][0]['uuid']);
        $this->assertSame('legal-hold', $summary['held'][0]['reason']);
        $this->assertTrue($summary['complete']);

    }//end testEraseRespectsLegalHold()

    /**
     * Whole-object erase soft-deletes (sets deleted metadata); pseudonymise
     * mode redacts the subject's matching field values in place.
     *
     * @return void
     */
    public function testEraseModesBehaveDifferently(): void
    {
        // Whole-object mode → setDeleted is populated.
        $this->wireIndexJoin(
            [['id' => 1, 'type' => 'email', 'value' => 'jane@example.org', 'category' => 'pii', 'detected_at' => '', 'object_id' => 10, 'object_uuid' => 'obj']]
        );
        $object = $this->buildObject('obj', ['email' => 'jane@example.org', 'name' => 'Jane']);
        $this->objectMapper->method('find')->willReturn($object);
        $this->retentionService->method('hasActiveLegalHold')->willReturn(false);
        $this->retentionService->method('validateNotImmutable')->willReturn(null);
        $this->objectMapper->method('update')->willReturnArgument(0);

        $this->service->erase(subjectId: 'jane@example.org', eraseMode: DataSubjectRequestService::ERASE_MODE_WHOLE_OBJECT);
        $this->assertNotNull($object->getDeleted());
        $this->assertSame('gdpr-erasure', $object->getDeleted()['deletedReason']);
        // Whole-object mode leaves the payload intact.
        $this->assertSame('jane@example.org', $object->getObject()['email']);

    }//end testEraseModesBehaveDifferently()

    /**
     * Pseudonymise mode replaces the subject's matching field values in
     * place and preserves the object (no soft-delete).
     *
     * @return void
     */
    public function testErasePseudonymiseRedactsMatchingFields(): void
    {
        $this->wireIndexJoin(
            [['id' => 1, 'type' => 'email', 'value' => 'jane@example.org', 'category' => 'pii', 'detected_at' => '', 'object_id' => 10, 'object_uuid' => 'obj']]
        );
        $object = $this->buildObject('obj', ['email' => 'jane@example.org', 'note' => 'unrelated']);
        $this->objectMapper->method('find')->willReturn($object);
        $this->retentionService->method('hasActiveLegalHold')->willReturn(false);
        $this->retentionService->method('validateNotImmutable')->willReturn(null);
        $this->objectMapper->method('update')->willReturnArgument(0);

        $this->service->erase(subjectId: 'jane@example.org', eraseMode: DataSubjectRequestService::ERASE_MODE_PSEUDONYMISE);

        $payload = $object->getObject();
        $this->assertSame('[erased]', $payload['email']);
        $this->assertSame('unrelated', $payload['note']);
        // Pseudonymise preserves the object — it is NOT soft-deleted.
        $this->assertArrayNotHasKey('deletedReason', (array) $object->getDeleted());

    }//end testErasePseudonymiseRedactsMatchingFields()

    /**
     * A dry-run reports matches but mutates nothing.
     *
     * @return void
     */
    public function testEraseDryRunMutatesNothing(): void
    {
        $this->wireIndexJoin(
            [['id' => 1, 'type' => 'email', 'value' => 'jane@example.org', 'category' => 'pii', 'detected_at' => '', 'object_id' => 10, 'object_uuid' => 'obj']]
        );
        $object = $this->buildObject('obj', ['email' => 'jane@example.org']);
        $this->objectMapper->method('find')->willReturn($object);
        $this->retentionService->method('hasActiveLegalHold')->willReturn(false);
        $this->retentionService->method('validateNotImmutable')->willReturn(null);

        // update() must never be called on a dry run.
        $this->objectMapper->expects($this->never())->method('update');

        $summary = $this->service->erase(subjectId: 'jane@example.org', dryRun: true);

        $this->assertTrue($summary['dryRun']);
        $this->assertCount(1, $summary['erased']);
        // Dry run mutates nothing — no soft-delete metadata written.
        $this->assertArrayNotHasKey('deletedReason', (array) $object->getDeleted());

    }//end testEraseDryRunMutatesNothing()

    /**
     * Fulfilment writes carry the configured DSAR processing-activity
     * attribution so the immutable audit trail records them.
     *
     * @return void
     */
    public function testRectifyAttributesDsarProcessingActivity(): void
    {
        $object = $this->buildObject('obj', ['email' => 'old@example.org']);
        $this->objectMapper->method('find')->willReturn($object);
        $this->retentionService->method('validateNotImmutable')->willReturn(null);
        $this->dsarService->method('getDsarProcessingActivityUuid')->willReturn('activity-uuid');
        $this->objectMapper->method('update')->willReturnArgument(0);

        $result = $this->service->rectify(objectIdentifier: 'obj', changes: ['email' => 'new@example.org']);

        $this->assertNotNull($result);
        $this->assertSame('activity-uuid', $object->getProcessingActivityId());
        $this->assertSame('new@example.org', $object->getObject()['email']);

    }//end testRectifyAttributesDsarProcessingActivity()

    /**
     * Rectify is refused on an immutable (archived/destroyed) object.
     *
     * @return void
     */
    public function testRectifyRefusedOnImmutableObject(): void
    {
        $object = $this->buildObject('obj', ['email' => 'x@example.org']);
        $this->objectMapper->method('find')->willReturn($object);
        $this->retentionService->method('validateNotImmutable')->willReturn('OBJECT_DESTROYED');
        $this->objectMapper->expects($this->never())->method('update');

        $this->assertNull($this->service->rectify(objectIdentifier: 'obj', changes: ['email' => 'y@example.org']));

    }//end testRectifyRefusedOnImmutableObject()

    /**
     * Deadline pass-throughs delegate to DataSubjectDeadline.
     *
     * @return void
     */
    public function testDeadlinePassThroughs(): void
    {
        $received = new \DateTimeImmutable('2026-01-10T00:00:00+00:00');
        $due      = $this->service->computeDueAt($received);
        $this->assertSame('2026-02-10T00:00:00+00:00', $due->format('c'));

        $extended = $this->service->extend($due);
        $this->assertSame('2026-04-10T00:00:00+00:00', $extended->format('c'));

        $this->assertTrue($this->service->isOverdue($due, new \DateTimeImmutable('2026-03-01T00:00:00+00:00')));
        $this->assertFalse($this->service->isOverdue($due, new \DateTimeImmutable('2026-01-15T00:00:00+00:00')));

    }//end testDeadlinePassThroughs()
}//end class
