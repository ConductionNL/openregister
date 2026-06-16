<?php

/**
 * OpenRegister AppHost — Metric Sample
 *
 * Immutable value object for one rendered Prometheus metric (a name, type,
 * help text, and one or more labelled samples). Produced by the metric
 * sources and by IMetricsProvider implementations, then rendered to text by
 * the {@see PrometheusRenderer}.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category ValueObject
 * @package  OCA\OpenRegister\AppHost\Observability
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost\Observability;

/**
 * One metric family: a name + type + HELP plus its labelled samples.
 *
 * @psalm-immutable
 */
final class MetricSample
{
    /**
     * Constructor.
     *
     * @param string                                            $name    Metric name (without `{app}_` prefix).
     * @param string                                            $type    Prometheus type (gauge|counter).
     * @param string                                            $help    HELP text.
     * @param array<int, array{labels: array<string,string>, value: float|int}> $samples Labelled samples.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly string $help,
        public readonly array $samples
    ) {
    }//end __construct()

    /**
     * Convenience factory for a single unlabelled sample.
     *
     * @param string                $name   Metric name.
     * @param string                $type   Prometheus type.
     * @param string                $help   HELP text.
     * @param float|int             $value  Sample value.
     * @param array<string, string> $labels Optional labels.
     *
     * @return self
     */
    public static function single(string $name, string $type, string $help, float|int $value, array $labels=[]): self
    {
        return new self(
            name: $name,
            type: $type,
            help: $help,
            samples: [['labels' => $labels, 'value' => $value]]
        );
    }//end single()
}//end class
