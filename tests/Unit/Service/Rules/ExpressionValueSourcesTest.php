<?php

/**
 * Conditions read integriq's allowlisted value sources, and fail closed (#4169)
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rules
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rules;

use OCA\OpenRegister\Service\Calculation\CalculationEvaluator;
use OCA\OpenRegister\Service\Calculation\EvaluationException;
use OCA\OpenRegister\Service\Lifecycle\LifecycleConditionEvaluator;
use OCA\OpenRegister\Service\Rules\ConditionDialect;
use OCA\OpenRegister\Service\Rules\ExpressionValueSources;
use OCA\OpenRegister\Service\Search\PlaceholderResolver;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The value-source node through the real dialect and the real lifecycle evaluator.
 *
 * Integriq is not a dependency of this repository, so its registry is played by
 * an object with the registry's two public methods; the container lookup by
 * class name is the real one.
 */
class ExpressionValueSourcesTest extends TestCase {

	/**
	 * Log lines written during the test.
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $logLines = [];

	/**
	 * A registry double that resolves the listed references and refuses the rest.
	 *
	 * @param array<string, mixed> $listed Reference to value.
	 *
	 * @return object
	 */
	private function registry(array $listed): object {
		return new class($listed) {
			/**
			 * @param array<string, mixed> $listed Reference to value.
			 */
			public function __construct(private array $listed) {
			}

			/**
			 * @param string               $reference The reference.
			 * @param array<string, mixed> $context   Unused.
			 *
			 * @return mixed
			 */
			public function resolve(string $reference, array $context = []): mixed {
				if (array_key_exists($reference, $this->listed) === false) {
					throw new RuntimeException('Refused: ' . $reference);
				}

				return $this->listed[$reference];
			}

			/**
			 * @param string $reference The reference.
			 *
			 * @return bool
			 */
			public function isSecret(string $reference): bool {
				return str_starts_with($reference, 'env:');
			}
		};
	}//end registry()

