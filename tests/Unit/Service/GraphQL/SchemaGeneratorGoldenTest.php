<?php

/**
 * Golden test for the GraphQL schema SchemaGenerator builds.
 *
 * Prints the whole generated schema for schemas that exercise slug and type
 * naming, singular forms, title and description fallbacks, property
 * descriptions with authorisation notes, composition and references. Also
 * records how the mappers are read and what the resolvers answer: the owner
 * per caller, the audit trail and each query and mutation. Re-record with
 * UPDATE_GOLDEN=1.
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

use GraphQL\Type\Definition\ObjectType;
use GraphQL\Utils\SchemaPrinter;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\GraphQL\GraphQLResolver;
use OCA\OpenRegister\Search\Service\GraphQL\SchemaGenerator;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Covers no metadata on purpose: it drives the generator through every collaborator it builds.
 */
class SchemaGeneratorGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/schema-generator-golden.json';

	private array $log = [];

	private static function declarations(): array {
		return [
			[1, 'zaken', 'Zaken', 'Lopende zaken', null, ['naam' => ['type' => 'string', 'description' => 'De naam'], 'bsn-nummer' => ['type' => 'string', 'authorization' => ['read' => [['group' => 'admins']]]], 'kale_prop' => ['type' => 'integer', 'authorization' => ['read' => [['group' => 'x']]]], 'leeg' => ['type' => 'string', 'description' => ''], 'beide' => ['type' => 'string', 'description' => 'Met uitleg', 'authorization' => ['read' => [['group' => 'y']]]], 'geen-array' => 'x', '1start' => ['type' => 'boolean']]],
			[2, 'categories', '', '', 'Samenvatting', ['ref' => ['type' => 'object', '$ref' => 'zaken'], 'byid' => ['type' => 'array', 'items' => ['$ref' => '1']], 'numeriek' => ['type' => 'object', '$ref' => '3']]],
			[3, 'boxes', null, null, null, []],
			[4, 'bus', 'Bus', null, null, []],
			[5, 'glass', 'Glass', null, null, []],
			[6, 'pen', 'Pen', null, null, []],
			[7, 'eens', 'Korte', null, null, []],
			[8, 'first_second-third', 'Mixed', null, null, []],
			[9, 'first-second-third', 'Clash', null, null, []],
			[10, '', 'Geen slug', null, null, []],
			[11, null, null, null, null, []],
			[12, '9lives', 'Cijfer', null, null, []],
			[13, 'sa.me!', 'Tekens', null, null, []],
			[14, 'compose', 'Compose', null, null, ['eigen' => ['type' => 'string']]],
		];
	}//end declarations()

	private function schemas(): array {
		$schemas = [];
		foreach (self::declarations() as [$id, $slug, $title, $description, $summary, $properties]) {
			$schema = new Schema();
			$schema->setId($id);
			$schema->setSlug($slug);
			$schema->setTitle($title);
			$schema->setDescription($description);
			$schema->setSummary($summary);
			$schema->setProperties($properties);
			if ($id === 14) {
				$schema->setAllOf(['zaken']);
			}

			$schemas[] = $schema;
		}

		return $schemas;
	}//end schemas()

	private function generator(?IUser $user): SchemaGenerator {
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('findAll')->willReturnCallback(
			function (mixed ...$args): array {
				$this->log[] = 'schemas.findAll ' . json_encode($args);
				return $this->schemas();
			}
		);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$rbac = $this->createMock(PropertyRbacHandler::class);
		$rbac->method('couldReadProperty')->willReturn(true);
		$rbac->method('isAdmin')->willReturn(true);

		return new SchemaGenerator($schemas, $session, $rbac);
	}//end generator()

	private function resolver(): GraphQLResolver {
		$resolver = $this->createMock(GraphQLResolver::class);
		foreach (['resolveSingle', 'resolveList', 'resolveCreate', 'resolveUpdate', 'resolveDelete'] as $method) {
			$resolver->method($method)->willReturnCallback(
				function (mixed ...$args) use ($method): array|bool {
					$this->log[] = $method . ' schema ' . $args[0]->getId() . ' ' . json_encode(array_slice($args, 1));
					return $method === 'resolveDelete' ? true : [$method];
				}
			);
		}

		return $resolver;
	}//end resolver()

	private function resolverAnswers(?GraphQLResolver $resolver): array {
		$generator = $this->generator(user: null);
		if ($resolver !== null) {
			$generator->setResolver($resolver);
		}

		$schema = $generator->generate();
		$answers = [];
		foreach ([$schema->getQueryType(), $schema->getMutationType()] as $root) {
			foreach ($root->getFields() as $field) {
				if (in_array($field->name, ['zak', 'zaken', 'createZak', 'updateZak', 'deleteZak', 'register'], true) === true) {
					$answers[$field->name] = ($field->resolveFn)(null, ['id' => 'x']);
				}
			}
		}

		return $answers;
	}//end resolverAnswers()

	private function metadataAnswers(?IUser $user): array {
		$type = $this->generator(user: $user)->generate()->getType('Zaken');
		$this->assertInstanceOf(ObjectType::class, $type);

		return [
			'owner'        => ($type->getField('_owner')->resolveFn)(['_owner' => 'alice']),
			'owner absent' => ($type->getField('_owner')->resolveFn)([]),
			'audit trail'  => ($type->getField('_auditTrail')->resolveFn)(['_auditTrail' => ['secret']]),
		];
	}//end metadataAnswers()

	public function testGeneratedSchemaMatchesGolden(): void {
		$actual = ['printed' => explode("\n", SchemaPrinter::doPrint($this->generator(user: null)->generate()))];
		$actual['mapper calls'] = $this->log;
		$actual['metadata anonymous'] = $this->metadataAnswers(user: null);
		$actual['metadata signed in'] = $this->metadataAnswers(user: $this->createMock(IUser::class));
		$actual['no resolver'] = $this->resolverAnswers(resolver: null);
		$this->log = [];
		$actual['with resolver'] = $this->resolverAnswers(resolver: $this->resolver());
		$actual['resolver calls'] = array_values(array_filter($this->log, static fn (string $line): bool => str_starts_with($line, 'resolve')));

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		foreach ($expected as $name => $outcome) {
			$this->assertSame($outcome, $actual[$name] ?? null, $name);
		}

		$this->assertSame(array_keys($expected), array_keys($actual));
	}//end testGeneratedSchemaMatchesGolden()
}//end class
