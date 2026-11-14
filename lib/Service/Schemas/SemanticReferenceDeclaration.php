<?php

/**
 * A property that references whichever installed schema implements a semantic type.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Schemas
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/cross-app-semantic-references/specs/semantic-schema-references/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Schemas;

use OCA\OpenRegister\Service\JsonLd\JsonLdContextService;

/**
 * `referenceSemanticType` names the canonical type a reference points at
 * (ADR-048), and `referenceSemanticApp` the app expected to provide it.
 *
 * The type is resolved at read time by SemanticTypeResolver, which matches it
 * against absolute IRIs only. A value that is not an absolute IRI therefore
 * never resolves: the field would stay disabled with "no provider" for ever,
 * which reads exactly like the provider app not being installed. So it is
 * refused at save, where the author can still fix it.
 *
 * @spec openspec/changes/cross-app-semantic-references/specs/semantic-schema-references/spec.md
 */
final class SemanticReferenceDeclaration {

	/**
	 * The semantic type key.
	 *
	 * @var string
	 */
	public const ANNOTATION = 'referenceSemanticType';

	/**
	 * The provider app hint key.
	 *
	 * @var string
	 */
	public const APP_HINT = 'referenceSemanticApp';

	/**
	 * The refusal for a type that is not a non-empty string: annotation, path.
	 *
	 * @var string
	 */
	private const MSG_NOT_A_STRING = '\'%s\' at \'%s\' must be an absolute IRI such as https://schema.org/Organization.';

	/**
	 * The refusal for a type that is not an absolute IRI: annotation, path, value.
	 *
	 * @var string
	 */
	private const MSG_NOT_AN_IRI = '\'%s\' at \'%s\' is \'%s\', which is not an absolute IRI, so no schema can ever resolve it. '
		. 'Use the full IRI, such as https://schema.org/Organization.';

	/**
	 * The refusal for an app hint that is not a string: annotation, path.
	 *
	 * @var string
	 */
	private const MSG_BAD_APP = '\'%s\' at \'%s\' must be a string naming an app.';

	/**
	 * Refuse a property whose semantic reference cannot be resolved.
	 *
	 * @param array<string, mixed> $property The property definition.
	 * @param string               $path     Where the property sits, for the message.
	 *
	 * @return void
	 *
	 * @throws SemanticReferenceException When the type is not an absolute IRI or the app hint is not a string.
	 *
	 * @spec openspec/changes/cross-app-semantic-references/specs/semantic-schema-references/spec.md
	 */
	public static function assert(array $property, string $path = ''): void {
		if (array_key_exists(self::APP_HINT, $property) === true
			&& $property[self::APP_HINT] !== null
			&& is_string($property[self::APP_HINT]) === false
		) {
			throw self::refusal(message: sprintf(self::MSG_BAD_APP, self::APP_HINT, $path), key: self::APP_HINT, path: $path);
		}

		if (array_key_exists(self::ANNOTATION, $property) === false || $property[self::ANNOTATION] === null) {
			return;
		}

		$type = $property[self::ANNOTATION];
		if (is_string($type) === false || $type === '') {
			throw self::refusal(message: sprintf(self::MSG_NOT_A_STRING, self::ANNOTATION, $path), key: self::ANNOTATION, path: $path);
		}

		if (JsonLdContextService::isAbsoluteIriValue(value: $type) === false) {
			throw self::refusal(message: sprintf(self::MSG_NOT_AN_IRI, self::ANNOTATION, $path, $type), key: self::ANNOTATION, path: $path);
		}
	}//end assert()

	/**
	 * The refusal, carrying one error a client can point at.
	 *
	 * @param string $message The sentence.
	 * @param string $key     The refused key.
	 * @param string $path    Where the property sits.
	 *
	 * @return SemanticReferenceException The refusal.
	 */
	private static function refusal(string $message, string $key, string $path): SemanticReferenceException {
		return new SemanticReferenceException(
			$message,
			[['code' => 'invalid_semantic_reference', 'key' => $key, 'path' => $path, 'message' => $message]]
		);
	}//end refusal()
}//end class
