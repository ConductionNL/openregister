<?php

/**
 * Repair step: register declared background jobs that Nextcloud never added.
 *
 * @category Repair
 * @package  OCA\OpenRegister\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Repair;

use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\IJobList;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reconciles `<job>` declarations in appinfo/info.xml against the job list.
 *
 * Nextcloud adds `<job>` entries to `oc_jobs` only when an app is INSTALLED or
 * UPGRADED. Add a job to info.xml without bumping the app version and it is
 * silently never registered — no error, no warning, and there is no
 * `occ background-job:add` to correct it by hand.
 *
 * The failure is invisible in the worst way: the job's synchronous
 * counterparts keep working, so the feature looks healthy while every
 * asynchronous path queues into a void. Observed 2026-07-27 on a live
 * instance where 24 of 31 declared jobs were absent, including
 * `FlowRunWorker` — 59 flow runs sat `queued` and could never execute, while
 * the synchronous `/api/flow-runs/test` route ran flows perfectly. See
 * or#2170.
 *
 * Running as a repair step means `occ maintenance:repair` fixes an instance
 * without anyone having to invent a version bump.
 */
class ReconcileDeclaredBackgroundJobs implements IRepairStep {
	/**
	 * Constructor.
	 *
	 * @param IJobList $jobList The Nextcloud job list.
	 * @param ContainerInterface $container DI container, used to resolve job
	 *                                      classes lazily — a job whose own
	 *                                      dependencies cannot be built must
	 *                                      not take the whole repair run down.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IJobList $jobList,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Human-readable step name surfaced in occ + the admin UI.
	 *
	 * @return string
	 */
	public function getName(): string {
		return 'Register OpenRegister background jobs declared in info.xml but missing from the job list';
	}//end getName()

	/**
	 * Add every declared job that is not already registered.
	 *
	 * Idempotent: `IJobList::has()` is checked first, and `add()` is itself a
	 * no-op for an already-present job/argument pair.
	 *
	 * @param IOutput $output Migration output handle.
	 *
	 * @return void
	 */
	public function run(IOutput $output): void {
		$declared = $this->declaredJobs();
		if ($declared === []) {
			$output->info('[OpenRegister] No <job> declarations found — nothing to reconcile.');
			return;
		}

		$added = 0;
		$skipped = 0;

		foreach ($declared as $class) {
			try {
				if ($this->jobList->has($class, null) === true) {
					continue;
				}

				// Resolve through the container so a job with unbuildable
				// dependencies fails here, named, rather than at cron time.
				$job = $this->container->get($class);
				if (($job instanceof IJob) === false) {
					$output->warning('[OpenRegister] ' . $class . ' is declared as a job but is not an IJob — skipped.');
					$skipped++;
					continue;
				}

				$this->jobList->add($class);
				$output->info('[OpenRegister] Registered missing background job: ' . $class);
				$added++;
			} catch (Throwable $e) {
				// One unresolvable job must not abort the repair run: the
				// whole point of this step is to recover an instance.
				$output->warning('[OpenRegister] Could not register ' . $class . ': ' . $e->getMessage());
				$this->logger->warning(
					'[OpenRegister] Background-job reconciliation skipped ' . $class,
					['exception' => $e]
				);
				$skipped++;
			}//end try
		}//end foreach

		if ($added === 0 && $skipped === 0) {
			$output->info('[OpenRegister] All ' . count($declared) . ' declared background jobs are already registered.');
			return;
		}

		$output->info(
			'[OpenRegister] Background jobs reconciled: ' . $added . ' added, '
			. $skipped . ' skipped, ' . count($declared) . ' declared.'
		);
	}//end run()

	/**
	 * Read the `<job>` class names out of appinfo/info.xml.
	 *
	 * Parsed from the manifest rather than hard-coded so the list cannot drift
	 * from the declaration this step exists to honour.
	 *
	 * @return array<int,string> Fully-qualified job class names.
	 */
	private function declaredJobs(): array {
		$path = dirname(__DIR__, 2) . '/appinfo/info.xml';
		if (file_exists($path) === false) {
			return [];
		}

		// Read the file ourselves and parse a STRING. simplexml_load_file()
		// routes file access through libxml's external-entity loader, and
		// Nextcloud installs a restrictive loader at boot — so loading by path
		// silently returns false inside the NC runtime while working perfectly
		// in a bare PHP process. That mismatch made this step report
		// "no <job> declarations found" against an info.xml holding 31 of them.
		$contents = file_get_contents($path);
		if ($contents === false) {
			return [];
		}

		$previous = libxml_use_internal_errors(true);
		$xml = simplexml_load_string($contents);
		libxml_use_internal_errors($previous);

		// NOTE: the element is `background-jobs` with a HYPHEN, so it cannot be
		// reached as `$xml->background_jobs` — SimpleXML would silently return
		// nothing and this step would cheerfully report "nothing to reconcile"
		// while every job stayed unregistered. Braced access is required.
		if ($xml === false || isset($xml->{'background-jobs'}) === false) {
			return [];
		}

		$classes = [];
		foreach ($xml->{'background-jobs'}->job as $job) {
			$class = trim((string)$job);
			if ($class !== '') {
				$classes[] = $class;
			}
		}

		return $classes;
	}//end declaredJobs()
}//end class
