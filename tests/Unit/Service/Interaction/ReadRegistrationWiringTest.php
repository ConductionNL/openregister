<?php

/**
 * The read registration is asserted from its CALLERS, not only the class.
 *
 * A ReadHistoryService with a full test suite and no call site would record
 * nothing. These tests drive the real callers (GetObject::find(), the
 * ObjectService processing-log hook, the search handler's ordering and
 * `@self.viewedAt`) and assert they reach the abstraction.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Interaction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/read-history-on-audit-trail/specs/object-interactions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Interaction;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Interaction\ReadHistoryService;
use OCA\OpenRegister\Service\Object\GetObject;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\ObjectSource\ObjectSourceRegistry;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCP\AppFramework\IAppContainer;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * @coversNothing
 */
class ReadRegistrationWiringTest extends TestCase {

	/**
	 * GetObject::find() registers the read through ReadHistoryService and
	 * passes the per-call `_audit` flag on.
	 *
	 * @return void
	 */
	public function testGetObjectRegistersTheReadThroughTheAbstraction(): void {
		$object = new ObjectEntity();
		$object->setUuid('uuid-a');

		$mapper = $this->createMock(MagicMapper::class);
		$mapper->method('find')->willReturn($object);

		$history = $this->createMock(ReadHistoryService::class);
		$history->expects($this->exactly(2))
			->method('registerAuditRead')
			->willReturnCallback(
				function (ObjectEntity $read, bool $audit) use ($object): ?AuditTrail {
					$this->assertSame($object, $read);
					return $audit === true ? new AuditTrail() : null;
				}
			);

		$getObject = new GetObject(
			$mapper,
			$this->createMock(AuditTrailMapper::class),
			$history,
			$this->createMock(ObjectSourceRegistry::class),
			$this->createMock(LoggerInterface::class)
		);

		$getObject->find(id: 'uuid-a');
		$getObject->find(id: 'uuid-a', _audit: false);
	}//end testGetObjectRegistersTheReadThroughTheAbstraction()

	/**
	 * ObjectService's processing-log hook goes through ReadHistoryService.
	 *
	 * @return void
	 */
	public function testObjectServiceLogsProcessingThroughTheAbstraction(): void {
		$object = new ObjectEntity();

		$history = $this->createMock(ReadHistoryService::class);
		$history->expects($this->once())->method('registerProcessingRead')->with($object);

		$container = $this->createMock(IAppContainer::class);
		$container->method('get')->with(ReadHistoryService::class)->willReturn($history);

		$service = (new ReflectionClass(ObjectService::class))->newInstanceWithoutConstructor();
		$logger = $this->createMock(LoggerInterface::class);
		(function () use ($container, $logger): void {
			$this->container = $container;
			$this->logger = $logger;
		})->call($service);

		$hook = new \ReflectionMethod(ObjectService::class, 'logProcessingRead');
		$hook->invoke($service, $object);
	}//end testObjectServiceLogsProcessingThroughTheAbstraction()

	/**
	 * A `_recent` page is ordered by the history's position, through bound
	 * parameters and with no table name in the SQL (openregister#4507).
	 *
	 * @return void
	 */
	public function testTheRecencyOrderFollowsTheHistory(): void {
		$params = [];
		$orders = [];

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('createNamedParameter')->willReturnCallback(
			function ($value) use (&$params): string {
				$params[] = $value;
				return ':p'.(count($params) - 1);
			}
		);
		$qb->method('createFunction')->willReturnCallback(
			function (string $sql): IQueryFunction {
				$function = $this->createMock(IQueryFunction::class);
				$function->method('__toString')->willReturn($sql);
				return $function;
			}
		);
		$qb->method('addOrderBy')->willReturnCallback(
			function ($sort, $direction) use (&$orders, $qb) {
				$orders[] = [(string)$sort, $direction];
				return $qb;
			}
		);

		$handler = (new ReflectionClass(MagicSearchHandler::class))->newInstanceWithoutConstructor();
		$order = new \ReflectionMethod(MagicSearchHandler::class, 'applyRecencyOrder');

		$applied = $order->invoke(
			$handler,
			$qb,
			['uuid-b' => '2026-10-09T10:00:00+00:00', 'uuid-a' => '2026-10-08T09:00:00+00:00']
		);

		$this->assertTrue($applied);
		$this->assertSame(['uuid-b', 'uuid-a'], $params);
		$this->assertSame(
			[
				['CASE t._uuid WHEN :p0 THEN 0 WHEN :p1 THEN 1 ELSE 2 END', 'ASC'],
				['t._id', 'ASC'],
			],
			$orders
		);
		$this->assertStringNotContainsString('audit', $orders[0][0]);
	}//end testTheRecencyOrderFollowsTheHistory()

	/**
	 * Without a history the default order stays.
	 *
	 * @return void
	 */
	public function testNoHistoryNoRecencyOrder(): void {
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->expects($this->never())->method('addOrderBy');

		$handler = (new ReflectionClass(MagicSearchHandler::class))->newInstanceWithoutConstructor();
		$order = new \ReflectionMethod(MagicSearchHandler::class, 'applyRecencyOrder');

		$this->assertFalse($order->invoke($handler, $qb, null));
	}//end testNoHistoryNoRecencyOrder()

	/**
	 * Each object on a `_recent` page carries `@self.viewedAt`, and only there.
	 *
	 * @return void
	 */
	public function testViewedAtLandsInSelf(): void {
		$seen = new ObjectEntity();
		$seen->setUuid('uuid-a');
		$other = new ObjectEntity();
		$other->setUuid('uuid-z');

		$handler = (new ReflectionClass(MagicSearchHandler::class))->newInstanceWithoutConstructor();
		$apply = new \ReflectionMethod(MagicSearchHandler::class, 'applyViewedAt');
		$apply->invoke($handler, [$seen, $other], ['uuid-a' => '2026-10-09T10:00:00+00:00']);

		$this->assertSame('2026-10-09T10:00:00+00:00', $seen->getObjectArray()['viewedAt'] ?? null);
		$this->assertArrayNotHasKey('viewedAt', $other->getObjectArray());
	}//end testViewedAtLandsInSelf()
}//end class
