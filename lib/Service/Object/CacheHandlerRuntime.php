<?php

/**
 * The platform services CacheHandler runs on
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCP\AppFramework\IAppContainer;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;

/**
 * The platform services CacheHandler runs on: logging, the distributed cache
 * factory, and the container it loads the object mapper from lazily.
 */
final class CacheHandlerRuntime {

	/**
	 * @param LoggerInterface    $logger       Logger for performance monitoring
	 * @param ICacheFactory|null $cacheFactory Cache factory for query result caching
	 * @param IAppContainer|null $container    Container for lazy loading dependencies
	 */
	public function __construct(
		public readonly LoggerInterface $logger,
		public readonly ?ICacheFactory $cacheFactory = null,
		public readonly ?IAppContainer $container = null,
	) {
	}//end __construct()
}//end class
