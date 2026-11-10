<?php

/**
 * MasterRecomputeService: a source-record change re-persists its master so the golden record is rebuilt.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Merge
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Merge;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Merge\MasterRecomputeService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Service\Object\ObjectSaveOptions;

/**
 * Each branch of one recompute.
 *
 * @covers \OCA\OpenRegister\Service\Merge\MasterRecomputeService
 * @uses \OCA\OpenRegister\Service\Merge\MergeService
 * @uses \OCA\OpenRegister\Service\WriteCause
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 */
class MasterRecomputeServiceTest extends TestCase {

	/**
	 * An empty uuid is not looked up.
	 *
	 * @return void
	 */
	public function testAnEmptyUuidIsIgnored(): void {
		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->expects($this->never())->method('find');
		$objects->expects($this->never())->method('saveObject');

		(new MasterRecomputeService($objects, $this->createMock(LoggerInterface::class)))->recompute('');
	}//end testAnEmptyUuidIsIgnored()

	/**
	 * A master that is not found is not saved.
	 *
	 * @return void
	 */
	public function testAMissingMasterIsNotSaved(): void {
		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->expects($this->once())->method('find')->with('m-1')->willReturn(null);
		$objects->expects($this->never())->method('saveObject');

		(new MasterRecomputeService($objects, $this->createMock(LoggerInterface::class)))->recompute('m-1');
	}//end testAMissingMasterIsNotSaved()

	/**
	 * A found master is re-saved in place, with stored date strings normalised back to ISO, nested ones too.
	 *
	 * @return void
	 */
	public function testAFoundMasterIsResavedWithIsoDates(): void {
		$master = new ObjectEntity();
		$master->setUuid('m-1');
		$master->setRegister('3');
		$master->setSchema('7');
		$master->setObject(['born' => '2001-02-03 04:05:06', 'name' => 'Ann', 'address' => ['since' => '2020-01-01 00:00:00']]);
		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->method('find')->with('m-1', [], false, null, null, true, true)->willReturn($master);
		$saved = null;
		$objects->expects($this->once())->method('saveObject')
			->with($this->identicalTo($master), $this->callback(static fn (ObjectSaveOptions $options): bool => $options->extend == [] && $options->target->register == '3' && $options->target->schema == '7' && $options->uuid == 'm-1'))
			->willReturnCallback(
				function (array|ObjectEntity $object, ObjectSaveOptions $options = new ObjectSaveOptions(), bool $_rbac = true, bool $_multitenancy = true) use (&$saved): ObjectEntity {
					$saved = $object->getObject();
					return $object;
				}
			);

		(new MasterRecomputeService($objects, $this->createMock(LoggerInterface::class)))->recompute('m-1');

		$this->assertSame('2001-02-03T04:05:06+00:00', $saved['born']);
		$this->assertSame('Ann', $saved['name']);
		$this->assertSame('2020-01-01T00:00:00+00:00', $saved['address']['since']);
	}//end testAFoundMasterIsResavedWithIsoDates()

	/**
	 * A failing save is logged as a warning naming the master, and does not escape.
	 *
	 * @return void
	 */
	public function testAFailureIsLoggedAndSwallowed(): void {
		$master = new ObjectEntity();
		$master->setUuid('m-1');
		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->method('find')->willReturn($master);
		$objects->method('saveObject')->willThrowException(new RuntimeException('invalid'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('Source-record change: could not recompute master "m-1": invalid');

		(new MasterRecomputeService($objects, $logger))->recompute('m-1');
	}//end testAFailureIsLoggedAndSwallowed()
}//end class
