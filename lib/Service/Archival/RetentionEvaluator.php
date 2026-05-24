<?php

/**
 * OpenRegister RetentionEvaluator
 *
 * Orchestrator that maps a `(annotation, row, createdAt)` triple to the
 * row's effective retention duration, matched rule index, and absolute
 * expiry instant. First-match-wins across `retention.rules[]`, falling
 * back to `retention.default` when no rule matches.
 *
 * Used by:
 *   - `ArchivalRetentionTask` (cron) to decide which rows are past
 *     retention and queue them for deletion.
 *   - `ObjectEntity::jsonSerialize()` (via the renderer) to surface the
 *     `_retention` block on read so the UI can show *why* a row is kept.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/add-archival-annotation-support/tasks.md#task-4-2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use Exception;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Maps an archival annotation + a row + its creation timestamp to the
 * effective retention metadata for that row.
 *
 * Stateless apart from the injected logger; safe to instantiate per-call
 * in hot paths if needed (the cron re-uses one instance per run).
 *
 * @spec openspec/changes/add-archival-annotation-support/tasks.md#task-4
 */
final class RetentionEvaluator
{

    /**
     * Condition DSL evaluator used to match rules against a row.
     *
     * @var RetentionConditionEvaluator
     */
    private RetentionConditionEvaluator $conditionEvaluator;

    /**
     * Logger used for malformed-condition warnings.
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * Constructor.
     *
     * @param RetentionConditionEvaluator|null $conditionEvaluator Condition DSL evaluator (defaults to a fresh instance).
     * @param LoggerInterface|null             $logger             Logger for malformed-condition warnings (defaults to NullLogger).
     */
    public function __construct(
        ?RetentionConditionEvaluator $conditionEvaluator=null,
        ?LoggerInterface $logger=null
    ) {
        $this->conditionEvaluator = ($conditionEvaluator ?? new RetentionConditionEvaluator());
        $this->logger = ($logger ?? new NullLogger());

    }//end __construct()

    /**
     * Compute the effective retention for a row.
     *
     * @param array<string, mixed> $annotation Full `x-openregister-archival` block from the schema's configuration.
     * @param array<string, mixed> $row        Row data keyed by field name (output of the row's hydration).
     * @param DateTimeInterface    $createdAt  The row's `_created` timestamp.
     *
     * @return array{
     *     effectiveRetention: string,
     *     matchedRule: int|null,
     *     expiresAt: string
     * } Effective retention duration (ISO-8601), matched rule index (or null
     *   when fallback to default), and absolute expiry as ATOM-formatted string.
     *
     * @throws InvalidArgumentException When the annotation has no usable retention.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     *
     * @spec openspec/changes/add-archival-annotation-support/tasks.md#task-4
     */
    public function evaluate(array $annotation, array $row, DateTimeInterface $createdAt): array
    {
        $retention = ($annotation['retention'] ?? null);
        if (is_array($retention) === false) {
            throw new InvalidArgumentException(
                'RetentionEvaluator: annotation has no `retention` block; validate at schema-save time.'
            );
        }

        $default = ($retention['default'] ?? null);
        if (is_string($default) === false || $default === '') {
            throw new InvalidArgumentException(
                'RetentionEvaluator: annotation.retention.default is missing or not a string.'
            );
        }

        $rules        = ($retention['rules'] ?? []);
        $matchedRule  = null;
        $effectiveDur = $default;

        if (is_array($rules) === true) {
            foreach ($rules as $index => $rule) {
                if (is_array($rule) === false) {
                    continue;
                }

                $condition = ($rule['condition'] ?? null);
                $ruleRet   = ($rule['retention'] ?? null);
                if (is_string($condition) === false || is_string($ruleRet) === false) {
                    continue;
                }

                try {
                    $matches = $this->conditionEvaluator->evaluate($condition, $row);
                } catch (InvalidArgumentException $error) {
                    // Cron must not crash on a single malformed rule.
                    // Spec D-Scenario "Malformed condition" of the
                    // RetentionConditionEvaluator requirement.
                    $this->logger->warning(
                        'Skipping malformed retention condition: '.$error->getMessage(),
                        ['rule_index' => $index]
                    );
                    continue;
                }

                if ($matches === true) {
                    $matchedRule  = (int) $index;
                    $effectiveDur = $ruleRet;
                    break;
                }
            }//end foreach
        }//end if

        $expiresAt = $this->addDuration(createdAt: $createdAt, duration: $effectiveDur)->format(DateTimeInterface::ATOM);

        return [
            'effectiveRetention' => $effectiveDur,
            'matchedRule'        => $matchedRule,
            'expiresAt'          => $expiresAt,
        ];

    }//end evaluate()

    /**
     * Add an ISO-8601 duration to a datetime, returning a new immutable instance.
     *
     * @param DateTimeInterface $createdAt Source instant (typically the row's `_created`).
     * @param string            $duration  ISO-8601 duration string.
     *
     * @return DateTimeImmutable
     *
     * @throws InvalidArgumentException When the duration cannot be parsed.
     *
     * @SuppressWarnings(PHPMD.StaticAccess)
     */
    private function addDuration(DateTimeInterface $createdAt, string $duration): DateTimeImmutable
    {
        try {
            $interval = new DateInterval($duration);
        } catch (Exception $error) {
            throw new InvalidArgumentException(
                sprintf('RetentionEvaluator: cannot parse duration "%s": %s', $duration, $error->getMessage())
            );
        }

        // Convert any DateTimeInterface to DateTimeImmutable for `->add()`.
        $immutable = DateTimeImmutable::createFromInterface($createdAt);

        return $immutable->add($interval);

    }//end addDuration()
}//end class
