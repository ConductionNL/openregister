<?php

/**
 * OpenRegister DeepLinkRegistration DTO
 *
 * Value object representing a deep link registration from a consuming app.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Dto
 * @package  OCA\OpenRegister\Dto
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/deep-link-registry/spec.md#requirement-deep-link-registry-shall-resolve-urls-for-unified-search-results
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Dto;

/**
 * Value object representing a deep link registration from a consuming app.
 *
 * Maps a (register, schema) pair to a consuming app's URL template,
 * so that search results link to the correct app instead of OpenRegister.
 */
class DeepLinkRegistration {
	/**
	 * Constructor for DeepLinkRegistration.
	 *
	 * @param string $appId The consuming app ID (e.g., "procest")
	 * @param string $registerSlug The register slug
	 * @param string $schemaSlug The schema slug
	 * @param string $urlTemplate URL template with placeholders
	 * @param string $icon Optional icon identifier
	 * @param string|null $displayName Optional human-readable label for the
	 *                                 owning app, used to label unified-search
	 *                                 results (defaults to null → app id)
	 * @param string|null $taskUrlTemplate Optional route of the app's own task view
	 *                                     for tasks about an object of this schema,
	 *                                     with {uuid} (the task), {objectUuid},
	 *                                     {register} and {schema} placeholders
	 *
	 * @return void
	 *
	 * @spec openspec/specs/deep-link-registry/spec.md
	 * @spec openspec/changes/flow-task-search-provider/tasks.md#task-2.1
	 */
	public function __construct(
		public readonly string $appId,
		public readonly string $registerSlug,
		public readonly string $schemaSlug,
		public readonly string $urlTemplate,
		public readonly string $icon = '',
		public readonly ?string $displayName = null,
		public readonly ?string $taskUrlTemplate = null,
	) {
	}//end __construct()

	/**
	 * The app's own route for a task about an object of this schema, or null when it registered none.
	 *
	 * Placeholders: {uuid} and {taskUuid} are the task, {objectUuid} its subject,
	 * {register} and {schema} the subject's register and schema ids. Each value
	 * is URL-encoded.
	 *
	 * @param array<string, mixed> $taskData A task row (uuid, objectUuid, registerId, schemaId).
	 *
	 * @return string|null The URL, or null.
	 *
	 * @spec openspec/changes/flow-task-search-provider/specs/unified-search-provider/spec.md#requirement-nextcloud-search-finds-the-flow-tasks-a-user-may-see
	 */
	public function resolveTaskUrl(array $taskData): ?string {
		if ($this->taskUrlTemplate === null || trim($this->taskUrlTemplate) === '') {
			return null;
		}

		$taskUuid = rawurlencode((string)($taskData['uuid'] ?? ''));

		return strtr(
			$this->taskUrlTemplate,
			[
				'{uuid}' => $taskUuid,
				'{taskUuid}' => $taskUuid,
				'{objectUuid}' => rawurlencode((string)($taskData['objectUuid'] ?? '')),
				'{register}' => rawurlencode((string)($taskData['registerId'] ?? '')),
				'{schema}' => rawurlencode((string)($taskData['schemaId'] ?? '')),
			]
		);
	}//end resolveTaskUrl()

	/**
	 * Resolve the URL template by replacing placeholders with object data.
	 *
	 * Supported placeholders: {uuid}, {id}, {register}, {schema},
	 * {contactId}, {contactEmail}, {contactName}, {entityId},
	 * and any top-level key from the object data array.
	 *
	 * Contact placeholders are resolved from the optional contactContext
	 * parameter, applied after object-level placeholder resolution.
	 *
	 * @param array $objectData The object data from search results
	 * @param array $contactContext Optional contact context with keys:
	 *                              contactId, contactEmail, contactName
	 *
	 * @return string The resolved URL
	 *
	 * @spec openspec/specs/deep-link-registry/spec.md#requirement-deep-link-registry-shall-resolve-urls-for-unified-search-results
	 */
	public function resolveUrl(array $objectData, array $contactContext = []): string {
		$replacements = $this->objectReplacements(objectData: $objectData);

		// Apply contact context placeholders (after object placeholders).
		if (empty($contactContext) === false) {
			$replacements = array_merge(
				$replacements,
				$this->contactReplacements(contactContext: $contactContext, objectData: $objectData)
			);
		}

		return strtr($this->urlTemplate, $replacements);
	}//end resolveUrl()

	/**
	 * The object placeholders: uuid, id, register and schema, then every scalar top-level key.
	 *
	 * @param array<string, mixed> $objectData The object data.
	 *
	 * @return array<string, string> Placeholder to value.
	 */
	private function objectReplacements(array $objectData): array {
		$replacements = [
			'{uuid}' => $objectData['uuid'] ?? '',
			'{id}' => (string)($objectData['id'] ?? ''),
			'{register}' => (string)($objectData['register'] ?? ''),
			'{schema}' => (string)($objectData['schema'] ?? ''),
		];

		// Also support any top-level key from object data.
		foreach ($objectData as $key => $value) {
			if (is_scalar($value) === true) {
				$replacements['{' . $key . '}'] = (string)$value;
			}
		}

		return $replacements;
	}//end objectReplacements()

	/**
	 * The contact placeholders, URL-encoded, and the entity id.
	 *
	 * @param array<string, mixed> $contactContext The contact context.
	 * @param array<string, mixed> $objectData     The object data.
	 *
	 * @return array<string, string> Placeholder to value.
	 */
	private function contactReplacements(array $contactContext, array $objectData): array {
		return [
			'{contactId}' => urlencode((string)($contactContext['contactId'] ?? '')),
			'{contactEmail}' => urlencode((string)($contactContext['contactEmail'] ?? '')),
			'{contactName}' => urlencode((string)($contactContext['contactName'] ?? '')),
			'{entityId}' => $objectData['uuid'] ?? '',
		];
	}//end contactReplacements()
}//end class
