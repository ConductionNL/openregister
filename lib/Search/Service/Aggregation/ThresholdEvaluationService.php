<?php

/**
 * OpenRegister ThresholdEvaluationService
 *
 * Threshold-typed notification evaluation, extracted verbatim from
 * AggregationThresholdListener so the same logic serves both execution
 * paths of the actor-forwarded deferral contract: the deferred
 * AggregationThresholdJob (created/updated/transitioned events) and the
 * listener's remaining inline path (delete events + the `listenerDeferral`
 * kill switch).
 *
 * When the referenced aggregation value crosses the configured threshold
 * (transitions from below → above the operator condition), the notification
 * is dispatched through the existing dispatcher. Transition tracking lives
 * in the distributed cache so a notification fires once on breach, not on
 * every subsequent write while still over the threshold — this also makes
 * at-least-once job re-runs idempotent.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Aggregation
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

namespace OCA\OpenRegister\Search\Service\Aggregation;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationRuleDispatcherInterface;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Service\Aggregation\AggregationRunnerInterface;

/**
 * Evaluates threshold-typed notifications declared on a schema.
 *
 * @spec openspec/specs/event-driven-architecture/spec.md
 */
class ThresholdEvaluationService {

	private const STATE_ABOVE = 'above';
	private const STATE_BELOW = 'below';

	/**
	 * Distributed cache holding the last threshold state per (schema, notification).
	 *
	 * @var ICache|null
	 */
	private ?ICache $stateCache = null;

	/**
	 * Wire collaborators and prepare the state cache.
	 *
	 * @param AggregationRunnerInterface $aggregationRunner Runner that computes aggregation values.
	 * @param NotificationRuleDispatcherInterface $dispatcher Notification dispatcher.
	 * @param LoggerInterface $logger PSR logger for warnings.
	 * @param ICacheFactory $cacheFactory Distributed-cache factory.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly AggregationRunnerInterface $aggregationRunner,
		private readonly NotificationRuleDispatcherInterface $dispatcher,
		private readonly LoggerInterface $logger,
		ICacheFactory $cacheFactory,
	) {
		try {
			$this->stateCache = $cacheFactory->createDistributed('openregister_threshold_state');
		} catch (\Throwable $e) {
			$this->stateCache = null;
		}
	}//end __construct()

	/**
	 * Whether a schema declares at least one threshold-typed notification.
	 *
	 * Cheap config-array scan used as the listener's inline enqueue gate.
	 *
	 * @param Schema $schema Schema to inspect.
	 *
	 * @return bool True when a threshold trigger is declared.
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	public function hasThresholdNotifications(Schema $schema): bool {
		foreach ($this->declaredNotifications(schema: $schema) as $spec) {
			if ($this->thresholdTrigger(spec: $spec) !== null) {
				return true;
			}
		}

		return false;
	}//end hasThresholdNotifications()

	/**
	 * Re-evaluate every threshold notification declared on the schema.
	 *
	 * Per-notification failures are logged and do not abort the remaining
	 * evaluations (behaviour carried over from the listener).
	 *
	 * @param Schema $schema Schema declaring the notifications.
	 * @param ObjectEntity $object Object whose write triggered the evaluation.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	public function evaluateSchema(Schema $schema, ObjectEntity $object): void {
		foreach ($this->declaredNotifications(schema: $schema) as $name => $spec) {
			$trigger = $this->thresholdTrigger(spec: $spec);
			if ($trigger === null) {
				continue;
			}

			try {
				$this->evaluate(schema: $schema, notificationName: (string)$name, trigger: $trigger, object: $object);
			} catch (\Throwable $e) {
				$this->logger->warning(
					sprintf(
						'[ThresholdEvaluationService] evaluation of "%s" failed: %s',
						(string)$name,
						$e->getMessage()
					)
				);
			}
		}//end foreach
	}//end evaluateSchema()

	/**
	 * The schema's declared notifications, or none when the block is not a list.
	 *
	 * @param Schema $schema Schema declaring the notifications.
	 *
	 * @return array<int|string, mixed>
	 */
	private function declaredNotifications(Schema $schema): array {
		$config = ($schema->getConfiguration() ?? []);
		$notifications = ($config['x-openregister-notifications'] ?? null);
		if (is_array($notifications) === false) {
			return [];
		}

		return $notifications;
	}//end declaredNotifications()

	/**
	 * The trigger block of a threshold-typed notification spec.
	 *
	 * @param mixed $spec One declared notification.
	 *
	 * @return array<string, mixed>|null The trigger, or null when the spec is not a threshold notification.
	 */
	private function thresholdTrigger(mixed $spec): ?array {
		if (is_array($spec) === false) {
			return null;
		}

		$trigger = ($spec['trigger'] ?? null);
		if (is_array($trigger) === true && (string)($trigger['type'] ?? '') === 'threshold') {
			return $trigger;
		}

		return null;
	}//end thresholdTrigger()

