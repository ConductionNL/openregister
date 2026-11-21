<?php

/**
 * UrlFileSource
 *
 * Fetches the bytes of a file to create from a URL, runs the upload checks on
 * them, and keeps where they came from on the created file (REQ-FCU-001,
 * REQ-FCU-002).
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
 *
 * @spec openspec/changes/files-create-from-url/specs
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

use DateTimeInterface;
use DateTimeZone;
use OCA\OpenRegister\Service\ApiVersion\ApiCapabilitiesService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\FilesMetadata\IFilesMetadataManager;
use Psr\Log\LoggerInterface;

/**
 * The URL side of creating a file: fetch, check, and record the provenance.
 *
 * @spec openspec/changes/files-create-from-url/specs
 */
class UrlFileSource {

	/**
	 * The metadata key of the URL a file was fetched from.
	 *
	 * @var string
	 */
	public const KEY_SOURCE_URL = 'openregister-source-url';

	/**
	 * The metadata key of the moment a file was fetched.
	 *
	 * @var string
	 */
	public const KEY_FETCHED_AT = 'openregister-fetched-at';

	/**
	 * The metadata key of the SHA-256 of the fetched bytes.
	 *
	 * @var string
	 */
	public const KEY_SHA256 = 'openregister-sha256';

	/**
	 * The ceiling when PHP sets no upload limit: 100 MiB.
	 *
	 * @var int
	 */
	public const DEFAULT_CEILING_BYTES = 104857600;

	/**
	 * Constructor.
	 *
	 * @param UrlFileFetcher        $fetcher         The shared fetch behind the address guard.
	 * @param FileValidationHandler $validator       The upload checks every file passes.
	 * @param IFilesMetadataManager $metadataManager Where the provenance is kept.
	 * @param ITimeFactory          $timeFactory     The clock.
	 * @param LoggerInterface       $logger          The logger.
	 */
	public function __construct(
		private readonly UrlFileFetcher $fetcher,
		private readonly FileValidationHandler $validator,
		private readonly IFilesMetadataManager $metadataManager,
		private readonly ITimeFactory $timeFactory,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Fetch the bytes for a file and run the upload checks on all of them.
	 *
	 * @param string $url      The URL.
	 * @param string $fileName The name the file will get; the checks read its extension.
	 *
	 * @return FetchedFile The bytes and their provenance.
	 *
	 * @throws UrlFetchRefused With the reason when the fetch produced no bytes.
	 * @throws \Exception      When the bytes fail an upload check.
	 *
	 * @spec openspec/changes/files-create-from-url/specs
	 */
	public function fetch(string $url, string $fileName): FetchedFile {
		$bytes = $this->fetcher->fetch(url: $url, maxBytes: self::ceilingBytes());

		// The same checks as an uploaded file, on every byte rather than a prefix.
		$this->validator->blockExecutableFile(fileName: $fileName, fileContent: $bytes);

		return new FetchedFile(
			bytes: $bytes,
			sourceUrl: $url,
			fetchedAt: $this->timeFactory->getDateTime('now', new DateTimeZone('UTC'))->format(DateTimeInterface::ATOM),
			sha256: hash('sha256', $bytes),
		);
	}//end fetch()

	/**
	 * Keep the provenance on the created file. Best-effort: the file exists
	 * already, and the caller gets the provenance in the response and on the
	 * object's audit trail either way.
	 *
	 * @param int         $fileId  The created file.
	 * @param FetchedFile $fetched What was fetched.
	 *
	 * @return bool True when the metadata was stored.
	 *
	 * @spec openspec/changes/files-create-from-url/specs
	 */
	public function remember(int $fileId, FetchedFile $fetched): bool {
		try {
			$metadata = $this->metadataManager->getMetadata($fileId, true);
			$metadata->setString(self::KEY_SOURCE_URL, $fetched->sourceUrl);
			$metadata->setString(self::KEY_FETCHED_AT, $fetched->fetchedAt);
			$metadata->setString(self::KEY_SHA256, $fetched->sha256);
			$this->metadataManager->saveMetadata($metadata);
			return true;
		} catch (\Throwable $error) {
			$this->logger->warning(
				message: '[UrlFileSource] Could not store where a fetched file came from',
				context: ['fileId' => $fileId, 'error' => $error->getMessage()]
			);
			return false;
		}
	}//end remember()

	/**
	 * The instance's upload ceiling in bytes: the lower of PHP's
	 * upload_max_filesize and post_max_size, or 100 MiB when neither sets one.
	 *
	 * @return int The ceiling.
	 *
	 * @spec openspec/changes/files-create-from-url/specs
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) The size shorthand parser is shared with the capabilities report.
	 */
	public static function ceilingBytes(): int {
		$candidates = [];
		foreach (['upload_max_filesize', 'post_max_size'] as $directive) {
			$bytes = ApiCapabilitiesService::toBytes(value: (string)ini_get($directive));
			if ($bytes !== null && $bytes > 0) {
				$candidates[] = $bytes;
			}
		}

		if ($candidates === []) {
			return self::DEFAULT_CEILING_BYTES;
		}

		return min($candidates);
	}//end ceilingBytes()
}//end class
