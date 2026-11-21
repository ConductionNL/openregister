<?php

/**
 * OpenRegister SharerAccessSweepJob
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/object-level-sharing-and-private-scope/specs/object-level-sharing/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\File\FolderManagementHandler;
use OCA\OpenRegister\Service\Rbac\SharerFolderAccess;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\Files\NotFoundException;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Hourly: ends every sharer view whose holder may no longer share the object.
 *
 * A change of owner through OpenRegister ends the former owner's view at once.
 * Access also ends by paths OpenRegister does not see, such as a user leaving
 * the admin group or an organisation withdrawing the right to manage grants.
 * This sweep ends those views.
 *
 * @spec openspec/changes/object-level-sharing-and-private-scope/specs/object-level-sharing/spec.md
 */
class SharerAccessSweepJob extends TimedJob {

	/**
	 * Once an hour.
	 *
	 * @var int
	 */
	private const INTERVAL_SECONDS = 3600;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory            $time         Time factory for TimedJob.
	 * @param SharerFolderAccess      $sharerAccess Reads and ends the sharer views.
	 * @param FolderManagementHandler $folders      The openregister account, which gives the views.
	 * @param MagicMapperInterface    $objects      Finds the object a view's folder belongs to.
	 * @param LoggerInterface         $logger       Diagnostics.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly SharerFolderAccess $sharerAccess,
		private readonly FolderManagementHandler $folders,
		private readonly MagicMapperInterface $objects,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL_SECONDS);
		$this->setTimeSensitivity(sensitivity: self::TIME_SENSITIVE);
	}//end __construct()

	/**
	 * Run the sweep. It never throws, so the job is not disabled by one bad run.
	 *
	 * @param mixed $argument The job argument (unused).
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The TimedJob contract; the sweep takes no argument.
	 */
	protected function run($argument): void {
		try {
			$ended = $this->sweep();
			$this->logger->info('[SharerAccessSweepJob] Ended {count} sharer views', ['count' => $ended]);
		} catch (Throwable $e) {
			$this->logger->warning('[SharerAccessSweepJob] Sweep failed: {error}', ['error' => $e->getMessage(), 'exception' => $e]);
		}
	}//end run()

	/**
	 * End every sharer view whose holder may no longer share its object, or whose object is gone.
	 *
	 * @return int How many views were ended.
	 *
	 * @spec openspec/changes/object-level-sharing-and-private-scope/specs/object-level-sharing/spec.md
	 */
	public function sweep(): int {
		$owner = $this->folders->getOpenRegisterUserFolder()->getOwner()?->getUID();
		if ($owner === null) {
			return 0;
		}

		$ended = 0;
		foreach ($this->sharerAccess->allViews(owner: $owner) as $view) {
			try {
				$object = $this->objectOf(view: $view);
			} catch (Throwable $e) {
				// A lookup that failed says nothing about access; the next run tries again.
				continue;
			}

			$ended += $this->sharerAccess->endStaleViews(object: $object, views: [$view]);
		}

		return $ended;
	}//end sweep()

	/**
	 * The object whose folder a view shows, or null when the folder or the object is gone.
	 *
	 * @param IShare $view The view.
	 *
	 * @return ObjectEntity|null
	 *
	 * @throws Throwable When the lookup itself fails.
	 */
	private function objectOf(IShare $view): ?ObjectEntity {
		try {
			$name = $view->getNode()->getName();
		} catch (NotFoundException $e) {
			return null;
		}

		try {
			return $this->objects->find(identifier: $name, _rbac: false, _multitenancy: false);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}//end objectOf()
}//end class
