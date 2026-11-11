<?php

/**
 * OpenRegister DangerousFileExtensions
 *
 * The file extensions that are never accepted as uploads.
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
 * The one list of executable and script file extensions that uploads refuse.
 *
 * Shared by the file upload validation (FileValidationHandler) and the file
 * property upload guard (FilePropertyParts), so both refuse the same set.
 *
 * @spec openspec/specs/file-actions/spec.md
 */
final class DangerousFileExtensions {

	/**
	 * Dangerous executable extensions to block, lower case, without the dot.
	 *
	 * @var list<string>
	 */
	public const ALL = [
		// Windows executables.
		'exe',
		'bat',
		'cmd',
		'com',
		'msi',
		'scr',
		'vbs',
		'vbe',
		'js',
		'jse',
		'wsf',
		'wsh',
		'ps1',
		'dll',
		// Unix/Linux executables.
		'sh',
		'bash',
		'csh',
		'ksh',
		'zsh',
		'run',
		'bin',
		'app',
		'deb',
		'rpm',
		// Scripts and code.
		'php',
		'phtml',
		'php3',
		'php4',
		'php5',
		'phps',
		'phar',
		'py',
		'pyc',
		'pyo',
		'pyw',
		'pl',
		'pm',
		'cgi',
		'rb',
		'rbw',
		'jar',
		'war',
		'ear',
		'class',
		// Containers and packages.
		'appimage',
		'snap',
		'flatpak',
		// MacOS.
		'dmg',
		'pkg',
		'command',
		// Android.
		'apk',
		// Other dangerous.
		'elf',
		'out',
		'o',
		'so',
		'dylib',
	];
}//end class
