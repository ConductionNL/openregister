<?php

/**
 * TaggingHandler golden test.
 *
 * Records every public method of TaggingHandler against a scripted tag store
 * and pins the replies, the store calls and the log lines, so a refactor of
 * the handler can be shown to keep its behaviour.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\File
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/file-actions/spec.md
 */

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\File;

use OCA\OpenRegister\Files\Service\File\TaggingHandler;
use OCP\IUser;
use OCP\IUserSession;
use OCP\SystemTag\ISystemTag;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use OCP\SystemTag\TagAlreadyExistsException;
use OCP\SystemTag\TagCreationForbiddenException;
use OCP\SystemTag\TagNotFoundException;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Throwable;

/**
 * @covers \OCA\OpenRegister\Files\Service\File\TaggingHandler
 */
class TaggingHandlerGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/tagging-handler-golden.json';

	/**
	 * Calls made against the scripted store, in order.
	 *
	 * @var array<int, string>
	 */
	private array $calls = [];

	/**
	 * Tags known to the scripted store, by id.
	 *
	 * @var array<string, array{name: string, visible: bool, assignable: bool}>
	 */
	private array $store = [];

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function cases(): array {
		$out = [];
		foreach (array_keys(self::scenarios()) as $name) {
			$out[$name] = [$name];
		}

		return $out;
	}//end cases()

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private static function scenarios(): array {
		$store = [
			'1' => ['name' => 'alpha', 'visible' => true, 'assignable' => true],
			'2' => ['name' => 'beta', 'visible' => true, 'assignable' => true],
			'3' => ['name' => 'object:hidden', 'visible' => false, 'assignable' => false],
			'4' => ['name' => 'object:shown', 'visible' => true, 'assignable' => false],
			'5' => ['name' => 'object:assignable', 'visible' => false, 'assignable' => true],
			'6' => ['name' => 'alphabet', 'visible' => true, 'assignable' => true],
		];
		$attach = static fn (array $tags, array $extra = []): array => array_merge(
			['store' => $store, 'call' => ['attachTagsToFile', '12', $tags]],
			$extra
		);
		return [
			'attach-nothing' => $attach([]),
			'attach-nothing-removes-old' => $attach([], ['objIds' => ['12' => ['1', '2']]]),
			'attach-old-entry-empty' => $attach(['alpha'], ['objIds' => ['12' => []]]),
			'attach-old-other-file' => $attach(['alpha'], ['objIds' => ['13' => ['2']]]),
			'attach-by-id' => $attach(['2'], ['objIds' => ['12' => ['1']]]),
			'attach-by-id-same' => $attach(['1', 'beta'], ['objIds' => ['12' => ['1', '2']]]),
			'attach-id-empty-result' => $attach(['99'], ['byIds' => 'empty']),
			'attach-id-not-found' => $attach(['99'], ['byIds' => 'notfound']),
			'attach-id-not-found-create-fails' => $attach(['99', 'alpha'], ['byIds' => 'notfound', 'create' => 'throw']),
			'attach-id-lookup-throws' => $attach(['99'], ['byIds' => 'throw']),
			'attach-id-lookup-throws-create-fails' => $attach(['99', 'alpha'], ['byIds' => 'throw', 'create' => 'throw']),
			'attach-by-name-prefix-match' => $attach(['alpha', 'alph']),
			'attach-new-user-tag' => $attach(['gamma']),
			'attach-new-object-tag' => $attach(['object:new']),
			'attach-race-found' => $attach(['delta'], ['create' => 'exists']),
			'attach-race-missing' => $attach(['delta', 'beta'], ['create' => 'exists-missing']),
			'attach-race-missing-twice' => $attach(['delta'], ['create' => 'exists-missing', 'objIds' => ['12' => ['1']]]),
			'attach-create-throws' => $attach(['delta', 'alpha'], ['create' => 'throw', 'objIds' => ['12' => ['2']]]),
			'attach-internal-hidden' => $attach(['object:hidden']),
			'attach-internal-shown' => $attach(['object:shown']),
			'attach-internal-assignable' => $attach(['object:assignable']),
			'attach-internal-update-throws' => $attach(['object:shown'], ['update' => 'throw']),
			'attach-mapper-throws' => $attach(['alpha'], ['objIds' => 'throw']),
			'attach-race-internal' => $attach(['object:race'], ['create' => 'exists', 'raceVisible' => true]),
			'file-tags' => ['store' => $store, 'objIds' => ['12' => ['2', '1']], 'call' => ['getFileTags', '12']],
			'file-tags-none' => ['store' => $store, 'call' => ['getFileTags', '12']],
			'file-tags-empty' => ['store' => $store, 'objIds' => ['12' => []], 'call' => ['getFileTags', '12']],
			'file-tags-throws' => ['store' => $store, 'objIds' => 'throw', 'call' => ['getFileTags', '12']],
			'file-tags-ids-throw' => ['store' => $store, 'objIds' => ['12' => ['1']], 'byIds' => 'throw', 'call' => ['getFileTags', '12']],
			'object-tags' => ['store' => $store, 'objIds' => ['u-1' => ['2', '1', '6']], 'call' => ['getObjectTags', 'u-1']],
			'object-tags-none' => ['store' => $store, 'objIds' => ['u-2' => ['1']], 'call' => ['getObjectTags', 'u-1']],
			'object-tags-empty' => ['store' => $store, 'objIds' => ['u-1' => []], 'call' => ['getObjectTags', 'u-1']],
			'object-tags-throws' => ['store' => $store, 'objIds' => 'throw', 'call' => ['getObjectTags', 'u-1']],
			'object-tag-generate' => ['store' => $store, 'call' => ['generateObjectTag', 'u-9']],
			'add-object-tag' => ['store' => $store, 'call' => ['addObjectTag', 'u-1', 'beta']],
			'add-object-tag-new' => ['store' => $store, 'call' => ['addObjectTag', 'u-1', 'gamma']],
			'add-object-tag-allowed' => ['store' => $store, 'user' => 'allowed', 'call' => ['addObjectTag', 'u-1', 'beta']],
			'add-object-tag-refused' => ['store' => $store, 'user' => 'refused', 'call' => ['addObjectTag', 'u-1', 'beta']],
			'add-object-tag-forbidden' => ['store' => $store, 'create' => 'forbidden', 'call' => ['addObjectTag', 'u-1', 'gamma']],
			'remove-object-tag' => ['store' => $store, 'call' => ['removeObjectTag', 'u-1', 'alpha']],
			'remove-object-tag-refused' => ['store' => $store, 'user' => 'refused', 'call' => ['removeObjectTag', 'u-1', 'alpha']],
			'remove-object-tag-missing' => ['store' => $store, 'call' => ['removeObjectTag', 'u-1', 'alph']],
			'all-tags' => ['store' => $store, 'call' => ['getAllTags']],
			'all-tags-empty' => ['store' => [], 'call' => ['getAllTags']],
			'all-tags-throws' => ['store' => $store, 'all' => 'throw', 'call' => ['getAllTags']],
		];
	}//end scenarios()

	/**
	 * @dataProvider cases
	 */
	public function testTaggingHandlerMatchesGolden(string $name): void {
		$recorded = $this->record(scenario: self::scenarios()[$name]);

		$all = [];
		if (file_exists(self::FIXTURE) === true) {
			$all = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('OR_GOLDEN_WRITE') === '1') {
			$all[$name] = $recorded;
			ksort($all);
			file_put_contents(self::FIXTURE, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertArrayHasKey($name, $all);
		$this->assertNotEmpty($all[$name]['calls'] ?? null);
		$this->assertSame($all[$name], $recorded);
	}//end testTaggingHandlerMatchesGolden()

	/**
	 * @param array<string, mixed> $scenario
	 *
	 * @return array<string, mixed>
	 */
	private function record(array $scenario): array {
		$this->calls = [];
		$this->store = $scenario['store'];
		$lines = [];
		$logger = new class($lines) extends AbstractLogger {
			/**
			 * @param array<int, string> $lines
			 */
			public function __construct(private array &$lines) {
			}

			public function log($level, string|\Stringable $message, array $context = []): void {
				$keys = array_keys($context);
				sort($keys);
				$this->lines[] = $level . ': ' . $message . ' [' . implode(',', $keys) . ']';
			}
		};

		$handler = new TaggingHandler(
			$this->manager(scenario: $scenario),
			$this->mapper(scenario: $scenario),
			$logger,
			$this->session(scenario: $scenario)
		);

		$call = $scenario['call'];
		$method = array_shift($call);
		try {
			$result = ['returned' => $handler->$method(...$call)];
		} catch (Throwable $e) {
			$result = ['threw' => get_class($e) . ': ' . $e->getMessage()];
		}

		$this->calls[] = 'end';
		return ['result' => $result, 'calls' => $this->calls, 'log' => $lines];
	}//end record()

	/**
	 * @param array<string, mixed> $scenario
	 */
	private function manager(array $scenario): ISystemTagManager {
		$manager = $this->createMock(ISystemTagManager::class);
		$manager->method('getAllTags')->willReturnCallback(
			function ($visibilityFilter = null, $pattern = null) use ($scenario): array {
				$this->calls[] = 'getAllTags(' . var_export($visibilityFilter, true) . ', ' . var_export($pattern, true) . ')';
				if (($scenario['all'] ?? null) === 'throw') {
					throw new RuntimeException('store down');
				}

				return $this->tagsMatching(pattern: $pattern);
			}
		);
		$manager->method('getTagsByIds')->willReturnCallback(
			function ($ids) use ($scenario): array {
				$this->calls[] = 'getTagsByIds(' . json_encode($ids) . ')';
				return $this->tagsById(ids: $ids, mode: ($scenario['byIds'] ?? 'store'));
			}
		);
		$manager->method('createTag')->willReturnCallback(
			function (string $tagName, bool $userVisible, bool $userAssignable) use ($scenario): ISystemTag {
				$this->calls[] = 'createTag(' . $tagName . ', ' . var_export($userVisible, true) . ', ' . var_export($userAssignable, true) . ')';
				return $this->created(name: $tagName, flags: [$userVisible, $userAssignable], scenario: $scenario);
			}
		);
		$manager->method('updateTag')->willReturnCallback(
			function (string $tagId, string $newName, bool $userVisible, bool $userAssignable, ?string $color) use ($scenario): void {
				$this->calls[] = 'updateTag(' . $tagId . ', ' . $newName . ', ' . var_export($userVisible, true) . ', ' . var_export($userAssignable, true) . ', ' . var_export($color, true) . ')';
				if (($scenario['update'] ?? null) === 'throw') {
					throw new RuntimeException('update refused');
				}
			}
		);
		$manager->method('canUserAssignTag')->willReturnCallback(
			function (ISystemTag $tag) use ($scenario): bool {
				$this->calls[] = 'canUserAssignTag(' . $tag->getId() . ')';
				return ($scenario['user'] ?? null) === 'allowed';
			}
		);

		return $manager;
	}//end manager()

	/**
	 * @param array<string, mixed> $scenario
	 */
	private function mapper(array $scenario): ISystemTagObjectMapper {
		$mapper = $this->createMock(ISystemTagObjectMapper::class);
		$mapper->method('getTagIdsForObjects')->willReturnCallback(
			function ($objIds, string $objectType) use ($scenario): array {
				$this->calls[] = 'getTagIdsForObjects(' . json_encode($objIds) . ', ' . $objectType . ')';
				$known = ($scenario['objIds'] ?? []);
				if ($known === 'throw') {
					throw new RuntimeException('mapper down');
				}

				return $known;
			}
		);
		$mapper->method('assignTags')->willReturnCallback(
			function (string $objId, string $objectType, $tagIds): void {
				$this->calls[] = 'assignTags(' . $objId . ', ' . $objectType . ', ' . json_encode($tagIds) . ')';
			}
		);
		$mapper->method('unassignTags')->willReturnCallback(
			function (string $objId, string $objectType, $tagIds): void {
				$this->calls[] = 'unassignTags(' . $objId . ', ' . $objectType . ', ' . json_encode($tagIds) . ')';
			}
		);

		return $mapper;
	}//end mapper()

	/**
	 * @param array<string, mixed> $scenario
	 */
	private function session(array $scenario): IUserSession {
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if (isset($scenario['user']) === true) {
			$user = $this->createMock(IUser::class);
		}

		$session->method('getUser')->willReturn($user);
		return $session;
	}//end session()

	/**
	 * @return array<int, ISystemTag>
	 */
	private function tagsMatching(mixed $pattern): array {
		$out = [];
		foreach (array_keys($this->store) as $id) {
			if ($pattern === null || str_contains($this->store[$id]['name'], (string)$pattern) === true) {
				$out[] = $this->tag(id: (string)$id);
			}
		}

		return $out;
	}//end tagsMatching()

	/**
	 * @param array<int, mixed> $ids
	 *
	 * @return array<string, ISystemTag>
	 */
	private function tagsById(array $ids, string $mode): array {
		if ($mode === 'notfound') {
			throw new TagNotFoundException('no such tag');
		}

		if ($mode === 'throw') {
			throw new RuntimeException('lookup broke');
		}

		$out = [];
		foreach ($ids as $id) {
			if ($mode === 'store' && isset($this->store[(string)$id]) === true) {
				$out[(string)$id] = $this->tag(id: (string)$id);
			}
		}

		return $out;
	}//end tagsById()

	/**
	 * @param array{0: bool, 1: bool} $flags
	 * @param array<string, mixed>    $scenario
	 */
	private function created(string $name, array $flags, array $scenario): ISystemTag {
		$mode = ($scenario['create'] ?? 'ok');
		if ($mode === 'throw') {
			throw new RuntimeException('create broke');
		}

		if ($mode === 'forbidden') {
			throw new TagCreationForbiddenException('not allowed');
		}

		$id = 'n' . (count($this->store) + 1);
		if ($mode !== 'exists-missing') {
			$visible = ($scenario['raceVisible'] ?? $flags[0]);
			$this->store[$id] = ['name' => $name, 'visible' => $visible, 'assignable' => $flags[1]];
		}

		if ($mode === 'exists' || $mode === 'exists-missing') {
			throw new TagAlreadyExistsException('already there');
		}

		return $this->tag(id: $id);
	}//end created()

	private function tag(string $id): ISystemTag {
		$data = $this->store[$id];
		$tag = $this->createMock(ISystemTag::class);
		$tag->method('getId')->willReturn($id);
		$tag->method('getName')->willReturn($data['name']);
		$tag->method('isUserVisible')->willReturnCallback(
			function () use ($id, $data): bool {
				$this->calls[] = 'isUserVisible(' . $id . ')';
				return $data['visible'];
			}
		);
		$tag->method('isUserAssignable')->willReturnCallback(
			function () use ($id, $data): bool {
				$this->calls[] = 'isUserAssignable(' . $id . ')';
				return $data['assignable'];
			}
		);
		$tag->method('getColor')->willReturn('#00ff00');

		return $tag;
	}//end tag()
}//end class
