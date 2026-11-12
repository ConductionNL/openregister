<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject;

use OCA\OpenRegister\Service\Object\SaveObject\ComputedPass;
use Error;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\SaveObject\ComputedFieldHandler;
use OCA\OpenRegister\Service\Vocabulary\CodedValueGuard;
use OCA\OpenRegister\Twig\MappingExtension;
use OCA\OpenRegister\Twig\MappingRuntimeLoader;
use OCA\OpenRegister\Twig\MappingTwigInstallerInterface;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Twig\Environment;

/**
 * Pins how ComputedFieldHandler resolves `_ref` cross-references and rolls up
 * concept scores: which properties count as references, that each referenced
 * object is read without RBAC and tenancy (the lookup only feeds the computed
 * value), what a missing, blank or failing reference yields, and what is
 * logged. Against fixtures/computed-field-references-golden.json;
 * UPDATE_GOLDEN=1 rewrites it.
 *
 * @covers \OCA\OpenRegister\Service\Object\SaveObject\ComputedFieldHandler
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 */
class ComputedFieldReferencesGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/computed-field-references-golden.json';

	public function testReferencesAndScoresMatchGolden(): void {
		$actual = [];
		foreach ($this->cases() as $name => [$properties, $data, $scores]) {
			$calls = [];
			$logs = [];
			$schema = new Schema();
			$schema->setId(7);
			$schema->setProperties($properties);
			$handler = $this->handler(calls: $calls, logs: $logs, scores: $scores);
			$actual[$name] = [
				'save' => $handler->evaluateComputedFields($data, new ComputedPass(schema: $schema)),
				'read' => $handler->evaluateComputedFields($data, new ComputedPass(schema: $schema, evaluateOn: 'read')),
				'calls' => $calls,
				'logs' => $logs,
			];
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$this->assertSame(json_decode((string)file_get_contents(self::FIXTURE), true), json_decode((string)json_encode($actual), true));
	}//end testReferencesAndScoresMatchGolden()

	private function cases(): array {
		$refs = [
			'owner' => ['$ref' => '#/components/schemas/Person'],
			'link' => ['type' => 'string', 'format' => 'uuid'],
			'plainUuid' => ['type' => 'string', 'format' => 'date'],
			'gone' => ['$ref' => 'x'],
			'blank' => ['$ref' => 'x'],
			'number' => ['$ref' => 'x'],
			'absent' => ['$ref' => 'x'],
			'nullObject' => ['$ref' => 'x'],
			'scalarDef' => 'scalar',
			7 => ['$ref' => 'x'],
		];
		$expressions = [
			'out' => ['computed' => ['expression' => "{{ _ref.owner.name }}|{{ _ref.link.name }}|{{ _ref.gone|length }}|{{ _ref.blank|length }}"]],
			'refKeys' => ['computed' => ['expression' => "{{ _ref|keys|join(',') }}"]],
			'hasRef' => ['computed' => ['expression' => "{{ _ref is defined ? 'y' : 'n' }}", 'evaluateOn' => 'read']],
		];
		$data = [
			'owner' => 'uuid-owner', 'link' => 'uuid-link', 'plainUuid' => 'uuid-owner', 'gone' => 'uuid-gone', 'blank' => '  ',
			'number' => 5, 'nullObject' => 'uuid-null', 7 => 'uuid-owner',
		];

		return [
			'references' => [$refs + $expressions, $data, null],
			'noReferenceProperties' => [['a' => ['type' => 'string']] + $expressions, ['a' => 'x'], null],
			'failingLookup' => [['owner' => ['$ref' => 'x']] + $expressions, ['owner' => 'uuid-error'], null],
			'runtimeFailure' => [['owner' => ['$ref' => 'x']] + $expressions, ['owner' => 'uuid-runtime'], null],
			'scores' => [['a' => ['type' => 'string']], ['a' => 'x', 'score' => 1], ['score' => 3.5, 'extra' => 1.0]],
			'scoresFail' => [['a' => ['type' => 'string']], ['a' => 'x'], 'fail'],
		];
	}//end cases()

	private function handler(array &$calls, array &$logs, mixed $scores): ComputedFieldHandler {
		$mapper = $this->createMock(MagicMapperInterface::class);
		$mapper->method('find')->willReturnCallback(function (...$args) use (&$calls) {
			$calls[] = $args;
			$identifier = $args['identifier'] ?? $args[0];
			if ($identifier === 'uuid-gone') {
				throw new DoesNotExistException('gone');
			}

			if ($identifier === 'uuid-error') {
				throw new Error('broken');
			}

			if ($identifier === 'uuid-runtime') {
				throw new RuntimeException('db down');
			}

			$entity = new ObjectEntity();
			$entity->setObject(['uuid-owner' => ['name' => 'Owner'], 'uuid-link' => ['name' => 'Link'], 'uuid-null' => []][$identifier]);
			return $entity;
		});

		$logger = new class($logs) extends AbstractLogger {
			private array $logs;

			public function __construct(array &$logs) {
				$this->logs = &$logs;
			}

			public function log($level, $message, array $context = []): void {
				unset($context['file'], $context['line']);
				$this->logs[] = [$level, (string)$message, $context];
			}
		};

		$guard = null;
		if ($scores !== null) {
			$guard = $this->createMock(CodedValueGuard::class);
			if ($scores === 'fail') {
				$guard->method('rolledUpScores')->willThrowException(new RuntimeException('vocabulary down'));
			} else {
				$guard->method('rolledUpScores')->willReturn($scores);
			}
		}

		return new ComputedFieldHandler(
			$mapper,
			$this->mappingTwig(),
			$logger,
			$guard
		);
	}//end handler()

	/**
	 * The mapping extension and a stand-in runtime loader, installed as before.
	 *
	 * @return MappingTwigInstallerInterface The installer.
	 */
	private function mappingTwig(): MappingTwigInstallerInterface {
		$installer = $this->createMock(MappingTwigInstallerInterface::class);
		$loader    = $this->createMock(MappingRuntimeLoader::class);
		$installer->method('installOn')->willReturnCallback(
			static function (Environment $twig) use ($loader): void {
				$twig->addExtension(new MappingExtension());
				$twig->addRuntimeLoader($loader);
			}
		);

		return $installer;
	}//end mappingTwig()
}//end class
