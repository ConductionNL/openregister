<?php

/**
 * Golden test: the slug decisions and every [ObjectSlug] log line, message and context.
 *
 * Written against the code before the slug classes were split into small units,
 * so the split is pinned to the same outcomes: which slug each path writes, which
 * rows a bulk group decides, and the exact warnings (message and context array).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/object-lifecycle/spec.md#requirement-object-slugs-must-be-unique-within-a-register-and-schema-and-a-colliding-slug-must-not-be-written
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject;

use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Service\Object\SaveObject\SlugRequest;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Object\SaveObject\BulkObjectSlugs;
use OCA\OpenRegister\Service\Object\SaveObject\MetadataHydrationHandler;
use OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugHandler;
use OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugScope;
use OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugWant;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins the slug outcomes and log lines of every write path.
 */
final class ObjectSlugGoldenTest extends TestCase {
	use BuildsObjectSlugHandler;

	private const UUID_A = '00000000-0000-0000-0000-00000000000a';
	private const UUID_B = '00000000-0000-0000-0000-00000000000b';
	private const UUID_C = '00000000-0000-0000-0000-00000000000c';

	/** @var MagicMapper&MockObject */
	private MagicMapper $magicMapper;

	/** @var RegisterMapper&MockObject */
	private RegisterMapper $registerMapper;

	/** @var list<array{0: string, 1: array}> Warnings logged: message and context. */
	private array $warnings = [];

	private LoggerInterface $logger;

	private Register $register;

	private MetadataHydrationHandler $hydrator;

	private ObjectSlugHandler $handler;

	/** @var array<string, list<string>> Slug => live holders the mapper reports. */
	private array $holders = [];

	/** @var array<string, string|null> Uuid => stored slug the mapper reports. */
	private array $stored = [];

	protected function setUp(): void {
		parent::setUp();

		$this->logger = $this->createMock(LoggerInterface::class);
		$this->logger->method('warning')->willReturnCallback(
			function ($message, array $context=[]): void {
				$this->warnings[] = [(string)$message, $context];
			}
		);

		$this->register = new Register();
		$this->register->setId(7);
		$this->register->setSlug('woo');

		$this->registerMapper = $this->createMock(RegisterMapper::class);

		$this->magicMapper = $this->createMock(MagicMapper::class);
		$this->magicMapper->method('findSlugHolders')->willReturnCallback(
			fn (Register $r, Schema $s, string $slug, string $excludeUuid): array => array_values(
				array_filter($this->holders[$slug] ?? [], static fn (string $h): bool => $h !== $excludeUuid)
			)
		);
		$this->magicMapper->method('findSlugState')->willReturnCallback(
			function (Register $r, Schema $s, array $slugs, array $uuids): array {
				$holders = [];
				foreach ($slugs as $slug) {
					if (isset($this->holders[$slug]) === true) {
						$holders[$slug] = $this->holders[$slug];
					}
				}

				$stored = [];
				foreach ($uuids as $uuid) {
					if (array_key_exists($uuid, $this->stored) === true) {
						$stored[$uuid] = $this->stored[$uuid];
					}
				}

				return ['holders' => $holders, 'stored' => $stored];
			}
		);

		$clock = $this->createMock(ITimeFactory::class);
		$clock->method('now')->willReturn(new DateTimeImmutable('2026-10-22 12:00:00', new DateTimeZone('UTC')));

		$this->hydrator = new MetadataHydrationHandler(
			logger: $this->createMock(LoggerInterface::class),
			cacheHandler: $this->createMock(CacheHandler::class)
		);

		$this->handler = $this->buildSlugHandler(
			magicMapper: $this->magicMapper,
			hydrator: $this->hydrator,
			registerMapper: $this->registerMapper,
			logger: $this->logger,
			timeFactory: $clock
		);
	}//end setUp()

	private function schema(array $configuration, ?string $slug='convenanten', ?string $title=null): Schema {
		$schema = new Schema();
		$schema->setId(11);
		$schema->setSlug($slug);
		$schema->setTitle($title);
		$schema->setProperties(['title' => ['type' => 'string']]);
		$schema->setConfiguration($configuration);
		return $schema;
	}//end schema()

