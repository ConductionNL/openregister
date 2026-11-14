<?php

/**
 * Unit tests for the `@self.confidentiality` render mirror.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/confidentiality-classification-primitive/specs/confidentiality-classification/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use DateTimeImmutable;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\RecordAnnotations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * `@self.confidentiality` states the stored tier, ground and release of a classified object.
 *
 * @covers \OCA\OpenRegister\Service\Object\RecordAnnotations
 * @covers \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Db\Schema\AnnotationVocabulary
 * @uses   \OCA\OpenRegister\Db\Schema\ConfigurationEntryRules
 * @uses   \OCA\OpenRegister\Db\Schema\ConfigurationValidator
 * @uses   \OCA\OpenRegister\Service\Confidentiality\ConfidentialityAnnotationValidator
 * @uses   \OCA\OpenRegister\Service\Confidentiality\ConfidentialityRule
 */
final class RecordAnnotationsConfidentialityTest extends TestCase {

	/**
	 * The declared properties of the besluit schema.
	 *
	 * @var array<string, array<string, string>>
	 */
	private const PROPERTIES = [
		'title' => ['type' => 'string'],
		'confidentialityTier' => ['type' => 'string'],
		'confidentialityGround' => ['type' => 'string'],
		'confidentialityReleaseUntil' => ['type' => 'string', 'format' => 'date-time'],
	];

	/**
	 * The besluit confidentiality block.
	 *
	 * @var array<string, mixed>
	 */
	private const BESLUIT = [
		'tiers' => ['public', 'confidential'],
		'tierProperty' => 'confidentialityTier',
		'groundProperty' => 'confidentialityGround',
		'releaseAtProperty' => 'confidentialityReleaseUntil',
		'clearance' => ['confidential' => 'raadsleden'],
	];

	/**
	 * The moment the tests render at.
	 *
	 * @return DateTimeImmutable
	 */
	private static function now(): DateTimeImmutable {
		return new DateTimeImmutable('2026-06-01T12:00:00+00:00');

	}//end now()

	/**
	 * A schema with the given confidentiality block, or none.
	 *
	 * @param array<string, mixed>|null $block The block, null for no annotation.
	 *
	 * @return Schema
	 */
	private static function schema(?array $block): Schema {
		$schema = new Schema();
		$schema->setProperties(self::PROPERTIES);
		$configuration = [];
		if ($block !== null) {
			$configuration['x-openregister-confidentiality'] = $block;
		}

		$schema->setConfiguration($configuration);
		return $schema;

	}//end schema()

	/**
	 * Render the mirror for a stored object; the entity carries only a title,
	 * as after a projection or property strip.
	 *
	 * @param Schema|null          $schema The schema.
	 * @param array<string, mixed> $stored The stored object data.
	 *
	 * @return array<string, mixed> The rendered @self block.
	 */
	private static function renderSelf(?Schema $schema, array $stored): array {
		$entity = new ObjectEntity();
		$entity->setUuid('5b1f3c1e-0000-4000-8000-0000000000c1');
		$entity->setObject(['title' => (string)($stored['title'] ?? '')]);

		(new RecordAnnotations(logger: new NullLogger()))->attachConfidentiality(
			entity: $entity,
			schema: $schema,
			stored: $stored,
			now: self::now()
		);

		return $entity->jsonSerialize()['@self'];

	}//end renderSelf()

	/**
	 * An unreleased classified object shows its tier and ground, no release, not released.
	 *
	 * @return void
	 */
	public function testAnUnreleasedObjectMirrorsItsTierAndGround(): void {
		$self = self::renderSelf(
			schema: self::schema(block: self::BESLUIT),
			stored: ['title' => 'Besluit 1', 'confidentialityTier' => 'confidential', 'confidentialityGround' => 'Gemeentewet art. 25']
		);

		self::assertSame(
			['tier' => 'confidential', 'ground' => 'Gemeentewet art. 25', 'releaseAt' => null, 'released' => false],
			$self['confidentiality']
		);

	}//end testAnUnreleasedObjectMirrorsItsTierAndGround()

