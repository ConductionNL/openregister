<?php

declare(strict_types=1);

/**
 * EvidenceHarvestService Unit Tests
 *
 * Verifies content-hash dedup + per-item status + registered-provider-only
 * enumeration: harvested items are stored with source/hash/status, re-runs do
 * not duplicate, and unregistered sources contribute nothing.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Gdpr\Evidence
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Gdpr\Evidence;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Gdpr\Case\CaseObjectAccessor;
use OCA\OpenRegister\Service\Gdpr\Evidence\EvidenceHarvestService;
use OCA\OpenRegister\Service\Gdpr\Evidence\EvidenceItem;
use OCA\OpenRegister\Service\Gdpr\Evidence\EvidenceSourceProvider;
use OCA\OpenRegister\Service\Gdpr\Evidence\EvidenceSourceRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Test class for EvidenceHarvestService.
 */
class EvidenceHarvestServiceTest extends TestCase
{

    /**
     * Build a fake provider yielding fixed items.
     *
     * @param string        $sourceId The provider source id.
     * @param EvidenceItem[] $items    Items to harvest.
     * @param bool          $enabled  Whether the provider is enabled.
     *
     * @return EvidenceSourceProvider
     */
    private function provider(string $sourceId, array $items, bool $enabled=true): EvidenceSourceProvider
    {
        return new class($sourceId, $items, $enabled) implements EvidenceSourceProvider {

            /**
             * Constructor.
             *
             * @param string         $sourceId Source id.
             * @param EvidenceItem[] $items    Items.
             * @param bool           $enabled  Enabled flag.
             */
            public function __construct(
                private string $sourceId,
                private array $items,
                private bool $enabled
            ) {
            }

            public function getSourceId(): string
            {
                return $this->sourceId;
            }

            public function isEnabled(): bool
            {
                return $this->enabled;
            }

            public function harvest(string $caseUuid, array $case): array
            {
                return $this->items;
            }
        };

    }//end provider()

    /**
     * A case entity carrying an existing evidence sub-collection.
     *
     * @param array<int, array<string, mixed>> $evidence Existing evidence rows.
     *
     * @return ObjectEntity
     */
    private function caseEntity(array $evidence=[]): ObjectEntity
    {
        $object = new ObjectEntity();
        $object->setUuid('00000000-0000-0000-0000-000000000000');
        $object->setObject(['subjectId' => 'subject@example.org', 'evidence' => $evidence]);
        return $object;

    }//end caseEntity()

    /**
     * Harvested items are stored with source, hash and status.
     *
     * @return void
     */
    public function testHarvestStoresItemsWithSourceHashStatus(): void
    {
        $case     = $this->caseEntity();
        $accessor = $this->createMock(CaseObjectAccessor::class);
        $accessor->method('load')->willReturn($case);

        $savedData = null;
        $accessor->expects($this->once())
            ->method('save')
            ->willReturnCallback(
                function ($c, $data) use (&$savedData, $case) {
                    $savedData = $data;
                    return $case;
                }
            );

        $registry = new EvidenceSourceRegistry($this->createMock(LoggerInterface::class));
        $registry->withProviders(
            [
                $this->provider(
                    'or-objects',
                    [new EvidenceItem('or-objects', 'sha256:aaa', EvidenceItem::STATUS_COLLECTED)]
                ),
            ]
        );

        $service = new EvidenceHarvestService($registry, $accessor, $this->createMock(LoggerInterface::class));
        $result  = $service->harvest(caseUuid: '00000000-0000-0000-0000-000000000000');

        $this->assertSame(1, $result['appended']);
        $this->assertNotNull($savedData);
        $this->assertCount(1, $savedData['evidence']);
        $this->assertSame('or-objects', $savedData['evidence'][0]['sourceId']);
        $this->assertSame('sha256:aaa', $savedData['evidence'][0]['contentHash']);
        $this->assertSame(EvidenceItem::STATUS_COLLECTED, $savedData['evidence'][0]['status']);

    }//end testHarvestStoresItemsWithSourceHashStatus()

    /**
     * Re-running a harvest with an already-present contentHash does not
     * duplicate; nothing is saved.
     *
     * @return void
     */
    public function testReHarvestDoesNotDuplicate(): void
    {
        $case     = $this->caseEntity([['sourceId' => 'or-objects', 'contentHash' => 'sha256:aaa', 'status' => 'collected']]);
        $accessor = $this->createMock(CaseObjectAccessor::class);
        $accessor->method('load')->willReturn($case);
        // No append → no save.
        $accessor->expects($this->never())->method('save');

        $registry = new EvidenceSourceRegistry($this->createMock(LoggerInterface::class));
        $registry->withProviders(
            [
                $this->provider(
                    'or-objects',
                    [new EvidenceItem('or-objects', 'sha256:aaa', EvidenceItem::STATUS_COLLECTED)]
                ),
            ]
        );

        $service = new EvidenceHarvestService($registry, $accessor, $this->createMock(LoggerInterface::class));
        $result  = $service->harvest(caseUuid: '00000000-0000-0000-0000-000000000000');

        $this->assertSame(0, $result['appended']);
        $this->assertSame(1, $result['skipped']);

    }//end testReHarvestDoesNotDuplicate()

    /**
     * An unregistered source contributes nothing (empty registry → no items).
     *
     * @return void
     */
    public function testUnregisteredSourceContributesNothing(): void
    {
        $case     = $this->caseEntity();
        $accessor = $this->createMock(CaseObjectAccessor::class);
        $accessor->method('load')->willReturn($case);
        $accessor->expects($this->never())->method('save');

        $registry = new EvidenceSourceRegistry($this->createMock(LoggerInterface::class));

        $service = new EvidenceHarvestService($registry, $accessor, $this->createMock(LoggerInterface::class));
        $result  = $service->harvest(caseUuid: '00000000-0000-0000-0000-000000000000');

        $this->assertSame(0, $result['appended']);
        $this->assertSame([], $result['providers']);

    }//end testUnregisteredSourceContributesNothing()

    /**
     * A disabled provider is skipped.
     *
     * @return void
     */
    public function testDisabledProviderSkipped(): void
    {
        $case     = $this->caseEntity();
        $accessor = $this->createMock(CaseObjectAccessor::class);
        $accessor->method('load')->willReturn($case);
        $accessor->expects($this->never())->method('save');

        $registry = new EvidenceSourceRegistry($this->createMock(LoggerInterface::class));
        $registry->withProviders(
            [
                $this->provider(
                    'disabled-src',
                    [new EvidenceItem('disabled-src', 'sha256:zzz')],
                    false
                ),
            ]
        );

        $service = new EvidenceHarvestService($registry, $accessor, $this->createMock(LoggerInterface::class));
        $result  = $service->harvest(caseUuid: '00000000-0000-0000-0000-000000000000');

        $this->assertSame(0, $result['appended']);
        $this->assertNotContains('disabled-src', $result['providers']);

    }//end testDisabledProviderSkipped()
}//end class
