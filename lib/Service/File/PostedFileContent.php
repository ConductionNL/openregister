<?php

/**
 * PostedFileContent: the bytes a posted file's content stands for
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\File
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

/**
 * Decodes posted file content the way the create pipeline writes it.
 *
 * A base64 data URI or plain base64 string is decoded; any other string, and a
 * stream resource, is the content as given. The create pipeline and the
 * upload malware check share this, so the bytes scanned are the bytes written.
 *
 * @spec openspec/changes/upload-malware-scan/specs/file-actions/spec.md#requirement-every-upload-is-scanned-by-files_antivirus-and-without-it-nothing-is-accepted-req-ums-001
 */
final class PostedFileContent {

	/**
	 * String content with a base64 data URI or plain base64 decoded; a stream resource as given.
	 *
	 * @param mixed $content The content: a string or a stream resource.
	 *
	 * @return mixed The content to write.
	 *
	 * @spec openspec/changes/upload-malware-scan/specs/file-actions/spec.md#requirement-every-upload-is-scanned-by-files_antivirus-and-without-it-nothing-is-accepted-req-ums-001
	 */
	public function decoded(mixed $content): mixed {
		if (is_resource($content) === true) {
			return $content;
		}

		$content = $this->dataUriPayload(content: $content);
		$decodedContent = base64_decode($content, true);
		if ($decodedContent !== false && base64_encode($decodedContent) === $content) {
			return $decodedContent;
		}

		return $content;
	}//end decoded()

	/**
	 * The base64 payload of a `data:mime/type;base64,...` URI; any other string as given.
	 *
	 * A data URI that is not base64-encoded is left as is (it might be URL-encoded).
	 *
	 * @param string $content The content.
	 *
	 * @return string The payload or the content.
	 */
	private function dataUriPayload(string $content): string {
		if (str_starts_with($content, 'data:') === false) {
			return $content;
		}

		$parts = explode(',', $content, 2);
		if (count($parts) === 2 && str_contains($parts[0], 'base64') === true) {
			return $parts[1];
		}

		return $content;
	}//end dataUriPayload()
}//end class
