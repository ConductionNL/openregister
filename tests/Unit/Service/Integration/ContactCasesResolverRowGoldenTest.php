<?php

/**
 * Golden test for the row one contact link becomes.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/contacts-leaf-cases-panel/specs/integration-contacts/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\Integration\ContactCasesPanel;
use OCA\OpenRegister\Service\Integration\ContactCasesResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Pins every field of the row a link becomes, the identifiers the reader is
 * called with, and the warning a failed read logs.
 *
 * @spec openspec/changes/contacts-leaf-cases-panel/specs/integration-contacts/spec.md
 */
class ContactCasesResolverRowGoldenTest extends TestCase {

	/**
	 * @return array<string,array{0:array<string,mixed>,1:mixed,2:array<string,mixed>,3:array<int,array<int,string>>,4:array<int,array<int,mixed>>}>
	 */
	public static function cases(): array {
		$blank = ['title' => '', 'status' => '', 'url' => '', 'readable' => false];

		return [
			'readable with register and schema keys' => [
				['register' => 'dossiq', 'schema' => 'case', 'objectUuid' => 'u-1', 'role' => 'gemachtigde', 'schemaLabel' => 'Zaak'],
				['title' => 'Zaak A', 'status' => 'open', 'url' => '/x'],
				['schema' => 'case', 'schemaLabel' => 'Zaak', 'objectUuid' => 'u-1', 'role' => 'gemachtigde', 'title' => 'Zaak A', 'status' => 'open', 'url' => '/x', 'readable' => true],
				[['dossiq', 'case', 'u-1']],
				[],
			],
			'readable with id keys and no label, role or title' => [
				['registerId' => 7, 'schemaId' => 9, 'objectUuid' => 'u-2'],
				['status' => 3],
				['schema' => '9', 'schemaLabel' => '9', 'objectUuid' => 'u-2', 'role' => '', 'title' => 'u-2', 'status' => '3', 'url' => '', 'readable' => true],
				[['7', '9', 'u-2']],
				[],
			],
			'no object uuid is never read' => [
				['register' => 'r', 'schema' => 's'],
				['title' => 'never'],
				['schema' => 's', 'schemaLabel' => 's', 'objectUuid' => '', 'role' => ''] + $blank,
				[],
				[],
			],
			'read returns null' => [
				['register' => 'r', 'schema' => 's', 'objectUuid' => 'u-3'],
				null,
				['schema' => 's', 'schemaLabel' => 's', 'objectUuid' => 'u-3', 'role' => ''] + $blank,
				[['r', 's', 'u-3']],
				[],
			],
			'read returns an empty array' => [
				['register' => 'r', 'schema' => 's', 'objectUuid' => 'u-4'],
				[],
				['schema' => 's', 'schemaLabel' => 's', 'objectUuid' => 'u-4', 'role' => ''] + $blank,
				[['r', 's', 'u-4']],
				[],
			],
			'read throws' => [
				['register' => 'r', 'schema' => 's', 'objectUuid' => 'u-5', 'role' => 'x'],
				'throw',
				['schema' => 's', 'schemaLabel' => 's', 'objectUuid' => 'u-5', 'role' => 'x'] + $blank,
				[['r', 's', 'u-5']],
				[['[ContactCasesResolver] a linked object could not be read for the contact panel', ['objectUuid' => 'u-5', 'exception' => 'storage is down']]],
			],
		];
	}//end cases()

	/**
	 * @dataProvider cases
	 *
	 * @param array<string,mixed>            $link     The link.
	 * @param mixed                          $object   What the reader returns, or 'throw'.
	 * @param array<string,mixed>            $expected The row.
	 * @param array<int,array<int,string>>   $reads    The identifiers the reader is called with.
	 * @param array<int,array<int,mixed>>    $warnings The warnings logged.
	 */
	public function testARowMatchesGolden(array $link, mixed $object, array $expected, array $reads, array $warnings): void {
		$logged = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			static function (string $message, array $context = []) use (&$logged): void {
				$logged[] = [$message, $context];
			}
		);
		$resolver = new ContactCasesResolver(new ContactCasesPanel(), $logger);

		$calls = [];
		$resolve = static function (string $r, string $s, string $u) use ($object, &$calls): mixed {
			$calls[] = [$r, $s, $u];
			if ($object === 'throw') {
				throw new RuntimeException('storage is down');
			}

			return $object;
		};

		$rowFor = new ReflectionMethod(ContactCasesResolver::class, 'rowFor');
		$row = $rowFor->invoke($resolver, $link, $resolve);

		$this->assertSame($expected, $row);
		$this->assertSame($reads, $calls);
		$this->assertSame($warnings, $logged);
	}//end testARowMatchesGolden()
}//end class
