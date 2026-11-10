<?php

/**
 * Golden test for FormDestinationSchemaListener::handle().
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Event\SchemaUpdatedEvent;
use OCA\OpenRegister\Listener\FormDestinationSchemaListener;
use OCA\OpenRegister\Service\Form\FormDependentsCheck;
use OCA\OpenRegister\Service\Form\FormDependentsCheckInterface;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;
use RuntimeException;
use stdClass;

/**
 * Pins every call the listener makes on the container, the check and the logger.
 *
 * @covers \OCA\OpenRegister\Listener\FormDestinationSchemaListener
 */
class FormDestinationSchemaListenerGoldenTest extends TestCase {

	/**
	 * The recorded trace.
	 *
	 * @var array<int, array>
	 */
	private array $trace = [];

	private const PROPS = ['naam' => ['type' => 'string', 'meta' => ['b' => 1, 'a' => 2]], 'bsn' => ['type' => 'string']];

	private function schema(?array $properties, ?array $required): Schema {
		$schema = new Schema();
		$schema->setId(12);
		$schema->setSlug('aanvraag');
		$schema->setProperties($properties);
		$schema->setRequired($required);
		return $schema;
	}

	/**
	 * Build a check double that records calls.
	 *
	 * @param array $setup claimed (bool), assessment (array), applyThrows (bool).
	 *
	 * @return FormDependentsCheck
	 */
	private function check(array $setup): FormDependentsCheck {
		$check = $this->createMock(FormDependentsCheck::class);
		$check->method('isClaimed')->willReturnCallback(
			function (int $schemaId) use ($setup): bool {
				$this->trace[] = ['isClaimed', $schemaId];
				return $setup['claimed'];
			}
		);
		$check->method('assess')->willReturnCallback(
			function (Schema $schema) use ($setup): array {
				$this->trace[] = ['assess', $schema->getSlug()];
				return $setup['assessment'];
			}
		);
		$check->method('apply')->willReturnCallback(
			function (array $assessment) use ($setup): array {
				$this->trace[] = ['apply', $assessment];
				if ($setup['applyThrows'] === true) {
					throw new RuntimeException('apply failed');
				}

				return [];
			}
		);
		return $check;
	}

