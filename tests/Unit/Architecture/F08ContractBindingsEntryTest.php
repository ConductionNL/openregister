<?php

/**
 * ContractBindings stays inside lib/Service: Application reaches it through
 * ContractBindingsRegistrar, which registers every binding, and no lib/ file
 * outside lib/Service names ContractBindings itself. Comments do not count.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Architecture
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Architecture;

use OCA\OpenRegister\Service\ContractBindings;
use OCA\OpenRegister\Service\ContractBindingsRegistrar;
use OCA\OpenRegister\Tests\Unit\AppHost\RecordingRegistrationContext;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * @coversNothing
 */
final class F08ContractBindingsEntryTest extends TestCase {

	/**
	 * The app's lib directory.
	 *
	 * @var string
	 */
	private const LIB = __DIR__ . '/../../../lib';

	/**
	 * The registrar registers exactly the aliases ContractBindings lists.
	 *
	 * @return void
	 */
	public function testTheRegistrarRegistersEveryBinding(): void {
		$context = new RecordingRegistrationContext();
		ContractBindingsRegistrar::register(context: $context);
		$aliases = array_column($context->aliases, 'target', 'alias');
		$this->assertSame(expected: ContractBindings::BINDINGS, actual: $aliases);
	}//end testTheRegistrarRegistersEveryBinding()

	/**
	 * No lib/ file outside lib/Service names ContractBindings.
	 *
	 * @return void
	 */
	public function testNoFileOutsideServiceNamesContractBindings(): void {
		$named = array_filter(
			$this->sources(),
			fn (string $code, string $path) => str_starts_with($path, 'Service/') === false
				&& $this->names(code: $code, class: ContractBindings::class) === true,
			ARRAY_FILTER_USE_BOTH
		);
		$this->assertSame(expected: [], actual: array_keys($named), message: 'ContractBindings is named outside Service');
	}//end testNoFileOutsideServiceNamesContractBindings()

	/**
	 * Whether comment-free code names the class by its fully qualified name.
	 *
	 * @param string $code  Code with the comments removed.
	 * @param string $class The class.
	 *
	 * @return bool
	 */
	private function names(string $code, string $class): bool {
		return preg_match('/(?<![\w\\\\])\\\\?' . preg_quote($class, '/') . '(?![\w\\\\])/', $code) === 1;
	}//end names()

	/**
	 * Every PHP file under lib/, by lib-relative path, with its comments removed.
	 *
	 * @return array<string, string>
	 */
	private function sources(): array {
		$sources = [];
		$files   = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::LIB));
		foreach ($files as $file) {
			if ($file->isFile() === true && $file->getExtension() === 'php') {
				$path = substr($file->getPathname(), strlen(self::LIB) + 1);
				$text = '';
				foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
					if (is_array($token) === false) {
						$text .= $token;
					} else if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) === false) {
						$text .= $token[1];
					}
				}

				$sources[$path] = $text;
			}
		}

		ksort($sources);
		return $sources;
	}//end sources()
}//end class
