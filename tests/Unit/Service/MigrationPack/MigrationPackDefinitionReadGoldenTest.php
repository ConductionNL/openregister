<?php

/**
 * Golden coverage for how MigrationPackRequestReader reads a submitted pack.
 *
 * Pins the wrapped and the bare body forms, the dropping of framework-internal
 * parameters, and the refusal of a body `id` that equals the row id in the URL.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\MigrationPack
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/migration-mapping-packs/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\MigrationPack;

use OCA\OpenRegister\Exception\InvalidPackDefinitionException;
use OCA\OpenRegister\Service\MigrationPack\MigrationPackRequestReader;
use OCA\OpenRegister\Service\MigrationPack\PackDefinitionValidator;
use OCA\OpenRegister\Tests\Unit\Support\MergingRequestFake;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Locks the forms a pack definition may arrive in.
 */
final class MigrationPackDefinitionReadGoldenTest extends TestCase {

	/**
	 * A reader whose translator returns the text unchanged.
	 *
	 * @return MigrationPackRequestReader The reader.
	 */
	private function reader(): MigrationPackRequestReader {
		return new MigrationPackRequestReader(new PackDefinitionValidator($this->l10n()), $this->l10n());
	}//end reader()

	/**
	 * A translator that returns the text unchanged.
	 *
	 * @return IL10N The translator.
	 */
	private function l10n(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return $l10n;
	}//end l10n()

	/**
	 * A wrapped definition is taken as it is.
	 *
	 * @return void
	 */
	public function testAWrappedDefinitionIsTakenAsItIs(): void {
		$request = new MergingRequestFake(['id' => '7'], ['definition' => ['id' => '7', 'name' => 'x'], '_route' => 'r']);

		$this->assertSame(['id' => '7', 'name' => 'x'], $this->reader()->definition($request));
	}//end testAWrappedDefinitionIsTakenAsItIs()

	/**
	 * A bare body drops the parameters that start with an underscore, and keeps an id that differs from the row's.
	 *
	 * @return void
	 */
	public function testABareBodyDropsInternalParameters(): void {
		$request = new MergingRequestFake(['id' => '7'], ['id' => 'other', 'name' => 'x', '_route' => 'r']);

		$this->assertSame(['id' => 'other', 'name' => 'x'], $this->reader()->definition($request));

		$noRoute = new MergingRequestFake([], ['id' => '7', 'name' => 'y']);
		$this->assertSame(['id' => '7', 'name' => 'y'], $this->reader()->definition($noRoute));
	}//end testABareBodyDropsInternalParameters()

	/**
	 * A bare body whose id equals the row id is refused, with the remaining errors first.
	 *
	 * @return void
	 */
	public function testABodyIdEqualToTheRowIdIsRefused(): void {
		$request = new MergingRequestFake(['id' => '7'], ['id' => '7', 'name' => 'x']);

		try {
			$this->reader()->definition($request);
			$this->fail('an id equal to the row id must be refused');
		} catch (InvalidPackDefinitionException $e) {
			$errors = $e->getErrors();
			$this->assertSame(
				'An "id" in the request body that equals the row id in the URL cannot be told apart from it.'
				. ' Send the pack as {"definition": {...}} to keep that id.',
				end($errors)
			);
			$this->assertSame(
				(new PackDefinitionValidator($this->l10n()))->validate(definition: ['name' => 'x']),
				array_slice($errors, 0, -1)
			);
		}
	}//end testABodyIdEqualToTheRowIdIsRefused()
}//end class
