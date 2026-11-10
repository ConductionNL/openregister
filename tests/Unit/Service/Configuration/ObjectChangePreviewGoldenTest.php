<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

/**
 * Golden test for ObjectChangePreview.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Configuration
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Configuration\ObjectChangePreview;
use OCA\OpenRegister\Service\Configuration\ObjectPreviewTargets;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins preview() and createdSlugs().
 *
 * Each case is a remote object, the local registers and schemas, the planned
 * slugs and what the object lookup finds. The preview row, every lookup and
 * every comparison are compared with fixtures/object-change-preview-golden.json.
 * Set UPDATE_GOLDEN=1 to rewrite it.
 *
 * @covers \OCA\OpenRegister\Service\Configuration\ObjectChangePreview
 * @uses   \OCA\OpenRegister\Service\Configuration\ObjectPreviewTargets
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 */
class ObjectChangePreviewGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/object-change-preview-golden.json';

	/** @var list<array<int, mixed>> Every lookup and comparison, in order. */
	private array $calls = [];

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$self = ['register' => 'Zaken', 'schema' => 'ZaakType', 'slug' => 'obj-1'];
		$remote = ['title' => 'Remote', 'omschrijving' => 'Nieuw', '@self' => $self + ['version' => '2.0.0']];
		$stored = ['version' => '1.0.0', 'object' => ['title' => 'Old', 'omschrijving' => 'Oud']];
		return [
			'missing slug' => [['object' => ['name' => 'Named', '@self' => ['register' => 'zaken', 'schema' => 'zaaktype']]]],
			'missing everything' => [['object' => ['title' => '', 'name' => 7]]],
			'self not an array' => [['object' => ['title' => 'T', '@self' => 'zaken/zaaktype/obj']]],
			'register unknown' => [['object' => $remote, 'registers' => [], 'find' => 'none']],
			'schema unknown, planned' => [['object' => $remote, 'schemas' => [], 'planned' => ['registers' => [], 'schemas' => ['zaaktype' => true]]]],
			'both unknown, both planned' => [['object' => $remote, 'registers' => [], 'schemas' => [], 'planned' => ['registers' => ['zaken' => true], 'schemas' => ['zaaktype' => true]]]],
			'both unknown, register planned' => [['object' => $remote, 'registers' => [], 'schemas' => [], 'planned' => ['registers' => ['zaken' => true], 'schemas' => []]]],
			'not stored yet' => [['object' => $remote, 'find' => 'none']],
			'duplicate slug' => [['object' => $remote, 'find' => 'many']],
			'older remote' => [['object' => ['@self' => $self + ['version' => '0.9']] + $remote, 'find' => $stored]],
			'same version top-level' => [['object' => ['version' => '1.0.0', 'title' => 'x', '@self' => $self], 'find' => $stored]],
			'newer, no version anywhere' => [['object' => ['title' => 'x', '@self' => $self], 'find' => ['object' => ['title' => 'x']]]],
			'newer with changes' => [['object' => $remote, 'find' => $stored]],
			'newer without changes' => [['object' => ['title' => 'Old', 'omschrijving' => 'Oud', '@self' => $self + ['version' => '3']], 'find' => $stored]],
			'undeclared and identity keys' => [[
				'object' => [
					'title' => 'Remote',
					'uuid' => 'u-1',
					'slug' => 'obj-1',
					'id' => 5,
					'_meta' => 1,
					'extra' => 'dropped',
					'' => 'empty key',
					'0' => 'numeric',
					'@other' => 'kept',
					'@self' => $self + ['version' => '2.0.0'],
				],
				'find' => $stored,
			]],
			'schema declares slug' => [[
				'properties' => ['title' => [], 'omschrijving' => [], 'slug' => []],
				'object' => ['title' => 'Remote', 'uuid' => 'u-1', 'slug' => 'obj-1', 'more' => 1, '@self' => $self + ['version' => '2.0.0']],
				'find' => $stored,
			]],
		];
	}//end cases()

	/**
	 * @param array<string, mixed> $case
	 */
	#[DataProvider('cases')]
	public function testPreviewMatchesGolden(array $case): void {
		$register = new Register();
		$register->setSlug('zaken');
		$schema = new Schema();
		$schema->setSlug('zaaktype');
		$schema->setProperties($case['properties'] ?? ['title' => ['type' => 'string'], 'omschrijving' => ['type' => 'string']]);

		$preview = new ObjectChangePreview($this->mapper($case['find'] ?? 'none'), function (array $current, array $proposed): array {
			$this->calls[] = ['compare', $current, $proposed];
			$changes = [];
			foreach ($proposed as $key => $value) {
				if (($current[$key] ?? null) !== $value) {
					$changes[] = ['field' => $key, 'current' => ($current[$key] ?? null), 'proposed' => $value];
				}
			}

			return $changes;
		});
		$row = $preview->preview(
			$case['object'],
			new ObjectPreviewTargets(
				$case['registers'] ?? ['zaken' => $register],
				$case['schemas'] ?? ['zaaktype' => $schema],
				$case['planned'] ?? ['registers' => [], 'schemas' => []]
			)
		);
		$created = $preview->createdSlugs([$row, ['action' => 'create', 'slug' => 'Mixed-Case'], ['action' => 'skip', 'slug' => 'skipped'], ['action' => 'create'], []]);
		$actual = json_decode(json_encode(['row' => $row, 'created' => $created, 'calls' => $this->calls]), true);

		$name = $this->dataName();
		$golden = [];
		if (is_file(self::FIXTURE) === true) {
			$golden = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			$golden[$name] = $actual;
			ksort($golden);
			file_put_contents(self::FIXTURE, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$this->assertArrayHasKey($name, $golden, 'No golden recording for this case.');
		$this->assertSame($golden[$name], $actual);
	}//end testPreviewMatchesGolden()

	private function mapper(mixed $find): MagicMapper {
		$mapper = $this->createMock(MagicMapper::class);
		$mapper->method('find')->willReturnCallback(
			function (...$args) use ($find): ObjectEntity {
				$this->calls[] = ['find', array_map(static fn ($arg) => is_object($arg) === true ? get_class($arg) : $arg, $args)];
				if ($find === 'none') {
					throw new DoesNotExistException('not found');
				}

				if ($find === 'many') {
					throw new MultipleObjectsReturnedException('many');
				}

				$object = new ObjectEntity();
				$object->setUuid('11111111-2222-3333-4444-555555555555');
				$object->setSlug('obj-1');
				$object->setRegister('4');
				$object->setSchema('9');
				if (isset($find['version']) === true) {
					$object->setVersion($find['version']);
				}

				$object->setObject($find['object']);
				return $object;
			}
		);

		return $mapper;
	}//end mapper()
}//end class
