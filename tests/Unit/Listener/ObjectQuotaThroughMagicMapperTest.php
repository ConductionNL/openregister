<?php

/**
 * The per-organisation quota through the REAL MagicMapper count path (live pass O10).
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
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/object-quota-per-organisation/specs/tenant-quotas/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicTableHandler;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Listener\ObjectQuotaListener;
use OCA\OpenRegister\Service\Quota\ObjectQuotaService;
use OCP\DB\IPreparedStatement;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Live (5 Oct): cap 2, three creates, three 201s. The count went through
 * MagicMapper::searchObjects(), whose register+schema path turns the integer
 * count the SQL layer answers into [], so every count "failed" and the listener
 * let the create through. Here the mapper, the quota service and the listener
 * are real; only the SQL layer (MagicSearchHandler, MagicTableHandler) is a
 * double, and it answers a `_count` query with an integer as the database does.
 */
class ObjectQuotaThroughMagicMapperTest extends TestCase {

	/** @var array<int, array<string, mixed>> The queries the SQL layer was asked. */
	private array $queries = [];

	/**
	 * The listener over the real service and the real mapper.
	 *
	 * @param int|\Throwable $answer What the SQL layer answers a count with.
	 *
	 * @return ObjectQuotaListener
	 */
	private function listener(int|\Throwable $answer): ObjectQuotaListener {
		$register = new Register();
		$register->setId(103);
		$schema = new Schema();
		$schema->setId(1685);
		$schema->setConfiguration([ObjectQuotaService::ANNOTATION => ['perOrganisation' => 2]]);

		$registers = $this->createMock(RegisterMapper::class);
		$registers->method('find')->willReturn($register);
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('find')->willReturn($schema);

		$search = $this->createMock(MagicSearchHandler::class);
		$search->method('searchObjects')->willReturnCallback(
			function (array $query) use ($answer) {
				$this->queries[] = $query;
				if ($answer instanceof \Throwable) {
					throw $answer;
				}

				return (($query['_count'] ?? false) === true) ? $answer : [];
			}
		);
		$tables = $this->createMock(MagicTableHandler::class);
		$tables->method('existsTableForRegisterSchema')->willReturn(true);
		$tables->method('getTableNameForRegisterSchema')->willReturn('openregister_table_103_1685');

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			fn (string $id) => match ($id) {
				\OCA\OpenRegister\Service\DateTimeNormalizer::class => $this->createMock(\OCA\OpenRegister\Service\DateTimeNormalizer::class),
				\OCA\OpenRegister\Service\ConditionMatcher::class => $this->createMock(\OCA\OpenRegister\Service\ConditionMatcher::class),
				\OCA\OpenRegister\Service\Object\SchemaTypeConverter::class => $this->createMock(\OCA\OpenRegister\Service\Object\SchemaTypeConverter::class),
				default => null,
			}
		);

		// A statement that ends its rows as the database does (fetch() answers
		// false). A bare mock answers null, and the column-cache loop of the
		// search path never ends on that.
		$statement = $this->createMock(IPreparedStatement::class);
		$statement->method('fetch')->willReturn(false);
		$db = $this->createMock(IDBConnection::class);
		$db->method('prepare')->willReturn($statement);

		$mapper = new MagicMapper(
			$db,
			$schemas,
			$registers,
			$this->createMock(IConfig::class),
			$this->createMock(IEventDispatcher::class),
			$this->createMock(IUserSession::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(IUserManager::class),
			$this->createMock(IAppConfig::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(\OCA\OpenRegister\Service\SettingsService::class),
			$container
		);
		foreach (['tableHandler' => $tables, 'searchHandler' => $search] as $property => $value) {
			$reflection = new \ReflectionProperty(MagicMapper::class, $property);
			$reflection->setAccessible(true);
			$reflection->setValue($mapper, $value);
		}

		return new ObjectQuotaListener(
			quotas: new ObjectQuotaService(objects: $mapper, registers: $registers),
			schemas: $schemas,
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end listener()

	/**
	 * A create by organisation 3b15b928 in register 103, schema 1685.
	 *
	 * @param ObjectQuotaListener $listener The listener.
	 *
	 * @return ObjectCreatingEvent
	 */
	private function create(ObjectQuotaListener $listener): ObjectCreatingEvent {
		$object = new ObjectEntity();
		$object->setRegister('103');
		$object->setSchema('1685');
		$object->setOrganisation('3b15b928');
		$event = new ObjectCreatingEvent($object);
		$listener->handle($event);
		return $event;
	}//end create()

	/**
	 * The third create past a cap of 2 is refused (live: 201).
	 *
	 * @return void
	 */
	public function testTheCreatePastTheCapIsRefusedThroughTheRealCount(): void {
		$event = $this->create(listener: $this->listener(answer: 2));

		$this->assertTrue($event->isPropagationStopped(), 'Cap 2 with 2 stored: the create must be refused.');
		$this->assertSame(ObjectQuotaListener::ERROR_CODE, $event->getErrors()['code']);
		$this->assertSame('3b15b928', $this->queries[0]['@self']['organisation']);
	}//end testTheCreatePastTheCapIsRefusedThroughTheRealCount()

	/**
	 * Below the cap the create goes through.
	 *
	 * @return void
	 */
	public function testACreateBelowTheCapIsAllowed(): void {
		$this->assertFalse($this->create(listener: $this->listener(answer: 1))->isPropagationStopped());
	}//end testACreateBelowTheCapIsAllowed()

	/**
	 * A count that cannot be made refuses the create (fail closed), with its own code.
	 *
	 * @return void
	 */
	public function testACountThatCannotBeMadeRefusesTheCreate(): void {
		$event = $this->create(listener: $this->listener(answer: new RuntimeException('database went away')));

		$this->assertTrue($event->isPropagationStopped(), 'A quota that cannot be checked must not let the create through.');
		$this->assertSame(ObjectQuotaListener::ERROR_CODE_UNCHECKED, $event->getErrors()['code']);
	}//end testACountThatCannotBeMadeRefusesTheCreate()
}//end class
