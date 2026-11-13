<?php

/**
 * What OpenRegister's upload malware scan relies on in files_antivirus.
 *
 * AntivirusScannerAdapter calls files_antivirus classes that are not
 * Nextcloud's public API: `Scanner\ScannerFactory::getScanner()`, the
 * scanner's `scanString()`, and `Status::getNumericStatus()`, `getDetails()`
 * and its clean and infected constants. A release can change any of them, and
 * every OpenRegister upload is then refused as "no scan could be made". This
 * suite runs against a real files_antivirus tree and goes red on the first
 * such change, before an instance updates.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Contract\FilesAntivirus
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/upload-malware-scan/specs/file-actions/spec.md#requirement-every-upload-is-scanned-by-files_antivirus-and-without-it-nothing-is-accepted-req-ums-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Contract\FilesAntivirus;

use OCA\OpenRegister\Service\File\AntivirusScannerAdapter;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * The files_antivirus contract of OpenRegister's upload scan.
 */
class FilesAntivirusContractTest extends TestCase {

	/**
	 * Print which files_antivirus the suite runs against, so a red run names it.
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void {
		$info = simplexml_load_file(FILES_ANTIVIRUS_CONTRACT_PATH . '/appinfo/info.xml');
		$version = ($info !== false) ? (string)$info->version : 'unknown';
		fwrite(STDERR, 'files_antivirus contract against version ' . $version . ' at ' . FILES_ANTIVIRUS_CONTRACT_PATH . "\n");
	}//end setUpBeforeClass()

	/**
	 * The app id the adapter checks is the one the app declares.
	 *
	 * @return void
	 */
	public function testTheAppIdIsTheOneTheAdapterChecks(): void {
		$info = simplexml_load_file(FILES_ANTIVIRUS_CONTRACT_PATH . '/appinfo/info.xml');
		$this->assertNotFalse($info);
		$this->assertSame(AntivirusScannerAdapter::APP, (string)$info->id);
	}//end testTheAppIdIsTheOneTheAdapterChecks()

	/**
	 * AntivirusScannerAdapter::scanner() resolves the factory and calls getScanner() with at most the path.
	 *
	 * @return void
	 */
	public function testTheFactoryGivesAScannerForAPath(): void {
		$this->assertTrue(class_exists(AntivirusScannerAdapter::FACTORY), AntivirusScannerAdapter::FACTORY . ' is gone: every upload is refused.');
		$method = new ReflectionMethod(AntivirusScannerAdapter::FACTORY, 'getScanner');
		$this->assertTrue($method->isPublic());
		$this->assertLessThanOrEqual(1, $method->getNumberOfRequiredParameters(), 'the adapter passes the path or nothing');
		if ($method->getNumberOfParameters() > 0) {
			$this->assertSame('string', (string)$method->getParameters()[0]->getType());
		}
	}//end testTheFactoryGivesAScannerForAPath()

	/**
	 * The scanner it returns scans a string and answers a Status.
	 *
	 * @return void
	 */
	public function testTheScannerScansAString(): void {
		$returned = (string)(new ReflectionMethod(AntivirusScannerAdapter::FACTORY, 'getScanner'))->getReturnType();
		$this->assertNotSame('', $returned, 'getScanner() declares no return type, so the scanner cannot be checked');
		$method = new ReflectionMethod(ltrim($returned, '?'), 'scanString');
		$this->assertSame(1, $method->getNumberOfRequiredParameters());
		$this->assertSame('string', (string)$method->getParameters()[0]->getType());
		$this->assertSame(ltrim(AntivirusScannerAdapter::STATUS, '\\'), ltrim((string)$method->getReturnType(), '?\\'));
	}//end testTheScannerScansAString()

	/**
	 * Status answers a number and details, and defines what clean and infected are.
	 *
	 * @return void
	 */
	public function testTheStatusSaysCleanOrInfected(): void {
		$status = new ReflectionClass(AntivirusScannerAdapter::STATUS);
		$this->assertTrue($status->hasMethod('getNumericStatus'));
		$this->assertTrue($status->hasMethod('getDetails'));
		$this->assertTrue($status->hasConstant('SCANRESULT_CLEAN'));
		$this->assertTrue($status->hasConstant('SCANRESULT_INFECTED'));
		$this->assertNotSame($status->getConstant('SCANRESULT_CLEAN'), $status->getConstant('SCANRESULT_INFECTED'));
	}//end testTheStatusSaysCleanOrInfected()
}//end class
