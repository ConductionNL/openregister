<?php

/**
 * Holds the oversight checks and asks them whether a hop may proceed.
 *
 * The whole contract of this class is that it FAILS CLOSED. A check that throws
 * is a veto, not an absence of objection — the alternative is a gate that
 * silently opens whenever the thing guarding it breaks, which is the standard
 * way a safety rail becomes decorative while still reading as present in the
 * code.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-engine-unification/specs/flow-oversight/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Registry of pre-hop oversight checks.
 *
 * @spec openspec/changes/flow-engine-unification/specs/flow-oversight/spec.md
 */
class FlowOversightRegistry
{

    /**
     * The registered checks, keyed by id.
     *
     * @var array<string, IFlowOversightCheck>
     */
    private array $checks = [];

    /**
     * Constructor.
     *
     * @param LoggerInterface $logger Records a check that misbehaves.
     */
    public function __construct(private readonly LoggerInterface $logger)
    {

    }//end __construct()

    /**
     * Register an oversight check.
     *
     * Later registrations of the same id replace earlier ones, matching how the
     * node registry behaves, so an app can deliberately override a check it
     * ships itself.
     *
     * @param IFlowOversightCheck $check The check to add.
     *
     * @return void
     *
     * @spec openspec/changes/flow-engine-unification/specs/flow-oversight/spec.md
     */
    public function register(IFlowOversightCheck $check): void
    {
        $this->checks[$check->getId()] = $check;

    }//end register()

    /**
     * The registered checks, keyed by id.
     *
     * @return array<string, IFlowOversightCheck> The checks.
     *
     * @spec openspec/changes/flow-engine-unification/specs/flow-oversight/spec.md
     */
    public function all(): array
    {
        return $this->checks;

    }//end all()

    /**
     * Ask every check whether this hop may run.
     *
     * Returns the FIRST refusal, with the vetoing check's id, so the caller can
     * record what stopped the run. An empty registry consents — there is
     * nothing to object — which is why enabling oversight on an instance with
     * no registered checks is a no-op rather than a wall.
     *
     * @param array<string, mixed> $context The run context passed to each check.
     *
     * @return array{checkId: string, reason: string}|null The refusal, or null when every check consents.
     *
     * @spec openspec/changes/flow-engine-unification/specs/flow-oversight/spec.md
     */
    public function firstRefusal(array $context): ?array
    {
        foreach ($this->checks as $id => $check) {
            try {
                $reason = $check->veto(context: $context);
            } catch (Throwable $e) {
                // FAIL CLOSED. A check that throws has not consented, and
                // treating its failure as consent would mean an oversight
                // outage silently disables oversight — precisely when it is
                // most likely to matter.
                $this->logger->error(
                    message: '[FlowOversight] Check "'.$id.'" threw; refusing the hop: '.$e->getMessage(),
                    context: ['exception' => $e, 'file' => __FILE__, 'line' => __LINE__]
                );

                return [
                    'checkId' => $id,
                    'reason'  => 'Oversight check "'.$id.'" could not complete, so the hop was refused.',
                ];
            }//end try

            if ($reason !== null && trim($reason) !== '') {
                return ['checkId' => $id, 'reason' => $reason];
            }
        }//end foreach

        return null;

    }//end firstRefusal()
}//end class
