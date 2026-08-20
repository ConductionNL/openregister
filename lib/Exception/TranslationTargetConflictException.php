<?php

/**
 * OpenRegister TranslationTargetConflictException
 *
 * Raised when a write request body sends a full language-keyed object
 * for a translatable property AND the request also carries the
 * `X-Translation-Target-Language` header. The two surfaces represent
 * conflicting intent (full-shape body vs target-language wrap); the
 * middleware fails fast with a structured 400 so consumers can fix the
 * call.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/i18n-api-language-negotiation/tasks.md#phase-2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

/**
 * Thrown by `TranslationHandler::normalizeTranslationsForSave` when a
 * language-keyed body collides with an `X-Translation-Target-Language`
 * header on the same request.
 *
 * Controllers catch this and return `400 Bad Request` with a body of:
 *
 * ```json
 * {
 *   "error": {
 *     "code": "TRANSLATION_TARGET_CONFLICT",
 *     "property": "title",
 *     "targetLanguage": "en"
 *   }
 * }
 * ```
 */
class TranslationTargetConflictException extends CustomValidationException {

	/**
	 * Structured error code, exposed on the response body.
	 */
	public const ERROR_CODE = 'TRANSLATION_TARGET_CONFLICT';

	/**
	 * The translatable property whose value conflicted with the header.
	 *
	 * @var string
	 */
	private readonly string $property;

	/**
	 * The BCP-47 target language read from the header.
	 *
	 * @var string
	 */
	private readonly string $targetLanguage;

	/**
	 * Construct a target-language conflict exception.
	 *
	 * Extends `CustomValidationException` so the existing controller
	 * `catch (ValidationException | CustomValidationException $e)`
	 * blocks pick it up as a `400 Bad Request` without any new
	 * controller code.
	 *
	 * @param string $property The translatable property name.
	 * @param string $targetLanguage The BCP-47 target language tag.
	 */
	public function __construct(string $property, string $targetLanguage) {
		$this->property = $property;
		$this->targetLanguage = $targetLanguage;

		parent::__construct(
			message: sprintf(
				'Cannot mix language-keyed body for "%s" with X-Translation-Target-Language: %s',
				$property,
				$targetLanguage
			),
			errors: [
				'code' => self::ERROR_CODE,
				'property' => $property,
				'targetLanguage' => $targetLanguage,
			]
		);
	}//end __construct()

	/**
	 * Get the property whose value conflicted.
	 *
	 * @return string The translatable property name.
	 */
	public function getProperty(): string {
		return $this->property;
	}//end getProperty()

	/**
	 * Get the BCP-47 target language read from the header.
	 *
	 * @return string The BCP-47 language tag.
	 */
	public function getTargetLanguage(): string {
		return $this->targetLanguage;
	}//end getTargetLanguage()

	/**
	 * Convert the exception to a structured response body shape.
	 *
	 * @return array<string, mixed>
	 */
	public function toErrorBody(): array {
		return [
			'error' => [
				'code' => self::ERROR_CODE,
				'property' => $this->property,
				'targetLanguage' => $this->targetLanguage,
				'message' => $this->getMessage(),
			],
		];
	}//end toErrorBody()
}//end class
