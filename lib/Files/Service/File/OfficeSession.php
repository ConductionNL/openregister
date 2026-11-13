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
 * @spec openspec/specs/file-actions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service\File;

/**
 * An issued Office session: the frame address, the WOPI source and the token.
 *
 * @spec openspec/specs/file-actions/spec.md
 */
final class OfficeSession {

	/**
	 * The Collabora address for this mimetype, from discovery.
	 *
	 * @var string
	 */
	public readonly string $urlSrc;

	/**
	 * The WOPI source Collabora calls back on.
	 *
	 * @var string
	 */
	public readonly string $wopiSrc;

	/**
	 * The WOPI access token.
	 *
	 * @var string
	 */
	public readonly string $token;

	/**
	 * The token expiry, as a unix timestamp in milliseconds.
	 *
	 * @var integer
	 */
	public readonly int $tokenTtl;

	/**
	 * Whether the token may not save.
	 *
	 * @var boolean
	 */
	public readonly bool $readOnly;

	/**
	 * Constructor.
	 *
	 * @param string             $urlSrc  The Collabora address for this mimetype, from discovery.
	 * @param string             $wopiSrc The WOPI source Collabora calls back on.
	 * @param OfficeSessionToken $access  The WOPI access token, its expiry and whether it may save.
	 */
	public function __construct(
		string $urlSrc,
		string $wopiSrc,
		OfficeSessionToken $access,
	) {
		$this->urlSrc = $urlSrc;
		$this->wopiSrc = $wopiSrc;
		$this->token = $access->token;
		$this->tokenTtl = $access->tokenTtl;
		$this->readOnly = $access->readOnly;
	}//end __construct()

	/**
	 * The address the token is posted to.
	 *
	 * @return string The Collabora frame address with the WOPI source attached.
	 *
	 * @spec openspec/specs/file-actions/spec.md
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
	 * @spec openspec/specs/file-actions/spec.md
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
