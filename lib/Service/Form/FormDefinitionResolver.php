<?php

/**
 * Resolves a form stored in OpenRegister to what a submit needs: its writes and its audience.
 *
 * The forms register of ADR-085 is not built yet; today a form lives in an
 * app's own schema (buildiq's registration form, portaliq's binding). Any
 * OpenRegister object can be a form when its body carries:
 *
 *   status: "published" (or published: true)
 *   destination: { register, schema }   or   writes: [ { as, register, schema, mapping? } ]
 *   mapping?: { fields, fixed }
 *   audience?: "public" (default) | "authenticated"
 *
 * Callers holding their form elsewhere skip this class and call
 * FormSubmitService with the resolved destination (ADR-117 D3).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Form
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-submit-must-create-the-destination-in-one-request-and-return-its-reference
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Form;

use OCA\OpenRegister\Exception\FormSubmitRefusedException;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IL10N;
use Throwable;

/**
 * A published form's writes and audience, or one identical 404 for everything else.
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-submit-must-create-the-destination-in-one-request-and-return-its-reference
 */
class FormDefinitionResolver {

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objects Reads the form object.
	 * @param IL10N         $l10n    Translations.
	 */
	public function __construct(
		private readonly ObjectService $objects,
		private readonly IL10N $l10n,
	) {

	}//end __construct()

	/**
	 * The form's id, writes and audience.
	 *
	 * Unknown, unpublished and destination-less forms answer the same 404, so
	 * the endpoint is not an oracle for which ids exist.
	 *
	 * @param string $formId The form object's uuid.
	 *
	 * @return array{id: string, writes: array<int, array<string, mixed>>, audience: string} The form.
	 *
	 * @throws FormSubmitRefusedException 404.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-submit-must-create-the-destination-in-one-request-and-return-its-reference
	 */
	public function resolve(string $formId): array {
		try {
			// RBAC off to READ the form definition only: a public form is read
			// by a visitor with no account. Nothing of the body is returned;
			// what may be WRITTEN is decided by the destination's RBAC.
			$entity = $this->objects->find(id: $formId, _rbac: false, _multitenancy: false, _audit: false);
		} catch (Throwable) {
			$entity = null;
		}

		$body = [];
		if ($entity !== null) {
			$body = $entity->getObject();
		}

		$published = (($body['status'] ?? null) === 'published' || ($body['published'] ?? null) === true);
		$writes = $this->writesOf(body: $body);
		if ($published === false || $writes === []) {
			throw new FormSubmitRefusedException(message: $this->l10n->t('This form does not exist.'), status: 404);
		}

		$audience = (string)($body['audience'] ?? 'public');
		if ($audience === '') {
			$audience = 'public';
		}

		return ['id' => $formId, 'writes' => $writes, 'audience' => $audience];
	}//end resolve()

	/**
	 * The writes a form body declares: its `writes` list, or one write from `destination` and `mapping`.
	 *
	 * @param array<string, mixed> $body The form body.
	 *
	 * @return array<int, array<string, mixed>> The writes; empty when none is declared.
	 */
	private function writesOf(array $body): array {
		if (is_array($body['writes'] ?? null) === true && $body['writes'] !== []) {
			return array_values(array_filter($body['writes'], 'is_array'));
		}

		$destination = ($body['destination'] ?? null);
		if (is_array($destination) === false || trim((string)($destination['schema'] ?? '')) === '') {
			return [];
		}

		$mapping = null;
		if (is_array($body['mapping'] ?? null) === true) {
			$mapping = $body['mapping'];
		}

		return [
			[
				'register' => ($destination['register'] ?? null),
				'schema' => $destination['schema'],
				'mapping' => $mapping,
				'as' => 'destination',
			],
		];
	}//end writesOf()
}//end class
