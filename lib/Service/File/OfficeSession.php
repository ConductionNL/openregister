<?php

/**
 * What a browser needs to open one document in Nextcloud Office.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\File
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/object-files-follow-object-access/specs/file-actions/spec.md#requirement-nextcloud-office-opens-an-objects-document-by-the-object-rule-req-ofoa-006
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

/**
 * An issued Office session: the frame address, the WOPI source and the token.
 *
 * @spec openspec/changes/object-files-follow-object-access/specs/file-actions/spec.md#requirement-nextcloud-office-opens-an-objects-document-by-the-object-rule-req-ofoa-006
 */
final class OfficeSession {

	/**
	 * Constructor.
	 *
	 * @param string $urlSrc   The Collabora address for this mimetype, from discovery.
	 * @param string $wopiSrc  The WOPI source Collabora calls back on.
	 * @param string $token    The WOPI access token.
	 * @param int    $tokenTtl The token expiry, as a unix timestamp in milliseconds.
	 * @param bool   $readOnly Whether the token may not save.
	 */
	public function __construct(
		public readonly string $urlSrc,
		public readonly string $wopiSrc,
		public readonly string $token,
		public readonly int $tokenTtl,
		public readonly bool $readOnly,
	) {
	}//end __construct()

	/**
	 * The address the token is posted to.
	 *
	 * @return string The Collabora frame address with the WOPI source attached.
	 *
	 * @spec openspec/changes/object-files-follow-object-access/specs/file-actions/spec.md#requirement-nextcloud-office-opens-an-objects-document-by-the-object-rule-req-ofoa-006
	 */
	public function frameAction(): string {
		$separator = '&';
		if (str_ends_with($this->urlSrc, '?') === true || str_ends_with($this->urlSrc, '&') === true) {
			$separator = '';
		} elseif (str_contains($this->urlSrc, '?') === false) {
			$separator = '?';
		}

		return $this->urlSrc . $separator . 'WOPISrc=' . rawurlencode($this->wopiSrc);
	}//end frameAction()

	/**
	 * The session as an API response body.
	 *
	 * @return array{urlSrc: string, wopiSrc: string, frameAction: string, token: string, tokenTtl: int, readOnly: bool}
	 *
	 * @spec openspec/changes/object-files-follow-object-access/specs/file-actions/spec.md#requirement-nextcloud-office-opens-an-objects-document-by-the-object-rule-req-ofoa-006
	 */
	public function toArray(): array {
		return [
			'urlSrc' => $this->urlSrc,
			'wopiSrc' => $this->wopiSrc,
			'frameAction' => $this->frameAction(),
			'token' => $this->token,
			'tokenTtl' => $this->tokenTtl,
			'readOnly' => $this->readOnly,
		];
	}//end toArray()
}//end class
