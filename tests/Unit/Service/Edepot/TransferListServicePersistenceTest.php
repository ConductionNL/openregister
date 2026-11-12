<?php

/**
 * Unit test for TransferListService's package format choice and its
 * best-effort persistence: a new list carries the configured format (zip when
 * the setting is unknown), and a failed save keeps the in-memory list.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Edepot
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Edepot;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Edepot\TransferListService;
use OCA\OpenRegister\Service\Edepot\TransferRecordService;
use OCP\IAppConfig;
use OCP\Notification\IManager as INotificationManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Edepot\TransferListService
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 */
class TransferListServicePersistenceTest extends TestCase {

	/**
	 * The transfer record store.
	 *
	 * @var TransferRecordService&MockObject
	 */
	private TransferRecordService&MockObject $records;

	/**
	 * The app config.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $config;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->records = $this->createMock(TransferRecordService::class);
		$this->config  = $this->createMock(IAppConfig::class);
		$this->logger  = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The service.
	 *
	 * @return TransferListService
	 */
	private function service(): TransferListService {
		return new TransferListService(new \OCA\OpenRegister\Service\Edepot\TransferStores(objects: $this->createMock(\OCA\OpenRegister\Db\MagicMapperInterface::class), records: $this->records, settings: $this->config, logger: $this->logger), $this->createMock(INotificationManager::class));
	}//end service()

	/**
	 * One object eligible for transfer.
	 *
	 * @return array<int, ObjectEntity>
	 */
	private function objects(): array {
		$object = new ObjectEntity();
		$object->setUuid('o-1');
		$object->setSchema('5');
		$object->setRegister('3');
		return [$object];
	}//end objects()

	/**
	 * A configured format the service knows is used, and saved.
	 *
	 * @return void
	 */
	public function testAKnownFormatIsUsed(): void {
		$this->config->method('getValueString')->with('openregister', 'edepot_package_format', 'zip')->willReturn('bagit');
		$this->records->expects($this->once())->method('saveTransferList')->willReturnCallback(fn (array $list): array => $list + ['saved' => true]);

		$list = $this->service()->createTransferList(objects: $this->objects());

		$this->assertSame('bagit', $list['packageFormat']);
		$this->assertTrue($list['saved']);
		$this->assertSame([['uuid' => 'o-1', 'schema' => '5', 'register' => '3']], $list['objectReferences']);
	}//end testAKnownFormatIsUsed()

	/**
	 * An unknown configured format falls back to zip.
	 *
	 * @return void
	 */
	public function testAnUnknownFormatFallsBackToZip(): void {
		$this->config->method('getValueString')->willReturn('tar.gz');
		$this->records->method('saveTransferList')->willReturnArgument(0);

		$this->assertSame('zip', $this->service()->createTransferList(objects: $this->objects())['packageFormat']);
	}//end testAnUnknownFormatFallsBackToZip()

	/**
	 * A save that fails is warned about with the list's uuid, and the in-memory list is returned.
	 *
	 * @return void
	 */
	public function testAFailedSaveKeepsTheInMemoryList(): void {
		$this->config->method('getValueString')->willReturn('zip');
		$this->records->method('saveTransferList')->willThrowException(new RuntimeException('register missing'));
		$warned = [];
		$this->logger->method('warning')->willReturnCallback(
			function (string $message, array $context) use (&$warned): void {
				$warned[] = [$message, $context['uuid']];
			}
		);

		$list = $this->service()->createTransferList(objects: $this->objects());

		$this->assertSame('in_review', $list['status']);
		$this->assertArrayNotHasKey('saved', $list);
		$this->assertSame([['[TransferListService] Could not persist transfer list: register missing', $list['uuid']]], $warned);
	}//end testAFailedSaveKeepsTheInMemoryList()
}//end class
