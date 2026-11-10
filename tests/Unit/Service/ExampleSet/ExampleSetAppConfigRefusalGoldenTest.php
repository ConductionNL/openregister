<?php

/**
 * Golden: which app config entries an example set may write from a file.
 *
 * Pins ExampleSetAppConfig::refusal() before it takes an ExampleSetConfigKey:
 * a sensitive stored key is refused unless the set generates its value, a key
 * whose sensitivity cannot be read is refused, and an absent or ordinary key
 * is allowed.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ExampleSet
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-set-its-own-apps-config-and-undo-exactly-that-req-exs-015
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

use OCA\OpenRegister\Service\ExampleSet\ExampleSetAppConfig;
use OCP\IAppConfig;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Golden for ExampleSetAppConfig::refusal().
 */
class ExampleSetAppConfigRefusalGoldenTest extends TestCase {

	/**
	 * The refusal for each kind of key, generated or written from a file.
	 *
	 * @return void
	 */
	public function testRefusalsMatchGolden(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('hasKey')->willReturnCallback(static fn (string $app, string $key): bool => $key !== 'absent');
		$config->method('isSensitive')->willReturnCallback(
			static function (string $app, string $key): bool {
				if ($key === 'unreadable') {
					throw new RuntimeException('no lazy cache');
				}

				return $key === 'secret';
			}
		);
		$subject = new ExampleSetAppConfig($config, $this->createMock(ISecureRandom::class));

		$refused = 'app config alpha secret is kept as sensitive; a set may only generate such a value, never write one from a file';
		$this->assertSame($refused, $subject->refusal(app: 'alpha', key: 'secret', generate: false));
		$this->assertSame('', $subject->refusal(app: 'alpha', key: 'secret', generate: true));
		$this->assertSame(
			'app config alpha unreadable is kept as sensitive; a set may only generate such a value, never write one from a file',
			$subject->refusal(app: 'alpha', key: 'unreadable', generate: false)
		);
		$this->assertSame('', $subject->refusal(app: 'alpha', key: 'plain', generate: false));
		$this->assertSame('', $subject->refusal(app: 'alpha', key: 'absent', generate: false));
	}//end testRefusalsMatchGolden()
}//end class
