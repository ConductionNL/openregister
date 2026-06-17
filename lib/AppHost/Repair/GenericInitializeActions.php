<?php

/**
 * OpenRegister AppHost — Generic Initialize-Actions Repair Step
 *
 * Engine-owned generalisation of the per-app `InitializeActions` repair step.
 * Seeds the ADR-023 action-authorization matrix from the leaf app's
 * `lib/actions.seed.json` on fresh install if the matrix is empty, and
 * preserves any admin-customised matrix on upgrade (non-empty matrix is left
 * untouched).
 *
 * The seed file is resolved from the leaf app's path via IAppManager, so one
 * generic step serves every adopting app. Like its sibling settings step, the
 * leaf keeps a one-line subclass referenced by info.xml `<repair-steps>`.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Repair
 * @package  OCA\OpenRegister\AppHost\Repair
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

namespace OCA\OpenRegister\AppHost\Repair;

use OCA\OpenRegister\AppHost\Service\GenericActionAuthService;
use OCP\App\IAppManager;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Generic repair step that seeds a leaf app's ADR-023 action matrix.
 *
 * @spec openspec/changes/apphost-boilerplate-controllers/tasks.md#task-2.2
 */
class GenericInitializeActions implements IRepairStep
{
    /**
     * Constructor.
     *
     * @param string                   $appId      The leaf app id.
     * @param GenericActionAuthService $actionAuth App-scoped action-auth service.
     * @param IAppManager              $appManager App path resolution for the seed file.
     * @param LoggerInterface          $logger     PSR logger.
     */
    public function __construct(
        protected readonly string $appId,
        protected readonly GenericActionAuthService $actionAuth,
        protected readonly IAppManager $appManager,
        protected readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Repair-step name.
     *
     * @return string
     */
    public function getName(): string
    {
        return sprintf('Initialize %s action-authorization matrix (ADR-023)', $this->appId);
    }//end getName()

    /**
     * Seed the matrix if empty; preserve any existing admin-customised matrix.
     *
     * @param IOutput $output Repair output channel.
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.StaticAccess)
     *
     * @spec openspec/changes/apphost-boilerplate-controllers/tasks.md#task-2.2
     */
    public function run(IOutput $output): void
    {
        $existing = $this->actionAuth->getMatrix();
        if (count($existing) > 0) {
            $output->info(sprintf('Action matrix already has %d entries — preserving.', count($existing)));
            return;
        }

        $seedPath = $this->resolveSeedPath();
        if ($seedPath === null || file_exists($seedPath) === false) {
            $output->warning('actions.seed.json not found — matrix left empty (default-deny).');
            $this->logger->warning(sprintf('[AppHost:%s] ADR-023 seed file missing', $this->appId));
            return;
        }

        $raw = file_get_contents($seedPath);
        if ($raw === false) {
            $output->warning('Could not read actions.seed.json — matrix left empty (default-deny).');
            return;
        }

        try {
            $parsed = json_decode($raw, associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $output->warning('actions.seed.json invalid JSON: '.$e->getMessage());
            $this->logger->error(sprintf('[AppHost:%s] ADR-023 seed malformed: %s', $this->appId, $e->getMessage()));
            return;
        }

        $actions = ($parsed['actions'] ?? null);
        if (is_array($actions) === false) {
            $output->warning('actions.seed.json missing `actions` object — matrix left empty.');
            return;
        }

        try {
            $this->actionAuth->setMatrix($actions);
        } catch (\JsonException $e) {
            $output->warning('Failed to write matrix: '.$e->getMessage());
            return;
        }

        $output->info(sprintf('Seeded action matrix with %d actions (default: admin-only).', count($actions)));
    }//end run()

    /**
     * Resolve the leaf app's `lib/actions.seed.json` path. Overridable hook.
     *
     * @return string|null Absolute path, or null when the app path is unresolvable.
     *
     * @SuppressWarnings(PHPMD.StaticAccess)
     */
    protected function resolveSeedPath(): ?string
    {
        try {
            $appPath = $this->appManager->getAppPath(appId: $this->appId);
        } catch (Throwable $e) {
            return null;
        }

        return $appPath.'/lib/actions.seed.json';
    }//end resolveSeedPath()
}//end class
