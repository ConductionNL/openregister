<?php

/**
 * URN capabilities provider.
 *
 * Advertises OpenRegister's RFC 8141 URN identifier surface through the
 * Nextcloud capabilities API (`OCS /cloud/capabilities`). Clients
 * inspect this envelope to discover that the instance:
 *
 *   1. Issues `urn:nl-or:{instance}:{register}:{schema}:{uuid}` URNs.
 *   2. Exposes `/api/urn/resolve`, `/api/urn/lookup`, and
 *      `/api/urn/bulk` endpoints.
 *   3. Reports its current `instance` slug so federation tooling can
 *      build trusted-instance maps without HTTP round-trips.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Capabilities
 * @package  OCA\OpenRegister\Capabilities
 *
 * @author  Conduction Development Team <dev@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Capabilities;

use OCA\OpenRegister\Service\UrnService;
use OCP\Capabilities\ICapability;

/**
 * Surface URN-resolution metadata via the Nextcloud capabilities API.
 *
 * Closes spec requirement "URN capabilities MUST be discoverable via
 * Nextcloud capabilities API" of the urn-resource-addressing change.
 */
class UrnCapability implements ICapability
{
    /**
     * Constructor.
     *
     * @param UrnService $urnService URN identifier service used to read
     *                               the configured instance slug.
     */
    public function __construct(private readonly UrnService $urnService)
    {
    }//end __construct()

    /**
     * Return the URN block under the `openregister` namespace.
     *
     * Shape:
     *
     *   openregister:
     *     urn:
     *       enabled: true
     *       version: '1'
     *       nid: 'nl-or'
     *       instance: '<configured-instance-slug>'
     *       endpoints:
     *         resolve: '/apps/openregister/api/urn/resolve'
     *         lookup:  '/apps/openregister/api/urn/lookup'
     *         bulk:    '/apps/openregister/api/urn/bulk'
     *       features:
     *         bulkResolve:    true
     *         reverseLookup:  true
     *         crossInstance:  false   # federation deferred to v1.1
     *         aliases:        false   # human-readable aliases deferred
     *         versioning:     false   # version-suffix addressing deferred
     *
     * @return array<string, array<string, mixed>>
     */
    public function getCapabilities(): array
    {
        return [
            'openregister' => [
                'urn' => [
                    'enabled'   => true,
                    'version'   => '1',
                    'nid'       => UrnService::DEFAULT_NID,
                    'instance'  => $this->urnService->getInstanceSlug(),
                    'endpoints' => [
                        'resolve' => '/apps/openregister/api/urn/resolve',
                        'lookup'  => '/apps/openregister/api/urn/lookup',
                        'bulk'    => '/apps/openregister/api/urn/bulk',
                    ],
                    'features'  => [
                        'bulkResolve'   => true,
                        'reverseLookup' => true,
                        'crossInstance' => false,
                        'aliases'       => false,
                        'versioning'    => false,
                    ],
                ],
            ],
        ];

    }//end getCapabilities()
}//end class
