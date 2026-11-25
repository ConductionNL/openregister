<?php

/**
 * OpenRegister BAG extract source
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Bag
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Bag;

use InvalidArgumentException;
use OCP\Http\Client\IClientService;
use OCP\ITempManager;
use Throwable;

/**
 * Turns a BAG extract source, a file path or an http(s) URL, into a local file.
 *
 * A URL is downloaded into a temporary file, which release() removes again;
 * a path is used where it is and never removed. The URL is an app config
 * value (`bag_bulk_url`), so an air-gapped instance can name an internal
 * mirror.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Bag
 *
 * @spec openspec/changes/bag-address-lookup-by-postcode/specs/bag-address-lookup/spec.md#requirement-the-bag-register-holds-the-national-address-set-req-bag-001
 */
class BagExtractSource {

	/**
	 * How long one download may take, in seconds.
	 */
	private const TIMEOUT = 1800;

	/**
	 * Constructor.
	 *
	 * @param IClientService $http Downloads a URL.
	 * @param ITempManager   $temp Holds the downloaded file.
	 *
	 * @spec openspec/changes/bag-address-lookup-by-postcode/specs/bag-address-lookup/spec.md#requirement-the-bag-register-holds-the-national-address-set-req-bag-001
	 */
	public function __construct(
		private readonly IClientService $http,
		private readonly ITempManager $temp,
	) {
	}//end __construct()

	/**
	 * Whether a source is a URL to download rather than a file path.
	 *
	 * @param string $source The source.
	 *
	 * @return bool True for an http or https URL.
	 *
	 * @spec openspec/changes/bag-address-lookup-by-postcode/specs/bag-address-lookup/spec.md#requirement-the-bag-register-holds-the-national-address-set-req-bag-001
	 */
	public static function isUrl(string $source): bool {
		return preg_match('#^https?://#i', trim($source)) === 1;
	}//end isUrl()

	/**
	 * A local file holding the extract.
	 *
	 * @param string $source A file path or an http(s) URL.
	 *
	 * @return string The path of the file to read.
	 *
	 * @throws InvalidArgumentException When there is no source, the file is missing or the download fails.
	 *
	 * @spec openspec/changes/bag-address-lookup-by-postcode/specs/bag-address-lookup/spec.md#requirement-the-bag-register-holds-the-national-address-set-req-bag-001
	 */
	public function localPath(string $source): string {
		$source = trim($source);
		if ($source === '') {
			throw new InvalidArgumentException('No BAG extract source is given; pass --source or set bag_bulk_url.');
		}

		if (self::isUrl(source: $source) === false) {
			if (is_file($source) === false || is_readable($source) === false) {
				throw new InvalidArgumentException('The BAG extract file does not exist or cannot be read: ' . basename($source));
			}

			return $source;
		}

		$path = $this->temp->getTemporaryFile('.bag');
		if ($path === false) {
			throw new InvalidArgumentException('No temporary file could be made for the BAG extract download.');
		}

		try {
			$this->http->newClient()->get($source, ['sink' => $path, 'timeout' => self::TIMEOUT]);
		} catch (Throwable $e) {
			@unlink($path);
			throw new InvalidArgumentException('The BAG extract could not be downloaded.', 0, $e);
		}

		return $path;
	}//end localPath()

	/**
	 * Remove a downloaded file; a file path given as the source is left alone.
	 *
	 * @param string $path   The local file localPath() answered.
	 * @param string $source The source it was made from.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bag-address-lookup-by-postcode/specs/bag-address-lookup/spec.md#requirement-the-bag-register-holds-the-national-address-set-req-bag-001
	 */
	public function release(string $path, string $source): void {
		if (self::isUrl(source: $source) === true && is_file($path) === true) {
			unlink($path);
		}
	}//end release()
}//end class
