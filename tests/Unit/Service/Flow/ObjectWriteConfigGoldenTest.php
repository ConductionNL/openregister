<?php

/**
 * Golden over what an object-write step accepts, refuses and renders.
 *
 * Every combination of the configuration grids below goes through
 * `validateConfig()`, every template value through `execute()`, and each
 * outcome, verdict or message, is folded into one digest per grid. The digests
 * and the refusal counts in the fixture were recorded on the code before the
 * step's validation and templating moved out of ObjectWriteNode.
 *
 * Set OBJECT_WRITE_GOLDEN_RECORD=1 to rewrite the fixture; a recording run
 * compares nothing.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/or-flow-object-write-node/specs/flow-object-write-node/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowNodeCall;
use OCA\OpenRegister\Service\Flow\FlowRunServiceInterface;
use OCA\OpenRegister\Service\Flow\Nodes\FlowNodePresentation;
use OCA\OpenRegister\Service\Flow\Nodes\ObjectWriteNode;
use OCA\OpenRegister\Service\Flow\Nodes\ObjectWritePlatform;
use OCA\OpenRegister\Service\Flow\Nodes\ObjectWriteStores;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Throwable;
use OCA\OpenRegister\Service\Object\ObjectSaveOptions;

/**
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\ObjectWriteNode
 * @uses   \OCA\OpenRegister\Service\Flow\Nodes\ObjectWriteMatcher
 * @uses   \OCA\OpenRegister\Service\Flow\Nodes\ObjectWriteMatchFilters
 * @uses   \OCA\OpenRegister\Service\Flow\Nodes\ObjectWriteBulk
 * @uses   \OCA\OpenRegister\Service\Flow\Nodes\ObjectWriteBulkPage
 * @uses   \OCA\OpenRegister\Service\Flow\Nodes\ObjectWritePayload
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\ObjectWriteConfigForm
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\ObjectWriteConfigValidator
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\ObjectWriteOptions
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\ObjectWriteTemplate
 * @uses   \OCA\OpenRegister\Service\Flow\FlowItems
 * @uses   \OCA\OpenRegister\Service\Flow\Nodes\ObjectWriteItem
 * @uses   \OCA\OpenRegister\Service\Flow\Nodes\ObjectWritePlan
 */
