<?php

declare(strict_types=1);

/**
 * RetentionSweepService Unit Tests
 *
 * Verifies the legal-hold-aware sweep: expired non-held cases are scrubbed
 * (erase pseudonymise) + hard-deleted, within-window cases are untouched,
 * dry-run reports without destroying, and a case under legal hold is skipped
 * intact.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Gdpr\Retention
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Gdpr\Retention;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Gdpr\Case\CaseObjectAccessor;
use OCA\OpenRegister\Service\Gdpr\DataSubjectRequestService;
use OCA\OpenRegister\Service\Gdpr\Retention\RetentionSweepService;
use OCA\OpenRegister\Service\RetentionService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Test class for RetentionSweepService.
 */
class RetentionSweepServiceTest extends TestCase
{

    /**
     * Fixed "now" for deterministic window comparisons.
     *
     * @var int
     */
    private int $now = 1751500000;

    /**
     * Build a case entity with a given uuid + retainUntil + subjectId.
     *
     * @param string $uuid        Case uuid.
     * @param string $retainUntil ISO-8601 retain-until stamp (or '' for none).
     * @param string $subjectId   Subject id.
     *
     * @return ObjectEntity
     */
    private function caseEntity(string $uuid, string $retainUntil, string $subjectId='s@example.org'): ObjectEntity
    {
        $object = new ObjectEntity();
        $object->setUuid($uuid);
        $object->setObject(['subjectId' => $subjectId, 'retainUntil' => $retainUntil]);
        return $object;

    }//end caseEntity()

    /**
     * Build the SUT with a fixed clock and the supplied cases + hold verdicts.
     *
     * @param ObjectEntity[]      $cases         Case entities to sweep.
     * @param callable            $holdResolver  fn(ObjectEntity):bool legal-hold verdict.
     * @param RetentionService|null $retentionMock Optional pre-built retention mock.
     *
     * @return array{0: RetentionSweepService, 1: \PHPUnit\Framework\MockObject\MockObject}
     */
    private function build(array $cases, callable $holdResolver): array
    {
        $accessor = $this->createMock(CaseObjectAccessor::class);
        $accessor->method('findAllCaseEntities')->willReturn($cases);

        $retention = $this->createMock(RetentionService::class);
        $retention->method('hasActiveLegalHold')->willReturnCallback($holdResolver);
        $retention->method('validateNotImmutable')->willReturn(null);

        $dsr = $this->createMock(DataSubjectRequestService::class);

        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn($this->now);

        $service = new RetentionSweepService(
            $accessor,
            $retention,
            $dsr,
            $time,
            $this->createMock(LoggerInterface::class)
        );

        return [$service, $accessor, $dsr];

    }//end build()

    /**
     * An expired, non-held case is scrubbed (erase pseudonymise) + deleted.
     *
     * @return void
     */
    public function testExpiredCaseIsScrubbedAndDeleted(): void
    {
        $expired = $this->caseEntity('case-expired', '2020-01-01T00:00:00+00:00');
        [$service, $accessor, $dsr] = $this->build([$expired], static fn(): bool => false);

        $dsr->expects($this->once())
            ->method('erase')
            ->with('s@example.org', null, DataSubjectRequestService::ERASE_MODE_PSEUDONYMISE)
            ->willReturn([]);

        $accessor->expects($this->once())
            ->method('deleteForSweep')
            ->with('case-expired')
            ->willReturn(true);

        $summary = $service->runSweep(dryRun: false);
        $this->assertSame(['case-expired'], $summary['purged']);

    }//end testExpiredCaseIsScrubbedAndDeleted()

    /**
     * A case still within its window is untouched.
     *
     * @return void
     */
    public function testWithinWindowUntouched(): void
    {
        $future = $this->caseEntity('case-future', '2099-01-01T00:00:00+00:00');
        [$service, $accessor, $dsr] = $this->build([$future], static fn(): bool => false);

        $dsr->expects($this->never())->method('erase');
        $accessor->expects($this->never())->method('deleteForSweep');

        $summary = $service->runSweep(dryRun: false);
        $this->assertSame([], $summary['purged']);
        $this->assertSame(1, $summary['withinWindow']);

    }//end testWithinWindowUntouched()

    /**
     * Dry-run reports the expired case without destroying anything.
     *
     * @return void
     */
    public function testDryRunReportsWithoutDestroying(): void
    {
        $expired = $this->caseEntity('case-expired', '2020-01-01T00:00:00+00:00');
        [$service, $accessor, $dsr] = $this->build([$expired], static fn(): bool => false);

        $dsr->expects($this->never())->method('erase');
        $accessor->expects($this->never())->method('deleteForSweep');

        $summary = $service->runSweep(dryRun: true);
        $this->assertTrue($summary['dryRun']);
        $this->assertSame(['case-expired'], $summary['purged']);

    }//end testDryRunReportsWithoutDestroying()

    /**
     * An expired case under an active legal hold is skipped intact.
     *
     * @return void
     */
    public function testExpiredButHeldCaseSkipped(): void
    {
        $held = $this->caseEntity('case-held', '2020-01-01T00:00:00+00:00');
        [$service, $accessor, $dsr] = $this->build([$held], static fn(): bool => true);

        $dsr->expects($this->never())->method('erase');
        $accessor->expects($this->never())->method('deleteForSweep');

        $summary = $service->runSweep(dryRun: false);
        $this->assertSame(['case-held'], $summary['skippedHeld']);
        $this->assertSame([], $summary['purged']);

    }//end testExpiredButHeldCaseSkipped()
}//end class
