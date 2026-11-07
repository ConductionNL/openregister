<?php

/**
 * `update` and `delete` ask the RBAC and organisation guards before anything else.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use Exception;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Schemas\PropertyValidatorHandler;
use OCP\AppFramework\Db\Entity;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Db\SchemaMapper
 * @uses   \OCA\OpenRegister\Db\Schema
 */
final class SchemaMapperWriteGuardsTest extends TestCase {

	/**
	 * A mapper whose guards log their question and refuse at the named one.
	 *
	 * @param string              $refuseAt `rbac` or `organisation`.
	 * @param \ArrayObject<int, string> $asked    Receives the questions, in order.
	 */
	private function mapper(string $refuseAt, \ArrayObject $asked): SchemaMapper {
		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->never())->method('getQueryBuilder');
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->expects($this->never())->method('dispatchTyped');

		return new class(
			$db,
			$dispatcher,
			$this->createMock(PropertyValidatorHandler::class),
			$this->createMock(OrganisationMapper::class),
			$this->createMock(IUserSession::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(IAppConfig::class),
			$this->createMock(LoggerInterface::class),
			$refuseAt,
			$asked
		) extends SchemaMapper {
			public function __construct(
				IDBConnection $db,
				IEventDispatcher $events,
				PropertyValidatorHandler $validator,
				OrganisationMapper $organisations,
				IUserSession $session,
				IGroupManager $groups,
				IAppConfig $config,
				LoggerInterface $logger,
				private string $refuseAt,
				private \ArrayObject $asked,
			) {
				parent::__construct($db, $events, $validator, $organisations, $session, $groups, $config, $logger);
			}

			protected function verifyRbacPermission(string $action, string $entityType): void {
				$this->asked[] = 'rbac ' . $action . ' ' . $entityType;
				if ($this->refuseAt === 'rbac') {
					throw new Exception('refused', 403);
				}
			}

			protected function verifyOrganisationAccess(Entity $entity): void {
				$this->asked[] = 'organisation ' . $entity->getId();
				if ($this->refuseAt === 'organisation') {
					throw new Exception('refused', 403);
				}
			}
		};
	}//end mapper()

	public static function refusals(): array {
		$update = static fn (SchemaMapper $mapper, Schema $schema) => $mapper->update($schema);
		$delete = static fn (SchemaMapper $mapper, Schema $schema) => $mapper->delete($schema);
		$force = static fn (SchemaMapper $mapper, Schema $schema) => $mapper->delete($schema, true);

		return [
			'update, rbac' => [$update, 'rbac', ['rbac update schema']],
			'update, organisation' => [$update, 'organisation', ['rbac update schema', 'organisation 7']],
			'delete, rbac' => [$delete, 'rbac', ['rbac delete schema']],
			'delete, organisation' => [$delete, 'organisation', ['rbac delete schema', 'organisation 7']],
			'forced delete, rbac' => [$force, 'rbac', ['rbac delete schema']],
			'forced delete, organisation' => [$force, 'organisation', ['rbac delete schema', 'organisation 7']],
		];
	}//end refusals()

	#[DataProvider('refusals')]
	public function testARefusalStopsTheWriteBeforeAnyQuery(callable $write, string $refuseAt, array $expected): void {
		$schema = new Schema();
		$schema->setId(7);
		$asked = new \ArrayObject();

		try {
			$write($this->mapper(refuseAt: $refuseAt, asked: $asked), $schema);
			$this->fail('The write went through.');
		} catch (Exception $refusal) {
			$this->assertSame(403, $refusal->getCode());
		}

		$this->assertSame($expected, $asked->getArrayCopy());
	}//end testARefusalStopsTheWriteBeforeAnyQuery()
}//end class
