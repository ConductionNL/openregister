<?php

/**
 * ShareUrlBuilder
 *
 * This file is part of the OpenRegister app for Nextcloud.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

use OCP\IConfig;
use OCP\IURLGenerator;

/**
 * Builds the absolute addresses of files: public share links and the
 * authenticated preview and download addresses.
 *
 * One instance can answer on several host names (cloud.zuiddrecht.nl,
 * zuiddrecht.nl, localhost:8080 in development). A link is therefore built
 * for the host the visitor used, through Nextcloud's own URL generator, which
 * honours `overwritehost`, `overwriteprotocol` and trusted proxies. Without a
 * request (cron, occ) there is no visitor host, and the link is built from
 * `overwrite.cli.url`, the address the administrator declared for exactly
 * that case. No entry of `trusted_domains` is ever picked as a host: that
 * list says which hosts are allowed, not which one a visitor can reach.
 *
 * @spec openspec/specs/file-actions/spec.md#requirement-a-file-link-points-at-the-host-the-visitor-used
 */
class ShareUrlBuilder {

	/**
	 * The SAPIs that run without an HTTP request.
	 */
	private const REQUESTLESS_SAPIS = ['cli', 'phpdbg'];

	/**
	 * Constructor.
	 *
	 * @param IURLGenerator $urlGenerator Nextcloud's URL generator, bound to the current request.
	 * @param IConfig $config System configuration, read for `overwrite.cli.url`.
	 * @param string $sapi The PHP SAPI this process runs under; a seam for tests.
	 */
	public function __construct(
		private readonly IURLGenerator $urlGenerator,
		private readonly IConfig $config,
		private readonly string $sapi = PHP_SAPI,
	) {
	}//end __construct()

	/**
	 * The public link of a share token.
	 *
	 * @param string $token The share token.
	 *
	 * @return string The absolute share link, without a trailing `/download`.
	 *
	 * @spec openspec/specs/file-actions/spec.md#requirement-a-file-link-points-at-the-host-the-visitor-used
	 */
	public function shareLink(string $token): string {
		return $this->absolute(path: '/index.php/s/' . rawurlencode($token));
	}//end shareLink()

	/**
	 * Turn an instance path into an absolute address for the current context.
	 *
	 * @param string $path A path below the Nextcloud web root, starting with `/`.
	 *
	 * @return string The absolute address.
	 *
	 * @spec openspec/specs/file-actions/spec.md#requirement-a-file-link-points-at-the-host-the-visitor-used
	 */
	public function absolute(string $path): string {
		$path = '/' . ltrim($path, '/');

		if (in_array($this->sapi, self::REQUESTLESS_SAPIS, true) === true) {
			$cliUrl = rtrim($this->config->getSystemValueString('overwrite.cli.url', ''), '/');
			if ($cliUrl !== '') {
				return $cliUrl . $path;
			}

			// No declared address: the generator's own base is the best there is.
			return rtrim($this->urlGenerator->getBaseUrl(), '/') . $path;
		}

		return $this->urlGenerator->getAbsoluteURL($path);
	}//end absolute()
}//end class
