<?php

/**
 * ScheduledNotificationJob
 *
 * Background job that evaluates schema-declared 'scheduled' notification
 * triggers and dispatches them when the interval has elapsed.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/notificatie-engine/tasks.md#task-5
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Notification\AnnotationNotificationDispatcher;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Evaluates scheduled notification triggers across all schemas.
 *
 * Runs every 5 minutes (DEFAULT_INTERVAL = 300 s).
 * For each schema that has at least one rule with trigger = 'scheduled',
 * checks whether the declared interval has elapsed since last dispatch and,
 * if so, calls the dispatcher.
 *
 * @psalm-suppress UnusedClass
 */
class ScheduledNotificationJob extends TimedJob
{

    /**
     * Job interval in seconds (5 minutes).
     */
    private const DEFAULT_INTERVAL = 300;

    /**
     * Constructor.
     *
     * @param ITimeFactory                     $time         Nextcloud time factory.
     * @param SchemaMapper                     $schemaMapper Schema mapper.
     * @param AnnotationNotificationDispatcher $dispatcher   Notification dispatcher.
     * @param LoggerInterface                  $logger       Logger.
     */
    public function __construct(
        ITimeFactory $time,
        private readonly SchemaMapper $schemaMapper,
        private readonly AnnotationNotificationDispatcher $dispatcher,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct(time: $time);
        $this->setInterval(interval: self::DEFAULT_INTERVAL);
    }//end __construct()

    /**
     * Execute the job.
     *
     * @param array<string, mixed> $argument Job argument (unused).
     *
     * @return void
     *
     * @spec openspec/changes/notificatie-engine/tasks.md#task-5
     */
    protected function run(mixed $argument): void
    {
        try {
            $schemas = $this->schemaMapper->findAll(limit: 500, offset: 0);

            foreach ($schemas as $schema) {
                $configuration = $schema->getConfiguration();
                if (is_string(value: $configuration) === true) {
                    try {
                        $configuration = json_decode(json: $configuration, associative: true, flags: JSON_THROW_ON_ERROR);
                    } catch (\JsonException $e) {
                        continue;
                    }
                }

                if (is_array(value: $configuration) === false) {
                    continue;
                }

                $rules = $configuration['x-openregister-notifications'] ?? [];
                if (is_array(value: $rules) === false) {
                    continue;
                }

                foreach ($rules as $rule) {
                    if (is_array(value: $rule) === false) {
                        continue;
                    }

                    if (($rule['trigger'] ?? '') !== 'scheduled') {
                        continue;
                    }

                    $this->evaluateScheduledRule(rule: $rule, schema: $schema);
                }
            }//end foreach
        } catch (\Throwable $e) {
            $this->logger->error(
                message: 'ScheduledNotificationJob failed',
                context: ['error' => $e->getMessage()]
            );
        }//end try
    }//end run()

    /**
     * Evaluate a single scheduled rule.
     *
     * @param array<string, mixed>        $rule   Rule spec.
     * @param \OCA\OpenRegister\Db\Schema $schema Schema entity.
     *
     * @return void
     */
    private function evaluateScheduledRule(array $rule, mixed $schema): void
    {
        try {
            $intervalSeconds = (int) ($rule['schedule']['intervalSeconds'] ?? 3600);
            $lastFiredKey    = 'sched_last_'.$schema->getId().'_'.($rule['name'] ?? 'anon');
            $lastFired       = (int) ($rule[$lastFiredKey] ?? 0);

            if ((time() - $lastFired) < $intervalSeconds) {
                return;
            }

            $this->dispatcher->dispatchScheduled(rule: $rule, schema: $schema);

            $this->logger->info(
                message: 'Dispatched scheduled notification',
                context: ['schema' => $schema->getId(), 'rule' => $rule['name'] ?? 'anon']
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                message: 'Failed to evaluate scheduled notification rule',
                context: ['error' => $e->getMessage(), 'schema' => $schema->getId() ?? 'unknown']
            );
        }//end try
    }//end evaluateScheduledRule()
}//end class
