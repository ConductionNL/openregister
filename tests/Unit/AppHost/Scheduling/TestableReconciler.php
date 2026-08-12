<?php

/**
 * AppHost scheduling — test double exposing overridable I/O seams.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\AppHost\Scheduling
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\AppHost\Scheduling;

use OCA\OpenRegister\AppHost\Scheduling\CronScheduleEvaluator;
use OCA\OpenRegister\AppHost\Scheduling\ScheduleActionAllowList;
use OCA\OpenRegister\AppHost\Scheduling\ScheduleManifestLoader;
use OCA\OpenRegister\AppHost\Scheduling\ScheduleReconciler;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Reconciler subclass that swaps ObjectService I/O for in-memory fixtures and
 * records every persisted job as `[data, uuid]` tuples.
 */
class TestableReconciler extends ScheduleReconciler {
	/**
	 * Recorded saveJob() calls, each `[array $data, ?string $uuid]`.
	 *
	 * @var array<int, array{0: array<string, mixed>, 1: string|null}>
	 */
	public array $saved = [];

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OR facade (unused; overridden I/O).
	 * @param ScheduleManifestLoader $manifestLoader On-disk loader.
	 * @param CronScheduleEvaluator $cron Cron evaluator.
	 * @param ScheduleActionAllowList $allowList Allow-list.
	 * @param IUserManager $userManager User manager.
	 * @param LoggerInterface $logger Logger.
	 * @param array<int, array<string, mixed>> $virtual Virtual application fixtures.
	 * @param array<string, array<string, mixed>> $managed Managed-job fixtures keyed by reference.
	 */
	public function __construct(
		ObjectService $objectService,
		ScheduleManifestLoader $manifestLoader,
		CronScheduleEvaluator $cron,
		ScheduleActionAllowList $allowList,
		IUserManager $userManager,
		LoggerInterface $logger,
		private readonly array $virtual,
		private readonly array $managed,
	) {
		parent::__construct($objectService, $manifestLoader, $cron, $allowList, $userManager, $logger);
	}

	protected function loadManagedJobs(): ?array {
		return $this->managed;
	}

	protected function loadVirtualApplications(): array {
		return $this->virtual;
	}

	protected function saveJob(array $data, ?string $uuid): void {
		$this->saved[] = [$data, $uuid];
	}
}//end class
