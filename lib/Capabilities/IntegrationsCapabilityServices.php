<?php

/**
 * OpenRegister integrations capability services
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Capabilities
 * @package  OCA\OpenRegister\Capabilities
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/pluggable-integration-registry/tasks.md#task-21
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Capabilities;

use OCA\OpenRegister\Service\Integration\LeafRegistryInterface;
use OCA\OpenRegister\Kernel\Contracts\SemanticProvidersInterface;
use OCP\App\IAppManager;
use OCP\ICache;
use OCP\L10N\IFactory;

/**
 * What the integrations capability reads besides the registry: which backing
 * apps are installed, and the cross-app leaf catalogue.
 *
 * @spec openspec/changes/pluggable-integration-registry/tasks.md#task-21
 */
final class IntegrationsCapabilityServices {
	/**
	 * Bundle the services.
	 *
	 * @param IAppManager           $appManager   App manager (installed check).
	 * @param LeafRegistryInterface $leafRegistry Cross-app leaf catalogue (ADR-066).
	 * @param SemanticProvidersInterface|null $semanticProviders The cached semantic provider list; null advertises none.
	 * @param ICache|null                     $cache             Keeps each reader's provider rows between page renders; null builds them every time.
	 * @param IFactory|null                   $l10nFactory       The reader's language, part of the cache key (provider labels are translated).
	 *
	 * @spec openspec/changes/pluggable-integration-registry/tasks.md#task-21
	 */
	public function __construct(
		public readonly IAppManager $appManager,
		public readonly LeafRegistryInterface $leafRegistry,
		public readonly ?SemanticProvidersInterface $semanticProviders = null,
		public readonly ?ICache $cache = null,
		public readonly ?IFactory $l10nFactory = null,
	) {
	}//end __construct()
}//end class
