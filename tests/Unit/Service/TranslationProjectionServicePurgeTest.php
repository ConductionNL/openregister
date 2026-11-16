<?php

/**
 * Unit tests for TranslationProjectionService::purge().
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\TranslationMapper;
use OCA\OpenRegister\Service\Object\TranslationHandler;
use OCA\OpenRegister\Service\RegisterSchemaMappers;
use OCA\OpenRegister\Service\TranslationProjectionService;
use OCA\OpenRegister\Service\TranslationStores;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\TranslationProjectionService
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Service\RegisterSchemaMappers
 * @uses   \OCA\OpenRegister\Service\TranslationStores
 */
final class TranslationProjectionServicePurgeTest extends TestCase {

	/**
	 * @var TranslationMapper&MockObject
	 */
	private TranslationMapper $translationMapper;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The service under test.
	 *
	 * @var TranslationProjectionService
	 */
	private TranslationProjectionService $service;

	/**
	 * Build the service with mocked stores.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->translationMapper = $this->createMock(TranslationMapper::class);
		$this->logger            = $this->createMock(LoggerInterface::class);
		$this->service           = new TranslationProjectionService(
			new TranslationStores($this->translationMapper, $this->createMock(TranslationHandler::class)),
			new RegisterSchemaMappers($this->createMock(RegisterMapper::class), $this->createMock(SchemaMapper::class)),
			$this->createMock(IUserSession::class),
			$this->logger
		);
	}//end setUp()

	/**
	 * An object with this uuid.
	 *
	 * @param string|null $uuid The uuid.
	 *
	 * @return ObjectEntity
	 */
	private function object(?string $uuid): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		return $object;
	}//end object()

	/**
	 * purge() drops every translation row of the object.
	 *
	 * @return void
	 */
	public function testPurgeDeletesTheObjectsRows(): void {
		$this->translationMapper->expects($this->once())->method('deleteByObject')->with('uuid-1')->willReturn(3);
		$this->logger->expects($this->never())->method('warning');

		$this->service->purge($this->object('uuid-1'));
	}//end testPurgeDeletesTheObjectsRows()

	/**
	 * An object without a uuid has nothing to purge.
	 *
	 * @return void
	 */
	public function testPurgeWithoutUuidDoesNothing(): void {
		$this->translationMapper->expects($this->never())->method('deleteByObject');

		$this->service->purge($this->object(null));
		$this->service->purge($this->object(''));
	}//end testPurgeWithoutUuidDoesNothing()

	/**
	 * A failing delete is logged as a warning and not rethrown.
	 *
	 * @return void
	 */
	public function testPurgeFailureIsLoggedNotThrown(): void {
		$this->translationMapper->method('deleteByObject')->willThrowException(new RuntimeException('db down'));
		$this->logger->expects($this->once())->method('warning')
			->with('[TranslationProjection] purge failed for object uuid-2: db down');

		$this->service->purge($this->object('uuid-2'));
	}//end testPurgeFailureIsLoggedNotThrown()
}//end class