	private function entity(string $uuid, array $data, ?string $slug=null, ?string $register='7'): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setRegister($register);
		$entity->setObject($data);
		$entity->setSlug($slug);
		return $entity;
	}//end entity()

	private function context(string $path, bool $withRegister=true): array {
		return [
			'app' => 'openregister',
			'path' => $path,
			'registerId' => $withRegister === true ? 7 : null,
			'registerSlug' => $withRegister === true ? 'woo' : null,
			'schemaId' => 11,
			'schemaSlug' => 'convenanten',
		];
	}//end context()

	private function resolvingRegister(): void {
		$this->registerMapper->method('find')->willReturn($this->register);
	}//end resolvingRegister()

	public function testASingleSaveCollisionLogsTheFullLine(): void {
		$this->resolvingRegister();
		$this->holders['hello-world'] = [self::UUID_A];
		$entity = $this->entity(self::UUID_B, ['title' => 'Hello World']);

		$this->handler->applyOnSave(new SlugRequest($entity, $this->schema(['objectSlugField' => 'title']), null), null);

		$this->assertNull($entity->getSlug());
		$this->assertSame(
			[
				[
					'[ObjectSlug] Slug "hello-world" is already held by object ' . self::UUID_A
					. ' in register woo (7) / schema convenanten (11); object ' . self::UUID_B . ' was saved without a slug',
					$this->context('single') + ['slug' => 'hello-world', 'objectUuid' => self::UUID_B, 'holderUuid' => self::UUID_A],
				],
			],
			$this->warnings
		);
	}

	public function testASingleSaveWithTheSuffixAlsoTakenLogsBothSlugs(): void {
		$this->resolvingRegister();
		$this->holders['hello-world'] = [self::UUID_A];
		$this->holders['hello-world-20261022120000'] = [self::UUID_C];
		$entity = $this->entity(self::UUID_B, ['title' => 'Hello World']);

		$this->handler->applyOnSave(new SlugRequest($entity, $this->schema(['objectSlugField' => 'title', 'objectSlugSuffix' => true]), null), '');

		$this->assertNull($entity->getSlug());
		$this->assertSame(
			'[ObjectSlug] Slug "hello-world" is already held by object ' . self::UUID_A
			. ' in register woo (7) / schema convenanten (11); object ' . self::UUID_B
			. ' was saved without a slug (suffixed "hello-world-20261022120000" also taken)',
			$this->warnings[0][0]
		);
		$this->assertCount(1, $this->warnings);
	}

	public function testASingleSaveTakesTheSuffixSilently(): void {
		$this->resolvingRegister();
		$this->holders['hello-world'] = [self::UUID_A];
		$entity = $this->entity(self::UUID_B, ['title' => 'Hello World']);

		$this->handler->applyOnSave(new SlugRequest($entity, $this->schema(['objectSlugField' => 'title', 'objectSlugSuffix' => true]), ''), null);

		$this->assertSame('hello-world-20261022120000', $entity->getSlug());
		$this->assertSame([], $this->warnings);
	}

	public function testAFrozenSlugLogsADifferingClientSlug(): void {
		$this->resolvingRegister();
		$entity = $this->entity(self::UUID_B, ['title' => 'Other']);

		$this->handler->applyOnSave(new SlugRequest($entity, $this->schema(['objectSlugField' => 'title']), 'Wanted Now'), 'kept');

		$this->assertSame('kept', $entity->getSlug());
		$this->assertSame(
			[
				[
					'[ObjectSlug] Ignored client slug "Wanted Now" for object ' . self::UUID_B
					. ' in register woo (7) / schema convenanten (11): slug "kept" is frozen',
					$this->context('single') + ['slug' => 'Wanted Now', 'objectUuid' => self::UUID_B, 'frozenSlug' => 'kept'],
				],
			],
			$this->warnings
		);
	}

	public function testAFrozenSlugIgnoresTheSameOrAnEmptyClientSlug(): void {
		$this->resolvingRegister();
		foreach ([null, '', 'kept', 'KEPT', '!!!'] as $sent) {
			$entity = $this->entity(self::UUID_B, ['title' => 'Other']);
			$this->handler->applyOnSave(new SlugRequest($entity, $this->schema(['objectSlugField' => 'title']), $sent), 'kept');
			$this->assertSame('kept', $entity->getSlug());
		}

		$this->assertSame([], $this->warnings);
	}

	public function testAnUnresolvedRegisterLogsOnceAndDropsTheSlug(): void {
		$this->registerMapper->method('find')->willThrowException(new Exception('gone'));
		$schema = $this->schema(['objectSlugField' => 'title']);
		$entity = $this->entity(self::UUID_B, ['title' => 'Hello World']);

		$this->handler->applyOnSave(new SlugRequest($entity, $schema, null), null);
		$second = $this->entity(self::UUID_C, ['title' => 'Hello World']);
		$this->handler->applyOnSave(new SlugRequest($second, $schema, null), null);

		$this->assertNull($entity->getSlug());
		$this->assertNull($second->getSlug());
		$this->assertSame(
			[
				['[ObjectSlug] Could not resolve register 7 for the slug check: gone', ['app' => 'openregister']],
				[
					'[ObjectSlug] Slug "hello-world" for object ' . self::UUID_B
					. ' (schema convenanten) was not written: its register did not resolve, so uniqueness could not be checked',
					$this->context('single', false) + ['slug' => 'hello-world', 'objectUuid' => self::UUID_B],
				],
				[
					'[ObjectSlug] Slug "hello-world" for object ' . self::UUID_C
					. ' (schema convenanten) was not written: its register did not resolve, so uniqueness could not be checked',
					$this->context('single', false) + ['slug' => 'hello-world', 'objectUuid' => self::UUID_C],
				],
			],
			$this->warnings
		);
	}

	public function testAnEntityWithoutRegisterIsUnresolvedWithoutALookup(): void {
		$this->registerMapper->expects($this->never())->method('find');
		$entity = $this->entity(self::UUID_B, ['title' => 'Hello World'], null, '');

		$this->handler->applyOnSave(new SlugRequest($entity, $this->schema(['objectSlugField' => 'title']), null), null);

		$this->assertNull($entity->getSlug());
		$this->assertCount(1, $this->warnings);
	}

	public function testAnEmptyCandidateClearsTheSlug(): void {
		$this->resolvingRegister();
		$entity = $this->entity(self::UUID_B, ['title' => '  '], 'stale');

		$this->handler->applyOnSave(new SlugRequest($entity, $this->schema(['objectSlugField' => 'title']), null), null);

		$this->assertNull($entity->getSlug());
		$this->assertSame([], $this->warnings);
	}

	public function testTheLegacyBranchWritesOnlyANonEmptyClientSlug(): void {
		$entity = $this->entity(self::UUID_B, ['title' => 'Hello'], 'old');
		$this->handler->applyOnSave(new SlugRequest($entity, $this->schema([]), ''), null);
		$this->assertSame('old', $entity->getSlug());

		$this->handler->applyOnSeed(new SlugRequest($entity, $this->schema(['objectSlugField' => '  ']), 'Raw Client'));
		$this->assertSame('Raw Client', $entity->getSlug());
		$this->assertSame([], $this->warnings);
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function suffixedPaths(): array {
		return [
			'seed' => ['seed', 'got the suffixed slug "hello-world-20261022120000" instead (seed)'],
			'restore' => ['restore', 'got the suffixed slug "hello-world-20261022120000" instead (restore)'],
			'move' => ['move', 'got the suffixed slug "hello-world-20261022120000" instead (move)'],
		];
	}//end suffixedPaths()

	#[DataProvider('suffixedPaths')]
	public function testRestoreMoveAndSeedSayTheSuffixedSlug(string $path, string $tail): void {
		$this->resolvingRegister();
		$this->holders['hello-world'] = [self::UUID_A];
		$schema = $this->schema(['objectSlugField' => 'title', 'objectSlugSuffix' => true]);
		$entity = $this->entity(self::UUID_B, ['title' => 'Hello World'], 'hello-world');

		$result = match ($path) {
			'seed' => $this->seed(entity: $this->entity(self::UUID_B, ['title' => 'Hello World']), schema: $schema),
			'restore' => $this->handler->slugForRestore($entity, new RegisterSchema($this->register, $schema)),
			default => $this->handler->slugForMove($entity, new RegisterSchema($this->register, $schema)),
		};

		$this->assertSame('hello-world-20261022120000', $result);
		$this->assertSame(
			[
				[
					'[ObjectSlug] Slug "hello-world" is already held by object ' . self::UUID_A
					. ' in register woo (7) / schema convenanten (11); object ' . self::UUID_B . ' ' . $tail,
					$this->context($path) + [
						'slug' => 'hello-world',
						'objectUuid' => self::UUID_B,
						'holderUuid' => self::UUID_A,
						'suffixedSlug' => 'hello-world-20261022120000',
					],
				],
			],
			$this->warnings
		);
	}

	private function seed(ObjectEntity $entity, Schema $schema): ?string {
		$this->handler->applyOnSeed(new SlugRequest($entity, $schema, null));
		return $entity->getSlug();
	}//end seed()

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function collisionPaths(): array {
		return [
			'seed' => ['seed', 'was seeded without a slug'],
			'restore' => ['restore', 'was restored without a slug'],
			'move' => ['move', 'was moved without a slug'],
			'backfill' => ['backfill', 'gets no slug from the backfill'],
			'bulk' => ['bulk', 'was saved without a slug'],
		];
	}//end collisionPaths()

	#[DataProvider('collisionPaths')]
	public function testEveryPathNamesItsCollisionOutcome(string $path, string $outcome): void {
		$this->resolvingRegister();
		$want = new ObjectSlugWant(
			slug: 'hello-world',
			scope: new ObjectSlugScope(register: $this->register, schema: $this->schema(['objectSlugField' => 'title'])),
			objectUuid: self::UUID_B,
			path: $path
		);

		$result = $this->handler->decide(decision: new \OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugDecision(want: $want, holderOf: static fn (string $slug): ?string => self::UUID_A, log: true));

		$this->assertNull($result);
		$this->assertSame(
			'[ObjectSlug] Slug "hello-world" is already held by object ' . self::UUID_A
			. ' in register woo (7) / schema convenanten (11); object ' . self::UUID_B . ' ' . $outcome,
			$this->warnings[0][0]
		);
		$this->assertSame($path, $this->warnings[0][1]['path']);
	}

	public function testDecideWithTheLogOffAndAGivenSuffixedSlug(): void {
		$want = new ObjectSlugWant(
			slug: 'a',
			scope: new ObjectSlugScope(register: $this->register, schema: $this->schema(['objectSlugField' => 'title', 'objectSlugSuffix' => true])),
			objectUuid: self::UUID_B,
			path: 'restore'
		);
		$asked = [];
		$holderOf = function (string $slug) use (&$asked): ?string {
			$asked[] = $slug;
			return $slug === 'a' ? self::UUID_A : null;
		};

		$this->assertSame('given', $this->handler->decide(decision: new \OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugDecision(want: $want, holderOf: $holderOf, log: false, suffixed: 'given')));
		$this->assertSame(['a', 'given'], $asked);
		$this->assertNull($this->handler->decide(decision: new \OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugDecision(want: $want, holderOf: static fn (string $s): ?string => self::UUID_A, log: false, suffixed: 'given')));
		$this->assertSame([], $this->warnings);
	}

	public function testRestoreAndMoveLeaveAnEmptySlugAndAnUnmanagedSchemaAlone(): void {
		$managed = $this->schema(['objectSlugField' => 'title']);
		$this->magicMapper->expects($this->never())->method('findSlugHolders');

		$this->assertNull($this->handler->slugForRestore($this->entity(self::UUID_B, []), new RegisterSchema($this->register, $managed)));
		$this->assertSame('', $this->handler->slugForMove($this->entity(self::UUID_B, [], ''), new RegisterSchema($this->register, $managed)));
		$this->assertSame('x', $this->handler->slugForMove($this->entity(self::UUID_B, [], 'x'), new RegisterSchema($this->register, $this->schema([]))));
	}

	/**
	 * @return array<string, array{0: array, 1: array, 2: string|null, 3: string|null, 4: string|null}>
	 */
	public static function candidates(): array {
		$name = ['objectSlugField' => ObjectSlugHandler::SCHEMA_NAME_SOURCE, 'objectNameField' => 'title'];
		return [
			'client wins' => [['title' => 'Ignored'], ['objectSlugField' => 'title'], 'convenanten', ' Client Slug ', 'client-slug'],
			'client blank falls back' => [['title' => 'From Field'], ['objectSlugField' => 'title'], 'convenanten', '   ', 'from-field'],
			'client unmanaged' => [['title' => 'X'], [], 'convenanten', 'Sent', 'sent'],
			'unmanaged no client' => [['title' => 'X'], [], 'convenanten', null, null],
			'envelope' => [['object' => ['title' => 'In Envelope']], ['objectSlugField' => 'title'], 'convenanten', null, 'in-envelope'],
			'empty value' => [['title' => ''], ['objectSlugField' => 'title'], 'convenanten', null, null],
			'slugifies to nothing' => [['title' => '!!!'], ['objectSlugField' => 'title'], 'convenanten', null, null],
			'schema name' => [['title' => 'Mijn Object'], $name, 'convenanten', null, 'convenanten-mijn-object'],
			'schema title' => [['title' => 'Mijn Object'], $name, null, null, 'het-schema-mijn-object'],
			'schema without name' => [['title' => 'Mijn Object'], $name, '', null, null],
			'no name field' => [['title' => 'Mijn Object'], ['objectSlugField' => ObjectSlugHandler::SCHEMA_NAME_SOURCE], 'convenanten', null, null],
			'blank name field' => [['title' => 'X'], ['objectSlugField' => ObjectSlugHandler::SCHEMA_NAME_SOURCE, 'objectNameField' => ' '], 'convenanten', null, null],
			'template' => [['title' => 'A'], ['objectSlugField' => '{{ title }} b'], 'convenanten', null, 'a-b'],
			'long' => [['title' => str_repeat('ab ', 200)], ['objectSlugField' => 'title'], 'convenanten', null, rtrim(substr(str_repeat('ab-', 200), 0, 255), '-')],
		];
	}//end candidates()

	#[DataProvider('candidates')]
	public function testTheCandidate(array $data, array $configuration, ?string $schemaSlug, ?string $client, ?string $expected): void {
		$schema = $this->schema($configuration, $schemaSlug, $schemaSlug === null ? 'Het Schema' : null);

		$this->assertSame($expected, $this->handler->candidate($data, $schema, $client));
	}

	public function testDiffersFromStored(): void {
		$this->assertFalse($this->handler->differsFromStored(null, 'a'));
		$this->assertFalse($this->handler->differsFromStored('', 'a'));
		$this->assertFalse($this->handler->differsFromStored('a', 'a'));
		$this->assertFalse($this->handler->differsFromStored(' A ', 'a'));
		$this->assertFalse($this->handler->differsFromStored('!!!', 'a'));
		$this->assertTrue($this->handler->differsFromStored('b', 'a'));
	}

	public function testABatchDecidesInOrderAndLogsWithContext(): void {
		$this->holders['taken'] = [self::UUID_C];
		$this->stored[self::UUID_A] = 'frozen';
		$this->stored[self::UUID_B] = null;
		$rows = [
			0 => ['uuid' => self::UUID_A, 'clientSlug' => 'Other', 'data' => ['title' => 'X']],
			1 => ['uuid' => self::UUID_B, 'clientSlug' => null, 'data' => ['title' => 'Taken']],
			2 => ['uuid' => null, 'clientSlug' => null, 'data' => ['title' => 'Fresh']],
			3 => ['uuid' => '', 'clientSlug' => null, 'data' => ['title' => 'Fresh']],
			4 => ['uuid' => self::UUID_B, 'clientSlug' => null, 'data' => ['title' => 'none here', 'x' => 1]],
			5 => ['uuid' => self::UUID_C, 'clientSlug' => null, 'data' => []],
		];

		$decisions = $this->handler->decideBatch($rows, $this->register, $this->schema(['objectSlugField' => 'title']));

		$this->assertSame([0 => 'frozen', 1 => null, 2 => 'fresh', 3 => null, 4 => 'none-here', 5 => null], $decisions);
		$this->assertSame(
			[
				[
					'[ObjectSlug] Ignored client slug "Other" for object ' . self::UUID_A
					. ' in register woo (7) / schema convenanten (11): slug "frozen" is frozen',
					$this->context('bulk') + ['slug' => 'Other', 'objectUuid' => self::UUID_A, 'frozenSlug' => 'frozen'],
				],
				[
					'[ObjectSlug] Slug "taken" is already held by object ' . self::UUID_C
					. ' in register woo (7) / schema convenanten (11); object ' . self::UUID_B . ' was saved without a slug',
					$this->context('bulk') + ['slug' => 'taken', 'objectUuid' => self::UUID_B, 'holderUuid' => self::UUID_C],
				],
				[
					'[ObjectSlug] Slug "fresh" is already held by object batch-2'
					. ' in register woo (7) / schema convenanten (11); object ? was saved without a slug',
					$this->context('bulk') + ['slug' => 'fresh', 'objectUuid' => '?', 'holderUuid' => 'batch-2'],
				],
			],
			$this->warnings
		);
	}

	public function testABatchSuffixesWithOneStampAndQueriesTheSuffixedSlugs(): void {
		$this->holders['taken'] = [self::UUID_C];
		$this->holders['taken-20261022120000'] = [];
		$rows = [
			0 => ['uuid' => self::UUID_A, 'clientSlug' => null, 'data' => ['title' => 'Taken']],
			1 => ['uuid' => self::UUID_B, 'clientSlug' => null, 'data' => ['title' => 'Taken']],
		];

		$decisions = $this->handler->decideBatch($rows, $this->register, $this->schema(['objectSlugField' => 'title', 'objectSlugSuffix' => true]));

		$this->assertSame([0 => 'taken-20261022120000', 1 => null], $decisions);
		$this->assertCount(1, $this->warnings);
		$this->assertStringEndsWith('(suffixed "taken-20261022120000" also taken)', $this->warnings[0][0]);
		$this->assertSame([], $this->handler->decideBatch($rows, $this->register, $this->schema([])));
	}

	private function bulk(?Schema $schema, bool $schemaFails=false): BulkObjectSlugs {
		return new BulkObjectSlugs(
			slugHandler: $this->handler,
			registerMapper: $this->registerMapper,
			logger: $this->logger,
			schemaOf: static function (string $id) use ($schema, $schemaFails): Schema {
				if ($schemaFails === true) {
					throw new Exception('no schema ' . $id);
				}

				return $schema;
			}
		);
	}//end bulk()

	public function testBulkAppliesBothRowShapesAndDropsTheCarrierKey(): void {
		$this->resolvingRegister();
		$this->holders['taken'] = [self::UUID_C];
		$schema = $this->schema(['objectSlugField' => 'title']);
		$rows = [
			'a' => ['uuid' => self::UUID_A, 'register' => 7, 'schema' => 11, 'object' => ['title' => 'Flat One'], BulkObjectSlugs::CLIENT_SLUG_KEY => 'Carried'],
			'b' => ['@self' => ['id' => self::UUID_B, 'register' => 7, 'schema' => 11, BulkObjectSlugs::CLIENT_SLUG_KEY => 'x'], 'title' => 'Taken'],
			'c' => ['@self' => ['register' => 7, 'schema' => 11, 'slug' => 'Own Slug'], 'title' => 'Ignored', 'slug' => 'data-slug'],
			'd' => ['uuid' => self::UUID_C, 'schema' => 11, 'object' => 'not-an-array', 'slug' => ''],
			'e' => ['uuid' => self::UUID_C, 'register' => 7, 'object' => ['title' => 'No Schema']],
			'f' => ['@self' => 'scalar', 'register' => 7, 'schema' => 11, 'object' => ['title' => 'Scalar Self']],
		];

		$result = $this->bulk($schema)->apply($rows, 7, null);

		$this->assertSame(
			[
				'a' => ['uuid' => self::UUID_A, 'register' => 7, 'schema' => 11, 'object' => ['title' => 'Flat One'], 'slug' => 'carried'],
				'b' => ['@self' => ['id' => self::UUID_B, 'register' => 7, 'schema' => 11, 'slug' => 'x'], 'title' => 'Taken'],
				'c' => ['@self' => ['register' => 7, 'schema' => 11, 'slug' => 'own-slug'], 'title' => 'Ignored', 'slug' => 'data-slug'],
				'd' => ['uuid' => self::UUID_C, 'schema' => 11, 'object' => 'not-an-array', 'slug' => null],
				'e' => ['uuid' => self::UUID_C, 'register' => 7, 'object' => ['title' => 'No Schema']],
				'f' => ['@self' => 'scalar', 'register' => 7, 'schema' => 11, 'object' => ['title' => 'Scalar Self']],
			],
			$result
		);
	}

	public function testBulkWithCallerEntitiesSkipsTheLookups(): void {
		$this->registerMapper->expects($this->never())->method('find');
		$schema = $this->schema(['objectSlugField' => 'title']);
		$rows = [['uuid' => self::UUID_A, 'register' => 3, 'schema' => 99, 'object' => ['title' => 'Mine']]];

		$result = $this->bulk(null, true)->apply($rows, $this->register, $schema);

		$this->assertSame('mine', $result[0]['slug']);
		$this->assertSame([], $this->warnings);
	}

	public function testBulkWithAnUnmanagedSchemaLeavesTheRowsAlone(): void {
		$rows = [['uuid' => self::UUID_A, 'register' => 7, 'schema' => 11, 'slug' => 'as-sent', 'object' => ['title' => 'Mine']]];

		$this->assertSame($rows, $this->bulk($this->schema([]))->apply($rows, null, null));
	}

	public function testBulkLogsAnUnresolvedSchemaAndRegister(): void {
		$this->registerMapper->method('find')->willThrowException(new Exception('gone'));
		$rows = [
			['uuid' => self::UUID_A, 'register' => 7, 'schema' => 11, 'slug' => 'as-sent', 'object' => []],
			['@self' => ['register' => 7, 'schema' => 11, 'slug' => 'sent'], 'title' => 'T'],
		];

		$this->assertSame($rows, $this->bulk(null, true)->apply($rows, null, null));
		$result = $this->bulk($this->schema(['objectSlugField' => 'title']))->apply($rows, null, null);

		$this->assertNull($result[0]['slug']);
		$this->assertNull($result[1]['@self']['slug']);
		$this->assertSame(
			[
				['[ObjectSlug] Could not resolve schema 11 for the bulk slug check: no schema 11', ['app' => 'openregister']],
				[
					'[ObjectSlug] Could not resolve register 7 for the bulk slug check; the rows of schema 11 are saved without a slug: gone',
					['app' => 'openregister'],
				],
			],
			$this->warnings
		);
	}

	public function testClientSlugToCarry(): void {
		$bulk = $this->bulk(null);
		$this->assertSame('top', $bulk->clientSlugToCarry([], ['slug' => 'top']));
		$this->assertNull($bulk->clientSlugToCarry(['slug' => 'self'], ['slug' => 'top']));
		$this->assertNull($bulk->clientSlugToCarry(null, ['slug' => '']));
		$this->assertNull($bulk->clientSlugToCarry(null, ['slug' => 5]));
		$this->assertNull($bulk->clientSlugToCarry(null, []));
	}
}//end class
