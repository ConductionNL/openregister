<?php

/**
 * A refused payload becomes findings in the validator's shape, per property.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-submit-must-create-the-destination-in-one-request-and-return-its-reference
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Form;

use OCA\OpenRegister\Exception\CustomValidationException;
use OCA\OpenRegister\Exception\HookStoppedException;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Service\Form\FormPayloadFindings;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

/**
 * Real opis results, the three exception kinds the save path throws.
 *
 * @covers \OCA\OpenRegister\Service\Form\FormPayloadFindings
 * @uses \OCA\OpenRegister\Exception\ValidationException
 * @uses \OCA\OpenRegister\Exception\CustomValidationException
 * @uses \OCA\OpenRegister\Exception\HookStoppedException
 */
class FormPayloadFindingsTest extends TestCase {

	private FormPayloadFindings $findings;

	protected function setUp(): void {
		$this->findings = new FormPayloadFindings();
	}//end setUp()

	/**
	 * A real opis result against a small case schema.
	 *
	 * @param array<string, mixed> $data The payload.
	 */
	private function opisResult(array $data): \Opis\JsonSchema\ValidationResult {
		$schema = json_decode(
			(string)json_encode(
				[
					'type' => 'object',
					'required' => ['title', 'caseType'],
					'properties' => [
						'title' => ['type' => 'string', 'maxLength' => 5],
						'caseType' => ['type' => 'string'],
						'amount' => ['type' => 'integer'],
					],
				]
			)
		);
		$validator = new Validator();
		$validator->setMaxErrors(10);

		return $validator->validate(json_decode((string)json_encode($data)), $schema);
	}//end opisResult()

	/**
	 * A missing required property names the property with code `required`.
	 */
	public function testAMissingRequiredPropertyIsARequiredFinding(): void {
		$result = $this->opisResult(['title' => 'abc']);
		$findings = $this->findings->fromResult(result: $result);

		$this->assertCount(1, $findings);
		$this->assertSame('caseType', $findings[0]['property']);
		$this->assertSame('required', $findings[0]['code']);
		$this->assertNotSame('', $findings[0]['message']);
	}//end testAMissingRequiredPropertyIsARequiredFinding()

	/**
	 * Several problems give several findings, each with its property and keyword.
	 */
	public function testSeveralProblemsGiveSeveralFindings(): void {
		$result = $this->opisResult(['title' => 'far too long', 'caseType' => 'x', 'amount' => 'veel']);
		$findings = $this->findings->fromResult(result: $result);

		$byProperty = [];
		foreach ($findings as $finding) {
			$byProperty[$finding['property']] = $finding['code'];
		}

		$this->assertSame(['title' => 'maxLength', 'amount' => 'type'], $byProperty);
	}//end testSeveralProblemsGiveSeveralFindings()

	/**
	 * A valid result has no findings.
	 */
	public function testAValidResultHasNoFindings(): void {
		$this->assertSame([], $this->findings->fromResult(result: $this->opisResult(['title' => 'abc', 'caseType' => 'x'])));
	}//end testAValidResultHasNoFindings()

	/**
	 * The write a finding belongs to is named when several objects are written.
	 */
	public function testTheWriteIsNamed(): void {
		$findings = $this->findings->fromResult(result: $this->opisResult(['title' => 'abc']), write: 'contact');

		$this->assertSame('contact', $findings[0]['write']);
	}//end testTheWriteIsNamed()

	/**
	 * The save path's exceptions: opis-backed, message-only, custom map and hook list.
	 */
	public function testTheSavePathExceptionsBecomeFindings(): void {
		$opis = $this->opisResult(['title' => 'abc']);
		$fromOpis = $this->findings->fromThrowable(exception: new ValidationException(message: 'Validation failed', errors: $opis->error()));
		$this->assertSame('required', $fromOpis[0]['code']);

		$messageOnly = $this->findings->fromThrowable(exception: new ValidationException(message: 'cannot modify readOnly property identifier'));
		$this->assertSame([['property' => '', 'code' => 'invalid', 'message' => 'cannot modify readOnly property identifier']], $messageOnly);

		$unique = $this->findings->fromThrowable(
			exception: new CustomValidationException(message: 'Fields are not unique: kvk', errors: ['kvk' => 'The identifying fields (kvk) are not unique.'])
		);
		$this->assertSame([['property' => 'kvk', 'code' => 'unique', 'message' => 'The identifying fields (kvk) are not unique.']], $unique);

		$hook = $this->findings->fromThrowable(
			exception: new HookStoppedException(message: 'stopped', errors: [['property' => 'bsn', 'message' => 'BSN fails the eleven test']])
		);
		$this->assertSame([['property' => 'bsn', 'code' => 'refused', 'message' => 'BSN fails the eleven test']], $hook);
	}//end testTheSavePathExceptionsBecomeFindings()

	/**
	 * Whether a throwable is a refusal of the payload (422) or of the moment (503).
	 */
	public function testPayloadRefusalsAreToldApartFromOutages(): void {
		$this->assertTrue($this->findings->isPayloadRefusal(exception: new ValidationException(message: 'x')));
		$this->assertTrue($this->findings->isPayloadRefusal(exception: new CustomValidationException(message: 'x', errors: [])));
		$this->assertTrue($this->findings->isPayloadRefusal(exception: new HookStoppedException()));
		$this->assertFalse($this->findings->isPayloadRefusal(exception: new \RuntimeException('database went away')));
	}//end testPayloadRefusalsAreToldApartFromOutages()
}//end class
