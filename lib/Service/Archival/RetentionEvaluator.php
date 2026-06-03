<?php

/**
 * OpenRegister Retention Evaluator
 *
 * Orchestrates condition evaluation across retention rules and computes
 * effectiveRetention, matchedRule, and expiresAt for a given row.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/add-archival-annotation-support/tasks.md#task-4.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

use DateTimeImmutable;
use DateTimeInterface;
use Psr\Log\LoggerInterface;

/**
 * Computes the effective retention metadata for a single object row.
 *
 * Given the x-openregister-archival annotation block, the row's field values,
 * and the row's creation timestamp, returns:
 *   - effectiveRetention : ISO-8601 duration string (e.g. "PT1H")
 *   - matchedRule        : zero-based index of the matched rule, or null (default used)
 *   - expiresAt          : createdAt + effectiveRetention, formatted as ATOM
 */
class RetentionEvaluator
{
    /**
     * Constructor.
     *
     * @param RetentionConditionEvaluator $conditionEvaluator Parses and evaluates condition clauses.
     * @param LoggerInterface             $logger             Psr logger.
     */
    public function __construct(
        private readonly RetentionConditionEvaluator $conditionEvaluator,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Evaluate retention for a row against the given annotation.
     *
     * Rules are evaluated in declared order; first match wins. If no rule matches,
     * `retention.default` is used and `matchedRule` is null.
     *
     * @param array             $annotation The value of configuration['x-openregister-archival'].
     * @param array             $row        The object's field data (user-defined columns only).
     * @param DateTimeInterface $createdAt  The row's creation timestamp.
     *
     * @return array{effectiveRetention: string, matchedRule: int|null, expiresAt: string}
     *
     * @spec openspec/changes/add-archival-annotation-support/tasks.md#task-4.2
     */
    public function evaluate(array $annotation, array $row, DateTimeInterface $createdAt): array
    {
        $retention  = $annotation['retention'] ?? [];
        $defaultDur = $retention['default'] ?? 'P30D';
        $rules      = $retention['rules'] ?? [];

        $effectiveRetention = $defaultDur;
        $matchedRule        = null;

        foreach ($rules as $index => $rule) {
            $condition = $rule['condition'] ?? '';
            if ($condition === '') {
                continue;
            }

            try {
                $matched = $this->conditionEvaluator->evaluate(condition: $condition, row: $row);
            } catch (\InvalidArgumentException $e) {
                // Malformed condition — log and skip, do not crash the sweep.
                $this->logger->warning(
                    '[RetentionEvaluator] Malformed condition, skipping rule',
                    [
                        'rule'      => $index,
                        'condition' => $condition,
                        'error'     => $e->getMessage(),
                    ]
                );
                continue;
            }

            if ($matched === true) {
                $effectiveRetention = $rule['retention'] ?? $defaultDur;
                $matchedRule        = $index;
                break;
            }
        }//end foreach

        $expiresAt = $this->computeExpiresAt(
            createdAt: $createdAt,
            duration: $effectiveRetention
        );

        return [
            'effectiveRetention' => $effectiveRetention,
            'matchedRule'        => $matchedRule,
            'expiresAt'          => $expiresAt,
        ];
    }//end evaluate()

    /**
     * Compute the expiry timestamp as an ATOM-formatted string.
     *
     * @param DateTimeInterface $createdAt The row's creation timestamp.
     * @param string            $duration  ISO-8601 duration string.
     *
     * @return string ATOM-formatted expiry timestamp.
     */
    private function computeExpiresAt(DateTimeInterface $createdAt, string $duration): string
    {
        try {
            $interval  = new \DateInterval($duration);
            $expiresAt = DateTimeImmutable::createFromInterface($createdAt)->add($interval);
            return $expiresAt->format(DateTimeInterface::ATOM);
        } catch (\Exception $e) {
            // Fallback: 30 days from creation.
            $this->logger->error(
                '[RetentionEvaluator] Invalid duration, falling back to P30D',
                ['duration' => $duration, 'error' => $e->getMessage()]
            );
            $expiresAt = DateTimeImmutable::createFromInterface($createdAt)
                ->add(new \DateInterval('P30D'));
            return $expiresAt->format(DateTimeInterface::ATOM);
        }
    }//end computeExpiresAt()
}//end class
