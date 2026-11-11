<?php

/**
 * OpenRegister Form Payload Mapper
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Form
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Form;

use OCA\OpenRegister\Exception\FormSubmitRefusedException;

/**
 * Maps a submitted payload onto the object a write stores, and redeems the upload tokens it carries.
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-submit-must-create-the-destination-in-one-request-and-return-its-reference
 */
final readonly class FormPayloadMapper {

	/**
	 * Constructor.
	 *
	 * @param FormUploadStore $uploads Holds and releases upload tokens.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-submit-must-create-the-destination-in-one-request-and-return-its-reference
	 */
	public function __construct(
		private FormUploadStore $uploads,
	) {
	}//end __construct()

	/**
	 * The object a mapping makes of a payload, plus the uploads it claims.
	 *
	 * Only mapped fields reach the object: the submitter does not choose which
	 * properties a form writes. Without a mapping the payload is the object,
	 * minus `_` control keys, `@` metadata and the `id` / `uuid` that would
	 * name an existing object, as the objects endpoint does.
	 *
	 * @param array<string, mixed>|null $mapping    The mapping.
	 * @param FormSubmission            $submission The submit: its payload, form id and draft.
	 *
	 * @return array{0: array<string, mixed>, 1: array<string, array<string, mixed>>, 2: array<int, string>} Object, uploads, tokens.
	 *
	 * @throws FormSubmitRefusedException 422 for an unknown, expired or misdirected upload token.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-submit-must-create-the-destination-in-one-request-and-return-its-reference
	 */
	public function mapPayload(?array $mapping, FormSubmission $submission): array {
		[$object, $uploads] = $this->splitUploads(pairs: $this->pairsOf(mapping: $mapping, payload: $submission->payload));
		[$files, $tokens] = $this->redeemAll(uploads: $uploads, submission: $submission);
		$object = $this->withFixed(object: $object, mapping: $mapping);

		return [$this->withoutObjectId(object: $object), $files, $tokens];
	}//end mapPayload()

	/**
	 * Split mapped pairs into plain values and upload-token values.
	 *
	 * @param array<string, mixed> $pairs Property => value.
	 *
	 * @return array{0: array<string, mixed>, 1: array<string, mixed>} The plain values, the token values.
	 */
	private function splitUploads(array $pairs): array {
		$object = [];
		$uploads = [];
		foreach ($pairs as $property => $value) {
			if ($this->tokenOf(value: $value) === null) {
				$object[$property] = $value;
				continue;
			}

			$uploads[$property] = $value;
		}

		return [$object, $uploads];
	}//end splitUploads()

	/**
	 * Redeem every upload token: the files and the tokens they came with.
	 *
	 * A token this very draft already claimed (the autosave before this one,
	 * or the draft the final submit leaves) names a file the draft holds: the
	 * property is left as the draft stores it.
	 *
	 * @param array<string, mixed> $uploads    Property => `{ uploadToken }`.
	 * @param FormSubmission       $submission The submit: its form id and draft.
	 *
	 * @return array{0: array<string, array<string, mixed>>, 1: array<int, string>} Files by property, tokens.
	 *
	 * @throws FormSubmitRefusedException 422 for an unknown, expired or misdirected upload token.
	 */
	private function redeemAll(array $uploads, FormSubmission $submission): array {
		$files = [];
		$tokens = [];
		foreach ($uploads as $property => $value) {
			$token = (string)$this->tokenOf(value: $value);
			$claim = new FormUploadClaim(formId: $submission->scope, property: (string)$property, draftId: $submission->draftId);
			$file = $this->uploads->redeem(claim: $claim, token: $token);
			if ($file !== null) {
				$files[$property] = $file;
				$tokens[] = $token;
			}
		}

		return [$files, $tokens];
	}//end redeemAll()

	/**
	 * The object with the mapping's fixed values set.
	 *
	 * @param array<string, mixed>      $object  The object.
	 * @param array<string, mixed>|null $mapping The mapping.
	 *
	 * @return array<string, mixed> The object.
	 */
	private function withFixed(array $object, ?array $mapping): array {
		$fixed = ($mapping['fixed'] ?? []);
		if (is_array($fixed) === false) {
			return $object;
		}

		foreach ($fixed as $property => $value) {
			$object[(string)$property] = $value;
		}

		return $object;
	}//end withFixed()

	/**
	 * The object without an id that would name an existing object.
	 *
	 * A submit never chooses the uuid of the object it writes. The save takes
	 * `id` (or `@self.id`) as the uuid of an existing object and updates it, so
	 * a submitter who names one would write over it, with RBAC off when the
	 * owner is a scope value. The only way to name an existing object is the
	 * draft id, which DraftFinder::own() checked.
	 *
	 * @param array<string, mixed> $object The object.
	 *
	 * @return array<string, mixed> The object.
	 */
	private function withoutObjectId(array $object): array {
		unset($object['id']);
		if (is_array($object['@self'] ?? null) === true) {
			unset($object['@self']['id']);
		}

		return $object;
	}//end withoutObjectId()

	/**
	 * Property => value: the mapped fields, or without a mapping the payload minus control keys, metadata and the object's `id` / `uuid`.
	 *
	 * @param array<string, mixed>|null $mapping The mapping.
	 * @param array<string, mixed>      $payload The payload.
	 *
	 * @return array<string, mixed> The pairs.
	 */
	private function pairsOf(?array $mapping, array $payload): array {
		if ($mapping === null) {
			return $this->payloadPairs(payload: $payload);
		}

		return $this->mappedPairs(mapping: $mapping, payload: $payload);
	}//end pairsOf()

	/**
	 * The payload's property keys and their values.
	 *
	 * @param array<string, mixed> $payload The payload.
	 *
	 * @return array<string, mixed> The pairs.
	 */
	private function payloadPairs(array $payload): array {
		$pairs = [];
		foreach ($payload as $name => $value) {
			if ($this->isPropertyKey(name: (string)$name) === true) {
				$pairs[(string)$name] = $value;
			}
		}

		return $pairs;
	}//end payloadPairs()

	/**
	 * The mapped fields the payload carries, keyed by the property they fill.
	 *
	 * @param array<string, mixed> $mapping The mapping.
	 * @param array<string, mixed> $payload The payload.
	 *
	 * @return array<string, mixed> The pairs.
	 */
	private function mappedPairs(array $mapping, array $payload): array {
		$pairs = [];
		foreach ((array)($mapping['fields'] ?? []) as $field) {
			$name = (string)($field['field'] ?? '');
			$property = (string)($field['property'] ?? '');
			if ($property !== '' && array_key_exists($name, $payload) === true) {
				$pairs[$property] = $payload[$name];
			}
		}

		return $pairs;
	}//end mappedPairs()

	/**
	 * Whether a payload key can be a property of the object: not a control key, metadata, or the `id` / `uuid` that name an object.
	 *
	 * @param string $name The payload key.
	 *
	 * @return bool True when it may reach the object.
	 */
	private function isPropertyKey(string $name): bool {
		return str_starts_with($name, '_') === false && str_starts_with($name, '@') === false && in_array($name, ['id', 'uuid'], true) === false;
	}//end isPropertyKey()

	/**
	 * The upload token a payload value carries, or null.
	 *
	 * @param mixed $value The payload value.
	 *
	 * @return string|null The token.
	 */
	private function tokenOf(mixed $value): ?string {
		if (is_array($value) === true && is_string($value['uploadToken'] ?? null) === true && count($value) === 1) {
			return $value['uploadToken'];
		}

		return null;
	}//end tokenOf()
}//end class
