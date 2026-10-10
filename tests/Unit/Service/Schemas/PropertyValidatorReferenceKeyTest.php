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
use OCA\OpenRegister\Service\Schemas\PropertyValidatorHandler;
use PHPUnit\Framework\TestCase;

/**
 * `referenceSchema` + `referenceKey` + `onDelete: RESTRICT` is a valid property; other actions are not.
 *
 * @covers \OCA\OpenRegister\Service\Schemas\PropertyValidatorHandler
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
}//end class
