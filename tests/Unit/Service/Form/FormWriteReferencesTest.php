<?php

/**
 * One write of a submit may point at an earlier one with { "$write": "<as>" }.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-submit-must-create-the-destination-in-one-request-and-return-its-reference
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Form;

use OCA\OpenRegister\Exception\FormSubmitRefusedException;
use OCA\OpenRegister\Service\Form\FormSubmitService;
use OCA\OpenRegister\Service\Form\FormWriteReferences;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Pending, resolved and unknown write references.
 */
class FormWriteReferencesTest extends TestCase {

	private function references(): FormWriteReferences {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf(str_replace('%1$s', '%s', $text), $params));

		return new FormWriteReferences(l10n: $l10n);
	}//end references()

	public function testAPendingReferenceBecomesThePlaceholderId(): void {
		$object = ['case' => ['$write' => 'case'], 'name' => 'Ada'];

		$resolved = $this->references()->resolveWriteReferences($object, [], true);

		$this->assertSame(['case' => FormSubmitService::PENDING_ID, 'name' => 'Ada'], $resolved);
	}//end testAPendingReferenceBecomesThePlaceholderId()

	public function testAReferenceToAnEarlierWriteBecomesItsId(): void {
		$object = ['case' => ['$write' => 'case'], 'other' => ['$write' => 'x', 'y' => 1]];

		$resolved = $this->references()->resolveWriteReferences($object, ['case' => 'uuid-1'], false);

		$this->assertSame(['case' => 'uuid-1', 'other' => ['$write' => 'x', 'y' => 1]], $resolved);
	}//end testAReferenceToAnEarlierWriteBecomesItsId()

	public function testAReferenceToNoEarlierWriteIsRefused(): void {
		try {
			$this->references()->resolveWriteReferences(['case' => ['$write' => 'case']], [], false);
			$this->fail('Expected a refusal');
		} catch (FormSubmitRefusedException $exception) {
			$this->assertSame(422, $exception->getStatus());
			$this->assertSame('Write "case" is referenced before it is made.', $exception->getMessage());
			$this->assertSame([['property' => 'case', 'code' => 'write-unknown', 'message' => 'Write "case" is referenced before it is made.']], $exception->getFindings());
		}
	}//end testAReferenceToNoEarlierWriteIsRefused()
}//end class
