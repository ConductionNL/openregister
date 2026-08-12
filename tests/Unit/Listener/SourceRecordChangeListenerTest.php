<?php

/**
 * SourceRecordChangeListener unit tests — reverse-FK source-change recompute.
 *
 * The recompute is DEFERRED (openregister#2420 / ADR-078), so the assertions
 * below are about what the listener ENQUEUES, not about a synchronous save.
 * The inline branch — reached only when the `openregister.listener_deferral`
 * kill switch is set to `inline` — is covered separately, because a test that
 * only exercised the deferred path could not tell a listener that defers
 * correctly from one that has stopped recomputing at all.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/mdm-reverse-fk-source-resolution/tasks.md#6.1
 */

declare(strict_types=1);

namespace Unit\Listener;

use OCA\OpenRegister\BackgroundJob\SourceRecordRecomputeJob;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Listener\SourceRecordChangeListener;
use OCA\OpenRegister\Service\Deferral\ListenerDeferralService;
use OCA\OpenRegister\Service\Merge\MasterRecomputeService;
use OCP\ICacheFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SourceRecordChangeListenerTest extends TestCase
{

    private SchemaMapper&MockObject $schemaMapper;

    private LoggerInterface&MockObject $logger;

    private ICacheFactory&MockObject $cacheFactory;

    private ListenerDeferralService&MockObject $deferral;

    private MasterRecomputeService&MockObject $recompute;

    protected function setUp(): void
    {
        $this->schemaMapper = $this->createMock(SchemaMapper::class);
        $this->logger       = $this->createMock(LoggerInterface::class);
        $this->cacheFactory = $this->createMock(ICacheFactory::class);
        $this->deferral     = $this->createMock(ListenerDeferralService::class);
        $this->recompute    = $this->createMock(MasterRecomputeService::class);
        // No distributed cache in unit tests — the listener falls back to
        // its per-instance memoisation when cache creation fails.
        $this->cacheFactory->method('createDistributed')
            ->willThrowException(new \RuntimeException('no cache in tests'));
    }//end setUp()

    /**
     * Build the listener with the deferral kill switch in a chosen position.
     *
     * @param bool $deferralEnabled Whether deferral is on (the default in production).
     *
     * @return SourceRecordChangeListener
     */
    private function listener(bool $deferralEnabled): SourceRecordChangeListener
    {
        $this->deferral->method('isDeferralEnabled')->willReturn($deferralEnabled);
        return new SourceRecordChangeListener(
            $this->schemaMapper,
            $this->logger,
            $this->cacheFactory,
            $this->deferral,
            $this->recompute
        );
    }//end listener()

    /**
     * A master schema declaring a reverse-FK sourceLink onto `sourceRecord`.
     * Uses a real Schema entity — `Schema` exposes magic getters that cannot
     * be configured on a PHPUnit mock.
     *
     * @return Schema
     */
    private function masterSchema(): Schema
    {
        $schema = new Schema();
        $schema->setConfiguration([
            'x-openregister-survivorship' => [
                'sourceLink' => [
                    'mode'           => 'reverseFk',
                    'sourceSchema'   => 'sourceRecord',
                    'referenceField' => 'currentMasterEntity',
                ],
            ],
        ]);
        return $schema;
    }//end masterSchema()

    /**
     * A real Schema entity with the given slug.
     *
     * @param string $slug Schema slug.
     *
     * @return Schema
     */
    private function schemaWith(string $slug): Schema
    {
        $schema = new Schema();
        $schema->setSlug($slug);
        $schema->setConfiguration([]);
        return $schema;
    }//end schemaWith()

    /**
     * Build a source ObjectEntity.
     *
     * @param string               $schemaRef Schema reference to set.
     * @param array<string, mixed> $payload   Object payload.
     *
     * @return ObjectEntity
     */
    private function source(string $schemaRef, array $payload): ObjectEntity
    {
        $object = new ObjectEntity();
        $object->setSchema($schemaRef);
        $object->setObject($payload);
        return $object;
    }//end source()

    /**
     * The referenced master is ENQUEUED for recompute, not saved in-request.
     *
     * The dedupe key is asserted explicitly because it is what turns N source
     * writes against one master into ONE recompute — a job enqueued without it
     * would still pass a bare "defer was called" assertion while restoring the
     * per-source fan-out this change exists to remove.
     *
     * @return void
     */
    public function testSourceSaveDefersReferencedMasterRecompute(): void
    {
        $this->schemaMapper->method('findAll')->willReturn([$this->masterSchema()]);
        $this->schemaMapper->method('find')->willReturn($this->schemaWith('sourceRecord'));

        // NOT recomputed inline — that is the defect this change closes.
        $this->recompute->expects($this->never())->method('recompute');

        $this->deferral->expects($this->once())
            ->method('defer')
            ->with(
                $this->equalTo(SourceRecordRecomputeJob::class),
                $this->equalTo(['masterUuid' => 'master-1']),
                $this->anything(),
                $this->equalTo('master-1')
            );

        $source = $this->source('106', ['currentMasterEntity' => 'master-1', 'mappedAttributes' => ['name' => 'Acme']]);
        $this->listener(true)->handle(new ObjectCreatedEvent($source));
    }//end testSourceSaveDefersReferencedMasterRecompute()

    /**
     * Reassigning a source to another master enqueues BOTH masters.
     *
     * A four-way gradient is not available here, so the control is the pair:
     * an implementation that only enqueued the new master would pass a
     * one-call assertion, and this one names both uuids.
     *
     * @return void
     */
    public function testReassignmentDefersBothOldAndNewMaster(): void
    {
        $this->schemaMapper->method('findAll')->willReturn([$this->masterSchema()]);
        $this->schemaMapper->method('find')->willReturn($this->schemaWith('sourceRecord'));

        $deferred = [];
        $this->deferral->expects($this->exactly(2))
            ->method('defer')
            ->willReturnCallback(
                function (string $jobClass, array $entry) use (&$deferred): void {
                    $deferred[] = $entry['masterUuid'];
                }
            );

        $new = $this->source('106', ['currentMasterEntity' => 'master-2']);
        $old = $this->source('106', ['currentMasterEntity' => 'master-1']);
        $this->listener(true)->handle(new ObjectUpdatedEvent($new, $old));

        sort($deferred);
        $this->assertSame(['master-1', 'master-2'], $deferred);
    }//end testReassignmentDefersBothOldAndNewMaster()

    /**
     * With the kill switch at `inline`, the recompute runs in-request again.
     *
     * @return void
     */
    public function testInlineKillSwitchRecomputesSynchronously(): void
    {
        $this->schemaMapper->method('findAll')->willReturn([$this->masterSchema()]);
        $this->schemaMapper->method('find')->willReturn($this->schemaWith('sourceRecord'));

        $this->deferral->expects($this->never())->method('defer');
        $this->recompute->expects($this->once())
            ->method('recompute')
            ->with($this->equalTo('master-1'));

        $source = $this->source('106', ['currentMasterEntity' => 'master-1']);
        $this->listener(false)->handle(new ObjectCreatedEvent($source));
    }//end testInlineKillSwitchRecomputesSynchronously()

    public function testNonSourceObjectIsIgnored(): void
    {
        $this->schemaMapper->method('findAll')->willReturn([$this->masterSchema()]);
        // The saved object's schema is not the reverse-FK source schema.
        $this->schemaMapper->method('find')->willReturn($this->schemaWith('contact'));

        $this->deferral->expects($this->never())->method('defer');
        $this->recompute->expects($this->never())->method('recompute');

        $object = $this->source('999', ['currentMasterEntity' => 'master-1']);
        $this->listener(true)->handle(new ObjectCreatedEvent($object));
    }//end testNonSourceObjectIsIgnored()

    /**
     * An enqueue failure must never abort the source object's own write.
     *
     * @return void
     */
    public function testDeferFailureIsSwallowed(): void
    {
        $this->schemaMapper->method('findAll')->willReturn([$this->masterSchema()]);
        $this->schemaMapper->method('find')->willReturn($this->schemaWith('sourceRecord'));

        $this->deferral->method('defer')->willThrowException(new \RuntimeException('boom'));
        $this->logger->expects($this->atLeastOnce())->method('warning');

        $source = $this->source('106', ['currentMasterEntity' => 'master-1']);
        // No exception escapes.
        $this->listener(true)->handle(new ObjectCreatedEvent($source));
        $this->addToAssertionCount(1);
    }//end testDeferFailureIsSwallowed()
}//end class
