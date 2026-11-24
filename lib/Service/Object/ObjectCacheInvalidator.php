<?php

/**
 * OpenRegister ObjectCacheInvalidator
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Kernel\Contracts\Core\ContactMatchCacheInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\ObjectPreviewCacheInterface;
use OCA\OpenRegister\Service\DeepLinkResolverInterface;
use OCP\AppFramework\IAppContainer;
use OCP\Collaboration\Reference\IReferenceManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Clears the caches that outlive a save of one object.
 *
 * Every invalidation here is a non-essential side effect: a failure is logged
 * with its context and never fails the save. The caches are reached through
 * Core-owned contracts; contact matching (Processes), the reference provider
 * (Configuration) and the deep-link registry (Integrations) bind their answers
 * in their own wiring.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class ObjectCacheInvalidator {

	/**
	 * Hold the collaborators.
	 *
	 * @param IAppContainer   $container Resolves the services the save path uses lazily.
	 * @param LoggerInterface $logger    Receives the failures.
	 */
	public function __construct(
		private readonly IAppContainer $container,
		private readonly LoggerInterface $logger
	) {
	}//end __construct()

	/**
	 * Clear the contact-match and reference caches of a saved object.
	 *
	 * @param SavedObjectScope $saved The saved object.
	 *
	 * @return void
	 */
	public function afterSave(SavedObjectScope $saved): void {
		$this->clearContactMatches(saved: $saved);
		$this->clearReferencePreviews(saved: $saved);
	}//end afterSave()

	/**
	 * Drop the saved object from the contact-match cache.
	 *
	 * @param SavedObjectScope $saved The saved object.
	 *
	 * @return void
	 */
	private function clearContactMatches(SavedObjectScope $saved): void {
		try {
			$contacts = $this->container->get(ContactMatchCacheInterface::class);
			$contacts->invalidateCacheForObject($saved->object->getObject());
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[ObjectService] Skipped contact-match cache invalidation: invalidation failed',
				context: ['file' => __FILE__, 'line' => __LINE__] + $saved->logContext(failure: $e)
			);
		}
	}//end clearContactMatches()

	/**
	 * Clear the Smart Picker and reference previews of the saved object.
	 *
	 * @param SavedObjectScope $saved The saved object.
	 *
	 * @return void
	 */
	private function clearReferencePreviews(SavedObjectScope $saved): void {
		try {
			$invalidation = new ReferencePreviewInvalidation(
				references: $this->container->get(IReferenceManager::class),
				formatter: $this->container->get(ObjectPreviewCacheInterface::class),
				deepLinks: $this->container->get(DeepLinkResolverInterface::class)
			);
			$invalidation->invalidate(saved: $saved);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[ObjectService] Skipped Smart Picker cache invalidation: invalidation failed',
				context: ['file' => __FILE__, 'line' => __LINE__] + $saved->logContext(failure: $e)
			);
		}
	}//end clearReferencePreviews()
}//end class
