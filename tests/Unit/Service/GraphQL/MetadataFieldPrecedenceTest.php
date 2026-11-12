<?php

/**
 * A schema property cannot take the place of a metadata field of a GraphQL object type.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service\GraphQL
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\GraphQL;

use GraphQL\GraphQL;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Search\Service\GraphQL\SchemaGenerator;
use OCA\OpenRegister\Service\GraphQL\GraphQLResolver;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Covers no metadata on purpose: it drives the generator through every collaborator it builds.
 */
class MetadataFieldPrecedenceTest extends TestCase {
	private const ROW = [
		'title'       => 'Dossier',
		'_owner'      => 'alice',
		'_auditTrail' => [['action' => 'read', 'user' => 'bob']],
	];

	private static function schema(int $id, string $slug, array $properties): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setSlug($slug);
		$schema->setTitle(ucfirst($slug));
		$schema->setProperties($properties);

		return $schema;
	}//end schema()

	private static function clashing(): Schema {
		return self::schema(
			1,
			'records',
			[
				'title'       => ['type' => 'string'],
				'!owner'      => ['type' => 'string'],
				'!auditTrail' => ['type' => 'array', 'items' => ['type' => 'object']],
				'!uuid'       => ['type' => 'string'],
			]
		);
	}//end clashing()

	private function generator(array $schemas, ?IUser $user): SchemaGenerator {
		$mapper = $this->createMock(SchemaMapper::class);
		$mapper->method('findAll')->willReturn($schemas);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$resolver = $this->createMock(GraphQLResolver::class);
		$resolver->method('resolveSingle')->willReturn(self::ROW);

		$generator = new SchemaGenerator($mapper, $session, $this->createMock(PropertyRbacHandler::class));
		$generator->setResolver($resolver);

		return $generator;
	}//end generator()

	private function answer(array $schemas, ?IUser $user, string $query): array {
		$result = GraphQL::executeQuery($this->generator($schemas, $user)->generate(), $query)->toArray();
		$this->assertArrayNotHasKey('errors', $result);

		return $result['data'];
	}//end answer()

	public function testTheOwnerFieldStaysTheMetadataField(): void {
		$data = $this->answer([self::clashing()], null, '{ record(id: "1") { title _owner } }');

		$this->assertSame(['title' => 'Dossier', '_owner' => null], $data['record']);
	}//end testTheOwnerFieldStaysTheMetadataField()

	public function testASignedInCallerReadsTheOwner(): void {
		$user = $this->createMock(IUser::class);
		$data = $this->answer([self::clashing()], $user, '{ record(id: "1") { _owner } }');

		$this->assertSame('alice', $data['record']['_owner']);
	}//end testASignedInCallerReadsTheOwner()

	public function testTheAuditTrailFieldStaysTheMetadataField(): void {
		$schema = self::clashing();
		$user = $this->createMock(IUser::class);
		$field = $this->generator([$schema], $user)->getObjectType($schema)->getField('_auditTrail');

		$this->assertNotNull($field->resolveFn);
		$this->assertNull(($field->resolveFn)(self::ROW));
	}//end testTheAuditTrailFieldStaysTheMetadataField()

	public function testTheUuidFieldKeepsItsType(): void {
		$schema = self::clashing();
		$type = $this->generator([$schema], null)->getObjectType($schema);

		$this->assertSame('UUID', (string)$type->getField('_uuid')->getType());
	}//end testTheUuidFieldKeepsItsType()

	public function testAChildSchemaKeepsItsMetadataOverAParentProperty(): void {
		$child = self::schema(2, 'childs', ['eigen' => ['type' => 'string']]);
		$child->setAllOf(['records']);

		$data = $this->answer([self::clashing(), $child], null, '{ child(id: "1") { title _owner } }');

		$this->assertSame(['title' => 'Dossier', '_owner' => null], $data['child']);
	}//end testAChildSchemaKeepsItsMetadataOverAParentProperty()

	public function testAnOrdinaryPropertyKeepsItsFieldAfterTheMetadata(): void {
		$schema = self::schema(1, 'records', ['title' => ['type' => 'string']]);
		$names = $this->generator([$schema], null)->getObjectType($schema)->getFieldNames();

		$this->assertSame(['_uuid', '_register', '_schema', '_created', '_updated', '_owner', '_auditTrail', 'title', '_usedBy'], $names);
	}//end testAnOrdinaryPropertyKeepsItsFieldAfterTheMetadata()
}//end class
