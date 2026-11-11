<?php

/**
 * Characterisation of UnknownMetadataFieldException and TranslationTargetConflictException:
 * their messages, codes, previous exceptions, errors and accessors.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Exception
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Exception;

use OCA\OpenRegister\Exception\CustomValidationException;
use OCA\OpenRegister\Exception\TranslationTargetConflictException;
use OCA\OpenRegister\Exception\UnknownMetadataFieldException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Golden messages of two refusal exceptions.
 *
 * @covers \OCA\OpenRegister\Exception\UnknownMetadataFieldException
 * @covers \OCA\OpenRegister\Exception\TranslationTargetConflictException
 */
final class MetadataAndTranslationExceptionsGoldenTest extends TestCase {

	/**
	 * The known columns are listed by their caller-facing names, sorted; the code defaults to 400.
	 *
	 * @return void
	 */
	public function testUnknownMetadataFieldMessage(): void {
		$exception = new UnknownMetadataFieldException('colour', ['_updated', '_created', 'owner', '_schema']);

		$this->assertSame(
			'Unknown "@self" metadata field "colour". Filterable metadata fields are: created, owner, schema, updated.',
			$exception->getMessage()
		);
		$this->assertSame(400, $exception->getCode());
		$this->assertNull($exception->getPrevious());
		$this->assertSame('colour', $exception->getField());
		$this->assertSame(['_updated', '_created', 'owner', '_schema'], $exception->getKnown());
	}//end testUnknownMetadataFieldMessage()

	/**
	 * No known columns, a code and a previous exception.
	 *
	 * @return void
	 */
	public function testUnknownMetadataFieldWithCodeAndPrevious(): void {
		$previous = new RuntimeException('cause');
		$exception = new UnknownMetadataFieldException('x', [], 422, $previous);

		$this->assertSame('Unknown "@self" metadata field "x". Filterable metadata fields are: .', $exception->getMessage());
		$this->assertSame(422, $exception->getCode());
		$this->assertSame($previous, $exception->getPrevious());
	}//end testUnknownMetadataFieldWithCodeAndPrevious()

	/**
	 * The translation conflict names the property and the target language, in the message and the errors.
	 *
	 * @return void
	 */
	public function testTranslationTargetConflict(): void {
		$exception = new TranslationTargetConflictException('title', 'nl');

		$this->assertInstanceOf(CustomValidationException::class, $exception);
		$this->assertSame(
			'Cannot mix language-keyed body for "title" with X-Translation-Target-Language: nl',
			$exception->getMessage()
		);
		$this->assertSame(
			['code' => TranslationTargetConflictException::ERROR_CODE, 'property' => 'title', 'targetLanguage' => 'nl'],
			$exception->getErrors()
		);
		$this->assertSame('title', $exception->getProperty());
		$this->assertSame('nl', $exception->getTargetLanguage());
		$this->assertSame(0, $exception->getCode());
	}//end testTranslationTargetConflict()
}//end class
