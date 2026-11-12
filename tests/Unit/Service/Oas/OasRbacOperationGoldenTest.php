<?php

/**
 * Golden coverage for OasRbacAnnotator::applyRbacToOperation.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Oas
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Oas;

use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Service\Oas\OasRbacAnnotator;
use PHPUnit\Framework\TestCase;

/**
 * Locks what an operation carries once its groups are applied.
 */
final class OasRbacOperationGoldenTest extends TestCase {

	/**
	 * Admin is put first once, the scopes are listed, a 403 is added and the security is oauth2 or basic.
	 *
	 * @return void
	 */
	public function testAnOperationCarriesItsScopesForbiddenAndSecurity(): void {
		$annotator = new OasRbacAnnotator($this->createMock(RegisterMapperInterface::class));

		foreach ([['zaak', 'admin', 'zaak'], ['zaak', 'beheer']] as $groups) {
			$operation = ['description' => 'List cases.', 'responses' => ['200' => ['description' => 'OK']]];
			$annotator->applyRbacToOperation($operation, $groups);

			$expected = ['admin', 'zaak', 'beheer'];
			if (in_array('beheer', $groups, true) === false) {
				$expected = ['zaak', 'admin'];
			}

			$this->assertSame(
				[
					'description' => "List cases.\n\n**Required scopes:** `" . implode('`, `', $expected) . '`',
					'responses' => [
						'200' => ['description' => 'OK'],
						'403' => [
							'description' => 'Forbidden — user does not have the required group membership for this action',
							'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]],
						],
					],
					'security' => [['oauth2' => $expected], ['basicAuth' => []]],
				],
				$operation
			);
		}
	}//end testAnOperationCarriesItsScopesForbiddenAndSecurity()
}//end class
