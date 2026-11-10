<?php

/**
 * OpenRegister - the calls and the report of MergeHandler::mergeObjects().
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use Exception;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\Object\MergeHandler;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Pins every mapper and file call, their order and arguments, and the report or the
 * exception, for a range of merges.
 *
 * Expected values live in `fixtures/merge-objects.php`, one entry per case. Times in
 * the source object's deleted marker are recorded by their keys only.
 */
class MergeHandlerMergeObjectsTest extends TestCase {

	/**
	 * The calls made, in order.
	 *
	 * @var list<string>
	 */
	private array $calls = [];

	/**
	 * Every case: the merge data and how the collaborators answer.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function cases(): array {
		$withFolder = ['source' => ['folder' => '17'], 'files' => ['a.pdf' => 'ok', 'b.pdf' => 'ok']];

		return [
			'no target'                       => ['merge' => []],
			'empty target'                    => ['merge' => ['target' => '']],
			'source not found'                => ['missing' => ['src']],
			'target not found'                => ['missing' => ['tgt']],
			'both missing'                    => ['missing' => ['src', 'tgt']],
			'other register'                  => ['target' => ['register' => '9']],
			'other schema'                    => ['target' => ['schema' => '9']],
			'defaults'                        => [],
			'properties merged'               => [
				'merge'  => ['object' => ['title' => 'same', 'count' => 2, 'new' => ['x'], 'gone' => null]],
				'target' => ['object' => ['title' => 'same', 'count' => '2', 'keep' => 1]],
			],
			'transfer without folder'         => ['files' => ['a.pdf' => 'ok']],
			'transfer files'                  => $withFolder,
			'transfer a file that stays'      => ['source' => ['folder' => '17'], 'files' => ['a.pdf' => 'stays', 'b.pdf' => 'ok']],
			'transfer copy fails'             => ['source' => ['folder' => '17'], 'files' => ['a.pdf' => 'copy fails']],
			'transfer skips folders'          => ['source' => ['folder' => '17'], 'files' => ['sub' => 'folder', 'a.pdf' => 'ok']],
			'transfer listing fails'          => ['source' => ['folder' => '17'], 'files' => 'throws'],
			'delete files'                    => ['merge' => ['fileAction' => 'delete']] + $withFolder,
			'delete a file that stays'        => [
				'merge'  => ['fileAction' => 'delete'],
				'source' => ['folder' => '17'],
				'files'  => ['a.pdf' => 'stays', 'b.pdf' => 'ok'],
			],
			'delete listing fails'            => ['merge' => ['fileAction' => 'delete'], 'source' => ['folder' => '17'], 'files' => 'throws'],
			'delete without folder'           => ['merge' => ['fileAction' => 'delete'], 'files' => ['a.pdf' => 'ok']],
			'other file action'               => ['merge' => ['fileAction' => 'keep']] + $withFolder,
			'relations transferred'           => [
				'source' => ['relations' => ['r1', 'r2', ['nested' => 'r3']]],
				'target' => ['relations' => ['r2', 'r9']],
			],
			'relations transferred from none' => ['source' => ['relations' => []], 'target' => ['relations' => ['r9']]],
			'relations dropped'               => [
				'merge'  => ['relationAction' => 'drop'],
				'source' => ['relations' => ['r1', 'r2']],
				'target' => ['relations' => ['r9']],
			],
			'relations other action'          => ['merge' => ['relationAction' => 'keep'], 'source' => ['relations' => ['r1']]],
			'references updated'              => [
				'referencing' => [
					['uuid' => 'ref-1', 'name' => 'One', 'relations' => ['a' => 'src', 'b' => ['src', 'z']], 'object' => ['link' => 'src']],
					['uuid' => 'ref-2', 'relations' => ['src'], 'object' => ['link' => 'other']],
					['uuid' => 'ref-3', 'name' => 'Three', 'relations' => ['x'], 'object' => ['deep' => ['list' => ['src', 'y']]]],
					['uuid' => 'ref-4', 'name' => 'Four', 'relations' => ['x'], 'object' => ['link' => 'nothing']],
				],
			],
			'references not transferred'      => [
				'merge'       => ['referenceAction' => 'keep'],
				'referencing' => [['uuid' => 'ref-1', 'relations' => ['src'], 'object' => []]],
			],
			'no user to delete as'            => ['user' => false],
			'target update fails'             => ['updateFails' => ['tgt']],
			'source update fails'             => ['updateFails' => ['src']],
			'reference update fails'          => [
				'updateFails' => ['ref-1'],
				'referencing' => [['uuid' => 'ref-1', 'relations' => ['src'], 'object' => []]],
			],
			'everything'                      => [
				'merge'       => [
					'object'          => ['title' => 'merged'],
					'fileAction'      => 'transfer',
					'relationAction'  => 'transfer',
					'referenceAction' => 'transfer',
				],
				'source'      => ['folder' => '17', 'relations' => ['r1']],
				'target'      => ['object' => ['title' => 'old'], 'relations' => []],
				'files'       => ['a.pdf' => 'ok'],
				'referencing' => [['uuid' => 'ref-1', 'name' => 'One', 'relations' => [], 'object' => ['link' => 'src']]],
			],
		];
	}//end cases()

	/**
	 * Every case paired with its expected outcome.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>}>
	 */
	public static function casesWithExpectations(): array {
		$expected = require __DIR__.'/fixtures/merge-objects.php';
		$pairs    = [];
		foreach (self::cases() as $name => $case) {
			$pairs[$name] = [$case, $expected[$name] ?? ['missing expectation' => $name]];
		}

		return $pairs;
	}//end casesWithExpectations()

