<?php

/**
 * ExecutableContentDetector
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

/**
 * The single source of truth for "do these bytes look executable?".
 *
 * WHY THIS CLASS EXISTS. The same three checks — an offset-0 executable
 * signature, a script shebang, an embedded PHP tag — were implemented TWICE,
 * byte-identically: once in {@see FileValidationHandler} (the `/files`
 * endpoints) and once in
 * {@see \OCA\OpenRegister\Service\Object\SaveObject\FilePropertyHandler} (the
 * object-save file-property path). openregister#2776 was a defect in that
 * logic, so it was a defect in BOTH copies, and fixing only the one the issue
 * happened to name would have left the same legitimate PNG rejected through
 * the other route. A byte-identical duplicate is precisely how a defect comes
 * to exist in two places; there is now one copy.
 *
 * This class DETECTS and REPORTS. It deliberately does not throw and does not
 * log: the two callers have different, caller-facing message shapes (`File
 * 'x.png' ...` versus `File at document ...`) and log under their own class
 * prefixes, and preserving those is what makes this extraction behaviour-
 * preserving rather than a rewrite.
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 * @version  1.0.0
 */
class ExecutableContentDetector {

	/**
	 * How many leading bytes are examined for shebangs and embedded PHP tags.
	 *
	 * @var int
	 */
	public const SCAN_PREFIX_LENGTH = 1024;

	/**
	 * Offset-0 signatures that identify an executable payload.
	 *
	 * These are matched at offset 0 ONLY, and this check is universal: it runs
	 * on every upload regardless of name or sniffed type.
	 *
	 * @var array<string, string>
	 */
	private const EXECUTABLE_SIGNATURES = [
		'MZ' => 'Windows executable (PE/EXE)',
		"\x7FELF" => 'Linux/Unix executable (ELF)',
		'#!/bin/sh' => 'Shell script',
		'#!/bin/bash' => 'Bash script',
		'#!/usr/bin/env' => 'Script with env shebang',
		'<?php' => 'PHP script',
		"\xCA\xFE\xBA\xBE" => 'Java class file',
	];

	/**
	 * Leading byte signatures of container formats that are unambiguously binary.
	 *
	 * A positive match here means the payload is a real image / audio / video /
	 * archive / document container, so a `<?php` or `<?=` byte pair found inside
	 * its compressed body is noise, not source code. Used ONLY to scope the
	 * embedded-PHP-tag scan in {@see containsEmbeddedPhpTag()}; the executable
	 * signature and shebang checks still run on these files.
	 *
	 * This is a WHITELIST on purpose: anything that does not positively identify
	 * as one of these formats keeps the full, unchanged scan.
	 *
	 * @var array<string, string>
	 */
	private const BINARY_CONTENT_SIGNATURES = [
		// Images.
		"\x89PNG\r\n\x1A\n" => 'image/png',
		"\xFF\xD8\xFF" => 'image/jpeg',
		'GIF87a' => 'image/gif',
		'GIF89a' => 'image/gif',
		"II*\x00" => 'image/tiff',
		"MM\x00*" => 'image/tiff',
		"\x00\x00\x01\x00" => 'image/vnd.microsoft.icon',
		'8BPS' => 'image/vnd.adobe.photoshop',
		// RIFF containers: webp, wav, avi.
		'RIFF' => 'application/x-riff',
		// Documents.
		'%PDF-' => 'application/pdf',
		"\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" => 'application/x-ole-storage',
		// Archives and zip-based office formats (docx/xlsx/pptx/odt).
		"PK\x03\x04" => 'application/zip',
		"PK\x05\x06" => 'application/zip',
		"PK\x07\x08" => 'application/zip',
		"\x1F\x8B" => 'application/gzip',
		'BZh' => 'application/x-bzip2',
		"\xFD7zXZ\x00" => 'application/x-xz',
		"7z\xBC\xAF\x27\x1C" => 'application/x-7z-compressed',
		"Rar!\x1A\x07" => 'application/vnd.rar',
		// Audio and video.
		'ID3' => 'audio/mpeg',
		'OggS' => 'application/ogg',
		'fLaC' => 'audio/flac',
		"\x1A\x45\xDF\xA3" => 'video/x-matroska',
		"FLV\x01" => 'video/x-flv',
		// Data.
		"SQLite format 3\x00" => 'application/vnd.sqlite3',
	];

