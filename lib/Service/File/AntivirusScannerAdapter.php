<?php

/**
 * AntivirusScannerAdapter: the files_antivirus scanner behind AntivirusScannerInterface
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

use OCP\App\IAppManager;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Asks Nextcloud's files_antivirus to scan bytes, without OpenRegister depending on it.
 *
 * The app is not ours and not part of Nextcloud's public API, so
 * nothing here names its classes in code that has to compile: the factory is
 * resolved by name from the container, only when the app is enabled. What
 * this class calls on it is pinned by tests/Contract/FilesAntivirus against a
 * real release, so a change there turns that suite red before an instance
 * updates.
 *
 * Every failure answers UNAVAILABLE and is logged; it never throws, because
 * the gate's answer to "could not scan" is a refusal, and a refusal needs no
 * exception to happen.
 *
 * @spec openspec/changes/upload-malware-scan/specs/file-actions/spec.md#requirement-every-upload-is-scanned-by-files_antivirus-and-without-it-nothing-is-accepted-req-ums-001
 */
class AntivirusScannerAdapter implements AntivirusScannerInterface {

	/**
	 * The app id.
	 */
	public const APP = 'files_antivirus';

	/**
	 * The factory class files_antivirus registers.
	 */
	public const FACTORY = 'OCA\\Files_Antivirus\\Scanner\\ScannerFactory';

	/**
	 * The status class and its constants, with the values used when a release does not define them.
	 */
	public const STATUS = 'OCA\\Files_Antivirus\\Status';

	/**
	 * Status codes the engine reports, by meaning.
	 *
	 * @var array<string, array{0: string, 1: int}>
	 */
	private const CODES = [
		AntivirusScan::CLEAN => ['SCANRESULT_CLEAN', 0],
		AntivirusScan::INFECTED => ['SCANRESULT_INFECTED', 1],
	];

	/**
	 * Constructor.
	 *
	 * @param IAppManager        $apps      Whether files_antivirus is enabled.
	 * @param ContainerInterface $container Resolves its scanner factory.
	 * @param IAppConfig         $appConfig Which engine it is configured for.
	 * @param LoggerInterface    $logger    Receives why a scan could not be made.
	 */
	public function __construct(
		private readonly IAppManager $apps,
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Scan one run of bytes through files_antivirus.
	 *
	 * @param string $name  The file or member name.
	 * @param string $bytes The content.
	 *
	 * @return AntivirusScan
	 *
	 * @spec openspec/changes/upload-malware-scan/specs/file-actions/spec.md#requirement-every-upload-is-scanned-by-files_antivirus-and-without-it-nothing-is-accepted-req-ums-001
	 */
	public function scan(string $name, string $bytes): AntivirusScan {
		if ($this->apps->isEnabledForAnyone(self::APP) === false) {
			return new AntivirusScan(status: AntivirusScan::UNAVAILABLE, finding: self::APP . ' is not installed and enabled');
		}

		try {
			$status = $this->scanner(name: $name)->scanString($bytes);

			return new AntivirusScan(
				status: $this->meaning(code: (int)$status->getNumericStatus()),
				engine: $this->engine(),
				finding: (string)$status->getDetails()
			);
		} catch (Throwable $e) {
			$this->logger->error('[AntivirusScannerAdapter] ' . self::APP . ' could not scan "' . $name . '": ' . $e->getMessage());
			return new AntivirusScan(status: AntivirusScan::UNAVAILABLE, finding: $e->getMessage());
		}
	}//end scan()

	/**
	 * The engine's scanner, from the factory files_antivirus registers.
	 *
	 * Newer releases take the path being scanned, for their log; older ones
	 * take nothing, and PHP ignores the extra argument there.
	 *
	 * @param string $name The file or member name.
	 *
	 * @return object
	 */
	private function scanner(string $name): object {
		return $this->container->get(self::FACTORY)->getScanner($name);
	}//end scanner()

	/**
	 * What a numeric status means; anything but clean or infected is unchecked.
	 *
	 * @param int $code The engine's status.
	 *
	 * @return string
	 */
	private function meaning(int $code): string {
		foreach (self::CODES as $meaning => [$constant, $fallback]) {
			$name  = self::STATUS . '::' . $constant;
			$value = $fallback;
			if (defined($name) === true) {
				$value = (int)constant($name);
			}

			if ($code === $value) {
				return $meaning;
			}
		}

		return AntivirusScan::UNCHECKED;
	}//end meaning()

	/**
	 * The engine files_antivirus is configured for.
	 *
	 * @return string
	 */
	private function engine(): string {
		$mode = $this->appConfig->getValueString(self::APP, 'av_mode', '');
		if ($mode === '') {
			return self::APP;
		}

		return self::APP . ':' . $mode;
	}//end engine()
}//end class
