<?php

/**
 * MergeRunTest
 *
 * The merge instruction, the run built from it, and MergeService::merge()'s self-merge refusal.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Merge
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/mdm-merge-engine/tasks.md#4.3
 */

declare(strict_types=1);

namespace Unit\Service\Merge;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Kernel\Contracts\MergeInstruction;
use OCA\OpenRegister\Service\Merge\MergePair;
use OCA\OpenRegister\Service\Merge\MergeReportingServices;
use OCA\OpenRegister\Service\Merge\MergeRun;
use OCA\OpenRegister\Service\Merge\MergeService;
use OCA\OpenRegister\Service\Merge\MergeSurvivorshipServices;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCA\OpenRegister\Service\Survivorship\SourceRecordResolver;
use OCA\OpenRegister\Service\Survivorship\SurvivorshipResolver;
use OCA\OpenRegister\Service\Survivorship\TrustTierResolver;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class MergeRunTest extends TestCase {

	public function testARunStartsFromBothPayloadsAndReadsSettingsWithDefaults(): void {
		$from = new ObjectEntity();
		$from->setObject(['name' => 'A']);
		$into = new ObjectEntity();

		$run = new MergeRun(
			instruction: new MergeInstruction(from: 'f', into: 'i', reason: 'duplicate', mergedBy: 'alice'),
			pair: new MergePair(fromObject: $from, intoObject: $into, schema: null, config: ['statusField' => 'state'])
		);

		$this->assertSame($from->getObject(), $run->fromData);
		$this->assertSame('A', $run->fromData['name']);
		$this->assertSame($into->getObject(), $run->intoData);
		$this->assertSame('state', $run->setting(key: 'statusField', default: 'status'));
		$this->assertSame('merged', $run->setting(key: 'mergedStatus', default: 'merged'));
		$this->assertSame(0, $run->relinkedCount);
		$this->assertNull($run->overrides);
		$this->assertNull($run->survivor);
	}//end testARunStartsFromBothPayloadsAndReadsSettingsWithDefaults()

	public function testMergeRefusesASelfMergeBeforeLoadingAnything(): void {
		$objects = $this->createMock(ObjectService::class);
		$objects->expects($this->never())->method('find');
		$schemas = $this->createMock(SchemaMapper::class);
		$logger  = $this->createMock(LoggerInterface::class);
		$service = new MergeService(
			$objects,
			$this->createMock(PropertyRbacHandler::class),
			new MergeSurvivorshipServices($schemas, new SurvivorshipResolver(), new TrustTierResolver(), new SourceRecordResolver($objects, $schemas, $logger)),
			new MergeReportingServices($this->createMock(IEventDispatcher::class), $this->createMock(AuditTrailMapper::class), $logger)
		);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Cannot merge an object into itself.');
		$service->merge(new MergeInstruction(from: 'same', into: 'same', reason: 'r', mergedBy: 'alice'));
	}//end testMergeRefusesASelfMergeBeforeLoadingAnything()
}//end class
