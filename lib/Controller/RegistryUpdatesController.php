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
			$result = $this->subscriptions->applyInboundUpdate(
				registry: $registry,
				identityValue: $this->requiredIdentity(data: $data),
				properties: $this->requiredProperties(data: $data),
				eventReference: (string)($data['eventReference'] ?? ''),
			);
			$this->assertNotAllRejected(result: $result);

			return $this->respondToResult(registry: $registry, result: $result);
		} catch (Throwable $e) {
			return $this->handleApiException(e: $e, context: 'registry-inbound-update');
		}
	}//end update()

	/**
	 * The pushed identity value, which must not be empty.
	 *
	 * @param array<string, mixed> $data The request parameters.
	 *
	 * @return string The identity value.
	 *
	 * @throws InvalidArgumentException When the identity is missing.
	 */
	private function requiredIdentity(array $data): string {
		$identity = (string)($data['identity'] ?? '');
		if ($identity === '') {
			throw new InvalidArgumentException(message: 'identity is required.');
		}

		return $identity;
	}//end requiredIdentity()

	/**
	 * The pushed properties, which must be an object.
	 *
	 * @param array<string, mixed> $data The request parameters.
	 *
	 * @return array<string, mixed> The properties.
	 *
	 * @throws InvalidArgumentException When the properties are not an object.
	 */
	private function requiredProperties(array $data): array {
		$properties = $data['properties'] ?? [];
		if (is_array($properties) === false) {
			throw new InvalidArgumentException(message: 'properties must be an object.');
		}

		return $properties;
	}//end requiredProperties()

	/**
	 * Refuse an update of which every matched object rejected every property.
	 *
	 * @param array<string, mixed> $result The service result.
	 *
	 * @return void
	 *
	 * @throws ValidationException When nothing was applied and something was rejected.
	 */
	private function assertNotAllRejected(array $result): void {
		if (count($result['applied']) > 0 || count($result['rejected']) === 0) {
			return;
		}

		$rejectedProperties = [];
		foreach ($result['rejected'] as $rejection) {
			$rejectedProperties = array_merge($rejectedProperties, $rejection['properties']);
		}

		throw new ValidationException(
			message: 'Property not owned by the registry: ' . implode(', ', array_unique($rejectedProperties))
		);
	}//end assertNotAllRejected()

	/**
	 * The 404 for an unsubscribed identity, or the result itself.
	 *
	 * @param string $registry The registry id.
	 * @param array<string, mixed> $result The service result.
	 *
	 * @return JSONResponse The response.
	 */
	private function respondToResult(string $registry, array $result): JSONResponse {
		if ($result['matched'] === 0) {
			return $this->notSubscribed(registry: $registry);
		}

		$this->logger?->info(
			'[OpenRegister.RegistryUpdatesController] Applied inbound update for registry ' . $registry
			. ' to ' . count($result['applied']) . ' object(s).'
		);

		return new JSONResponse(data: $result);
	}//end respondToResult()

	/**
	 * The 404 for an identity no object is subscribed to.
	 *
	 * @param string $registry The registry id.
	 *
	 * @return JSONResponse The 404 response.
	 */
	private function notSubscribed(string $registry): JSONResponse {
		$this->logger?->warning(
			'[OpenRegister.RegistryUpdatesController] No subscription found for registry ' . $registry
		);

		return new JSONResponse(
			data: ['message' => 'No object is subscribed to this registry/identity.'],
			statusCode: Http::STATUS_NOT_FOUND
		);
	}//end notSubscribed()
}//end class
