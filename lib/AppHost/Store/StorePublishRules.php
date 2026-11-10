<?php

/**
 * OpenRegister AppHost — Store publish rules
 *
 * The pure half of the store plane's publish: which body may leave this server,
 * which outcome a registry's answer maps to, and whether an answer is an object
 * at all. No I/O and no logging, so every rule can be asserted without a client,
 * and GenericStoreService keeps only the transport and the reporting.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Store
 * @package  OCA\OpenRegister\AppHost\Store
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/apphost-store-plane/spec.md#requirement-a-publish-must-send-only-allowed-fields-and-never-an-identity-key
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost\Store;

use OCA\OpenRegister\AppHost\Service\GenericStoreService;
use OCA\OpenRegister\AppHost\Service\StoreDescriptor;

/**
 * Body, status and answer rules for a store publish.
 *
 * @spec openspec/specs/apphost-store-plane/spec.md#requirement-a-publish-must-send-only-allowed-fields-and-never-an-identity-key
 */
final class StorePublishRules {
	/**
	 * The slug shape a published object must carry.
	 *
	 * The same pattern GenericStoreController::install() accepts, so whatever
	 * is published can later be resolved and installed.
	 */
	public const SLUG_PATTERN = '/^[a-z0-9][a-z0-9-]*[a-z0-9]$/';

	/**
	 * Keys that name a target object and therefore never travel.
	 *
	 * The registry's objects API resolves its write target FROM the payload,
	 * so a body carrying the id of an object that already lives there would
	 * replace it instead of creating one. Stripped even when a descriptor
	 * lists them, because the allowlist governs which fields may leave, never
	 * whether the write creates or replaces.
	 */
	public const IDENTITY_KEYS = ['id', 'uuid', '@self'];

	/**
	 * The body a publish sends: the slug plus the descriptor's allowed fields.
	 *
	 * @param StoreDescriptor      $descriptor The calling app's store parameters.
	 * @param array<string, mixed> $payload    The caller's object.
	 *
	 * @return array<string, mixed>|null The body, or null when the payload has no valid slug.
	 *
	 * @spec openspec/specs/apphost-store-plane/spec.md#requirement-a-publish-must-send-only-allowed-fields-and-never-an-identity-key
	 */
	public function body(StoreDescriptor $descriptor, array $payload): ?array {
		$slug = ($payload['slug'] ?? null);
		if (is_string($slug) === false || preg_match(self::SLUG_PATTERN, $slug) !== 1) {
			return null;
		}

		$body = ['slug' => $slug];
		foreach ($descriptor->publishFields as $field) {
			$field = (string)$field;
			if ($field === 'slug' || in_array($field, self::IDENTITY_KEYS, true) === true) {
				continue;
			}

			if (array_key_exists($field, $payload) === true) {
				$body[$field] = $payload[$field];
			}
		}

		return $body;
	}//end body()

	/**
	 * The publish body as JSON, or null when nothing may be sent.
	 *
	 * @param StoreDescriptor      $descriptor The calling app's store parameters.
	 * @param array<string, mixed> $payload    The caller's object.
	 *
	 * @return string|null The JSON body, or null when the payload has no valid slug or does not encode.
	 *
	 * @spec openspec/specs/apphost-store-plane/spec.md#requirement-a-publish-must-send-only-allowed-fields-and-never-an-identity-key
	 */
	public function encodedBody(StoreDescriptor $descriptor, array $payload): ?string {
		$body = $this->body(descriptor: $descriptor, payload: $payload);
		if ($body === null) {
			return null;
		}

		$json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		if ($json === false) {
			return null;
		}

		return $json;
	}//end encodedBody()

	/**
	 * Whether a status means the registry accepted the publish.
	 *
	 * @param int $status The HTTP status.
	 *
	 * @return bool True for any 2xx.
	 *
	 * @spec openspec/specs/apphost-store-plane/spec.md#requirement-publish-failures-must-map-to-generic-outcomes-that-name-the-remedy
	 */
	public function isSuccess(int $status): bool {
		return $status >= 200 && $status < 300;
	}//end isSuccess()

	/**
	 * The outcome for a non-2xx answer to a publish.
	 *
	 * A 4xx means the registry answered and refused this object, which is a
	 * different remedy from a registry that is down; 429 is its own outcome,
	 * as it is for discovery.
	 *
	 * @param int $status The HTTP status.
	 *
	 * @return string One of the GenericStoreService outcome constants.
	 *
	 * @spec openspec/specs/apphost-store-plane/spec.md#requirement-publish-failures-must-map-to-generic-outcomes-that-name-the-remedy
	 */
	public function failureOutcome(int $status): string {
		if ($status === 429) {
			return GenericStoreService::OUTCOME_RATE_LIMITED;
		}

		if ($status >= 400 && $status < 500) {
			return GenericStoreService::OUTCOME_REJECTED;
		}

		return GenericStoreService::OUTCOME_UNREACHABLE;
	}//end failureOutcome()

	/**
	 * Decode a registry's answer to a publish into the stored object.
	 *
	 * @param string $body The raw response body.
	 *
	 * @return array<string, mixed>|null The object, or null when the body is not a JSON object.
	 *
	 * @spec openspec/specs/apphost-store-plane/spec.md#requirement-a-publish-must-verify-the-slug-the-registry-stored
	 */
	public function storedObject(string $body): ?array {
		$decoded = json_decode($body, true);
		if (is_array($decoded) === false || array_is_list($decoded) === true) {
			return null;
		}

		return $decoded;
	}//end storedObject()
}//end class