	/**
	 * The dialect over the real AST evaluator, with the given registry or none.
	 *
	 * @param object|null $registry The registry, or null when integriq is absent.
	 *
	 * @return ConditionDialect
	 */
	private function dialect(?object $registry): ConditionDialect {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturnCallback(
			static fn (string $id): bool => $registry !== null && $id === ExpressionValueSources::REGISTRY_CLASS
		);
		$container->method('get')->willReturnCallback(
			static fn (string $id): ?object => $registry
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->logLines[] = [$message, $context];
			}
		);

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);

		return new ConditionDialect(
			ast: new CalculationEvaluator(new PlaceholderResolver($userSession)),
			sources: new ExpressionValueSources(container: $container, logger: $logger)
		);
	}//end dialect()

	/**
	 * The lifecycle evaluator a transition condition goes through.
	 *
	 * @param ConditionDialect $dialect The dialect.
	 *
	 * @return LifecycleConditionEvaluator
	 */
	private function lifecycle(ConditionDialect $dialect): LifecycleConditionEvaluator {
		return new LifecycleConditionEvaluator(
			$this->createMock(IUserSession::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(IL10N::class),
			$this->createMock(LoggerInterface::class),
			$dialect
		);
	}//end lifecycle()

	/**
	 * Evaluate a transition condition for an object with the given region.
	 *
	 * @param ConditionDialect $dialect   The dialect.
	 * @param mixed            $condition The condition.
	 * @param string           $region    The object's region.
	 *
	 * @return bool
	 */
	private function transitionHolds(ConditionDialect $dialect, mixed $condition, string $region): bool {
		return $this->lifecycle(dialect: $dialect)->holds(
			rule: $condition,
			newData: ['region' => $region],
			oldData: ['region' => $region],
			action: 'accept',
			from: 'new',
			to: 'accepted',
			schemaSlug: 'intake',
			field: 'status'
		);
	}//end transitionHolds()

	/**
	 * An AST transition condition compares with an allowlisted variable.
	 *
	 * @return void
	 */
	public function testAnAstTransitionConditionReadsAnAllowlistedVariable(): void {
		$dialect   = $this->dialect(registry: $this->registry(listed: ['env:INTAKE_REGION' => 'north']));
		$condition = ['eq' => [['prop' => 'object.region'], ['source' => 'env:INTAKE_REGION']]];

		self::assertTrue($this->transitionHolds(dialect: $dialect, condition: $condition, region: 'north'));
		self::assertFalse($this->transitionHolds(dialect: $dialect, condition: $condition, region: 'south'));
	}//end testAnAstTransitionConditionReadsAnAllowlistedVariable()

	/**
	 * A JSONLogic transition condition reads the same node.
	 *
	 * @return void
	 */
	public function testAJsonLogicTransitionConditionReadsTheSameNode(): void {
		$dialect   = $this->dialect(registry: $this->registry(listed: ['env:INTAKE_REGION' => 'north']));
		$condition = ['==' => [['var' => 'object.region'], ['source' => 'env:INTAKE_REGION']]];

		self::assertTrue($this->transitionHolds(dialect: $dialect, condition: $condition, region: 'north'));
		self::assertFalse($this->transitionHolds(dialect: $dialect, condition: $condition, region: 'south'));
	}//end testAJsonLogicTransitionConditionReadsTheSameNode()

	/**
	 * A refused reference fails closed and the log names the reference, not a value.
	 *
	 * @return void
	 */
	public function testARefusedReferenceFailsClosedAndLogsNoValue(): void {
		$dialect   = $this->dialect(registry: $this->registry(listed: ['env:INTAKE_REGION' => 'north']));
		$condition = ['ne' => [['prop' => 'object.region'], ['source' => 'env:NOT_LISTED']]];

		self::assertFalse($this->transitionHolds(dialect: $dialect, condition: $condition, region: 'north'));

		$logged = json_encode($this->logLines);
		self::assertStringContainsString('env:NOT_LISTED', (string)$logged);
		self::assertStringNotContainsString('north', (string)$logged);
	}//end testARefusedReferenceFailsClosedAndLogsNoValue()

	/**
	 * Without integriq nothing resolves, and openregister does not read the environment.
	 *
	 * @return void
	 */
	public function testWithoutIntegriqNothingResolves(): void {
		putenv('INTAKE_REGION=north');
		try {
			$dialect   = $this->dialect(registry: null);
			$condition = ['eq' => [['prop' => 'object.region'], ['source' => 'env:INTAKE_REGION']]];

			self::assertFalse($this->transitionHolds(dialect: $dialect, condition: $condition, region: 'north'));
		} finally {
			putenv('INTAKE_REGION');
		}
	}//end testWithoutIntegriqNothingResolves()

	/**
	 * A condition without a source node evaluates as before.
	 *
	 * @return void
	 */
	public function testAConditionWithoutASourceNodeIsUnchanged(): void {
		$dialect = $this->dialect(registry: null);

		self::assertTrue($this->transitionHolds(dialect: $dialect, condition: ['eq' => [['prop' => 'object.region'], 'north']], region: 'north'));
	}//end testAConditionWithoutASourceNodeIsUnchanged()

	/**
	 * A calculation refuses a value source, naming the reference.
	 *
	 * @return void
	 */
	public function testACalculationRefusesAValueSource(): void {
		$userSession = $this->createMock(IUserSession::class);
		$evaluator   = new CalculationEvaluator(new PlaceholderResolver($userSession));

		$this->expectException(EvaluationException::class);
		$this->expectExceptionMessage('env:INTAKE_REGION');
		$evaluator->evaluate(['region' => 'north'], ['concat' => [['prop' => 'region'], ['source' => 'env:INTAKE_REGION']]]);
	}//end testACalculationRefusesAValueSource()
}//end class
