<?php

/**
 * OfficeDocumentSanitizer
 *
 * Orchestrates Office document (DOCX / ODT) sanitisation. Resolves the input
 * file via Nextcloud's IRootFolder, MIME-sniffs it, dispatches to the matching
 * per-format strategy on a temp copy, and returns the sanitised path plus an
 * audit report. The original Nextcloud file is never mutated.
 *
 * Sentinel: metadata fields scrubbed by the strategies are replaced with the
 * SENTINEL string. This is intentionally a tool brand (per design D5): it both
 * signals that the document was processed (in-file audit trail) and defends
 * against Word's "fill missing metadata on save" re-leak.
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
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/office-document-sanitization/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

use OCA\OpenRegister\Exception\SanitizationException;
use OCA\OpenRegister\Service\File\Sanitizer\DocxSanitizer;
use OCA\OpenRegister\Service\File\Sanitizer\OdtSanitizer;
use OCA\OpenRegister\Service\File\Sanitizer\SanitizerInterface;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\ITempManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Dispatches Office document sanitisation to the correct per-format strategy.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\File
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/office-document-sanitization/spec.md
 */
class OfficeDocumentSanitizer
{

    /**
     * Sentinel value substituted for scrubbed metadata fields.
     *
     * Intentionally a tool brand (per design D5) — see class docblock.
     *
     * @var string
     */
    public const SENTINEL = 'DocuDesk Anonymisation';

    /**
     * Registered per-format sanitiser strategies.
     *
     * @var SanitizerInterface[]
     */
    private array $strategies;

    /**
     * Constructor.
     *
     * Strategies default to the built-in DOCX + ODT sanitisers so the service
     * is autowire-friendly; tests MAY inject a custom strategy list.
     *
     * @param IRootFolder               $rootFolder  Root folder for file resolution.
     * @param ITempManager              $tempManager Temp file allocator (auto-cleans at request end).
     * @param LoggerInterface           $logger      PII-free logger.
     * @param SanitizerInterface[]|null $strategies  Optional strategy override (DI / tests).
     *
     * @spec openspec/specs/office-document-sanitization/spec.md
     */
    public function __construct(
        private readonly IRootFolder $rootFolder,
        private readonly ITempManager $tempManager,
        private readonly LoggerInterface $logger,
        ?array $strategies=null
    ) {
        if ($strategies === null) {
            $strategies = [
                new DocxSanitizer(sentinel: self::SENTINEL),
                new OdtSanitizer(sentinel: self::SENTINEL),
            ];
        }

        $this->strategies = $strategies;
    }//end __construct()

    /**
     * Whether any registered strategy supports the given MIME type.
     *
     * @param string $mimeType The file MIME type.
     *
     * @return bool True when at least one strategy supports the format.
     *
     * @spec openspec/specs/office-document-sanitization/spec.md
     */
    public function isSanitizable(string $mimeType): bool
    {
        foreach ($this->strategies as $strategy) {
            if ($strategy->supports($mimeType) === true) {
                return true;
            }
        }

        return false;
    }//end isSanitizable()

    /**
     * Sanitise the Nextcloud file identified by $fileId.
     *
     * Copies the file to a temp path, runs the matching strategy in-place on
     * the copy, and returns the sanitised path plus the audit report. The
     * original Nextcloud file is untouched.
     *
     * @param int $fileId The Nextcloud file ID.
     *
     * @throws SanitizationException On unsupported MIME, encryption, corrupt
     *                               zip, or internal failure.
     *
     * @return SanitizationResult The sanitised path and audit report.
     *
     * @spec openspec/specs/office-document-sanitization/spec.md
     */
    public function sanitize(int $fileId): SanitizationResult
    {
        $nodes = $this->rootFolder->getById($fileId);
        if (count($nodes) === 0) {
            throw new SanitizationException(
                reason: SanitizationException::REASON_UNSUPPORTED_MIME,
                message: 'Sanitisation target file could not be resolved'
            );
        }

        $file = $nodes[0];
        if (($file instanceof File) === false) {
            throw new SanitizationException(
                reason: SanitizationException::REASON_UNSUPPORTED_MIME,
                message: 'Sanitisation target is not a file'
            );
        }

        $mimeType = $file->getMimeType();

        $strategy = $this->resolveStrategy(mimeType: $mimeType);

        $tempPath = $this->tempManager->getTemporaryFile($this->extensionForMime(mimeType: $mimeType));
        if ($tempPath === false) {
            throw new SanitizationException(
                reason: SanitizationException::REASON_INTERNAL,
                message: 'Could not allocate a temporary file for sanitisation'
            );
        }

        $this->copyToTemp(stream: $file->fopen('r'), tempPath: $tempPath);

        try {
            $report = $strategy->sanitize($tempPath, $tempPath);
        } catch (SanitizationException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new SanitizationException(
                reason: SanitizationException::REASON_INTERNAL,
                message: 'Sanitisation surgery failed',
                previous: $e
            );
        }

        $this->logger->info(
            message: '[OfficeDocumentSanitizer] Document sanitised',
            context: [
                'fileId'   => $fileId,
                'mimeType' => $mimeType,
                'strategy' => $strategy::class,
                'counts'   => $report->jsonSerialize(),
            ]
        );

        return new SanitizationResult(path: $tempPath, report: $report);
    }//end sanitize()

    /**
     * Resolve the first strategy supporting the MIME type.
     *
     * @param string $mimeType The file MIME type.
     *
     * @throws SanitizationException When no strategy supports the MIME type.
     *
     * @return SanitizerInterface The matching strategy.
     *
     * @spec openspec/specs/office-document-sanitization/spec.md
     */
    private function resolveStrategy(string $mimeType): SanitizerInterface
    {
        foreach ($this->strategies as $strategy) {
            if ($strategy->supports($mimeType) === true) {
                return $strategy;
            }
        }

        throw new SanitizationException(
            reason: SanitizationException::REASON_UNSUPPORTED_MIME,
            message: sprintf('No sanitiser strategy for MIME type: %s', $mimeType)
        );
    }//end resolveStrategy()

    /**
     * Map a supported MIME type to a temp-file extension.
     *
     * @param string $mimeType The file MIME type.
     *
     * @return string The extension (with leading dot).
     *
     * @spec openspec/specs/office-document-sanitization/spec.md
     */
    private function extensionForMime(string $mimeType): string
    {
        if ($mimeType === 'application/vnd.oasis.opendocument.text') {
            return '.odt';
        }

        return '.docx';
    }//end extensionForMime()

    /**
     * Copy a read stream into the temp path via stream copy.
     *
     * @param resource|false $stream   The source read stream.
     * @param string         $tempPath The destination temp path.
     *
     * @throws SanitizationException When the stream or temp file cannot be opened.
     *
     * @return void
     *
     * @spec openspec/specs/office-document-sanitization/spec.md
     */
    private function copyToTemp($stream, string $tempPath): void
    {
        if (is_resource($stream) === false) {
            throw new SanitizationException(
                reason: SanitizationException::REASON_INTERNAL,
                message: 'Could not open source file stream for sanitisation'
            );
        }

        $tempStream = fopen($tempPath, 'w');
        if ($tempStream === false) {
            fclose($stream);
            throw new SanitizationException(
                reason: SanitizationException::REASON_INTERNAL,
                message: 'Could not open temporary file for writing'
            );
        }

        stream_copy_to_stream($stream, $tempStream);
        fclose($tempStream);
        fclose($stream);
    }//end copyToTemp()
}//end class
