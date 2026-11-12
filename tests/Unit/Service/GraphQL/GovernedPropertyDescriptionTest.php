<?php

/**
 * The generated types describe a governed property only to a caller who may read it.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service\GraphQL
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 *
 * @spec openspec/changes/schema-shape-exposure/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\GraphQL;

use GraphQL\Type\Definition\FieldDefinition;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Schema as GraphQLSchema;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Search\Service\GraphQL\SchemaGenerator;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\Lifecycle\StateFieldRuleResolver;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers no metadata on purpose: it drives the generator through every collaborator it builds.
 */
class GovernedPropertyDescriptionTest extends TestCase {
	private const PROPERTIES = ['name', 'salary', 'notes', 'motto', 'badge', 'odd'];

	private const GROUPS = ['alice' => ['hr'], 'bob' => ['staff'], 'root' => ['admin']];

	private static function schema(): Schema {
		$schema = new Schema();
		$schema->setId(1);
		$schema->setSlug('people');
		$schema->setTitle('People');
		$schema->setProperties(
			[
				'name'   => ['type' => 'string'],
				'salary' => ['type' => 'integer', 'authorization' => ['read' => ['hr']]],
				'notes'  => ['type' => 'string', 'authorization' => ['read' => [['group' => 'hr', 'match' => ['status' => 'open']]]]],
				'motto'  => ['type' => 'string', 'authorization' => ['read' => [['group' => 'public', 'match' => ['status' => 'open']]]]],
				'badge'  => ['type' => 'string', 'authorization' => ['update' => ['hr']]],
				'odd'    => ['type' => 'string', 'authorization' => ['read' => [['match' => ['status' => 'open']]]]],
			]
		);

		return $schema;
	}//end schema()

	private function rbac(IUserSession $session): PropertyRbacHandler {
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('getUserGroupIds')->willReturnCallback(
			static fn (IUser $user): array => self::GROUPS[$user->getUID()]
		);

		return new PropertyRbacHandler(caller: new \OCA\OpenRegister\Service\PropertyRbacCaller($session, $groups), rules: new \OCA\OpenRegister\Service\PropertyRbacRules($this->createMock(ConditionMatcher::class), $this->createMock(StateFieldRuleResolver::class)), logger: $this->createMock(LoggerInterface::class));
	}//end rbac()

	private function generated(?string $uid): GraphQLSchema {
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$mapper = $this->createMock(SchemaMapper::class);
		$mapper->method('findAll')->willReturn([self::schema()]);

		return (new SchemaGenerator($mapper, $session, $this->rbac($session)))->generate();
	}//end generated()

	private function fieldsOfType(GraphQLSchema $schema, string $name): array {
		$type = $schema->getType($name);
		$this->assertInstanceOf(InputObjectType::class, $type);

		return array_keys($type->getFields());
	}//end fieldsOfType()

	public static function callers(): array {
		return [
			'in the group'     => ['alice', ['name', 'salary', 'badge']],
			'outside it'       => ['bob', ['name', 'badge']],
			'anonymous'        => [null, ['name', 'badge']],
			'an administrator' => ['root', ['name', 'salary', 'notes', 'motto', 'badge', 'odd']],
		];
	}//end callers()

	#[DataProvider('callers')]
	public function testTheFilterTypeFollowsTheReadRule(?string $uid, array $expected): void {
		$fields = $this->fieldsOfType($this->generated($uid), 'PeopleFilter');

		$this->assertSame($expected, array_values(array_intersect($fields, self::PROPERTIES)));
	}//end testTheFilterTypeFollowsTheReadRule()

	#[DataProvider('callers')]
	public function testTheInputTypeFollowsTheReadRule(?string $uid, array $expected): void {
		$fields = $this->fieldsOfType($this->generated($uid), 'CreatePeopleInput');

		$this->assertSame($expected, $fields);
	}//end testTheInputTypeFollowsTheReadRule()

	public static function objectFields(): array {
		return [
			'in the group'     => ['alice', ['name', 'salary', 'notes', 'motto', 'badge']],
			'outside it'       => ['bob', ['name', 'motto', 'badge']],
			'anonymous'        => [null, ['name', 'motto', 'badge']],
			'an administrator' => ['root', ['name', 'salary', 'notes', 'motto', 'badge', 'odd']],
		];
	}//end objectFields()

	#[DataProvider('objectFields')]
	public function testTheObjectTypeKeepsWhatSomeRowCouldShow(?string $uid, array $expected): void {
		$type = $this->generated($uid)->getType('People');
		$this->assertInstanceOf(ObjectType::class, $type);

		$this->assertSame($expected, array_values(array_intersect($type->getFieldNames(), self::PROPERTIES)));
	}//end testTheObjectTypeKeepsWhatSomeRowCouldShow()

	#[DataProvider('objectFields')]
	public function testOnlyAnAdministratorReadsTheGroupsOfARule(?string $uid): void {
		$type = $this->generated($uid)->getType('People');
		$this->assertInstanceOf(ObjectType::class, $type);
		$notes = array_map(static fn (FieldDefinition $field): ?string => $field->description, $type->getFields());

		if ($uid === 'root') {
			$this->assertSame('Requires group: hr', $notes['salary']);
			return;
		}

		$this->assertStringNotContainsString('Requires', implode(' ', array_filter($notes)));
	}//end testOnlyAnAdministratorReadsTheGroupsOfARule()
}//end class
