<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Decision;

use OCA\OpenRegister\Db\EntityRelation;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Decision\ReviewPolicyResolver;
use OCA\OpenRegister\Service\File\ManagedFileOwnerLookup;
use OCA\OpenRegister\Service\Settings\FileSettingsHandler;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A relation's policy comes from its schema, through its object or its file's
 * object, else from the instance default (REQ-ROD-001).
 *
 * @covers \OCA\OpenRegister\Service\Decision\ReviewPolicyResolver
 * @uses   \OCA\OpenRegister\Service\Decision\ReviewPolicy
 * @uses   \OCA\OpenRegister\Service\Settings\FileSettingsHandler
 *
 * @spec openspec/changes/reviewer-owns-their-decisions/specs/entity-relation-grondslagen/spec.md#requirement-a-reviewers-decision-is-changed-only-by-that-reviewer-or-a-supervisor-req-rod-001
 * @uses \OCA\OpenRegister\Db\EntityRelation
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Schema
 */
class ReviewPolicyResolverTest extends TestCase {

	/**
	 * A resolver over a schema store, stored file settings, and a file that belongs to object `doc-1`.
	 *
	 * @param array<string, mixed>|null $schemaConfiguration The schema's configuration, or null when it cannot be read.
	 * @param array<string, mixed>      $fileSettings        The stored file settings.
	 * @param bool                      $lookupFails         Whether the file-to-object lookup throws.
	 * @param bool                      $objectLookupFails   Whether the object lookup behind an existing file throws.
	 *
	 * @return ReviewPolicyResolver The resolver.
	 */
	private function resolver(?array $schemaConfiguration, array $fileSettings=[], bool $lookupFails=false, bool $objectLookupFails=false): ReviewPolicyResolver {
		$schemas = $this->createMock(SchemaMapper::class);
		if ($schemaConfiguration === null) {
			$schemas->method('find')->willThrowException(new RuntimeException('gone'));
		} else {
			$schema = new Schema();
			$schema->setConfiguration($schemaConfiguration);
			$schemas->method('find')->with('12')->willReturn($schema);
		}

		$appConfig = $this->createMock(IAppConfig::class);
		$stored = '';
		if ($fileSettings !== []) {
			$stored = (string)json_encode($fileSettings);
		}

		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default=''): string => ($key === 'fileManagement' ? $stored : $default)
		);

		$file = $this->createMock(File::class);
		$loose = $this->createMock(File::class);
		$root = $this->createMock(IRootFolder::class);
		if ($lookupFails === true) {
			$root->method('getFirstNodeById')->willThrowException(new RuntimeException('lookup failed'));
		} else {
			$root->method('getFirstNodeById')->willReturnCallback(static fn (int $id) => ([99 => $file, 50 => $loose][$id] ?? null));
		}

		$object = new ObjectEntity();
		$object->setRegister('3');
		$object->setSchema('12');
		$object->setUuid('doc-1');
		$owners = $this->createMock(ManagedFileOwnerLookup::class);
		if ($objectLookupFails === true) {
			$owners->method('find')->willThrowException(new RuntimeException('database is gone'));
		} else {
			// File 99 belongs to `doc-1`; file 50 exists and belongs to no object.
			$owners->method('find')->willReturnCallback(static fn ($node) => ($node === $file ? $object : null));
		}

		return new ReviewPolicyResolver(
			schemaMapper: $schemas,
			fileSettings: new FileSettingsHandler(appConfig: $appConfig),
			rootFolder: $root,
			owners: $owners,
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end resolver()

	/**
	 * A relation on a file reads the policy of the schema of the object the file belongs to.
	 *
	 * @return void
	 */
	public function testAFileReadsTheSchemaOfItsObject(): void {
		$relation = new EntityRelation();
		$relation->setId(7);
		$relation->setFileId(99);

		$resolved = $this->resolver(schemaConfiguration: ['x-openregister-review' => ['ownership' => 'decider', 'supervisors' => ['woo-coordinator']]])
			->forRelation(relation: $relation);

		self::assertSame(['ownership' => 'decider', 'supervisors' => ['woo-coordinator'], 'hideOthers' => false], $resolved['policy']->toArray());
		self::assertSame('entity-relation', $resolved['subject']['subjectType']);
		self::assertSame('7', $resolved['subject']['subjectId']);
		self::assertSame('12', $resolved['subject']['schema']);
		self::assertSame('doc-1', $resolved['subject']['objectUuid']);
		self::assertSame('3', $resolved['subject']['register']);
	}//end testAFileReadsTheSchemaOfItsObject()

	/**
	 * A schema without the declaration, or a file without an object, falls back to the instance default.
	 *
	 * @return void
	 */
	public function testWithoutADeclarationTheInstanceDefaultApplies(): void {
		$relation = new EntityRelation();
		$relation->setObjectId(5);
		$relation->setSchemaId('12');

		$resolver = $this->resolver(schemaConfiguration: [], fileSettings: ['anonymisationReview' => ['ownership' => 'decider', 'hideOthers' => true]]);
		self::assertTrue($resolver->forRelation(relation: $relation)['policy']->hidesOthers());

		$orphan = new EntityRelation();
		$orphan->setFileId(50);
		self::assertTrue($resolver->forRelation(relation: $orphan)['policy']->isDeciderOwned());

		self::assertFalse($this->resolver(schemaConfiguration: [])->forRelation(relation: $orphan)['policy']->isDeciderOwned());

		// A file that cannot be found at all has no known owner: closed, never the open default.
		$gone = new EntityRelation();
		$gone->setFileId(404);
		self::assertTrue($this->resolver(schemaConfiguration: [])->forRelation(relation: $gone)['policy']->isDeciderOwned());
	}//end testWithoutADeclarationTheInstanceDefaultApplies()

	/**
	 * A schema that cannot be read leaves the decision with its decider.
	 *
	 * @return void
	 */
	public function testAnUnreadableSchemaFailsClosed(): void {
		$relation = new EntityRelation();
		$relation->setSchemaId('12');

		$policy = $this->resolver(schemaConfiguration: null)->forRelation(relation: $relation)['policy'];

		self::assertTrue($policy->isDeciderOwned());
		self::assertSame([], $policy->getSupervisors());
		self::assertTrue($policy->hidesOthers());
	}//end testAnUnreadableSchemaFailsClosed()

	/**
	 * A file whose object cannot be looked up is decider-owned and hidden, not the open instance default.
	 *
	 * @return void
	 */
	public function testAFailedFileLookupFailsClosed(): void {
		$relation = new EntityRelation();
		$relation->setId(7);
		$relation->setFileId(99);

		$resolved = $this->resolver(schemaConfiguration: [], lookupFails: true)->forRelation(relation: $relation);

		self::assertTrue($resolved['policy']->isDeciderOwned());
		self::assertTrue($resolved['policy']->hidesOthers());
		self::assertSame([], $resolved['policy']->getSupervisors());
		self::assertArrayNotHasKey('lookupFailed', $resolved['subject']);
	}//end testAFailedFileLookupFailsClosed()

	/**
	 * An object lookup that fails behind an existing file fails closed, like a failed file lookup.
	 *
	 * @return void
	 */
	public function testAFailedObjectLookupBehindAnExistingFileFailsClosed(): void {
		$relation = new EntityRelation();
		$relation->setId(7);
		$relation->setFileId(99);

		$resolved = $this->resolver(schemaConfiguration: [], objectLookupFails: true)->forRelation(relation: $relation);

		self::assertTrue($resolved['policy']->isDeciderOwned());
		self::assertTrue($resolved['policy']->hidesOthers());
	}//end testAFailedObjectLookupBehindAnExistingFileFailsClosed()
}//end class