	/**
	 * Evaluate one notification spec and dispatch on rising-edge crossings.
	 *
	 * @param Schema $schema Schema declaring the notification.
	 * @param string $notificationName Notification key in the schema config.
	 * @param array<string, mixed> $trigger Trigger configuration block.
	 * @param ObjectEntity $object Object that just changed.
	 *
	 * @return void
	 */
	private function evaluate(Schema $schema, string $notificationName, array $trigger, ObjectEntity $object): void {
		$parsed = $this->readTrigger(trigger: $trigger);
		if ($parsed === null) {
			return;
		}

		$value = $this->aggregationValue(schema: $schema, object: $object, aggregationName: $parsed['aggregation']);
		if ($value === null) {
			return;
		}

		$newState = self::STATE_BELOW;
		if ($this->compare(actual: $value, op: $parsed['op'], expected: $parsed['threshold']) === true) {
			$newState = self::STATE_ABOVE;
		}

		$stateKey = sprintf('threshold:%d:%s', $schema->getId(), $notificationName);

		if ($this->isRisingEdge(stateKey: $stateKey, newState: $newState) === true) {
			$this->dispatcher->dispatch(
				$object,
				'threshold',
				[
					'notificationName' => $notificationName,
					'aggregation' => $parsed['aggregation'],
					'value' => $value,
					'threshold' => $parsed['threshold'],
					'op' => $parsed['op'],
				]
			);
		}

		$this->storeState(stateKey: $stateKey, newState: $newState);
	}//end evaluate()

	/**
	 * Read the aggregation name, operator and threshold from a trigger block.
	 *
	 * @param array<string, mixed> $trigger Trigger configuration block.
	 *
	 * @return array{aggregation: string, op: string, threshold: mixed}|null Null when any is missing.
	 */
	private function readTrigger(array $trigger): ?array {
		$aggregationName = (string)($trigger['aggregation'] ?? '');
		$op = (string)($trigger['op'] ?? '');
		$threshold = ($trigger['value'] ?? null);
		if ($aggregationName === '' || $op === '' || $threshold === null) {
			return null;
		}

		return ['aggregation' => $aggregationName, 'op' => $op, 'threshold' => $threshold];
	}//end readTrigger()

	/**
	 * Run the named aggregation for the object's register and the schema.
	 *
	 * BypassRbac: threshold evaluation is a system reaction to a write
	 * event, not a user-driven request. The write itself already passed
	 * RBAC; the threshold evaluation's authoritative reason is "this object
	 * just changed", independent of the active session's `list` permission
	 * on the schema.
	 *
	 * @param Schema $schema Schema declaring the notification.
	 * @param ObjectEntity $object Object that just changed.
	 * @param string $aggregationName Aggregation to run.
	 *
	 * @return int|float|null The numeric value, or null when it is not numeric.
	 */
	private function aggregationValue(Schema $schema, ObjectEntity $object, string $aggregationName): int|float|null {
		$result = $this->aggregationRunner->run(
			registerRef: (string)$object->getRegister(),
			schemaRef: (string)$schema->getSlug(),
			name: $aggregationName,
			bypassRbac: true
		);
		$value = ($result['value'] ?? null);
		if (is_int($value) === false && is_float($value) === false) {
			return null;
		}

		return $value;
	}//end aggregationValue()

	/**
	 * Whether this evaluation crosses from not-above to above.
	 *
	 * @param string $stateKey State cache key.
	 * @param string $newState The state this evaluation computed.
	 *
	 * @return bool
	 */
	private function isRisingEdge(string $stateKey, string $newState): bool {
		$oldState = $this->stateCache?->get($stateKey);

		return $newState === self::STATE_ABOVE && $oldState !== self::STATE_ABOVE;
	}//end isRisingEdge()

	/**
	 * Store the computed state; a cache failure is not escalated.
	 *
	 * @param string $stateKey State cache key.
	 * @param string $newState The state this evaluation computed.
	 *
	 * @return void
	 */
	private function storeState(string $stateKey, string $newState): void {
		try {
			// 30 day TTL; long enough that a slow-moving threshold series
			// still has continuity across maintenance restarts.
			$this->stateCache?->set($stateKey, $newState, (60 * 60 * 24 * 30));
		} catch (\Throwable $e) {
			// Don't escalate.
		}
	}//end storeState()

	/**
	 * Compare two values with the configured operator.
	 *
	 * @param int|float $actual Numeric value computed from the aggregation.
	 * @param string $op Comparison operator (gt|gte|lt|lte|eq|ne).
	 * @param mixed $expected Threshold value, must be numeric.
	 *
	 * @return bool True when the comparison is satisfied, false otherwise.
	 */
	private function compare($actual, string $op, $expected): bool {
		if (is_numeric($expected) === false) {
			return false;
		}

		$rhs = (float)$expected;
		$lhs = (float)$actual;
		return match ($op) {
			'gt' => $lhs > $rhs,
			'gte' => $lhs >= $rhs,
			'lt' => $lhs < $rhs,
			'lte' => $lhs <= $rhs,
			'eq' => $lhs === $rhs,
			'ne' => $lhs !== $rhs,
			default => false,
		};
	}//end compare()
}//end class
