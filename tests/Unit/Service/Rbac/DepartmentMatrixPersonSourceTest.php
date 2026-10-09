<?php

/**
 * A department matrix whose user source is a person schema (task 2.2b).
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Rbac\DepartmentMatrixCompiler;
use OCA\OpenRegister\Service\Rbac\DepartmentMatrixPersonValues;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The person source, through the class and through the permission handler.
 */
class DepartmentMatrixPersonSourceTest extends TestCase {

	private SchemaMapper&MockObject $schemas;

	private RegisterMapper&MockObject $registers;

	private MagicMapper&MockObject $objects;

	/**
	 * The searches the person reader made.
	 *
	 * @var array<int, array{query: array, rbac: bool}>
	 */
	private array $searches = [];

	protected function setUp(): void {
		parent::setUp();
		$this->schemas = $this->createMock(SchemaMapper::class);
		$this->registers = $this->createMock(RegisterMapper::class);
		$this->objects = $this->createMock(MagicMapper::class);

		$person = new Schema();
		$person->setId(40);
		$person->setSlug('person');
		$this->schemas->method('find')->willReturnCallback(
			static function ($id) use ($person): Schema {
				if ($id === 'person' || $id === 40 || $id === '40') {
					return $person;
				}

				throw new \OCP\AppFramework\Db\DoesNotExistException('no schema ' . $id);
			}
		);

		$register = new Register();
		$register->setId(7);
		$this->registers->method('getFirstRegisterWithSchema')->willReturn(7);
		$this->registers->method('find')->willReturn($register);
	}//end setUp()

	/**
	 * A person object, as the search returns it.
	 *
	 * @param array $data The object's data.
	 *
	 * @return ObjectEntity The object.
	 */
	private function person(array $data): ObjectEntity {
		$object = new ObjectEntity();
		$object->setObject($data);
		return $object;
	}//end person()

	/**
	 * Route the search to a fixed list and record what was asked.
	 *
	 * @param ObjectEntity[] $persons The answer.
	 *
	 * @return void
	 */
	private function searchAnswers(array $persons): void {
		$this->objects->method('searchObjects')->willReturnCallback(
			function (array $query = [], ?string $_activeOrgUuid = null, bool $_rbac = true) use ($persons): array {
				$this->searches[] = ['query' => $query, 'rbac' => $_rbac];
				return $persons;
			}
		);
	}//end searchAnswers()

	/**
	 * The person whose userId is the caller gives their departments, a list
	 * or a single value; the search runs without RBAC, against the person
	 * schema's register.
	 *
	 * @return void
	 */
	public function testThePersonsPropertyGivesTheCallersOwnValues(): void {
		$this->searchAnswers([$this->person(['userId' => 'anna', 'department' => ['VTH', 'Bouw']])]);
		$reader = new DepartmentMatrixPersonValues($this->schemas, $this->registers, $this->objects, $this->createMock(LoggerInterface::class));

		$values = $reader->valuesFor(['schema' => 'person', 'property' => 'department', 'match' => 'userId'], 'anna');

		$this->assertSame(['VTH', 'Bouw'], $values);
		$this->assertFalse($this->searches[0]['rbac'], 'the read must not re-enter the permission handler');
		$this->assertSame(['register' => '7', 'schema' => '40'], $this->searches[0]['query']['@self']);
		$this->assertSame('anna', $this->searches[0]['query']['userId']);
	}//end testThePersonsPropertyGivesTheCallersOwnValues()

	/**
	 * A person returned by the search whose match field is not the caller
	 * contributes nothing: a filter the search layer ignored must not turn
	 * every person's department into this user's.
	 *
	 * @return void
	 */
	public function testAPersonWhoIsNotTheCallerContributesNothing(): void {
		$this->searchAnswers([
			$this->person(['userId' => 'bob', 'department' => 'Belastingen']),
			$this->person(['userId' => 'anna', 'department' => 'VTH']),
		]);
		$reader = new DepartmentMatrixPersonValues($this->schemas, $this->registers, $this->objects, $this->createMock(LoggerInterface::class));

		$this->assertSame(['VTH'], $reader->valuesFor(['schema' => 'person', 'property' => 'department'], 'anna'));
	}//end testAPersonWhoIsNotTheCallerContributesNothing()

	/**
	 * A source that cannot be read gives no values, never an error.
	 *
	 * @return void
	 */
	public function testAnUnreadableSourceGivesNoValues(): void {
		$reader = new DepartmentMatrixPersonValues($this->schemas, $this->registers, $this->objects, $this->createMock(LoggerInterface::class));

		$this->assertSame([], $reader->valuesFor(['schema' => 'no-such-schema', 'property' => 'department'], 'anna'));
		$this->assertSame([], $reader->valuesFor(['schema' => 'person', 'property' => 'department'], null));
	}//end testAnUnreadableSourceGivesNoValues()

	/**
	 * Through the caller: a schema whose matrix reads the person source
	 * compiles a `$self` row into a scope on the person's departments.
	 * Before 2.2b the person source compiled nothing.
	 *
	 * @return void
	 */
	public function testResolveAuthorizationCompilesAPersonSourcedSelfRow(): void {
		$this->searchAnswers([$this->person(['userId' => 'anna', 'department' => 'VTH'])]);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('anna');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('getUserGroupIds')->willReturn(['behandelaars']);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			fn (string $id) => ($id === RegisterMapper::class ? $this->registers : null)
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueBool')->willReturnCallback(
			static fn (string $app, string $key, bool $default = false): bool => $default
		);

		$handler = new PermissionHandler(
			$session,
			$userManager,
			$groups,
			$this->schemas,
			$this->objects,
			$this->createMock(ConditionMatcher::class),
			$appConfig,
			$this->createMock(LoggerInterface::class),
			$container
		);

		$case = new Schema();
		$case->setId(41);
		$case->setSlug('case');
		$case->setAuthorization(
			[
				DepartmentMatrixCompiler::KEY => [
					'field' => 'department',
					'userSource' => ['schema' => 'person', 'property' => 'department', 'match' => 'userId'],
					'rows' => [['value' => '$self', 'group' => 'behandelaars', 'actions' => ['read']]],
				],
			]
		);

		$resolved = $handler->resolveAuthorization($case);

		$this->assertContains(
			['group' => 'behandelaars', 'match' => ['department' => ['$in' => ['VTH']]]],
			($resolved['read'] ?? [])
		);
	}//end testResolveAuthorizationCompilesAPersonSourcedSelfRow()
}//end class