	private function act(Event $event, mixed $resolved): array {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($resolved): mixed {
				$this->trace[] = ['get', $id];
				if ($resolved instanceof RuntimeException) {
					throw $resolved;
				}

				return $resolved;
			}
		);
		$logger = new class($this->trace) extends AbstractLogger {
			public function __construct(private array &$sink) {
			}

			public function log($level, $message, array $context = []): void {
				$this->sink[] = ['log', $level, (string) $message, $context];
			}
		};
		(new FormDestinationSchemaListener($container, $logger))->handle($event);
		return $this->trace;
	}

	private function changed(): SchemaUpdatedEvent {
		return new SchemaUpdatedEvent(
			newSchema: $this->schema(self::PROPS, ['bsn']),
			oldSchema: $this->schema(self::PROPS, [])
		);
	}

	private function stub(bool $claimed, array $assessment, bool $applyThrows=false): FormDependentsCheck {
		return $this->check(['claimed' => $claimed, 'assessment' => $assessment, 'applyThrows' => $applyThrows]);
	}

	public function testOtherEventIsIgnored(): void {
		$this->assertSame([], $this->act(new Event(), new stdClass()));
	}

	public static function unchangedProvider(): array {
		$reordered = ['bsn' => ['type' => 'string'], 'naam' => ['meta' => ['a' => 2, 'b' => 1], 'type' => 'string']];
		return [
			'reordered keys' => [$reordered, ['x'], self::PROPS, ['x']],
			'null vs empty' => [null, null, [], []],
			'both null' => [null, null, null, null],
		];
	}

	#[DataProvider('unchangedProvider')]
	public function testUnchangedDefinitionNeverResolvesTheCheck(?array $newP, ?array $newR, ?array $oldP, ?array $oldR): void {
		$event = new SchemaUpdatedEvent(newSchema: $this->schema($newP, $newR), oldSchema: $this->schema($oldP, $oldR));
		$this->assertSame([], $this->act($event, new stdClass()));
	}

	public function testListOrderOfRequiredCounts(): void {
		$event = new SchemaUpdatedEvent(
			newSchema: $this->schema(self::PROPS, ['a', 'b']),
			oldSchema: $this->schema(self::PROPS, ['b', 'a'])
		);
		$this->assertSame([['get', FormDependentsCheckInterface::class]], $this->act($event, new stdClass()));
	}

	public function testPropertiesOnlyChangeIsChecked(): void {
		$event = new SchemaUpdatedEvent(
			newSchema: $this->schema(['x' => []], ['r']),
			oldSchema: $this->schema(['y' => []], ['r'])
		);
		$check = $this->stub(true, []);
		$this->assertSame([['get', FormDependentsCheckInterface::class], ['isClaimed', 12]], $this->act($event, $check));
	}

	public function testNonCheckFromContainerStops(): void {
		$this->assertSame([['get', FormDependentsCheckInterface::class]], $this->act($this->changed(), new stdClass()));
	}

	public function testClaimedStops(): void {
		$this->assertSame(
			[['get', FormDependentsCheckInterface::class], ['isClaimed', 12]],
			$this->act($this->changed(), $this->stub(true, []))
		);
	}

	public function testNothingAffectedStops(): void {
		$this->assertSame(
			[['get', FormDependentsCheckInterface::class], ['isClaimed', 12], ['assess', 'aanvraag']],
			$this->act($this->changed(), $this->stub(false, ['refused' => true, 'schema' => 's', 'affected' => []]))
		);
	}

	public function testAffectedFormsAreApplied(): void {
		$assessment = ['refused' => false, 'schema' => 's', 'affected' => [['id' => 'f1']]];
		$this->assertSame(
			[['get', FormDependentsCheckInterface::class], ['isClaimed', 12], ['assess', 'aanvraag'], ['apply', $assessment]],
			$this->act($this->changed(), $this->stub(false, $assessment))
		);
	}

	public function testRefusalIsDowngradedAndLogged(): void {
		$assessment = ['refused' => true, 'schema' => 's', 'affected' => [['id' => 'f1'], ['id' => 'f2']]];
		$this->assertSame(
			[
				['get', FormDependentsCheckInterface::class],
				['isClaimed', 12],
				['assess', 'aanvraag'],
				[
					'log',
					'warning',
					'[FormDestinationSchemaListener] An owning app set formDestinationBreak to refuse, but this schema write '
					. 'has no response to refuse; the broken forms are unpublished instead',
					['schema' => 's', 'forms' => 2],
				],
				['apply', ['refused' => false, 'schema' => 's', 'affected' => [['id' => 'f1'], ['id' => 'f2']]]],
			],
			$this->act($this->changed(), $this->stub(false, $assessment))
		);
	}

	public function testContainerFailureIsLogged(): void {
		$this->assertSame(
			[
				['get', FormDependentsCheckInterface::class],
				[
					'log',
					'warning',
					'[FormDestinationSchemaListener] The published forms of a changed schema could not be re-checked',
					['schema' => 12, 'error' => 'no container'],
				],
			],
			$this->act($this->changed(), new RuntimeException('no container'))
		);
	}

	public function testApplyFailureIsLogged(): void {
		$assessment = ['refused' => false, 'schema' => 's', 'affected' => [['id' => 'f1']]];
		$trace = $this->act($this->changed(), $this->stub(false, $assessment, true));
		$this->assertSame(
			[
				'log',
				'warning',
				'[FormDestinationSchemaListener] The published forms of a changed schema could not be re-checked',
				['schema' => 12, 'error' => 'apply failed'],
			],
			$trace[4]
		);
		$this->assertCount(5, $trace);
	}
}
