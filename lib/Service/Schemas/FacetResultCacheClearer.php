<?php

/**
 * FacetResultCacheClearer: empties the caches that hold computed facet results.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Schemas
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Schemas;

use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;

/**
 * Clears the facet response cache and the facet label cache.
 *
 * FacetResponseCache and MagicFacetHandler read these namespaces. Object writes
 * make stale entries unreachable through FacetCacheVersion; a schema change and
 * the admin cache clear empty them through this class, because entries are keyed
 * by query hash and cannot be selected by schema.
 */
class FacetResultCacheClearer implements FacetResultCacheClearerInterface {

	/**
	 * The namespaces holding computed facet results.
	 *
	 * @var list<string>
	 */
	public const CACHE_NAMES = ['openregister_facets', 'openregister_facet_labels'];

	/**
	 * Constructor.
	 *
	 * @param ICacheFactory   $cacheFactory Distributed and local caches.
	 * @param LoggerInterface $logger       Logger.
	 */
	public function __construct(
		private readonly ICacheFactory $cacheFactory,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Empty every facet result namespace, distributed first, local when there is no distributed cache.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function clearAll(): void {
		foreach (self::CACHE_NAMES as $cacheName) {
			$this->clearNamespace(cacheName: $cacheName);
		}

		$this->logger->debug(
			message: '[FacetResultCacheClearer] Facet result caches cleared',
			context: ['file' => __FILE__, 'line' => __LINE__, 'caches' => self::CACHE_NAMES]
		);
	}//end clearAll()

	/**
	 * Empty one namespace; nothing to do when no cache is available.
	 *
	 * @param string $cacheName The namespace.
	 *
	 * @return void
	 */
	private function clearNamespace(string $cacheName): void {
		try {
			$this->cacheFactory->createDistributed($cacheName)->clear();
		} catch (\Exception $e) {
			try {
				$this->cacheFactory->createLocal($cacheName)->clear();
			} catch (\Exception $e) {
				return;
			}
		}
	}//end clearNamespace()
}//end class
