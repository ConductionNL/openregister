<?php

declare(strict_types=1);

/**
 * Golden of the register rows RegisterTool returns: the listing row and the
 * single-register row, field by field and in order.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  Unit\Tool
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

namespace Unit\Tool;

use DateTime;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Mcp\Tool\RegisterTool;
use OCA\OpenRegister\Mcp\Tool\ToolCallTrail;
use OCA\OpenRegister\Service\RegisterService;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins the register rows of the register tool.
 */
class RegisterToolShapeGoldenTest extends TestCase {

	public function testAListingRowCarriesTheSummaryFields(): void {
		$result = $this->tool()->listRegisters(limit: 5, offset: 0);

		$this->assertSame('Found 1 registers', $result['message']);
		$this->assertSame(
			[['id' => 3, 'uuid' => 'reg-uuid', 'title' => 'R', 'description' => 'RD', 'slug' => 'r']],
			$result['data']
		);
	}//end testAListingRowCarriesTheSummaryFields()

	public function testASingleRegisterRowAddsFolderOrganisationAndDates(): void {
		$result = $this->tool()->getRegister(id: 'reg-uuid');

		$this->assertSame('Register retrieved successfully', $result['message']);
		$this->assertSame(
			[
				'id' => 3,
				'uuid' => 'reg-uuid',
				'title' => 'R',
				'description' => 'RD',
				'slug' => 'r',
				'folder' => 'f',
				'organisation' => 'org-1',
				'created' => '2026-01-02 03:04:05',
				'updated' => null,
			],
			$result['data']
		);
	}//end testASingleRegisterRowAddsFolderOrganisationAndDates()

	/**
	 * A register tool over one register.
	 *
	 * @return RegisterTool
	 */
	private function tool(): RegisterTool {
		$register = new Register();
		$register->setId(3);
		$register->setUuid('reg-uuid');
		$register->setTitle('R');
		$register->setDescription('RD');
		$register->setSlug('r');
		$register->setFolder('f');
		$register->setOrganisation('org-1');
		$register->setCreated(new DateTime('2026-01-02 03:04:05'));

		$service = $this->createMock(RegisterService::class);
		$service->method('findAll')->willReturn([$register]);
		$service->method('find')->willReturn($register);

		$user    = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('anna');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new RegisterTool(new ToolCallTrail($session, $this->createMock(LoggerInterface::class)), $service);
	}//end tool()
}//end class
