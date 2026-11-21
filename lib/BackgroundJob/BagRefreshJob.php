<?php

/**
 * OpenRegister BAG refresh job
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  BackgroundJob
 * @package   OCA\OpenRegister\BackgroundJob
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use OCA\OpenRegister\Command\BagLoadCommand;
use OCA\OpenRegister\Service\Bag\BagBulkLoader;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Refreshes the bag register from the configured extract once a month.
 *
 * Off by default: it runs only while the app config `bag_bulk_enabled` is
 * true and `bag_bulk_url` names an extract. A failed load is logged, not thrown.
 *
 * @spec openspec/changes/bag-address-lookup-by-postcode/specs/bag-address-lookup/spec.md#requirement-the-bag-register-holds-the-national-address-set-req-bag-001
 */
class BagRefreshJob extends TimedJob {

	/**
	 * The app config key that switches the refresh on.
	 *
	 * @var string
	 */
	public const CONFIG_ENABLED = 'bag_bulk_enabled';

	/**
	 * Thirty days.
	 *
	 * @var int
	 */
	private const INTERVAL_SECONDS = 2592000;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory       $time      The scheduler clock.
	 * @param IAppConfig         $appConfig The switch and the source.
	 * @param ContainerInterface $container Resolves the loader when the job runs.
	 * @param LoggerInterface    $logger    Records the outcome.
	 *
	 * @spec openspec/changes/bag-address-lookup-by-postcode/specs/bag-address-lookup/spec.md#requirement-the-bag-register-holds-the-national-address-set-req-bag-001
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly IAppConfig $appConfig,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL_SECONDS);
		$this->setTimeSensitivity(sensitivity: IJob::TIME_INSENSITIVE);
	}//end __construct()

	/**
	 * Load the configured extract, if the refresh is on.
	 *
	 * @param mixed $argument Unused for a recurring job.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/changes/bag-address-lookup-by-postcode/specs/bag-address-lookup/spec.md#requirement-the-bag-register-holds-the-national-address-set-req-bag-001
	 */
	protected function run($argument): void {
		if ($this->appConfig->getValueBool('openregister', self::CONFIG_ENABLED, false) === false) {
			return;
		}

		$source = trim($this->appConfig->getValueString('openregister', BagLoadCommand::CONFIG_URL, ''));
		if ($source === '') {
			$this->logger->warning(message: '[BagRefreshJob] bag_bulk_enabled is on but bag_bulk_url is empty; nothing loaded');
			return;
		}

		try {
			$counts = $this->container->get(BagBulkLoader::class)->loadFrom(source: $source);
			$this->logger->info(message: '[BagRefreshJob] BAG extract loaded', context: $counts);
		} catch (Throwable $e) {
			$this->logger->error(message: '[BagRefreshJob] BAG extract load failed: ' . $e->getMessage());
		}
	}//end run()
}//end class
