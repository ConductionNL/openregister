<?php

/**
 * ObjectSlugHandler unit tests (#4037).
 *
 * Every assertion checks the resulting slug and, where it matters, which object
 * holds it: today's code also "succeeds", so a passing call proves nothing.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/object-slugs-configurable-and-unique/specs/object-lifecycle/spec.md#requirement-object-slugs-must-be-unique-within-a-register-and-schema-and-a-colliding-slug-must-not-be-written
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Object\SaveObject\MetadataHydrationHandler;
use OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugHandler;
use OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugSource;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the slug decision: candidate, freeze, uniqueness, suffix, batch.
 */
class ObjectSlugHandlerTest extends TestCase {

	private const UUID_A = '00000000-0000-0000-0000-00000000000a';
	private const UUID_B = '00000000-0000-0000-0000-00000000000b';
	private const UUID_C = '00000000-0000-0000-0000-00000000000c';

	/** @var MagicMapper&MockObject */
	private MagicMapper $magicMapper;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	/** @var list<string> Warnings logged during the test. */
	private array $warnings = [];

	private Register $register;

	private ObjectSlugHandler $handler;

	protected function setUp(): void {
		parent::setUp();

		$this->magicMapper = $this->createMock(MagicMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->logger->method('warning')->willReturnCallback(
			function ($message): void {
				$this->warnings[] = (string)$message;
			}
		);

		$this->register = new Register();
		$this->register->setId(7);
		$this->register->setSlug('woo');

		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturn($this->register);

		$clock = $this->createMock(ITimeFactory::class);
		$clock->method('now')->willReturn(new DateTimeImmutable('2026-10-22 12:00:00', new DateTimeZone('UTC')));

		$hydrator = new MetadataHydrationHandler(
			logger: $this->createMock(LoggerInterface::class),
			cacheHandler: $this->createMock(CacheHandler::class)
		);

		$this->handler = new ObjectSlugHandler(
			magicMapper: $this->magicMapper,
			hydrator: $hydrator,
			registerMapper: $registerMapper,
			logger: $this->logger,
			timeFactory: $clock
		);
	}//end setUp()

	/**
	 * A schema with the given configuration, built through the real validation.
	 *
	 * @param array $configuration The configuration.
	 *
	 * @return Schema The schema.
	 */
	private function schema(array $configuration): Schema {
		$schema = new Schema();
		$schema->setId(11);
		$schema->setSlug('convenanten');
		$schema->setProperties(['title' => ['type' => 'string']]);
		$schema->setConfiguration($configuration);
		return $schema;
	}//end schema()

	/**
	 * An entity in register 7 with the given uuid, data and stored slug.
	 *
	 * @param string      $uuid The uuid.
	 * @param array       $data The business data.
	 * @param string|null $slug The stored slug.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function entity(string $uuid, array $data, ?string $slug=null): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setRegister('7');
		$entity->setObject($data);
		$entity->setSlug($slug);
		return $entity;
	}//end entity()

	public function testSchemaWithoutSlugFieldKeepsTheLegacyClientSlug(): void {
		$this->magicMapper->expects($this->never())->method('findSlugHolders');
		$entity = $this->entity(self::UUID_A, ['title' => 'Hello World'], 'old');

		$this->handler->applyOnSave($entity, $this->schema([]), 'old', 'sent-by-client');

		$this->assertSame('sent-by-client', $entity->getSlug());
		$this->assertSame([], $this->warnings);
	}

	public function testSchemaWithoutSlugFieldLeavesAnObjectWithoutSlugAlone(): void {
		$this->magicMapper->expects($this->never())->method('findSlugHolders');
		$entity = $this->entity(self::UUID_A, ['title' => 'Hello World']);

		$this->handler->applyOnSave($entity, $this->schema([]), null, null);

		$this->assertNull($entity->getSlug());
	}

	public function testAFreeSlugIsWrittenFromTheSlugField(): void {
		$this->magicMapper->method('findSlugHolders')->willReturn([]);
		$entity = $this->entity(self::UUID_A, ['title' => 'Hello World']);

		$this->handler->applyOnSave($entity, $this->schema(['objectSlugField' => 'title']), null, null);

		$this->assertSame('hello-world', $entity->getSlug());
	}

	public function testATakenSlugIsNotWrittenAndTheWarningNamesBothObjects(): void {
		$this->magicMapper->method('findSlugHolders')
			->with($this->register, $this->anything(), 'hello-world', self::UUID_B)
			->willReturn([self::UUID_A]);
		$entity = $this->entity(self::UUID_B, ['title' => 'Hello World']);

		$this->handler->applyOnSave($entity, $this->schema(['objectSlugField' => 'title']), null, null);

		$this->assertNull($entity->getSlug());
		$this->assertCount(1, $this->warnings);
		$this->assertStringContainsString('"hello-world"', $this->warnings[0]);
		$this->assertStringContainsString(self::UUID_A, $this->warnings[0]);
		$this->assertStringContainsString(self::UUID_B, $this->warnings[0]);
		$this->assertStringContainsString('register woo (7)', $this->warnings[0]);
		$this->assertStringContainsString('schema convenanten (11)', $this->warnings[0]);
	}

	public function testSuffixIsAppendedOnlyOnACollision(): void {
		$this->magicMapper->method('findSlugHolders')->willReturnCallback(
			static fn (Register $r, Schema $s, string $slug): array => $slug === 'hello-world' ? [self::UUID_A] : []
		);
		$entity = $this->entity(self::UUID_B, ['title' => 'Hello World']);

		$this->handler->applyOnSave(
			$entity,
			$this->schema(['objectSlugField' => 'title', 'objectSlugSuffix' => true]),
			null,
			null
		);

		$this->assertSame('hello-world-20261022120000', $entity->getSlug());
		$this->assertSame([], $this->warnings);
	}

	public function testSuffixAlsoTakenLeavesTheObjectWithoutSlug(): void {
		$this->magicMapper->method('findSlugHolders')->willReturn([self::UUID_A]);
		$entity = $this->entity(self::UUID_B, ['title' => 'Hello World']);

		$this->handler->applyOnSave(
			$entity,
			$this->schema(['objectSlugField' => 'title', 'objectSlugSuffix' => true]),
			null,
			null
		);

		$this->assertNull($entity->getSlug());
		$this->assertCount(1, $this->warnings);
		$this->assertStringContainsString('suffixed "hello-world-20261022120000" also taken', $this->warnings[0]);
	}

	public function testSuffixedSlugFitsTheColumn(): void {
		$slug = $this->handler->withSuffix(str_repeat('a', 255));

		$this->assertSame(255, strlen($slug));
		$this->assertStringEndsWith('-20261022120000', $slug);
	}

	public function testTheSchemaNameSourceBuildsTheSlugFromSchemaSlugAndObjectName(): void {
		// Robert Zondervan (2026-10-26): "schema name + title" is the second choice next
		// to a property. The object's name is what objectNameField says; the schema part
		// is the schema slug.
		$this->magicMapper->method('findSlugHolders')->willReturn([]);
		$schema = $this->schema(['objectSlugField' => ObjectSlugHandler::SCHEMA_NAME_SOURCE, 'objectNameField' => 'title']);
		$entity = $this->entity(self::UUID_A, ['title' => 'Hello World']);

		$this->handler->applyOnSave(entity: $entity, schema: $schema, originalSlug: null, clientSlug: null);

		$this->assertSame('convenanten-hello-world', $entity->getSlug());
	}

	public function testTheSchemaNameSourceFallsBackToTheSchemaTitleWhenItHasNoSlug(): void {
		$this->magicMapper->method('findSlugHolders')->willReturn([]);
		$schema = $this->schema(['objectSlugField' => ObjectSlugHandler::SCHEMA_NAME_SOURCE, 'objectNameField' => 'title']);
		$schema->setSlug(null);
		$schema->setTitle('WOO Besluiten');

		$this->assertSame('woo-besluiten-hello', $this->handler->candidate(['title' => 'Hello'], $schema, null));
	}

	public function testTheSchemaNameSourceGivesNoSlugWithoutANameFieldOrName(): void {
		// No fallback to title/name/label: the schema says what the name is, or there is none.
		$withoutNameField = $this->schema(['objectSlugField' => ObjectSlugHandler::SCHEMA_NAME_SOURCE]);
		$withEmptyName = $this->schema(['objectSlugField' => ObjectSlugHandler::SCHEMA_NAME_SOURCE, 'objectNameField' => 'title']);

		$this->assertNull($this->handler->candidate(['title' => 'Hello'], $withoutNameField, null));
		$this->assertNull($this->handler->candidate(['title' => '   '], $withEmptyName, null));
		$this->assertNull($this->handler->candidate(['body' => 'x'], $withEmptyName, null));
	}

	public function testASchemaNameSourceNameThatSlugifiesToNothingGivesNoSlug(): void {
		// Only punctuation, or only letters the slugify routine removes: no name, so the
		// schema slug alone must not become the object's slug.
		$schema = $this->schema(['objectSlugField' => ObjectSlugHandler::SCHEMA_NAME_SOURCE, 'objectNameField' => 'title']);

		$this->assertNull($this->handler->candidate(['title' => '!!!'], $schema, null));
		$this->assertNull($this->handler->candidate(['title' => 'Москва'], $schema, null));
	}

	public function testASchemaNameSourceSlugIsCutToTheColumnWithoutATrailingHyphen(): void {
		$schema = $this->schema(['objectSlugField' => ObjectSlugHandler::SCHEMA_NAME_SOURCE, 'objectNameField' => 'title']);
		$source = new ObjectSlugSource(
			hydrator: new MetadataHydrationHandler(
				logger: $this->createMock(LoggerInterface::class),
				cacheHandler: $this->createMock(CacheHandler::class)
			)
		);

		// "convenanten-abcdefgh-ijk" cut at 21 ends on the hyphen before "ijk".
		$this->assertSame('convenanten-abcdefgh', $source->candidate(objectData: ['title' => 'abcdefgh ijk'], schema: $schema, maxLength: 21));
	}

	public function testAStoredSlugIsFrozenWhenTheSourceFieldIsRenamed(): void {
		$this->magicMapper->expects($this->never())->method('findSlugHolders');
		$entity = $this->entity(self::UUID_A, ['title' => 'Hello World Renamed'], 'hello-world');

		$this->handler->applyOnSave($entity, $this->schema(['objectSlugField' => 'title']), 'hello-world', null);

		$this->assertSame('hello-world', $entity->getSlug());
		$this->assertSame([], $this->warnings);
	}

	public function testAnOldTimestampSlugAndAnExistingDuplicateAreKept(): void {
		// BC2: slugs written before #4037, including the `-<time()>` variant and a
		// slug another object also holds, are never rewritten by a save.
		$this->magicMapper->expects($this->never())->method('findSlugHolders');
		$schema = $this->schema(['objectSlugField' => 'title']);

		$timestamped = $this->entity(self::UUID_A, ['title' => 'Hello World'], 'hello-world-1700000000');
		$this->handler->applyOnSave($timestamped, $schema, 'hello-world-1700000000', null);
		$duplicate = $this->entity(self::UUID_B, ['title' => 'Hello World'], 'hello-world');
		$this->handler->applyOnSave($duplicate, $schema, 'hello-world', null);

		$this->assertSame('hello-world-1700000000', $timestamped->getSlug());
		$this->assertSame('hello-world', $duplicate->getSlug());
	}

	public function testADifferingClientSlugOnAFrozenObjectIsIgnoredWithAWarning(): void {
		$entity = $this->entity(self::UUID_A, ['title' => 'Hello World'], 'hello-world');

		$this->handler->applyOnSave($entity, $this->schema(['objectSlugField' => 'title']), 'hello-world', 'other');

		$this->assertSame('hello-world', $entity->getSlug());
		$this->assertCount(1, $this->warnings);
		$this->assertStringContainsString('Ignored client slug "other"', $this->warnings[0]);
	}

	public function testReSendingTheStoredSlugInAnotherFormatOnAFrozenObjectLogsNothing(): void {
		// Review finding r2save-f2: created with `Zaak_2026-0042`, stored `zaak-2026-0042`.
		$entity = $this->entity(self::UUID_A, ['title' => 'Hello World'], 'zaak-2026-0042');

		$this->handler->applyOnSave($entity, $this->schema(['objectSlugField' => 'title']), 'zaak-2026-0042', 'Zaak_2026-0042');

		$this->assertSame('zaak-2026-0042', $entity->getSlug());
		$this->assertSame([], $this->warnings);
	}

	public function testReSendingTheStoredSlugInAnotherFormatInABatchLogsNothing(): void {
		$this->magicMapper->method('findSlugState')->willReturn(['holders' => ['zaak-2026-0042' => [self::UUID_A]], 'stored' => [self::UUID_A => 'zaak-2026-0042']]);
		$rows = [0 => ['uuid' => self::UUID_A, 'clientSlug' => 'Zaak_2026-0042', 'data' => ['title' => 'Other']]];

		$decisions = $this->handler->decideBatch($rows, $this->register, $this->schema(['objectSlugField' => 'title']));

		$this->assertSame([0 => 'zaak-2026-0042'], $decisions);
		$this->assertSame([], $this->warnings);
	}

	public function testADifferingClientSlugInABatchStillWarnsOnAFrozenObject(): void {
		$this->magicMapper->method('findSlugState')->willReturn(['holders' => ['zaak-2026-0042' => [self::UUID_A]], 'stored' => [self::UUID_A => 'zaak-2026-0042']]);
		$rows = [0 => ['uuid' => self::UUID_A, 'clientSlug' => 'Other_Slug', 'data' => ['title' => 'Other']]];

		$this->handler->decideBatch($rows, $this->register, $this->schema(['objectSlugField' => 'title']));

		$this->assertCount(1, $this->warnings);
		$this->assertStringContainsString('Ignored client slug "Other_Slug"', $this->warnings[0]);
	}

	public function testAClientSlugIsTheCandidateNormalisedLikeAGeneratedOne(): void {
		$this->magicMapper->method('findSlugHolders')->willReturn([]);
		$entity = $this->entity(self::UUID_A, ['title' => 'Hello World']);

		$this->handler->applyOnSave($entity, $this->schema(['objectSlugField' => 'title']), null, 'Zaak_2026-0042');

		$this->assertSame('zaak-2026-0042', $entity->getSlug());
	}

	public function testAClientSlugIsCutToTheColumnWidth(): void {
		// Review finding save-f7: an overlong client slug must not reach the 255-wide column.
		$this->magicMapper->method('findSlugHolders')->willReturn([]);
		$entity = $this->entity(self::UUID_A, ['title' => 'Hello World']);

		$this->handler->applyOnSave($entity, $this->schema(['objectSlugField' => 'title']), null, str_repeat('a', 300));

		$this->assertSame(str_repeat('a', 255), $entity->getSlug());
	}

	public function testAClientSlugThatSlugifiesToNothingFallsBackToTheSourceField(): void {
		$this->magicMapper->method('findSlugHolders')->willReturn([]);
		$entity = $this->entity(self::UUID_A, ['title' => 'Hello World']);

		$this->handler->applyOnSave($entity, $this->schema(['objectSlugField' => 'title']), null, '!!!');

		$this->assertSame('hello-world', $entity->getSlug());
	}

	public function testAnUnresolvableRegisterDropsTheSlugAndSaysSo(): void {
		// Review finding save-f7: fail closed, but not silently.
		$this->magicMapper->expects($this->never())->method('findSlugHolders');
		$entity = $this->entity(self::UUID_A, ['title' => 'Hello World']);
		$entity->setRegister(null);

		$this->handler->applyOnSave($entity, $this->schema(['objectSlugField' => 'title']), null, 'my-slug');

		$this->assertNull($entity->getSlug());
		$this->assertCount(1, $this->warnings);
		$this->assertStringContainsString('register did not resolve', $this->warnings[0]);
		$this->assertStringContainsString('my-slug', $this->warnings[0]);
	}

	public function testBatchSeesACollisionWhenTheClientSlugDiffersOnlyInCase(): void {
		// Review finding bulk-f3: stored `hello`, bulk client slug `Hello`.
		$this->magicMapper->method('findSlugState')->willReturn(['holders' => ['hello' => [self::UUID_C]], 'stored' => []]);
		$rows = [0 => ['uuid' => self::UUID_A, 'clientSlug' => 'Hello', 'data' => ['title' => 'Other']]];

		$decisions = $this->handler->decideBatch($rows, $this->register, $this->schema(['objectSlugField' => 'title']));

		$this->assertSame([0 => null], $decisions);
		$this->assertStringContainsString(self::UUID_C, $this->warnings[0]);
	}

	public function testBatchComparesTheHoldersTheDatabaseReturnsCaseFolded(): void {
		// A case-insensitive collation returns the stored spelling for the queried slug.
		$this->magicMapper->method('findSlugState')->willReturn(['holders' => ['Hello' => [self::UUID_C]], 'stored' => []]);
		$rows = [0 => ['uuid' => self::UUID_A, 'clientSlug' => null, 'data' => ['title' => 'Hello']]];

		$decisions = $this->handler->decideBatch($rows, $this->register, $this->schema(['objectSlugField' => 'title']));

		$this->assertSame([0 => null], $decisions);
	}

	public function testBatchSecondRowWithTheSameSlugInOtherCaseCollides(): void {
		$this->magicMapper->method('findSlugState')->willReturn(['holders' => [], 'stored' => []]);
		$rows = [
			0 => ['uuid' => self::UUID_A, 'clientSlug' => 'Same', 'data' => []],
			1 => ['uuid' => self::UUID_B, 'clientSlug' => 'SAME', 'data' => []],
		];

		$decisions = $this->handler->decideBatch($rows, $this->register, $this->schema(['objectSlugField' => 'title']));

		$this->assertSame([0 => 'same', 1 => null], $decisions);
	}

	public function testRestoreKeepsTheSlugWhenNobodyHoldsIt(): void {
		$this->magicMapper->method('findSlugHolders')->willReturn([]);
		$entity = $this->entity(self::UUID_A, ['title' => 'Hello World'], 'hello-world');

		$slug = $this->handler->slugForRestore($entity, $this->register, $this->schema(['objectSlugField' => 'title']));

		$this->assertSame('hello-world', $slug);
		$this->assertSame([], $this->warnings);
	}

	public function testRestoreDropsTheSlugAnotherLiveObjectNowHolds(): void {
		$this->magicMapper->method('findSlugHolders')->willReturn([self::UUID_B]);
		$entity = $this->entity(self::UUID_A, ['title' => 'Hello World'], 'hello-world');

		$slug = $this->handler->slugForRestore($entity, $this->register, $this->schema(['objectSlugField' => 'title']));

		$this->assertNull($slug);
		$this->assertCount(1, $this->warnings);
		$this->assertStringContainsString('was restored without a slug', $this->warnings[0]);
		$this->assertStringContainsString(self::UUID_A, $this->warnings[0]);
		$this->assertStringContainsString(self::UUID_B, $this->warnings[0]);
	}

	public function testRestoreLeavesAnUnmanagedSchemaAlone(): void {
		$this->magicMapper->expects($this->never())->method('findSlugHolders');
		$entity = $this->entity(self::UUID_A, ['title' => 'Hello World'], 'hello-world');

		$this->assertSame('hello-world', $this->handler->slugForRestore($entity, $this->register, $this->schema([])));
	}

	public function testMoveKeepsTheSlugWhenTheTargetHasNoHolder(): void {
		// Review finding (Robert, 30-10): a move or migration re-checks the slug in the target.
		$this->magicMapper->method('findSlugHolders')->willReturn([]);
		$entity = $this->entity(self::UUID_A, ['title' => 'Hello World'], 'hello-world');

		$this->assertSame('hello-world', $this->handler->slugForMove($entity, $this->register, $this->schema(['objectSlugField' => 'title'])));
		$this->assertSame([], $this->warnings);
	}

	public function testMoveDropsASlugTheTargetAlreadyHolds(): void {
		$this->magicMapper->method('findSlugHolders')->willReturn([self::UUID_B]);
		$entity = $this->entity(self::UUID_A, ['title' => 'Hello World'], 'hello-world');

		$this->assertNull($this->handler->slugForMove($entity, $this->register, $this->schema(['objectSlugField' => 'title'])));
		$this->assertCount(1, $this->warnings);
		$this->assertStringContainsString('was moved without a slug', $this->warnings[0]);
		$this->assertStringContainsString(self::UUID_B, $this->warnings[0]);
	}

	public function testMoveSuffixesATakenSlugWhenTheTargetAsksForIt(): void {
		$this->magicMapper->method('findSlugHolders')->willReturnCallback(
			fn ($register, $schema, string $slug) => ($slug === 'hello-world') ? [self::UUID_B] : []
		);
		$entity = $this->entity(self::UUID_A, ['title' => 'Hello World'], 'hello-world');

		$slug = $this->handler->slugForMove($entity, $this->register, $this->schema(['objectSlugField' => 'title', 'objectSlugSuffix' => true]));

		$this->assertMatchesRegularExpression('/^hello-world-\d{14}$/', (string)$slug);
		$this->assertCount(1, $this->warnings);
		$this->assertStringContainsString('(move)', $this->warnings[0]);
	}

	public function testMoveIntoAnUnmanagedSchemaKeepsTheSlug(): void {
		$this->magicMapper->expects($this->never())->method('findSlugHolders');
		$entity = $this->entity(self::UUID_A, ['title' => 'Hello World'], 'hello-world');

		$this->assertSame('hello-world', $this->handler->slugForMove($entity, $this->register, $this->schema([])));
	}

	public function testAnEmptySourceFieldGivesNoSlug(): void {
		$this->magicMapper->expects($this->never())->method('findSlugHolders');
		$entity = $this->entity(self::UUID_A, ['title' => '']);

		$this->handler->applyOnSave($entity, $this->schema(['objectSlugField' => 'title']), null, null);

		$this->assertNull($entity->getSlug());
	}

	public function testAnObjectWithoutSlugTakesAFreedSlugOnItsNextSave(): void {
		// The former holder is gone: no live object holds the slug any more.
		$this->magicMapper->method('findSlugHolders')->willReturn([]);
		$entity = $this->entity(self::UUID_B, ['title' => 'Hello World']);

		$this->handler->applyOnSave($entity, $this->schema(['objectSlugField' => 'title']), null, null);

		$this->assertSame('hello-world', $entity->getSlug());
	}

	public function testBatchGivesTheSlugToTheFirstRowAndLogsTheSecond(): void {
		$this->magicMapper->method('findSlugState')->willReturn(['holders' => [], 'stored' => []]);
		$rows = [
			0 => ['uuid' => self::UUID_A, 'clientSlug' => null, 'data' => ['title' => 'Bulk Item']],
			1 => ['uuid' => self::UUID_B, 'clientSlug' => null, 'data' => ['title' => 'Bulk Item']],
		];

		$decisions = $this->handler->decideBatch($rows, $this->register, $this->schema(['objectSlugField' => 'title']));

		$this->assertSame([0 => 'bulk-item', 1 => null], $decisions);
		$this->assertCount(1, $this->warnings);
		$this->assertStringContainsString(self::UUID_A, $this->warnings[0]);
		$this->assertStringContainsString(self::UUID_B, $this->warnings[0]);
	}

	public function testBatchKeepsAStoredSlugAndRespectsAnExistingHolder(): void {
		$this->magicMapper->method('findSlugState')->willReturn(
			[
				'holders' => ['bulk-item' => [self::UUID_C]],
				'stored' => [self::UUID_A => 'kept'],
			]
		);
		$rows = [
			0 => ['uuid' => self::UUID_A, 'clientSlug' => null, 'data' => ['title' => 'Renamed']],
			1 => ['uuid' => self::UUID_B, 'clientSlug' => null, 'data' => ['title' => 'Bulk Item']],
		];

		$decisions = $this->handler->decideBatch($rows, $this->register, $this->schema(['objectSlugField' => 'title']));

		$this->assertSame([0 => 'kept', 1 => null], $decisions);
		$this->assertStringContainsString(self::UUID_C, $this->warnings[0]);
	}

	public function testBatchDoesNotCollideARowWithItsOwnStoredSlug(): void {
		$this->magicMapper->method('findSlugState')->willReturn(
			['holders' => ['bulk-item' => [self::UUID_A]], 'stored' => [self::UUID_A => null]]
		);
		$rows = [0 => ['uuid' => self::UUID_A, 'clientSlug' => null, 'data' => ['title' => 'Bulk Item']]];

		$decisions = $this->handler->decideBatch($rows, $this->register, $this->schema(['objectSlugField' => 'title']));

		$this->assertSame([0 => 'bulk-item'], $decisions);
	}

	public function testBatchChecksTheSuffixedSlugAgainstTheDatabaseToo(): void {
		// Review finding: the suffixed variant must be in the one prefetch, or a
		// suffixed slug stored by an earlier call in the same second is reused.
		$askedFor = [];
		$this->magicMapper->method('findSlugState')->willReturnCallback(
			function (Register $r, Schema $s, array $slugs) use (&$askedFor): array {
				$askedFor = $slugs;
				return [
					'holders' => ['same' => [self::UUID_A], 'same-20261022120000' => [self::UUID_C]],
					'stored' => [],
				];
			}
		);
		$rows = [0 => ['uuid' => self::UUID_B, 'clientSlug' => null, 'data' => ['title' => 'Same']]];

		$decisions = $this->handler->decideBatch(
			$rows,
			$this->register,
			$this->schema(['objectSlugField' => 'title', 'objectSlugSuffix' => true])
		);

		$this->assertContains('same-20261022120000', $askedFor);
		$this->assertSame([0 => null], $decisions);
		$this->assertStringContainsString('also taken', $this->warnings[0]);
	}

	public function testBatchFreezesTheFirstDecisionForARepeatedUuid(): void {
		$this->magicMapper->method('findSlugState')->willReturn(['holders' => [], 'stored' => []]);
		$rows = [
			0 => ['uuid' => self::UUID_A, 'clientSlug' => null, 'data' => ['title' => 'Alpha']],
			1 => ['uuid' => self::UUID_A, 'clientSlug' => null, 'data' => ['title' => 'Beta']],
		];

		$decisions = $this->handler->decideBatch($rows, $this->register, $this->schema(['objectSlugField' => 'title']));

		$this->assertSame([0 => 'alpha', 1 => 'alpha'], $decisions);
	}

	public function testIsSuffixOfRecognisesOnlyADateTimeSuffix(): void {
		$this->assertTrue($this->handler->isSuffixOf('hello-world-20261022120000', 'hello-world'));
		$this->assertFalse($this->handler->isSuffixOf('hello-world-2026', 'hello-world'));
		$this->assertFalse($this->handler->isSuffixOf('hello-world', 'hello-world'));
	}

	public function testBatchLeavesASchemaWithoutSlugFieldAlone(): void {
		$this->magicMapper->expects($this->never())->method('findSlugState');

		$decisions = $this->handler->decideBatch(
			[0 => ['uuid' => self::UUID_A, 'clientSlug' => 'x', 'data' => []]],
			$this->register,
			$this->schema([])
		);

		$this->assertSame([], $decisions);
	}

	public function testDecideDoesNotLogWhenAskedNotTo(): void {
		$slug = $this->handler->decide(
			candidate: 'hello-world',
			register: $this->register,
			schema: $this->schema(['objectSlugField' => 'title']),
			objectUuid: self::UUID_B,
			holderOf: static fn (string $s): ?string => self::UUID_A,
			path: 'backfill',
			log: false
		);

		$this->assertNull($slug);
		$this->assertSame([], $this->warnings);
	}
}//end class
