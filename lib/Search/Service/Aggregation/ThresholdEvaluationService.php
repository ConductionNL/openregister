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
use OCA\OpenRegister\Service\Aggregation\NamedAggregationRef;
use OCA\OpenRegister\Service\Aggregation\NamedAggregationOptions;

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
		$this->stateCache = $this->openStateCache(cacheFactory: $cacheFactory);
	}//end __construct()

	/**
	 * The distributed state cache, or null when no backend is available.
	 *
	 * @param ICacheFactory $cacheFactory Distributed-cache factory.
	 *
	 * @return ICache|null
	 */
	private function openStateCache(ICacheFactory $cacheFactory): ?ICache {
		try {
			return $cacheFactory->createDistributed('openregister_threshold_state');
		} catch (\Throwable $e) {
			return null;
		}
	}//end openStateCache()

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

			$this->evaluateLogged(
				run: new ThresholdRun(schema: $schema, object: $object, notificationName: (string)$name),
				trigger: $trigger
			);
		}
	}//end evaluateSchema()

	/**
	 * Evaluate one notification, logging a failure instead of letting it stop the others.
	 *
	 * @param ThresholdRun         $run     The schema, the object that just changed and the notification name.
	 * @param array<string, mixed> $trigger Trigger configuration block.
	 *
	 * @return void
	 */
	private function evaluateLogged(ThresholdRun $run, array $trigger): void {
		try {
			$this->evaluate(run: $run, trigger: $trigger);
		} catch (\Throwable $e) {
			$this->logger->warning(
				sprintf(
					'[ThresholdEvaluationService] evaluation of "%s" failed: %s',
					$run->notificationName,
					$e->getMessage()
				)
			);
		}
	}//end evaluateLogged()

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
	 * @param ThresholdRun         $run     The schema, the object that just changed and the notification name.
	 * @param array<string, mixed> $trigger Trigger configuration block.
	 *
	 * @return void
	 */
	private function evaluate(ThresholdRun $run, array $trigger): void {
		$parsed = ThresholdTrigger::fromConfig(trigger: $trigger);
		if ($parsed === null) {
			return;
		}

		$value = $this->aggregationValue(run: $run, aggregationName: $parsed->aggregation);
		if ($value === null) {
			return;
		}

		$newState = $this->stateFor(trigger: $parsed, value: $value);

		if ($this->isRisingEdge(stateKey: $run->stateKey(), newState: $newState) === true) {
			$this->notify(run: $run, payload: $this->breachPayload(trigger: $parsed, value: $value));
		}

		$this->storeState(stateKey: $run->stateKey(), newState: $newState);
	}//end evaluate()

	/**
	 * Whether the value puts the notification above or below its threshold.
	 *
	 * @param ThresholdTrigger $trigger The parsed trigger.
	 * @param int|float        $value   The aggregation value.
	 *
	 * @return string STATE_ABOVE when the trigger is met, else STATE_BELOW.
	 */
	private function stateFor(ThresholdTrigger $trigger, int|float $value): string {
		if ($trigger->isMetBy(actual: $value) === true) {
			return self::STATE_ABOVE;
		}

		return self::STATE_BELOW;
	}//end stateFor()

	/**
	 * What a breach notification carries.
	 *
	 * @param ThresholdTrigger $trigger The trigger that was met.
	 * @param int|float        $value   The aggregation value that met it.
	 *
	 * @return array<string, mixed> Without the notification name, which notify() adds.
	 */
	private function breachPayload(ThresholdTrigger $trigger, int|float $value): array {
		return [
			'aggregation' => $trigger->aggregation,
			'value' => $value,
			'threshold' => $trigger->threshold,
			'op' => $trigger->op,
		];
	}//end breachPayload()

	/**
	 * Dispatch the threshold notification of a run.
	 *
	 * @param ThresholdRun         $run     The run whose notification fires.
	 * @param array<string, mixed> $payload The breach payload.
	 *
	 * @return void
	 */
	private function notify(ThresholdRun $run, array $payload): void {
		$this->dispatcher->dispatch(
			$run->object,
			'threshold',
			array_merge(['notificationName' => $run->notificationName], $payload)
		);
	}//end notify()

	/**
	 * Run the aggregation a trigger names and return its numeric value.
	 *
	 * @param ThresholdRun $run             The schema and the object that just changed.
	 * @param string       $aggregationName Aggregation to run.
	 *
	 * @return int|float|null The numeric value, or null when it is not numeric.
	 */
	private function aggregationValue(ThresholdRun $run, string $aggregationName): int|float|null {
		$result = $this->aggregationRunner->run(
			ref: new NamedAggregationRef(
				register: (string)$run->object->getRegister(),
				schema: (string)$run->schema->getSlug(),
				name: $aggregationName
			),
			options: new NamedAggregationOptions(bypassRbac: true)
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
			// A lost state write can repeat a crossing notification; not escalated.
			$this->logger->warning(
				message: '[ThresholdEvaluationService] Could not store the threshold state',
				context: [
					'stateKey' => $stateKey,
					'exception' => $e->getMessage(),
				]
			);
		}
	}//end storeState()
}//end class
