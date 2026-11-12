<?php

/**
 * The Nextcloud services the anonymisation backend selection reads.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Anonymisation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Anonymisation;

use OCP\App\IAppManager;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\ICacheFactory;

/**
 * The platform bundle of {@see AnonymisationBackendService}.
 *
 * @spec openspec/specs/pdf-anonymisation/spec.md
 */
final readonly class AnonymisationBackendPlatform {

	/**
	 * Bundle the services.
	 *
	 * @param IAppManager    $appManager    App manager used for ExApp detection.
	 * @param IAppConfig     $appConfig     App configuration store.
	 * @param ICacheFactory  $cacheFactory  Factory for the distributed probe cache.
	 * @param IClientService $clientService HTTP client factory for endpoint probes.
	 *
	 * @spec openspec/specs/pdf-anonymisation/spec.md
	 */
	public function __construct(
		public IAppManager $appManager,
		public IAppConfig $appConfig,
		public ICacheFactory $cacheFactory,
		public IClientService $clientService,
	) {
	}//end __construct()
}//end class
