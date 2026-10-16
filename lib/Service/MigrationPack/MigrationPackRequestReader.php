<?php

/**
 * Reads migration pack definitions, uploads and preview input from a request.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\MigrationPack
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-create-and-update-must-accept-a-definition-envelope-and-keep-the-route-id
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\MigrationPack;

use DateTimeImmutable;
use OCA\OpenRegister\Exception\InvalidPackDefinitionException;
use OCA\OpenRegister\Exception\MigrationPackRequestException;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Turns a MigrationPacksController request into the input its service call needs.
 *
 * Every refusal here happens before a pack is read or written, and is thrown
 * as an exception the controller answers in the shared error shape.
 *
 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-create-and-update-must-accept-a-definition-envelope-and-keep-the-route-id
 */
class MigrationPackRequestReader {
	/**
	 * The most sample rows one preview request may map.
	 *
	 * @var int
	 */
	public const MAX_PREVIEW_ROWS = 20;

	/**
	 * The largest preview input, definition and rows together, as JSON bytes.
	 *
	 * The row count alone does not bound the work: one row can carry any
	 * number of columns, and every row runs through the mapping engine in the
	 * request. 1 MiB holds a large pack with long lookup tables and twenty
	 * wide rows.
	 *
	 * @var int
	 */
	public const MAX_PREVIEW_BYTES = 1048576;

