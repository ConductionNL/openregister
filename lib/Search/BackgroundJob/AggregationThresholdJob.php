<?php

/**
 * OpenRegister AggregationThresholdJob
 *
 * Actor-forwarded background job carrying the deferred work of
 * AggregationThresholdListener (created/updated/transitioned events):
 * re-evaluates threshold-typed `x-openregister-notifications` by running
 * their aggregations fresh and dispatching on rising-edge crossings.
 *
 * Entries are deduped per (register, schema) at enqueue time, so a bulk
 * save of N objects of one schema triggers ONE evaluation instead of N.
 * Idempotent: the aggregation is recomputed against current data and the
 * rising-edge dedup lives in the distributed state cache, exactly as the
 * inline evaluation. Delete events stay inline in the listener — a
 * hard-deleted object cannot be re-fetched here, and delete-driven
 * crossings (`lt`/`lte` rules) would be silently lost.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @psalm-suppress UnusedClass
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\Support\ActorForwardingServices;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Search\BackgroundJob\Support\AggregationThresholdServices;
use OCA\OpenRegister\Search\Service\Aggregation\ThresholdEvaluationService;
use OCA\OpenRegister\Service\Deferral\DeferredEntryObjectResolverInterface;
use OCA\OpenRegister\Service\Deferral\DeferredListenerContext;
use OCP\AppFramework\Utility\ITimeFactory;
use OCA\OpenRegister\BackgroundJob\ActorForwardedJob;

/**
 * Deferred threshold-notification evaluation under the forwarded actor.
 *
 * @spec openspec/specs/event-driven-architecture/spec.md
 */
class AggregationThresholdJob extends ActorForwardedJob {
	/**
	 * Stale-safe entry re-fetch.
	 *
	 * @var DeferredEntryObjectResolverInterface
	 */
	private readonly DeferredEntryObjectResolverInterface $resolver;

	/**
	 * Schema lookup mapper.
	 *
	 * @var SchemaMapperInterface
	 */
	private readonly SchemaMapperInterface $schemaMapper;

	/**
	 * Shared threshold evaluation logic.
	 *
	 * @var ThresholdEvaluationService
	 */
	private readonly ThresholdEvaluationService $evaluator;

	/**
	 * Wire the evaluation collaborators on top of the actor plumbing.
	 *
	 * @param ITimeFactory                 $time       Time factory for the parent job class.
	 * @param ActorForwardingServices      $actor      Session, user manager, organisation and logger for the parent job class.
	 * @param AggregationThresholdServices $evaluation Entry re-fetch, schema lookup and threshold evaluation.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	public function __construct(
		ITimeFactory $time,
		ActorForwardingServices $actor,
		AggregationThresholdServices $evaluation,
	) {
		parent::__construct(
			time: $time,
			userSession: $actor->userSession,
			userManager: $actor->userManager,
			organisation: $actor->organisation,
			logger: $actor->logger
		);
		$this->resolver = $evaluation->resolver;
		$this->schemaMapper = $evaluation->schemaMapper;
		$this->evaluator = $evaluation->evaluator;
	}//end __construct()

	/**
	 * Evaluate thresholds for every live entry's schema.
	 *
	 * Per-entry failures are logged and do not abort the chunk.
	 *
	 * @param DeferredListenerContext $context The captured dispatch-time context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	protected function runDeferred(DeferredListenerContext $context): void {
		foreach ($context->getEntries() as $entry) {
			$object = $this->resolver->resolve(entry: $entry);
			if ($object === null) {
				continue;
			}

			try {
				$schema = $this->schemaMapper->find((string)($entry['schema'] ?? ''));
				$this->evaluator->evaluateSchema(schema: $schema, object: $object);
			} catch (\Throwable $e) {
				$this->logger->warning(
					message: '[AggregationThresholdJob] Threshold evaluation failed for entry',
					context: [
						'file' => __FILE__,
						'line' => __LINE__,
						'uuid' => ($entry['uuid'] ?? null),
						'schema' => ($entry['schema'] ?? null),
						'error' => $e->getMessage(),
					]
				);
			}
		}//end foreach
	}//end runDeferred()
}//end class