	/**
	 * A past release date reads as released; a future one does not.
	 *
	 * @return void
	 */
	public function testTheReleaseFlagFollowsTheReleaseDate(): void {
		$released = self::renderSelf(
			schema: self::schema(block: self::BESLUIT),
			stored: ['confidentialityTier' => 'confidential', 'confidentialityReleaseUntil' => '2026-01-01T00:00:00+00:00']
		);
		$embargoed = self::renderSelf(
			schema: self::schema(block: self::BESLUIT),
			stored: ['confidentialityTier' => 'confidential', 'confidentialityReleaseUntil' => '2027-01-01T00:00:00+00:00']
		);

		self::assertTrue($released['confidentiality']['released']);
		self::assertSame('2026-01-01T00:00:00+00:00', $released['confidentiality']['releaseAt']);
		self::assertFalse($embargoed['confidentiality']['released']);
		self::assertSame('confidential', $embargoed['confidentiality']['tier']);

	}//end testTheReleaseFlagFollowsTheReleaseDate()

	/**
	 * An object without a tier reads as the first, least restrictive tier.
	 *
	 * @return void
	 */
	public function testAnObjectWithoutATierReadsAsTheFirstTier(): void {
		$self = self::renderSelf(schema: self::schema(block: self::BESLUIT), stored: ['title' => 'Open']);

		self::assertSame(
			['tier' => 'public', 'ground' => null, 'releaseAt' => null, 'released' => false],
			$self['confidentiality']
		);

	}//end testAnObjectWithoutATierReadsAsTheFirstTier()

	/**
	 * A faulty block still mirrors the ground, but names no tier and never a release.
	 *
	 * @return void
	 */
	public function testAFaultyBlockNamesNoTierAndNoRelease(): void {
		$block = self::BESLUIT;
		$block['clearance'] = ['geheim' => 'college'];

		$self = self::renderSelf(
			schema: self::schema(block: $block),
			stored: ['confidentialityTier' => 'confidential', 'confidentialityReleaseUntil' => '2026-01-01T00:00:00+00:00']
		);

		self::assertNull($self['confidentiality']['tier']);
		self::assertFalse($self['confidentiality']['released']);

	}//end testAFaultyBlockNamesNoTierAndNoRelease()

	/**
	 * A schema without the annotation, or no schema, gets no mirror at all.
	 *
	 * @return void
	 */
	public function testASchemaWithoutTheAnnotationGetsNoMirror(): void {
		$stored = ['confidentialityTier' => 'confidential'];

		self::assertArrayNotHasKey('confidentiality', self::renderSelf(schema: self::schema(block: null), stored: $stored));
		self::assertArrayNotHasKey('confidentiality', self::renderSelf(schema: null, stored: $stored));

	}//end testASchemaWithoutTheAnnotationGetsNoMirror()

	/**
	 * A mirror that cannot be built is a debug line, and the object renders without it.
	 *
	 * @return void
	 */
	public function testAFailingMirrorIsLoggedAndLeftOut(): void {
		$schema = $this->createMock(Schema::class);
		$schema->method('getConfiguration')->willReturn(['x-openregister-confidentiality' => self::BESLUIT]);
		$schema->method('getProperties')->willThrowException(new \RuntimeException('properties unreadable'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('debug')->with(self::stringContains('confidentiality mirror failed'));

		$entity = new ObjectEntity();
		$entity->setObject(['title' => 'x']);
		(new RecordAnnotations(logger: $logger))->attachConfidentiality(
			entity: $entity,
			schema: $schema,
			stored: ['confidentialityTier' => 'confidential'],
			now: self::now()
		);

		self::assertNull($entity->getConfidentiality());

	}//end testAFailingMirrorIsLoggedAndLeftOut()
}//end class
