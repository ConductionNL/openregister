<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\FileService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Golden test for the FileService constructor: the debug log lines and the handlers
 * that receive the service through setFileService(), in order, are compared with
 * fixtures/file-service-constructor-golden.json. Set UPDATE_GOLDEN=1 to rewrite it.
 */
class FileServiceConstructorGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/file-service-constructor-golden.json';

	public function testConstructorWiringMatchesGolden(): void {
		$calls = [];
		$arguments = [];
		foreach ((new \ReflectionMethod(FileService::class, '__construct'))->getParameters() as $parameter) {
			$type = (string)$parameter->getType();
			$double = $this->createMock($type);
			if ($type === LoggerInterface::class) {
				$double->method('debug')->willReturnCallback(static function (string $message, array $context = []) use (&$calls): void {
					unset($context['file'], $context['line']);
					$calls[] = ['debug', $message, $context];
				});
			} elseif (method_exists($type, 'setFileService') === true) {
				$name = $parameter->getName();
				$double->method('setFileService')->willReturnCallback(static function ($service) use (&$calls, $name): void {
					$calls[] = ['setFileService', $name, $service instanceof FileService];
				});
			}

			$arguments[] = $double;
		}

		new FileService(...$arguments);

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($calls, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$this->assertSame(json_decode((string)file_get_contents(self::FIXTURE), true), $calls);
	}//end testConstructorWiringMatchesGolden()
}//end class
