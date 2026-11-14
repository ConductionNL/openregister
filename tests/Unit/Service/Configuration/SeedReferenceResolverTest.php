<?php

/**
 * Tests for resolving seed reference slugs to uuids.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Configuration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

use OCA\OpenRegister\Service\Configuration\SeedReferenceResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A slug in a reference property becomes the uuid it names; a uuid, a non-reference
 * property and an unknown slug stay as written, the last with a warning.
 *
 * @covers \OCA\OpenRegister\Service\Configuration\SeedReferenceResolver
 *
 * @spec openspec/changes/aggregation-dialect-repair/tasks.md#6-adjacent-repair-a--seed-ref-resolution
 */
final class SeedReferenceResolverTest extends TestCase {

	private const ANNA = '0b6e3c1a-1111-4a2b-9c3d-000000000001';
	private const BERT = '0b6e3c1a-1111-4a2b-9c3d-000000000002';

	/**
	 * The lookups asked, as "target:slug".
	 *
	 * @var list<string>
	 */
	private array $asked = [];

	/**
	 * The warnings logged.
	 *
	 * @var list<string>
	 */
	private array $warnings = [];

	/**
	 * A resolver that knows anna and bert in the person schema.
	 *
	 * @return SeedReferenceResolver The resolver.
	 */
	private function resolver(): SeedReferenceResolver {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(function (string $message): void {
			$this->warnings[] = $message;
		});

		return new SeedReferenceResolver(
			lookup: function (string $target, string $slug): ?string {
				$this->asked[] = $target . ':' . $slug;
				$known = ['person:anna' => self::ANNA, 'person:bert' => self::BERT];
				return $known[$target . ':' . $slug] ?? null;
			},
			logger: $logger
		);
	}//end resolver()

	/**
	 * A scalar and an array reference resolve; the target is the last segment of the `$ref`.
	 *
	 * @return void
	 */
	public function testSlugsInReferencePropertiesBecomeUuids(): void {
		$properties = [
			'chair' => ['type' => 'string', '$ref' => '#/components/schemas/person'],
			'members' => ['type' => 'array', 'items' => ['$ref' => 'person']],
			'title' => ['type' => 'string'],
		];

		$data = $this->resolver()->resolve(
			data: ['chair' => 'anna', 'members' => ['anna', 'bert'], 'title' => 'bert'],
			properties: $properties,
			schemaSlug: 'committee'
		);

		self::assertSame(['chair' => self::ANNA, 'members' => [self::ANNA, self::BERT], 'title' => 'bert'], $data);
		self::assertSame(['person:anna', 'person:anna', 'person:bert'], $this->asked);
		self::assertSame([], $this->warnings);
	}//end testSlugsInReferencePropertiesBecomeUuids()

	/**
	 * A uuid, an empty value, a non-string and an absent property are left alone and never looked up.
	 *
	 * @return void
	 */
	public function testUuidsAndNonSlugsAreNotLookedUp(): void {
		$properties = [
			'chair' => ['$ref' => 'person'],
			'deputy' => ['$ref' => 'person'],
			'members' => ['type' => 'array', 'items' => ['$ref' => 'person']],
			'secretary' => ['$ref' => 'person'],
			'notes' => 'not a property',
		];

		$data = $this->resolver()->resolve(
			data: ['chair' => strtoupper(self::BERT), 'deputy' => '', 'members' => [7, ['id' => 'x']], 'notes' => 'anna'],
			properties: $properties,
			schemaSlug: 'committee'
		);

		self::assertSame(['chair' => strtoupper(self::BERT), 'deputy' => '', 'members' => [7, ['id' => 'x']], 'notes' => 'anna'], $data);
		self::assertSame([], $this->asked);
	}//end testUuidsAndNonSlugsAreNotLookedUp()

	/**
	 * A slug the lookup cannot find is kept as written, with a warning naming the property and the slug.
	 *
	 * @return void
	 */
	public function testAnUnknownSlugIsKeptWithAWarning(): void {
		$data = $this->resolver()->resolve(
			data: ['chair' => 'carla', 'members' => ['anna', 'dirk']],
			properties: ['chair' => ['$ref' => 'person'], 'members' => ['type' => 'array', 'items' => ['$ref' => 'person']]],
			schemaSlug: 'committee'
		);

		self::assertSame(['chair' => 'carla', 'members' => [self::ANNA, 'dirk']], $data);
		self::assertCount(2, $this->warnings);
		self::assertStringContainsString('"carla" on property "chair" of schema "committee"', $this->warnings[0]);
		self::assertStringContainsString('"dirk" on property "members" of schema "committee"', $this->warnings[1]);
		self::assertStringContainsString('kept as written', $this->warnings[0]);
	}//end testAnUnknownSlugIsKeptWithAWarning()

	/**
	 * A property whose `$ref` is empty or not a string is not a reference.
	 *
	 * @return void
	 */
	public function testAPropertyWithoutAUsableRefIsNotAReference(): void {
		$data = $this->resolver()->resolve(
			data: ['a' => 'anna', 'b' => 'anna', 'c' => ['anna']],
			properties: ['a' => ['$ref' => ''], 'b' => ['$ref' => ['person']], 'c' => ['type' => 'array', 'items' => 'person']],
			schemaSlug: 'committee'
		);

		self::assertSame(['a' => 'anna', 'b' => 'anna', 'c' => ['anna']], $data);
		self::assertSame([], $this->asked);
	}//end testAPropertyWithoutAUsableRefIsNotAReference()
}//end class
