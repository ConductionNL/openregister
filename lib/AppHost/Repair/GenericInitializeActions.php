<?php

/**
 * OpenRegister AppHost — Generic Initialize-Actions Repair Step
 *
 * Engine-owned generalisation of the per-app `InitializeActions` repair step.
 * Seeds the ADR-023 action-authorization matrix from the leaf app's
 * `lib/actions.seed.json` on fresh install if the matrix is empty. On upgrade
 * it adds the seeded actions an existing matrix lacks and never changes an
 * entry the matrix already has, so an admin's narrowing survives.
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
class GenericInitializeActions implements IRepairStep {
	/**
	 * Constructor.
	 *
	 * @param string $appId The leaf app id.
	 * @param GenericActionAuthService $actionAuth App-scoped action-auth service.
	 * @param IAppManager $appManager App path resolution for the seed file.
	 * @param LoggerInterface $logger PSR logger.
	 */
	public function __construct(
		protected readonly string $appId,
		protected readonly GenericActionAuthService $actionAuth,
		protected readonly IAppManager $appManager,
		protected readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Repair-step name.
	 *
	 * @return string
	 */
	public function getName(): string {
		return sprintf('Initialize %s action-authorization matrix (ADR-023)', $this->appId);
	}//end getName()

	/**
	 * Seed the matrix if empty; on an existing matrix add only the seeded
	 * actions it lacks, never touching an entry it already has.
	 *
	 * WHY AN EXISTING MATRIX IS NOT LEFT ALONE ANY MORE
	 * -------------------------------------------------
	 * The step used to return as soon as the matrix held anything. Every
	 * instance that ran it once kept that first matrix forever, so an action
	 * added to the seed in a later release never arrived, and an unlisted
	 * action is admin-only. That is how `flow.read` locked every non-admin
	 * flow author out of the version history (or#4098).
	 *
	 * An entry already stored is never overwritten, because it may be an
	 * admin's narrowing: only keys absent from the stored matrix are added.
	 *
	 * @param IOutput $output Repair output channel.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @spec openspec/changes/apphost-boilerplate-controllers/tasks.md#task-2.2
	 * @spec openspec/specs/flow-engine/spec.md#requirement-creating-editing-and-running-a-flow-are-named-rights
	 */
	public function run(IOutput $output): void {
		$existing = $this->actionAuth->getMatrix();

		$actions = $this->readSeedActions(output: $output);
		if ($actions === null) {
			return;
		}

		$missing = array_diff_key($actions, $existing);
		if (count($existing) > 0 && count($missing) === 0) {
			$output->info(sprintf('Action matrix already has %d entries — preserving.', count($existing)));
			return;
		}

		if ($this->writeMatrix(matrix: array_merge($existing, $missing), output: $output) === true) {
			$output->info(self::seedReport(existing: $existing, missing: $missing));
		}
	}//end run()

	/**
	 * Store the matrix, reporting a failure.
	 *
	 * @param array<string, array<int, string>> $matrix The matrix to store.
	 * @param IOutput                           $output Repair output channel.
	 *
	 * @return bool Whether it was stored.
	 */
	private function writeMatrix(array $matrix, IOutput $output): bool {
		try {
			$this->actionAuth->setMatrix($matrix);
			return true;
		} catch (\JsonException $e) {
			$output->warning('Failed to write matrix: ' . $e->getMessage());
			return false;
		}
	}//end writeMatrix()

	/**
	 * What a seeding reports: the actions an existing matrix gained, or the size of a fresh one.
	 *
	 * On an empty matrix every seeded action is missing, so the missing set is the seed.
	 *
	 * @param array<string, array<int, string>> $existing The matrix before seeding.
	 * @param array<string, array<int, string>> $missing  The seeded actions it lacked.
	 *
	 * @return string
	 */
	private static function seedReport(array $existing, array $missing): string {
		if (count($existing) > 0) {
			return sprintf(
				'Action matrix kept its %d entries and gained %d seeded actions: %s.',
				count($existing),
				count($missing),
				implode(', ', array_keys($missing))
			);
		}

		return sprintf('Seeded action matrix with %d actions (default: admin-only).', count($missing));
	}//end seedReport()

	/**
	 * Read the `actions` map from the leaf app's seed file.
	 *
	 * @param IOutput $output Repair output channel.
	 *
	 * @return array<string, array<int, string>>|null The seeded actions, or null when the seed is missing or unreadable.
	 */
	private function readSeedActions(IOutput $output): ?array {
		$raw = $this->readSeed(output: $output);
		if ($raw === null) {
			return null;
		}

		return $this->seededActions(raw: $raw, output: $output);
	}//end readSeedActions()

	/**
	 * The raw content of the leaf app's seed file; null, reported, when it is missing or unreadable.
	 *
	 * @param IOutput $output Repair output channel.
	 *
	 * @return string|null
	 */
	private function readSeed(IOutput $output): ?string {
		$seedPath = $this->resolveSeedPath();
		if ($seedPath === null || file_exists($seedPath) === false) {
			$output->warning('actions.seed.json not found — matrix left unchanged (default-deny).');
			$this->logger->warning(sprintf('[AppHost:%s] ADR-023 seed file missing', $this->appId));
			return null;
		}

		$raw = file_get_contents($seedPath);
		if ($raw === false) {
			$output->warning('Could not read actions.seed.json — matrix left unchanged (default-deny).');
			return null;
		}

		return $raw;
	}//end readSeed()

	/**
	 * The `actions` map of the seed; null, reported, when it is not valid JSON or has none.
	 *
	 * @param string  $raw    The seed file content.
	 * @param IOutput $output Repair output channel.
	 *
	 * @return array<string, array<int, string>>|null
	 */
	private function seededActions(string $raw, IOutput $output): ?array {
		try {
			$parsed = json_decode($raw, associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);
		} catch (\JsonException $e) {
			$output->warning('actions.seed.json invalid JSON: ' . $e->getMessage());
			$this->logger->error(sprintf('[AppHost:%s] ADR-023 seed malformed: %s', $this->appId, $e->getMessage()));
			return null;
		}

		$actions = ($parsed['actions'] ?? null);
		if (is_array($actions) === false) {
			$output->warning('actions.seed.json missing `actions` object — matrix left unchanged.');
			return null;
		}

		return $actions;
	}//end seededActions()

	/**
	 * Resolve the leaf app's `lib/actions.seed.json` path. Overridable hook.
	 *
	 * @return string|null Absolute path, or null when the app path is unresolvable.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	protected function resolveSeedPath(): ?string {
		try {
			$appPath = $this->appManager->getAppPath(appId: $this->appId);
		} catch (Throwable $e) {
			return null;
		}

		return $appPath . '/lib/actions.seed.json';
	}//end resolveSeedPath()
}//end class
