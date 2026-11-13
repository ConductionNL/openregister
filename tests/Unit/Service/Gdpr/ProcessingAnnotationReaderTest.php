<?php

/**
 * ProcessingAnnotationReader tests: the dialect, the legacy shorthand and the
 * declared subject id fields, with and without a type.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Gdpr
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Gdpr;

use OCA\OpenRegister\Service\Gdpr\ProcessingAnnotationReader;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ProcessingAnnotationReader.
 *
 * @covers \OCA\OpenRegister\Service\Gdpr\ProcessingAnnotationReader
 */
class ProcessingAnnotationReaderTest extends TestCase {

	/**
	 * The dialect is normalised; non-array parts become empty arrays.
	 *
	 * @return void
	 */
	public function testTheDialectIsNormalised(): void {
		$read = (new ProcessingAnnotationReader())->read(
			config: [
				'x-openregister-processing' => [
					'logReads' => true,
					'attribution' => ['default' => 'va-1'],
					'subjectIdFields' => 'not a map',
				],
			]
		);

		$this->assertSame(['logReads' => true, 'attribution' => ['default' => 'va-1'], 'subjectIdFields' => []], $read);
	}//end testTheDialectIsNormalised()

	/**
	 * logReads is only on when it is literally true.
	 *
	 * @return void
	 */
	public function testLogReadsIsOnlyOnWhenTrue(): void {
		$read = (new ProcessingAnnotationReader())->read(config: ['x-openregister-processing' => ['logReads' => 'yes']]);

		$this->assertFalse($read['logReads']);
	}//end testLogReadsIsOnlyOnWhenTrue()

	/**
	 * The legacy key becomes a default attribution with reads off; the dialect wins over it.
	 *
	 * @return void
	 */
	public function testTheLegacyShorthandAndItsPrecedence(): void {
		$reader = new ProcessingAnnotationReader();

		$this->assertSame(
			['logReads' => false, 'attribution' => ['default' => 'va-legacy'], 'subjectIdFields' => []],
			$reader->read(config: ['x-openregister-processing-activity' => 'va-legacy'])
		);
		$this->assertSame(
			['default' => 'va-new'],
			$reader->read(
				config: [
					'x-openregister-processing-activity' => 'va-legacy',
					'x-openregister-processing' => ['attribution' => ['default' => 'va-new']],
				]
			)['attribution']
		);
	}//end testTheLegacyShorthandAndItsPrecedence()

	/**
	 * No annotation, an empty legacy value or no configuration at all reads as null.
	 *
	 * @return void
	 */
	public function testNothingToReadIsNull(): void {
		$reader = new ProcessingAnnotationReader();

		$this->assertNull($reader->read(config: null));
		$this->assertNull($reader->read(config: []));
		$this->assertNull($reader->read(config: ['x-openregister-processing-activity' => '']));
		$this->assertNull($reader->read(config: ['x-openregister-processing-activity' => ['not' => 'a string']]));
	}//end testNothingToReadIsNull()

	/**
	 * The declared subject id fields, all of them or those of one type; malformed pairs are dropped.
	 *
	 * @return void
	 */
	public function testSubjectIdFieldsByType(): void {
		$config = [
			'x-openregister-processing' => [
				'subjectIdFields' => [
					'BSN' => 'bsn',
					'learnerId' => 'learnerId',
					'empty' => '',
					'list' => ['a'],
					0 => 'positional',
				],
			],
		];
		$reader = new ProcessingAnnotationReader();

		$this->assertSame(['BSN' => 'bsn', 'learnerId' => 'learnerId'], $reader->subjectIdFields(config: $config));
		$this->assertSame(['BSN' => 'bsn', 'learnerId' => 'learnerId'], $reader->subjectIdFields(config: $config, type: ''));
		$this->assertSame(['learnerId' => 'learnerId'], $reader->subjectIdFields(config: $config, type: 'learnerId'));
		$this->assertSame([], $reader->subjectIdFields(config: $config, type: 'email'));
		$this->assertSame([], $reader->subjectIdFields(config: ['x-openregister-processing-activity' => 'va-1']));
	}//end testSubjectIdFieldsByType()
}//end class
