<?php

/**
 * What SaveObject records when it soft-deletes, skips or fails an orphan.
 *
 * Pins the info record of a soft-deleted orphan, that a refused orphan is
 * neither updated nor logged, that a missing row is skipped silently, and the
 * warning of a failing lookup.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use Exception;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\OrphanDeletionGuard;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use ReflectionClass;
use Stringable;

/**
 * deleteOrphanedRelatedObjects' records, without a database.
 *
 * @covers \OCA\OpenRegister\Service\Object\SaveObject
 */
final class SaveObjectOrphanDeletionLogTest extends TestCase {

	/**
	 * Every log record, as [level, message, uuid, error].
	 *
	 * @var array<int, array{0: mixed, 1: string, 2: mixed, 3: mixed}>
	 */
	private array $records = [];

	/**
	 * Every update the mapper received, by orphan uuid.
	 *
	 * @var array<int, string|null>
	 */
	private array $updates = [];

	/**
	 * Run the cleanup of `row-1` with a mapper that finds or throws, and a guard that allows or refuses.
	 *
	 * @param Exception|null $lookupFailure What the lookup throws, null to find the row.
	 * @param bool           $allowed       What the guard answers.
	 *
	 * @return void
	 */
	private function cleanUp(?Exception $lookupFailure, bool $allowed): void {
		$handler = $this->getMockBuilder(SaveObject::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
		$row = new ObjectEntity();
		$row->setUuid('row-1');
		$row->setObject(['incident' => 'p-1']);
		$mapper = $this->createMock(MagicMapperInterface::class);
		if ($lookupFailure !== null) {
			$mapper->method('find')->willThrowException($lookupFailure);
		} else {
			$mapper->method('find')->willReturn($row);
		}

		$mapper->method('update')->willReturnCallback(
			function (ObjectEntity $entity) {
				$this->updates[] = $entity->getUuid();

				return $entity;
			}
		);
		$guard = $this->createMock(OrphanDeletionGuard::class);
		$guard->method('allows')->willReturn($allowed);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('carol');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$logger = new class ($this->records) extends AbstractLogger {
			/**
			 * @param array $records The test's record list.
			 */
			public function __construct(private array &$records) {
			}

			/**
			 * @param mixed             $level   The level.
			 * @param string|Stringable $message The message.
			 * @param array             $context The context.
			 */
			public function log($level, string|Stringable $message, array $context = []): void {
				$this->records[] = [$level, (string) $message, $context['uuid'] ?? null, $context['error'] ?? null];
			}
		};

		$reflection = new ReflectionClass(SaveObject::class);
		$reflection->getProperty('objectEntityMapper')->setValue($handler, $mapper);
		$reflection->getProperty('orphanGuard')->setValue($handler, $guard);
		$reflection->getProperty('userSession')->setValue($handler, $session);
		$reflection->getProperty('logger')->setValue($handler, $logger);
		$scope = ['register' => new Register(), 'schema' => new Schema(), 'inverse' => 'incident', 'parent' => 'p-1'];
		$reflection->getMethod('deleteOrphanedRelatedObjects')->invoke($handler, ['row-1'], $scope);
	}//end cleanUp()

	/**
	 * An allowed orphan is updated once and logged at info level with its uuid.
	 *
	 * @return void
	 */
	public function testADeletedOrphanIsLogged(): void {
		$this->cleanUp(lookupFailure: null, allowed: true);

		$this->assertSame(['row-1'], $this->updates);
		$this->assertSame([['info', '[SaveObject] Soft-deleted orphaned related object', 'row-1', null]], $this->records);
	}//end testADeletedOrphanIsLogged()

	/**
	 * A refused orphan is neither updated nor logged.
	 *
	 * @return void
	 */
	public function testARefusedOrphanIsLeftAndNotLogged(): void {
		$this->cleanUp(lookupFailure: null, allowed: false);

		$this->assertSame([[], []], [$this->updates, $this->records]);
	}//end testARefusedOrphanIsLeftAndNotLogged()

	/**
	 * A missing row is skipped silently; another failure is a warning with the error.
	 *
	 * @return void
	 */
	public function testAMissingRowIsSilentAndAFailureIsAWarning(): void {
		$this->cleanUp(lookupFailure: new DoesNotExistException('gone'), allowed: true);
		$this->assertSame([], $this->records);

		$this->cleanUp(lookupFailure: new Exception('db down'), allowed: true);
		$this->assertSame([['warning', '[SaveObject] Failed to delete orphaned related object', 'row-1', 'db down']], $this->records);
		$this->assertSame([], $this->updates);
	}//end testAMissingRowIsSilentAndAFailureIsAWarning()
}//end class
