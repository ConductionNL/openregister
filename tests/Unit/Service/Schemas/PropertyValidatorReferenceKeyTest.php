<?php

/**
 * Property validation of a reference by key.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Schemas
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/referential-integrity/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Schemas;

use Exception;
use OCA\OpenRegister\Service\Object\ReferenceKey;
use OCA\OpenRegister\Service\Schemas\PropertyValidatorHandler;
use PHPUnit\Framework\TestCase;

/**
 * `referenceSchema` + `referenceKey` + `onDelete: RESTRICT` is a valid property; other actions are not.
 *
 * @covers \OCA\OpenRegister\Service\Schemas\PropertyValidatorHandler
 * @uses \OCA\OpenRegister\Service\Schemas\CodedChoiceDeclaration
 * @uses \OCA\OpenRegister\Service\Schemas\GeneratedIdentifierDeclaration
 * @uses \OCA\OpenRegister\Service\Schemas\PropertySourceDeclaration
 * @uses \OCA\OpenRegister\Service\Schemas\ReferenceFilterDeclaration
 * @uses \OCA\OpenRegister\Service\Schemas\RepeatingGroupDeclarationValidator
 * @uses \OCA\OpenRegister\Service\Schemas\ScopedPropertyDeclaration
 * @uses \OCA\OpenRegister\Service\Vocabulary\CodedPropertyDeclarationFactory
 * @uses \OCA\OpenRegister\Service\Object\ReferenceKey
 */
class PropertyValidatorReferenceKeyTest extends TestCase {

	/**
	 * A key reference that restricts is accepted, on the property or on its items.
	 *
	 * @return void
	 */
	public function testRestrictingKeyReferenceIsValid(): void {
		$validator = new PropertyValidatorHandler();

		self::assertTrue($validator->validateProperty(property: ['type' => 'string', 'referenceSchema' => 'label', 'referenceKey' => 'code', 'onDelete' => 'RESTRICT']));
		self::assertTrue(
			$validator->validateProperty(
				property: ['type' => 'array', 'items' => ['type' => 'string', 'referenceSchema' => 'label', 'referenceKey' => 'code'], 'onDelete' => 'restrict']
			)
		);
	}//end testRestrictingKeyReferenceIsValid()

	/**
	 * A key reference with an action that rewrites by uuid is refused.
	 *
	 * @return void
	 */
	public function testCascadingKeyReferenceIsRefused(): void {
		$this->expectException(Exception::class);
		$this->expectExceptionMessage("'referenceKey' at '' is only valid with onDelete RESTRICT");

		(new PropertyValidatorHandler())->validateProperty(property: ['type' => 'string', 'referenceSchema' => 'label', 'referenceKey' => 'code', 'onDelete' => 'CASCADE']);
	}//end testCascadingKeyReferenceIsRefused()

	/**
	 * onDelete without a `$ref` or `referenceSchema` is still refused.
	 *
	 * @return void
	 */
	public function testOnDeleteWithoutTargetIsRefused(): void {
		$this->expectException(Exception::class);
		$this->expectExceptionMessage('only valid on relation properties');

		(new PropertyValidatorHandler())->validateProperty(property: ['type' => 'string', 'referenceKey' => 'code', 'onDelete' => 'RESTRICT']);
	}//end testOnDeleteWithoutTargetIsRefused()

	/**
	 * `referenceKey: uuid` (or empty) is a plain uuid reference: every onDelete action applies, as the delete reads it.
	 *
	 * @return void
	 */
	public function testAUuidReferenceKeyIsAPlainReferenceAndAcceptsCascade(): void {
		$validator = new PropertyValidatorHandler();

		foreach (['uuid', ' uuid ', ''] as $key) {
			self::assertTrue(
				$validator->validateProperty(
					property: ['type' => 'string', 'referenceSchema' => 'label', 'referenceKey' => $key, 'onDelete' => 'CASCADE']
				),
				'referenceKey "' . $key . '"'
			);
		}

		self::assertTrue(
			$validator->validateProperty(
				property: ['type' => 'array', 'items' => ['type' => 'string', 'referenceSchema' => 'label', 'referenceKey' => 'uuid'], 'onDelete' => 'SET_NULL']
			)
		);
	}//end testAUuidReferenceKeyIsAPlainReferenceAndAcceptsCascade()

	/**
	 * The validator and the delete read one declaration the same way.
	 *
	 * @return void
	 */
	public function testTheReaderAnswersTheKeyOrNullForAUuidReference(): void {
		$reader = new ReferenceKey();

		self::assertSame('code', $reader->keyOf(property: ['referenceKey' => ' code ']));
		self::assertSame('code', $reader->keyOf(property: ['items' => ['referenceKey' => 'code']]));
		self::assertNull($reader->keyOf(property: ['referenceKey' => 'uuid']));
		self::assertNull($reader->keyOf(property: ['referenceKey' => '']));
		self::assertNull($reader->keyOf(property: ['referenceKey' => 7]));
		self::assertNull($reader->keyOf(property: []));
	}//end testTheReaderAnswersTheKeyOrNullForAUuidReference()
}//end class
