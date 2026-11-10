<?php

/**
 * TaggingHandlerInterface: the public surface of TaggingHandler, for callers to type against.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Files
 * @package  OCA\OpenRegister\Files\Service\File
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

use Exception;

/**
 * Every public method of TaggingHandler. Callers depend on this interface, not on the class.
 */
interface TaggingHandlerInterface {

	/**
	 * Attach tags to a file.
	 *
	 * @param string $fileId The file ID.
	 * @param array $tags Tags to associate with the file.
	 *
	 * @return void
	 *
	 * @phpstan-param array<int, string> $tags
	 * @psalm-param   array<int, string> $tags
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Tag management requires handling create/find/attach scenarios
	 * @SuppressWarnings(PHPMD.NPathComplexity)      Multiple paths for tag creation and attachment
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function attachTagsToFile(string $fileId, array $tags = []): void;

	/**
	 * Get tags for a file.
	 *
	 * @param string $fileId The file ID.
	 *
	 * @return string[]
	 *
	 * @phpstan-return array<int, string>
	 *
	 * @psalm-return list<string>
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function getFileTags(string $fileId): array;

	/**
	 * Generate the tag of an object.
	 *
	 * @param string $objectIdentifier The object's UUID, or its id when it has no UUID.
	 *
	 * @return string The object tag (e.g., 'object:uuid').
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function generateObjectTag(string $objectIdentifier): string;

	/**
	 * Get tags for an OpenRegister object.
	 *
	 * @param string $objectUuid The object UUID.
	 *
	 * @return string[] Tag names attached to this object.
	 *
	 * @phpstan-return array<int, string>
	 * @psalm-return   list<string>
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function getObjectTags(string $objectUuid): array;

	/**
	 * Add a tag to an OpenRegister object.
	 *
	 * @param string $objectUuid The object UUID.
	 * @param string $tagName The tag name to add.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function addObjectTag(string $objectUuid, string $tagName): void;

	/**
	 * Remove a tag from an OpenRegister object.
	 *
	 * @param string $objectUuid The object UUID.
	 * @param string $tagName The tag name to remove.
	 *
	 * @return void
	 *
	 * @throws Exception If the tag is not found.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function removeObjectTag(string $objectUuid, string $tagName): void;

	/**
	 * Get all system tags.
	 *
	 * @return string[] Tag names.
	 *
	 * @phpstan-return array<int, string>
	 *
	 * @psalm-return list<string>
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function getAllTags(): array;
}//end interface
