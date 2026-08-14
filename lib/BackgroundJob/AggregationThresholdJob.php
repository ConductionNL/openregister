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

namespace OCA\OpenRegister\BackgroundJob;

use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Aggregation\ThresholdEvaluationService;
use OCA\OpenRegister\Service\Deferral\DeferredEntryObjectResolver;
use OCA\OpenRegister\Service\Deferral\DeferredListenerContext;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Deferred threshold-notification evaluation under the forwarded actor.
 *
 * @spec openspec/changes/actor-forwarded-listener-jobs/tasks.md#task-2.3
 */
class AggregationThresholdJob extends ActorForwardedJob {
	/**
	 * Wire the evaluation collaborators on top of the actor plumbing.
	 *
	 * @param ITimeFactory $time Time factory for the parent job class.
	 * @param IUserSession $userSession Session to impersonate on / restore.
	 * @param IUserManager $userManager Resolver for the captured user id.
	 * @param OrganisationService $organisation Active-organisation resolver.
	 * @param LoggerInterface $logger PSR logger.
	 * @param DeferredEntryObjectResolver $resolver Stale-safe entry re-fetch.
	 * @param SchemaMapper $schemaMapper Schema lookup mapper.
	 * @param ThresholdEvaluationService $evaluator Shared threshold evaluation logic.
	 *
	 * @return void
	 */
	public function __construct(
		ITimeFactory $time,
		IUserSession $userSession,
		IUserManager $userManager,
		OrganisationService $organisation,
		LoggerInterface $logger,
		private readonly DeferredEntryObjectResolver $resolver,
		private readonly SchemaMapper $schemaMapper,
		private readonly ThresholdEvaluationService $evaluator,
	) {
		parent::__construct(
			time: $time,
			userSession: $userSession,
			userManager: $userManager,
			organisation: $organisation,
			logger: $logger
		);
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