final class ObjectWriteConfigGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/object-write-config-golden.json';

	private const ABSENT = '__absent__';

	/**
	 * The payloads the fake store was asked to save.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * The golden grids must match the recording.
	 *
	 * @return void
	 */
	public function testGridsMatchTheRecording(): void {
		$node = $this->node();
		$actual = [
			'operations' => $this->outcomes(node: $node, configs: $this->operationGrid()),
			'targets' => $this->outcomes(node: $node, configs: $this->targetGrid()),
			'form' => $this->digest(lines: [(string)json_encode([$node->configForm(), $node->configKeys()])], total: 1),
			'templates' => $this->templateOutcomes(node: $node),
			'paths' => $this->pathOutcomes(node: $node),
		];

		if (getenv('OBJECT_WRITE_GOLDEN_RECORD') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "\n");
		}

		self::assertGreaterThan(50000, $actual['operations']['total']);
		self::assertGreaterThan(25, count($actual['operations']['outcomes']));
		self::assertSame(json_decode((string)file_get_contents(self::FIXTURE), true), $actual);

	}//end testGridsMatchTheRecording()

	/**
	 * Run every configuration through validateConfig().
	 *
	 * @param ObjectWriteNode $node    The node.
	 * @param iterable        $configs The configurations.
	 *
	 * @return array{total: int, digest: string, outcomes: array<string, int>}
	 */
	private function outcomes(ObjectWriteNode $node, iterable $configs): array {
		$lines = [];
		$total = 0;
		foreach ($configs as $config) {
			$total++;
			try {
				$node->validateConfig(config: $config);
				$lines[] = 'accepted';
			} catch (Throwable $e) {
				$lines[] = get_class($e) . ': ' . $e->getMessage();
			}
		}

		return $this->digest(lines: $lines, total: $total);

	}//end outcomes()

	/**
	 * Fold outcome lines into a digest and a count per distinct line.
	 *
	 * @param array<int, string> $lines The outcomes, in order.
	 * @param int                $total The number of cases.
	 *
	 * @return array{total: int, digest: string, outcomes: array<string, int>}
	 */
	private function digest(array $lines, int $total): array {
		$outcomes = array_count_values($lines);
		ksort($outcomes);
		if ($total === 1) {
			$outcomes = [];
		}

		return ['total' => $total, 'digest' => hash('sha256', implode("\n", $lines)), 'outcomes' => $outcomes];

	}//end digest()

	/**
	 * Operation, match, payload and guard keys, crossed.
	 *
	 * @return iterable<int, array<string, mixed>>
	 */
	private function operationGrid(): iterable {
		return $this->cross(
			base: ['register' => 'reg', 'schema' => 'sch'],
			axes: [
				'operation' => [self::ABSENT, 7, 'create', ' Update ', 'upsert', 'delete', 'bogus'],
				'match' => [
					self::ABSENT,
					'text',
					[],
					['status' => 'a'],
					[['property' => 'uuid', 'value' => '{{id}}']],
					['text'],
					[['value' => 1]],
					[['property' => ' a ']],
					[['property' => '@self.uuid', 'value' => 1]],
					[['property' => 'uuid', 'value' => 1], ['property' => 'b', 'value' => 2]],
				],
				'fields' => [self::ABSENT, [], ['a' => 1]],
				'payloadFrom' => [self::ABSENT, 'p'],
				'replace' => [self::ABSENT, true, null],
				'permanent' => [self::ABSENT, true, 'true', null],
				'confirmDelete' => [self::ABSENT, true, 'true'],
				'bulk' => [self::ABSENT, false, 'true', true, null],
				'onConflict' => [self::ABSENT, 'fail'],
			]
		);

	}//end operationGrid()

	/**
	 * Register, schema and option keys, crossed.
	 *
	 * @return iterable<int, array<string, mixed>>
	 */
	private function targetGrid(): iterable {
		return $this->cross(
			base: ['operation' => 'create', 'fields' => ['a' => 1]],
			axes: [
				'register' => [self::ABSENT, '', ' reg ', 5, 1.5],
				'schema' => [self::ABSENT, '  ', 'sch', 6, true],
				'maxWrites' => [self::ABSENT, 0, '3', 3, null],
				'onMissing' => [self::ABSENT, 'omit', 'fail', 'bogus', 5, null],
				'onNoMatch' => [self::ABSENT, 'skip', 'error', 'bogus', 5, null],
				'onConflict' => [self::ABSENT, 'bogus', 5],
			]
		);

	}//end targetGrid()

	/**
	 * The cartesian product of the axes on top of a base configuration.
	 *
	 * @param array<string, mixed>            $base The fixed keys.
	 * @param array<string, array<int, mixed>> $axes The varied keys.
	 *
	 * @return iterable<int, array<string, mixed>>
	 */
	private function cross(array $base, array $axes): iterable {
		if ($axes === []) {
			yield $base;
			return;
		}

		$key = (string)array_key_first($axes);
		$values = $axes[$key];
		unset($axes[$key]);
		foreach ($values as $value) {
			$next = $base;
			if ($value !== self::ABSENT) {
				$next[$key] = $value;
			}

			yield from $this->cross(base: $next, axes: $axes);
		}

	}//end cross()

	/**
	 * Render every template value under both missing-field policies.
	 *
	 * @param ObjectWriteNode $node The node.
	 *
	 * @return array{total: int, digest: string, outcomes: array<string, int>}
	 */
	private function templateOutcomes(ObjectWriteNode $node): array {
		$values = [
			5, null, true, [1], 'plain', '{{a}}', '{{ a.b }}', 'x {{a.b}} y', '{{missing}}', 'x {{missing}}',
			'{{n}}', '{{e}}', '{{f}}', '{{i}}', '{{d}}', 'b{{f}}', 'b{{t}}', 'j{{arr}}', '{{a.b}}{{i}}', '{{}}',
			'{{ }}', '{{ .a }}', '{{a.}}', '{{{a.b}}}', '{{arr.0}}', '{{s}}', 'x{{s}}', '', ' ',
		];
		$lines = [];
		foreach (['omit', 'fail'] as $policy) {
			foreach ($values as $value) {
				$lines[] = $this->written(node: $node, config: ['fields' => ['k' => $value, 'keep' => 1], 'onMissing' => $policy]);
			}
		}

		return $this->digest(lines: $lines, total: count($lines));

	}//end templateOutcomes()

	/**
	 * Resolve `payloadFrom` and `skipWhen` against the same record.
	 *
	 * @param ObjectWriteNode $node The node.
	 *
	 * @return array{total: int, digest: string, outcomes: array<string, int>}
	 */
	private function pathOutcomes(ObjectWriteNode $node): array {
		$paths = ['a', ' a ', 'a.b', 'arr', 'arr.0', 'n', 'e', 'f', 't', 's', 'i', 'skip', 'skipUpper', 'no', '.', 'a.', ' '];
		$lines = [];
		foreach ($paths as $path) {
			$lines[] = $this->written(node: $node, config: ['payloadFrom' => $path]);
			$lines[] = $this->written(node: $node, config: ['fields' => ['k' => 1], 'skipWhen' => $path]);
		}

		return $this->digest(lines: $lines, total: count($lines));

	}//end pathOutcomes()

	/**
	 * What one create step sent to the store and emitted, or the failure.
	 *
	 * @param ObjectWriteNode      $node   The node.
	 * @param array<string, mixed> $config The step keys beyond the target.
	 *
	 * @return string The outcome, as JSON.
	 */
	private function written(ObjectWriteNode $node, array $config): string {
		$this->saved = [];
		$record = [
			'a' => ['b' => 'deep'], 'arr' => [1, 2], 'n' => null, 'e' => [], 'f' => false, 't' => true, 'i' => 3, 'd' => 1.5,
			's' => '', 'skip' => ' SKIP ', 'skipUpper' => 'Skip', 'no' => 'false', '' => 'blank',
		];

		try {
			$out = $node->execute(new FlowNodeCall(
				items: [FlowItems::item(json: $record)],
				config: array_merge(['register' => '1', 'schema' => '2', 'operation' => 'create'], $config),
				context: [FlowRunServiceInterface::RUN_AS_CONTEXT_KEY => 'alice']
			));
		} catch (Throwable $e) {
			return get_class($e) . ': ' . $e->getMessage();
		}

		return (string)json_encode(['saved' => $this->saved, 'out' => $out]);

	}//end written()

	/**
	 * A node over fakes that accept everything.
	 *
	 * @return ObjectWriteNode The node.
	 */
	private function node(): ObjectWriteNode {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters)
		);

		$user = $this->createMock(IUser::class);
		$user->method('isEnabled')->willReturn(true);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($user);

		$register = new Register();
		$register->setId(1);
		$schema = new Schema();
		$schema->setId(2);
		$registers = $this->createMock(RegisterMapperInterface::class);
		$registers->method('find')->willReturn($register);
		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('find')->willReturn($schema);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueInt')->willReturnArgument(2);

		return new ObjectWriteNode(
			new ObjectWriteStores($this->store(), $registers, $schemas),
			new ObjectWritePlatform($users, $config),
			new FlowNodePresentation($l10n, $this->createMock(IURLGenerator::class))
		);

	}//end node()

	/**
	 * A store that records every save and runs every callable.
	 *
	 * @return ObjectOperationsInterface The fake.
	 */
	private function store(): ObjectOperationsInterface {
		$store = $this->createMock(ObjectOperationsInterface::class);
		$store->method('runAs')->willReturnCallback(static fn (IUser $user, callable $work): mixed => $work());
		$store->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ObjectSaveOptions $options = new ObjectSaveOptions(), bool $_rbac = true, bool $_multitenancy = true): ObjectEntity {
				$this->saved[] = (array)$object;
				$entity = new ObjectEntity();
				$entity->setUuid('fixed-uuid');
				$entity->setObject((array)$object);
				return $entity;
			}
		);

		return $store;

	}//end store()
}//end class