	/**
	 * Constructor.
	 *
	 * @param PackDefinitionValidator $validator Reports what is wrong with a raw body that lost its id.
	 * @param IL10N                   $l10n      Translates the refusals the caller is shown.
	 *
	 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-create-and-update-must-accept-a-definition-envelope-and-keep-the-route-id
	 */
	public function __construct(
		private readonly PackDefinitionValidator $validator,
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * The pack row id from the route, falling back to the dispatched argument.
	 *
	 * The dispatched `$id` is read from the merged parameters, where a body
	 * `id` wins; a pack slug there is cast to 0. The route value does not
	 * have that problem.
	 *
	 * @param IRequest $request      The request.
	 * @param int      $dispatchedId The id the dispatcher passed to the controller.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-create-and-update-must-accept-a-definition-envelope-and-keep-the-route-id
	 */
	public function routeId(IRequest $request, int $dispatchedId): int {
		$routeId = (string)($this->routeParams(request: $request)['id'] ?? '');
		if (ctype_digit($routeId) === true) {
			return (int)$routeId;
		}

		return $dispatchedId;
	}//end routeId()

	/**
	 * Read the pack definition from a create or update request body.
	 *
	 * Accepts the envelope `{"definition": {...}}`, taken as is, or the pack
	 * document as the whole body (the raw form). A body with a top-level
	 * `definition` object is always read as the envelope. In the raw form,
	 * every `_`-prefixed key is dropped, because Nextcloud merges router keys
	 * such as `_route` into the same parameters; a pack that needs such keys
	 * must use the envelope.
	 *
	 * Nextcloud also merges the route `id` into those parameters, so in the
	 * raw form a body `id` equal to the row id in the URL cannot be told apart
	 * from no body `id` at all. That request is refused, with every validation
	 * error of the pack without an id plus a pointer to the envelope, rather
	 * than guessed at.
	 *
	 * @param IRequest $request The request.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws InvalidPackDefinitionException When the raw form's id is the route id.
	 *
	 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-create-and-update-must-accept-a-definition-envelope-and-keep-the-route-id
	 */
	public function definition(IRequest $request): array {
		$params = $request->getParams();
		if (is_array($params['definition'] ?? null) === true) {
			return $params['definition'];
		}

		$definition = array_filter(
			$params,
			static fn ($key): bool => str_starts_with((string)$key, '_') === false,
			ARRAY_FILTER_USE_KEY
		);

		$routeParams = $this->routeParams(request: $request);
		if (array_key_exists('id', $routeParams) === false
			|| array_key_exists('id', $definition) === false
			|| (string)$definition['id'] !== (string)$routeParams['id']
		) {
			return $definition;
		}

		unset($definition['id']);
		$errors   = $this->validator->validate(definition: $definition);
		$errors[] = $this->l10n->t('An "id" in the request body that equals the row id in the URL cannot be told apart from it.')
			. ' '
			. $this->l10n->t('Send the pack as {"definition": {...}} to keep that id.');

		throw new InvalidPackDefinitionException(errors: $errors);
	}//end definition()

	/**
	 * The `updatedAt` an update was based on, from the envelope's `expectedUpdatedAt`.
	 *
	 * An update that names it is refused when the pack was saved since; one
	 * that does not overwrites whatever is stored. Only the envelope carries
	 * it: in the raw form every body key belongs to the pack document.
	 *
	 * @param IRequest $request The request.
	 *
	 * @return DateTimeImmutable|null Null when the request names no version.
	 *
	 * @throws MigrationPackRequestException When it is not a date-time as the API answers `updatedAt`.
	 *
	 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-create-and-update-must-accept-a-definition-envelope-and-keep-the-route-id
	 */
	public function expectedUpdatedAt(IRequest $request): ?DateTimeImmutable {
		$params = $request->getParams();
		if (is_array($params['definition'] ?? null) === false
			|| array_key_exists('expectedUpdatedAt', $params) === false
			|| $params['expectedUpdatedAt'] === null
		) {
			return null;
		}

		$expected = $params['expectedUpdatedAt'];
		$parsed   = false;
		if (is_string($expected) === true) {
			$parsed = DateTimeImmutable::createFromFormat(DATE_ATOM, $expected);
		}

		if ($parsed === false) {
			throw new MigrationPackRequestException(
				errorCode: 'invalid-request',
				userMessage: $this->l10n->t('The expected update time must be the updatedAt of the pack as it was read')
			);
		}

		return $parsed;
	}//end expectedUpdatedAt()

	/**
	 * Read the pack document from the uploaded `file` of an import request.
	 *
	 * @param IRequest $request The request.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws MigrationPackRequestException When no file was uploaded, or it is not a JSON object.
	 *
	 * @spec openspec/specs/migration-mapping-packs/spec.md#migration-packs-must-be-manageable-via-admin-gated-rest-crud-with-json-file-importexport
	 */
	public function uploadedDefinition(IRequest $request): array {
		$uploadedFile = $request->getUploadedFile('file');
		if (is_array($uploadedFile) === false || empty($uploadedFile['tmp_name']) === true) {
			throw new MigrationPackRequestException(
				errorCode: 'invalid-request',
				userMessage: $this->l10n->t('No file uploaded')
			);
		}

		$raw     = file_get_contents($uploadedFile['tmp_name']);
		$decoded = null;
		if ($raw !== false) {
			$decoded = json_decode($raw, true);
		}

		if (is_array($decoded) === false) {
			throw new MigrationPackRequestException(
				errorCode: 'invalid-request',
				userMessage: $this->l10n->t('Uploaded file is not a valid JSON object')
			);
		}

		return $decoded;
	}//end uploadedDefinition()

	/**
	 * Read the definition and sample rows of a preview request.
	 *
	 * Bounded twice: at most MAX_PREVIEW_ROWS rows, and at most
	 * MAX_PREVIEW_BYTES for the definition and rows together.
	 *
	 * @param IRequest $request The request.
	 *
	 * @return array{definition: array<string, mixed>, rows: list<array<string, mixed>>}
	 *
	 * @throws MigrationPackRequestException When the body is malformed, or has too many or too large rows.
	 *
	 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-a-pack-must-be-testable-against-sample-rows-without-saving-it
	 */
	public function previewInput(IRequest $request): array {
		$definition = $request->getParam('definition');
		if (is_array($definition) === false) {
			throw new MigrationPackRequestException(
				errorCode: 'invalid-request',
				userMessage: $this->l10n->t('The request must contain a pack definition object')
			);
		}

		$rows = $this->sampleRows(rows: $request->getParam('rows', []));

		$size = strlen((string)json_encode([$definition, $rows], JSON_PARTIAL_OUTPUT_ON_ERROR));
		if ($size > self::MAX_PREVIEW_BYTES) {
			throw new MigrationPackRequestException(
				errorCode: 'invalid-request',
				userMessage: $this->l10n->t('The preview request is too large')
			);
		}

		return ['definition' => $definition, 'rows' => $rows];
	}//end previewInput()

	/**
	 * Check that the sample rows are a list of at most MAX_PREVIEW_ROWS objects.
	 *
	 * @param mixed $rows The `rows` parameter as sent.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @throws MigrationPackRequestException When the rows are not a list of objects, or too many.
	 *
	 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-a-pack-must-be-testable-against-sample-rows-without-saving-it
	 */
	private function sampleRows(mixed $rows): array {
		if (is_array($rows) === false || array_is_list($rows) === false) {
			throw $this->rowsAreNotAList();
		}

		if (count($rows) > self::MAX_PREVIEW_ROWS) {
			throw new MigrationPackRequestException(
				errorCode: 'too-many-rows',
				userMessage: $this->l10n->t('A preview takes at most %s sample rows', [self::MAX_PREVIEW_ROWS])
			);
		}

		foreach ($rows as $row) {
			if (is_array($row) === false) {
				throw $this->rowsAreNotAList();
			}
		}

		return $rows;
	}//end sampleRows()

	/**
	 * The refusal for sample rows that are not a list of objects.
	 *
	 * @return MigrationPackRequestException
	 *
	 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-a-pack-must-be-testable-against-sample-rows-without-saving-it
	 */
	private function rowsAreNotAList(): MigrationPackRequestException {
		return new MigrationPackRequestException(
			errorCode: 'invalid-request',
			userMessage: $this->l10n->t('Sample rows must be a list of objects')
		);
	}//end rowsAreNotAList()

	/**
	 * The parameters the router extracted from the URL, without the body.
	 *
	 * IRequest declares `urlParams` with `@property-read`; the isset() lets a
	 * request double that does not carry it fall back to no route values.
	 *
	 * @param IRequest $request The request.
	 *
	 * @return array<string, string>
	 *
	 * @psalm-suppress NoInterfaceProperties `urlParams` is a documented read-only property of IRequest.
	 *
	 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-create-and-update-must-accept-a-definition-envelope-and-keep-the-route-id
	 */
	private function routeParams(IRequest $request): array {
		if (isset($request->urlParams) === false) {
			return [];
		}

		return $request->urlParams;
	}//end routeParams()
}//end class