	/**
	 * The merge makes the recorded calls and answers the recorded report.
	 *
	 * @param array<string, mixed> $case     The case
	 * @param array<string, mixed> $expected The recorded calls and outcome
	 *
	 * @return void
	 */
	#[DataProvider('casesWithExpectations')]
	public function testMergeMakesTheRecordedCalls(array $case, array $expected): void {
		$this->assertSame(expected: $expected, actual: $this->runCase(case: $case));
	}//end testMergeMakesTheRecordedCalls()

	/**
	 * Every case has a recorded expectation and every expectation a case.
	 *
	 * @return void
	 */
	public function testEveryCaseIsRecorded(): void {
		$expected = require __DIR__.'/fixtures/merge-objects.php';
		$this->assertSame(expected: array_keys(self::cases()), actual: array_keys($expected));
	}//end testEveryCaseIsRecorded()

	/**
	 * Run one case and describe its calls and outcome.
	 *
	 * @param array<string, mixed> $case The case
	 *
	 * @return array<string, mixed>
	 */
	public function runCase(array $case): array {
		$this->calls = [];
		$merge       = ($case['merge'] ?? []);
		if (array_key_exists('merge', $case) === false) {
			$merge = ['target' => 'tgt'];
		} elseif (array_key_exists('target', $merge) === false && $merge !== []) {
			$merge['target'] = 'tgt';
		}

		$source  = $this->entity(uuid: 'src', overrides: ($case['source'] ?? []));
		$target  = $this->entity(uuid: 'tgt', overrides: ($case['target'] ?? []));
		$handler = new MergeHandler(
			objectEntityMapper: $this->mapper(case: $case, objects: ['src' => $source, 'tgt' => $target]),
			fileService: $this->fileService(files: ($case['files'] ?? [])),
			userSession: $this->userSession(loggedIn: ($case['user'] ?? true))
		);

		try {
			$outcome = ['report' => $handler->mergeObjects(sourceObjectId: 'src', mergeData: $merge)];
		} catch (Throwable $e) {
			$outcome = ['exception' => $e::class.': '.$e->getMessage()];
		}

		return ['calls' => $this->calls] + $outcome;
	}//end runCase()

	/**
	 * An object in register 3, schema 5, with the case's overrides.
	 *
	 * @param string               $uuid      The uuid
	 * @param array<string, mixed> $overrides Folder, relations, object, register or schema
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $uuid, array $overrides): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setName($overrides['name'] ?? null);
		$entity->setRegister($overrides['register'] ?? '3');
		$entity->setSchema($overrides['schema'] ?? '5');
		$entity->setFolder($overrides['folder'] ?? null);
		$entity->setRelations($overrides['relations'] ?? ['r0']);
		$entity->setObject($overrides['object'] ?? ['title' => $uuid]);

		return $entity;
	}//end entity()

	/**
	 * A mapper that finds the case's objects, records every call and fails where told to.
	 *
	 * @param array<string, mixed>        $case    The case
	 * @param array<string, ObjectEntity> $objects The source and target
	 *
	 * @return MagicMapper
	 */
	private function mapper(array $case, array $objects): MagicMapper {
		$referencing = [];
		foreach (($case['referencing'] ?? []) as $spec) {
			$referencing[] = $this->entity(uuid: $spec['uuid'], overrides: $spec);
		}

		$register = new Register();
		$register->setId(3);
		$schema = new Schema();
		$schema->setId(5);
		$missing = ($case['missing'] ?? []);
		$fails   = ($case['updateFails'] ?? []);

		$mapper = $this->createMock(originalClassName: MagicMapper::class);
		$mapper->method('findAcrossAllSources')->willReturnCallback(
			function (...$args) use ($objects, $referencing, $missing, $register, $schema): array {
				$this->calls[] = 'findAcrossAllSources '.json_encode($args);
				$id = $args[0];
				if (in_array($id, $missing, true) === true) {
					throw new Exception('not found: '.$id);
				}

				$found = ($objects[$id] ?? null);
				foreach ($referencing as $object) {
					if ($object->getUuid() === $id) {
						$found = $object;
					}
				}

				return ['object' => $found, 'register' => $register, 'schema' => $schema];
			}
		);
		$mapper->method('update')->willReturnCallback(
			function (...$args) use ($fails): ObjectEntity {
				$entity = $args[0];
				$this->calls[] = 'update '.json_encode($this->describe(entity: $entity, register: $args[1], schema: $args[2]));
				if (in_array($entity->getUuid(), $fails, true) === true) {
					throw new Exception('update failed: '.$entity->getUuid());
				}

				return $entity;
			}
		);
		$mapper->method('findByRelation')->willReturnCallback(
			function (...$args) use ($referencing): array {
				$this->calls[] = 'findByRelation '.json_encode($args);
				return $referencing;
			}
		);

		return $mapper;
	}//end mapper()

