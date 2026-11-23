<?php

/**
 * SaveObjectRecords carries the Core port for archival metadata.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject;

use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Kernel\Contracts\Core\ObjectArchivalMetadataInterface;
use OCA\OpenRegister\Service\Edepot\MdtoXmlGenerator;
use OCA\OpenRegister\Service\Object\SaveObject\SaveObjectRecords;
use OCA\OpenRegister\Service\SettingsServiceInterface;
use OCA\OpenRegister\Service\TmloService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionNamedType;
use ReflectionProperty;

/**
 * @covers \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectRecords
 */
class SaveObjectRecordsTest extends TestCase {

	public function testTheArchivalMetadataIsTypedToTheCorePort(): void {
		$type = (new ReflectionProperty(SaveObjectRecords::class, 'tmloService'))->getType();

		$this->assertInstanceOf(ReflectionNamedType::class, $type);
		$this->assertSame(ObjectArchivalMetadataInterface::class, $type->getName());
	}//end testTheArchivalMetadataIsTypedToTheCorePort()

	public function testAnyImplementationOfThePortIsCarried(): void {
		$port    = $this->createMock(ObjectArchivalMetadataInterface::class);
		$records = new SaveObjectRecords(
			auditTrailMapper: $this->createMock(AuditTrailMapperInterface::class),
			settingsService: $this->createMock(SettingsServiceInterface::class),
			tmloService: $port
		);

		$this->assertSame($port, $records->tmloService);
	}//end testAnyImplementationOfThePortIsCarried()

	public function testTheComplianceTmloServiceAnswersThePort(): void {
		$service  = new TmloService(logger: $this->createMock(LoggerInterface::class), mdtoGenerator: $this->createMock(MdtoXmlGenerator::class));
		$register = new Register();
		$register->setConfiguration(['tmloEnabled' => true]);

		$this->assertInstanceOf(ObjectArchivalMetadataInterface::class, $service);
		$this->assertTrue($service->isTmloEnabled($register));
		$this->assertSame([], $service->validateFieldValues([]));
	}//end testTheComplianceTmloServiceAnswersThePort()
}//end class
