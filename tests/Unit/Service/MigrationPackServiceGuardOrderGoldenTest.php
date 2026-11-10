<?php

/**
 * Golden test pinning which MigrationPackService writes are allowed and refused, and in what order the guards run.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://OpenRegister.app
 *
 * @spec openspec/specs/migration-mapping-packs/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use DateTime;
use OCA\OpenRegister\Db\MigrationPack;
use OCA\OpenRegister\Db\MigrationPackMapper;
use OCA\OpenRegister\Exception\BuiltinPackSlugLockedException;
use OCA\OpenRegister\Exception\InvalidPackDefinitionException;
use OCA\OpenRegister\Exception\PackChangedException;
use OCA\OpenRegister\Exception\PackSlugExistsException;
use OCA\OpenRegister\Service\MigrationPack\MappingEngine;
use OCA\OpenRegister\Service\MigrationPack\PackDefinitionValidator;
use OCA\OpenRegister\Service\MigrationPackService;
use OCA\OpenRegister\Tests\Unit\Service\MigrationPack\PassThroughL10nTrait;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Throwable;

final class MigrationPackServiceGuardOrderGoldenTest extends TestCase {
	use PassThroughL10nTrait;

	/**
	 * Every mapper and app-config call, in order.
	 *
	 * @var list<string>
	 */
	private array $calls = [];

	/**
	 * Build the service over a stored pack and a set of taken slugs.
	 *
	 * @param MigrationPack|null $stored       The pack find() returns, or null for none.
	 * @param list<string>       $takenSlugs   The slugs findByPackSlug() finds.
	 * @param list<string>       $deletedSlugs The deleted built-in slugs.
	 *
	 * @return MigrationPackService
	 */
	private function service(?MigrationPack $stored, array $takenSlugs = [], array $deletedSlugs = []): MigrationPackService {
		$mapper = $this->createMock(MigrationPackMapper::class);
		$mapper->method('find')->willReturnCallback(function (int $id) use ($stored): MigrationPack {
			$this->calls[] = 'find:' . $id;
			if ($stored === null) {
				throw new DoesNotExistException('none');
			}
			return $stored;
		});
		$mapper->method('findByPackSlug')->willReturnCallback(function (string $slug) use ($takenSlugs): MigrationPack {
			$this->calls[] = 'findByPackSlug:' . $slug;
			if (in_array($slug, $takenSlugs, true) === false) {
				throw new DoesNotExistException('none');
			}
			$pack = new MigrationPack();
			$pack->setId(99);
			$pack->setPackSlug($slug);
			return $pack;
		});
		foreach (['insert', 'update'] as $write) {
			$mapper->method($write)->willReturnCallback(function (MigrationPack $pack) use ($write): MigrationPack {
				$this->calls[] = $write . ':' . $pack->getPackSlug() . ':builtin=' . var_export($pack->getBuiltin(), true);
				return $pack;
			});
		}

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueArray')->willReturnCallback(function () use (&$deletedSlugs): array {
			$this->calls[] = 'config.get';
			return $deletedSlugs;
		});
		$config->method('setValueArray')->willReturnCallback(function (string $app, string $key, array $value) use (&$deletedSlugs): bool {
			$this->calls[] = 'config.set:' . implode(',', $value);
			$deletedSlugs = $value;
			return true;
		});

		return new MigrationPackService(
			$mapper,
			new PackDefinitionValidator($this->passThroughL10n()),
			new MappingEngine($this->passThroughL10n()),
			$config
		);
	}

	private function definition(string $slug): array {
		return [
			'id' => $slug,
			'name' => 'Pack ' . $slug,
			'sourceFormat' => 'csv',
			'version' => '2.0.0',
			'idStrategy' => ['type' => 'generate'],
			'fieldMappings' => [['source' => 'Name', 'target' => 'title']],
		];
	}

	private function stored(string $slug, bool $builtin, ?string $updatedAt = '2026-10-12T10:00:00+00:00'): MigrationPack {
		$pack = new MigrationPack();
		$pack->setId(1);
		$pack->setPackSlug($slug);
		$pack->setBuiltin($builtin);
		if ($updatedAt !== null) {
			$pack->setUpdatedAt(new DateTime($updatedAt));
		}
		return $pack;
	}

	/**
	 * Run a call and describe what came of it.
	 *
	 * @param callable $call The call.
	 *
	 * @return array{outcome: string, calls: list<string>}
	 */
	private function outcome(callable $call): array {
		try {
			$result = $call();
			$outcome = 'ok';
			if ($result instanceof MigrationPack) {
				$outcome = 'ok:' . $result->getPackSlug() . ':' . $result->getName() . ':' . $result->getVersion()
					. ':builtin=' . var_export($result->getBuiltin(), true);
			}
		} catch (Throwable $e) {
			$outcome = get_class($e);
		}

		return ['outcome' => $outcome, 'calls' => $this->calls];
	}

	/**
	 * Update cases: the stored pack, the new definition, the expected time, and the outcome.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function updates(): array {
		$at = (new DateTime('2026-10-12T10:00:00+00:00'))->getTimestamp();
		return [
			'invalid definition refused before the pack is read' => [[
				'slug' => 'test-pack',
				'builtin' => false,
				'updatedAt' => '2026-10-12T10:00:00+00:00',
				'next' => ['id' => 'x', 'name' => ''],
				'expected' => $at,
				'taken' => [],
				'deleted' => [],
				'want' => ['outcome' => InvalidPackDefinitionException::class, 'calls' => []],
			]],
			'stale and a built-in rename: the stale check wins' => [[
				'slug' => 'test-pack',
				'builtin' => true,
				'updatedAt' => '2026-10-12T10:00:05+00:00',
				'next' => 'renamed',
				'expected' => $at,
				'taken' => [],
				'deleted' => [],
				'want' => ['outcome' => PackChangedException::class, 'calls' => ['find:1']],
			]],
			'stored without updatedAt is stale for any expected time' => [[
				'slug' => 'test-pack',
				'builtin' => false,
				'updatedAt' => null,
				'next' => 'test-pack',
				'expected' => $at,
				'taken' => [],
				'deleted' => [],
				'want' => ['outcome' => PackChangedException::class, 'calls' => ['find:1']],
			]],
			'built-in rename onto a taken slug: the lock wins' => [[
				'slug' => 'test-pack',
				'builtin' => true,
				'updatedAt' => '2026-10-12T10:00:00+00:00',
				'next' => 'taken',
				'expected' => $at,
				'taken' => ['taken'],
				'deleted' => [],
				'want' => ['outcome' => BuiltinPackSlugLockedException::class, 'calls' => ['find:1']],
			]],
			'rename onto a taken slug refused' => [[
				'slug' => 'test-pack',
				'builtin' => false,
				'updatedAt' => '2026-10-12T10:00:00+00:00',
				'next' => 'taken',
				'expected' => null,
				'taken' => ['taken'],
				'deleted' => [],
				'want' => ['outcome' => PackSlugExistsException::class, 'calls' => ['find:1', 'findByPackSlug:taken']],
			]],
			'rename onto a deleted built-in slug becomes built-in' => [[
				'slug' => 'test-pack',
				'builtin' => false,
				'updatedAt' => '2026-10-12T10:00:00+00:00',
				'next' => 'gone',
				'expected' => $at,
				'taken' => [],
				'deleted' => ['gone'],
				'want' => [
					'outcome' => 'ok:gone:Pack gone:2.0.0:builtin=true',
					'calls' => ['find:1', 'findByPackSlug:gone', 'config.get', 'update:gone:builtin=true'],
				],
			]],
			'rename to a free slug stays non-built-in' => [[
				'slug' => 'test-pack',
				'builtin' => false,
				'updatedAt' => '2026-10-12T10:00:00+00:00',
				'next' => 'free',
				'expected' => null,
				'taken' => [],
				'deleted' => ['gone'],
				'want' => [
					'outcome' => 'ok:free:Pack free:2.0.0:builtin=false',
					'calls' => ['find:1', 'findByPackSlug:free', 'config.get', 'update:free:builtin=false'],
				],
			]],
			'built-in keeping its slug is allowed without slug lookups' => [[
				'slug' => 'test-pack',
				'builtin' => true,
				'updatedAt' => '2026-10-12T10:00:00+00:00',
				'next' => 'test-pack',
				'expected' => $at,
				'taken' => ['test-pack'],
				'deleted' => ['test-pack'],
				'want' => [
					'outcome' => 'ok:test-pack:Pack test-pack:2.0.0:builtin=true',
					'calls' => ['find:1', 'update:test-pack:builtin=true'],
				],
			]],
		];
	}

	/**
	 * The update guards, allowed and refused, with their calls.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return void
	 *
	 * @dataProvider updates
	 */
	public function testUpdateGuards(array $case): void {
		$stored = $this->stored($case['slug'], $case['builtin'], $case['updatedAt']);
		$service = $this->service(stored: $stored, takenSlugs: $case['taken'], deletedSlugs: $case['deleted']);
		$definition = $case['next'];
		if (is_string($definition) === true) {
			$definition = $this->definition($definition);
		}

		$this->assertSame($case['want'], $this->outcome(fn () => $service->update(1, $definition, $case['expected'])));
	}

	/**
	 * Create cases: the slug, the built-in flag, the taken and deleted slugs, and the outcome.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function creates(): array {
		return [
			'taken slug refused before anything is written' => [[
				'slug' => 'taken',
				'builtin' => false,
				'taken' => ['taken'],
				'deleted' => [],
				'want' => ['outcome' => PackSlugExistsException::class, 'calls' => ['findByPackSlug:taken']],
			]],
			'admin create under a deleted built-in slug is built-in and keeps the lock' => [[
				'slug' => 'gone',
				'builtin' => false,
				'taken' => [],
				'deleted' => ['gone'],
				'want' => [
					'outcome' => 'ok:gone:Pack gone:2.0.0:builtin=true',
					'calls' => ['findByPackSlug:gone', 'config.get', 'insert:gone:builtin=true'],
				],
			]],
			'seeding a built-in clears the lock after the insert' => [[
				'slug' => 'gone',
				'builtin' => true,
				'taken' => [],
				'deleted' => ['gone', 'other'],
				'want' => [
					'outcome' => 'ok:gone:Pack gone:2.0.0:builtin=true',
					'calls' => ['findByPackSlug:gone', 'insert:gone:builtin=true', 'config.get', 'config.get', 'config.set:other'],
				],
			]],
			'plain admin create' => [[
				'slug' => 'new',
				'builtin' => false,
				'taken' => [],
				'deleted' => [],
				'want' => [
					'outcome' => 'ok:new:Pack new:2.0.0:builtin=false',
					'calls' => ['findByPackSlug:new', 'config.get', 'insert:new:builtin=false'],
				],
			]],
		];
	}

	/**
	 * The create guards, allowed and refused, with their calls.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return void
	 *
	 * @dataProvider creates
	 */
	public function testCreateGuards(array $case): void {
		$service = $this->service(stored: null, takenSlugs: $case['taken'], deletedSlugs: $case['deleted']);

		$this->assertSame($case['want'], $this->outcome(fn () => $service->create($this->definition($case['slug']), 'admin', $case['builtin'])));
	}

	public function testImportUpdatesThroughTheSameGuards(): void {
		$service = $this->service(stored: $this->stored('locked', true), takenSlugs: ['locked']);

		$this->assertSame(
			['outcome' => 'ok', 'calls' => ['findByPackSlug:locked', 'find:99', 'update:locked:builtin=true']],
			$this->outcome(fn () => $service->importDefinition($this->definition('locked'), 'admin'))
		);
	}
}
