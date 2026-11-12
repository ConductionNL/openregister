<?php

/**
 * GeneratedIdentifierDeclarationReader returns what GeneratedIdentifierDeclaration::fromProperty() returns.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Schemas
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Schemas;

use DateTimeImmutable;
use OCA\OpenRegister\Service\Schemas\GeneratedIdentifierDeclaration;
use OCA\OpenRegister\Service\Schemas\GeneratedIdentifierDeclarationInterface;
use OCA\OpenRegister\Service\Schemas\GeneratedIdentifierDeclarationReader;
use OCA\OpenRegister\Service\Schemas\GeneratedIdentifierException;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Schemas\GeneratedIdentifierDeclarationReader
 */
final class GeneratedIdentifierDeclarationReaderTest extends TestCase {

	/**
	 * A declared property yields the same declaration, typed as the interface.
	 *
	 * @return void
	 */
	public function testADeclaredPropertyYieldsTheSameDeclaration(): void {
		$property = ['type' => 'string', 'x-openregister-generated' => ['sequence' => 'case', 'format' => 'Z-{year}-{seq:4}']];
		$read     = GeneratedIdentifierDeclarationReader::fromProperty(property: $property, path: 'caseNumber');
		$direct   = GeneratedIdentifierDeclaration::fromProperty(property: $property, path: 'caseNumber');
		$moment   = new DateTimeImmutable('2026-03-01T10:00:00Z');

		$this->assertInstanceOf(GeneratedIdentifierDeclarationInterface::class, $read);
		$this->assertEquals($direct, $read);
		$this->assertSame('case', $read->sequence());
		$this->assertSame($direct->periodAt(moment: $moment), $read->periodAt(moment: $moment));
		$this->assertSame($direct->render(sequenceValue: 7, moment: $moment), $read->render(sequenceValue: 7, moment: $moment));
		$this->assertSame($direct->parse(value: 'Z-2026-0120'), $read->parse(value: 'Z-2026-0120'));
	}//end testADeclaredPropertyYieldsTheSameDeclaration()

	/**
	 * A property without the annotation yields null.
	 *
	 * @return void
	 */
	public function testAnUndeclaredPropertyYieldsNull(): void {
		$this->assertNull(GeneratedIdentifierDeclarationReader::fromProperty(property: ['type' => 'string']));
	}//end testAnUndeclaredPropertyYieldsNull()

	/**
	 * A malformed declaration is refused the same way.
	 *
	 * @return void
	 */
	public function testAMalformedDeclarationIsRefused(): void {
		$this->expectException(GeneratedIdentifierException::class);
		GeneratedIdentifierDeclarationReader::fromProperty(property: ['x-openregister-generated' => 'not-an-object'], path: 'caseNumber');
	}//end testAMalformedDeclarationIsRefused()

	/**
	 * The generated identifier listener reads declarations without naming the value class.
	 *
	 * @return void
	 */
	public function testTheListenerDoesNotNameTheValueClass(): void {
		$source = (string) file_get_contents(__DIR__ . '/../../../../lib/Listener/GeneratedIdentifierListener.php');
		$this->assertDoesNotMatchRegularExpression('/\bGeneratedIdentifierDeclaration\b(?!Interface|Reader)/', $source);
	}//end testTheListenerDoesNotNameTheValueClass()
}//end class
