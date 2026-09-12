<?php

/**
 * OpenRegister RegistryUpdatesController
 *
 * The inbound half of `registry-subscriptions` (finding B22): a connector
 * app (integriq) posts a registry's own change here after
 * `RegistrySubscriptionRequestedEvent` turned into a live subscription.
 * Authentication is the connector's Nextcloud app-password account
 * (`registry-subscriptions` design D-3) — this is a plain `#[NoAdminRequired]`
 * endpoint, not `#[PublicPage]`; NC core rejects an unauthenticated request
 * before this method runs.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/registry-subscriptions/specs/registry-subscriptions/spec.md#requirement-an-inbound-registry-update-writes-owned-properties-only
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use InvalidArgumentException;
use OCA\OpenRegister\Controller\Trait\HandlesExceptionsTrait;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Service\Registry\RegistrySubscriptionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Applies an inbound registry update to every object subscribed to it.
 */
class RegistryUpdatesController extends Controller {

	use HandlesExceptionsTrait;

	/**
	 * Constructor.
	 *
	 * @param string $appName The app id.
	 * @param IRequest $request The request.
	 * @param RegistrySubscriptionService $subscriptions Applies the update.
	 * @param LoggerInterface|null $logger Consumed by HandlesExceptionsTrait for server-side 500 logging.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly RegistrySubscriptionService $subscriptions,
		private readonly ?LoggerInterface $logger = null,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Apply an inbound update for one registry.
	 *
	 * Body: `{identity: string, properties: object, eventReference: string}`.
	 * `identity` is the BSN/KvK-number/... value the registry pushed a
	 * change for, matched against every object's stored `identityValue` for
	 * this `$registry` — not the property NAME, which is per-schema and
	 * irrelevant here.
	 *
	 * @param string $registry The registry id (`brp`, `kvk`, ...) from the route.
	 *
	 * @return JSONResponse Which objects were updated, and why any were refused.
	 *
	 * @spec openspec/changes/registry-subscriptions/specs/registry-subscriptions/spec.md#requirement-an-inbound-registry-update-writes-owned-properties-only
	 */
	#[NoAdminRequired]
	public function update(string $registry): JSONResponse {
		try {
			$data = $this->request->getParams();
			$identity = (string)($data['identity'] ?? '');
			$properties = $data['properties'] ?? [];
			$eventReference = (string)($data['eventReference'] ?? '');

			if ($identity === '') {
				throw new InvalidArgumentException(message: 'identity is required.');
			}
			if (is_array($properties) === false) {
				throw new InvalidArgumentException(message: 'properties must be an object.');
			}

			$result = $this->subscriptions->applyInboundUpdate(
				registry: $registry,
				identityValue: $identity,
				properties: $properties,
				eventReference: $eventReference,
			);

			if (count($result['applied']) === 0 && count($result['rejected']) > 0) {
				$rejectedProperties = [];
				foreach ($result['rejected'] as $rejection) {
					$rejectedProperties = array_merge($rejectedProperties, $rejection['properties']);
				}

				throw new ValidationException(
					message: 'Property not owned by the registry: ' . implode(', ', array_unique($rejectedProperties))
				);
			}

			if ($result['matched'] === 0) {
				$this->logger?->warning(
					'[OpenRegister.RegistryUpdatesController] No subscription found for registry ' . $registry
				);

				return new JSONResponse(
					data: ['message' => 'No object is subscribed to this registry/identity.'],
					statusCode: Http::STATUS_NOT_FOUND
				);
			}

			$this->logger?->info(
				'[OpenRegister.RegistryUpdatesController] Applied inbound update for registry ' . $registry
				. ' to ' . count($result['applied']) . ' object(s).'
			);

			return new JSONResponse(data: $result);
		} catch (Throwable $e) {
			return $this->handleApiException(e: $e, context: 'registry-inbound-update');
		}
	}//end update()
}//end class