	/**
	 * What an update writes: the entity's identity, data, relations and deleted marker.
	 *
	 * @param ObjectEntity  $entity   The entity
	 * @param Register|null $register The register handed to update()
	 * @param Schema|null   $schema   The schema handed to update()
	 *
	 * @return array<string, mixed>
	 */
	private function describe(ObjectEntity $entity, ?Register $register, ?Schema $schema): array {
		$deleted = $entity->getDeleted();
		if (is_array($deleted) === true) {
			foreach (['deleted', 'deletedAt', 'purgeDate', 'destroyableFrom'] as $timeKey) {
				if (isset($deleted[$timeKey]) === true) {
					$deleted[$timeKey] = '<time>';
				}
			}
		}

		return [
			'uuid'      => $entity->getUuid(),
			'object'    => $entity->getObject(),
			'relations' => $entity->getRelations(),
			'deleted'   => $deleted,
			'register'  => $register?->getId(),
			'schema'    => $schema?->getId(),
		];
	}//end describe()

	/**
	 * A file service whose files behave as the case says.
	 *
	 * @param array<string, string>|string $files File name => ok, stays, copy fails or folder; or `throws`
	 *
	 * @return FileService
	 */
	private function fileService(array|string $files): FileService {
		$nodes = [];
		if (is_array($files) === true) {
			foreach ($files as $name => $behaviour) {
				$node = $this->createMock(originalClassName: Folder::class);
				if ($behaviour !== 'folder') {
					$node = $this->createMock(originalClassName: File::class);
					$node->method('getContent')->willReturn('bytes of '.$name);
				}

				$node->method('getName')->willReturn($name);
				$nodes[$name] = [$node, $behaviour];
			}
		}

		$service = $this->createMock(originalClassName: FileService::class);
		$service->method('getFiles')->willReturnCallback(
			function (ObjectEntity $object) use ($files, $nodes): array {
				$this->calls[] = 'getFiles '.$object->getUuid();
				if ($files === 'throws') {
					throw new Exception('listing failed');
				}

				return array_values(array_map(static fn (array $pair) => $pair[0], $nodes));
			}
		);
		$service->method('addFile')->willReturnCallback(
			function (...$args) use ($nodes): File {
				$this->calls[] = 'addFile '.json_encode([$args[0]->getUuid(), ...array_slice($args, 1)]);
				if ($nodes[$args[1]][1] === 'copy fails') {
					throw new Exception('copy failed');
				}

				$copy = $this->createMock(originalClassName: File::class);
				$copy->method('getName')->willReturn('copy of '.$args[1]);
				return $copy;
			}
		);
		$service->method('deleteFile')->willReturnCallback(
			function (...$args) use ($nodes): bool {
				$name = $args[0]->getName();
				$this->calls[] = 'deleteFile '.$name.' from '.$args[1]->getUuid();
				return (($nodes[$name][1] ?? 'ok') !== 'stays');
			}
		);

		return $service;
	}//end fileService()

	/**
	 * A session with user `merger`, or without a user.
	 *
	 * @param bool $loggedIn Whether a user is logged in
	 *
	 * @return IUserSession
	 */
	private function userSession(bool $loggedIn): IUserSession {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('merger');
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);
		if ($loggedIn === false) {
			$session = $this->createMock(originalClassName: IUserSession::class);
			$session->method('getUser')->willReturn(null);
		}

		return $session;
	}//end userSession()
}//end class