	/**
	 * Filename extensions that are ALWAYS scanned for embedded PHP tags.
	 *
	 * These are the extensions under which a server or a browser may hand the
	 * bytes to an interpreter or a markup parser, so a PHP tag inside them is
	 * meaningful regardless of what the leading bytes look like. A polyglot that
	 * opens with PNG magic but is named `x.html` still gets the full scan.
	 *
	 * @var array<string>
	 */
	private const ALWAYS_SCANNED_EXTENSIONS = [
		'txt',
		'text',
		'log',
		'md',
		'markdown',
		'csv',
		'tsv',
		'htm',
		'html',
		'xhtml',
		'shtml',
		'xml',
		'xsl',
		'xslt',
		'svg',
		'svgz',
		'json',
		'yaml',
		'yml',
		'ini',
		'conf',
		'cfg',
		'env',
		'htaccess',
		'htpasswd',
		'tpl',
		'twig',
		'mustache',
		'hbs',
		'ejs',
		'erb',
		'jsp',
		'asp',
		'aspx',
		'cshtml',
		'css',
		'scss',
		'less',
		'sql',
		'sh',
		'bash',
		'php',
		'phtml',
		'php3',
		'php4',
		'php5',
		'phps',
		'phar',
		'inc',
		'module',
		'install',
	];

	/**
	 * Match the content's leading bytes against the executable signature table.
	 *
	 * Universal: applies to every upload, whatever its name or sniffed type.
	 *
	 * @param string $content The file content to check.
	 *
	 * @return string|null A human-readable description of the matched executable
	 *                     format, or null when no signature matches.
	 *
	 * @psalm-return   string|null
	 * @phpstan-return string|null
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function matchExecutableSignature(string $content): ?string {
		foreach (self::EXECUTABLE_SIGNATURES as $signature => $description) {
			if (strpos($content, (string)$signature) === 0) {
				return $description;
			}
		}

		return null;
	}//end matchExecutableSignature()

	/**
	 * Check for a script shebang within the scanned prefix.
	 *
	 * Universal: applies to every upload, whatever its name or sniffed type.
	 *
	 * @param string $content The file content to check.
	 *
	 * @return bool True when a shebang line is present.
	 *
	 * @psalm-return   bool
	 * @phpstan-return bool
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function hasScriptShebang(string $content): bool {
		$firstLines = substr($content, 0, self::SCAN_PREFIX_LENGTH);

		return preg_match('/^#!.*\/(sh|bash|zsh|ksh|csh|python|perl|ruby|php|node)/m', $firstLines) === 1;
	}//end hasScriptShebang()

	/**
	 * Check for an embedded PHP tag — TEXT-ISH PAYLOADS ONLY.
	 *
	 * Scoped by openregister#2776. This scan used to run over the first kilobyte
	 * of EVERY upload, including compressed binary bodies where a `<?` byte pair
	 * carries no meaning. A genuine 1283x926 PNG screenshot was reported as
	 * "contains PHP code" because its deflate stream happened to contain `<?=`.
	 *
	 * The scan is skipped only when {@see shouldScanForEmbeddedPhp()} says the
	 * payload is a positively-identified binary container with a name no
	 * interpreter would claim. Everything else — plain text, HTML, XML/SVG,
	 * unknown byte streams, files with no extension — is scanned exactly as
	 * before.
	 *
	 * @param string $content The file content to check.
	 * @param string $fileName The filename, used for its extension only.
	 *
	 * @return bool True when a PHP tag is present in a payload that is scanned.
	 *
	 * @psalm-return   bool
	 * @phpstan-return bool
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function containsEmbeddedPhpTag(string $content, string $fileName): bool {
		if ($this->shouldScanForEmbeddedPhp(content: $content, fileName: $fileName) === false) {
			return false;
		}

		$firstLines = substr($content, 0, self::SCAN_PREFIX_LENGTH);

		return preg_match('/<\?php|<\?=|<script\s+language\s*=\s*["\']php/i', $firstLines) === 1;
	}//end containsEmbeddedPhpTag()

	/**
	 * Decide whether the embedded-PHP-tag scan applies to this payload.
	 *
	 * Returns false ONLY when both of the following hold:
	 *  1. the content's leading bytes positively match a known binary container
	 *     signature ({@see BINARY_CONTENT_SIGNATURES}) — an image, audio, video,
	 *     archive or binary document format whose body is compressed or encoded,
	 *     so an embedded `<?` byte pair is statistically expected noise; and
	 *  2. the filename does NOT carry an extension under which a server or a
	 *     browser would hand the bytes to an interpreter or a markup parser
	 *     ({@see ALWAYS_SCANNED_EXTENSIONS}).
	 *
	 * Both conditions are required so that a polyglot — PNG magic bytes followed
	 * by PHP source, saved as `payload.html` or `payload.phtml` — is still
	 * scanned. Note that such a file is already rejected by the callers'
	 * dangerous-extension blocklists; this is defence in depth, not the primary
	 * control.
	 *
	 * @param string $content The file content whose leading bytes are sniffed.
	 * @param string $fileName The filename, used for its extension only.
	 *
	 * @return bool True when the embedded-PHP-tag scan must run (the default).
	 *
	 * @psalm-return   bool
	 * @phpstan-return bool
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function shouldScanForEmbeddedPhp(string $content, string $fileName): bool {
		if ($this->sniffBinaryContentType(content: $content) === null) {
			// Not a recognised binary container — scan, as before.
			return true;
		}

		return $this->hasAlwaysScannedExtension(fileName: $fileName);
	}//end shouldScanForEmbeddedPhp()

	/**
	 * Sniff the content's leading bytes against the known binary container list.
	 *
	 * ISO base media files (mp4/mov/m4a/heic) are special-cased because their
	 * `ftyp` box marker sits at offset 4, not offset 0.
	 *
	 * The declared MIME type from the request is deliberately NOT consulted: it
	 * is client-supplied and would let a caller opt out of the scan by lying.
	 * Only the bytes decide.
	 *
	 * @param string $content The file content to sniff.
	 *
	 * @return string|null The matched container MIME type, or null when the
	 *                     content is not a recognised binary container.
	 *
	 * @psalm-return   string|null
	 * @phpstan-return string|null
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function sniffBinaryContentType(string $content): ?string {
		if ($content === '') {
			return null;
		}

		foreach (self::BINARY_CONTENT_SIGNATURES as $signature => $mimeType) {
			if (str_starts_with($content, (string)$signature) === true) {
				return $mimeType;
			}
		}

		// ISO base media file format (mp4, m4a, mov, 3gp, heic): 4-byte box
		// length, then the literal 'ftyp' brand marker at offset 4.
		if (strlen($content) >= 12 && substr($content, 4, 4) === 'ftyp') {
			return 'video/mp4';
		}

		return null;
	}//end sniffBinaryContentType()

	/**
	 * Check whether the filename carries an extension that is always scanned.
	 *
	 * A file with NO extension is treated as text-ish and scanned: an
	 * extension-less upload is the least identifiable, and strictness there
	 * costs nothing for the formats this scoping exists to unblock.
	 *
	 * @param string $fileName The filename to inspect.
	 *
	 * @return bool True when the extension is in the always-scanned list.
	 *
	 * @psalm-return   bool
	 * @phpstan-return bool
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function hasAlwaysScannedExtension(string $fileName): bool {
		$extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

		if ($extension === '') {
			return true;
		}

		return in_array($extension, self::ALWAYS_SCANNED_EXTENSIONS, true);
	}//end hasAlwaysScannedExtension()
}//end class
