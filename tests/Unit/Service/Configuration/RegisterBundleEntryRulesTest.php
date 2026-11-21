<?php

/**
 * Why one entry of a bundle a register manager uploaded may not be imported:
 * another register, a schema linked from outside the register, a schema that
 * is not the register's own, or a rule widened to read across organisations.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Configuration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Configuration\RegisterBundleEntryRules;
use PHPUnit\Framework\TestCase;

/**
 * RegisterBundleEntryRules.
 *
 * @covers \OCA\OpenRegister\Service\Configuration\RegisterBundleEntryRules
 * @uses \OCA\OpenRegister\Service\Rbac\CrossOrganisationMarkerChange
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\Schema
 */
class RegisterBundleEntryRulesTest extends TestCase {

	private const OTHER_REGISTER = 'A register manager may not import another register';
	private const FOREIGN_LINK   = 'A register manager may not link another schema to this register';
	private const FOREIGN_SCHEMA = 'A register manager may only import schemas of this register';
	private const CROSS_ORG      = 'Only an administrator may let a rule read across organisations (crossOrganisation: true)';

	private function rules(?array $ownAuthorization = null): RegisterBundleEntryRules {
		$register = new Register();
		$register->setSlug('zaken');

		$case = new Schema();
		$case->setSlug('case');
		$case->setAuthorization($ownAuthorization);

		$note = new Schema();
		$note->setSlug('note');

		return new RegisterBundleEntryRules(register: $register, own: ['case' => $case, 'note' => $note]);
	}//end rules()

	public function testTheRegisterItselfWithItsOwnSchemasIsAllowed(): void {
		$this->assertNull($this->rules()->registerEntryRefusal('zaken', ['slug' => 'zaken', 'schemas' => ['case', 'note']]));
	}//end testTheRegisterItselfWithItsOwnSchemasIsAllowed()

	public function testTheSlugFallsBackToTheKeyAndSchemasToNone(): void {
		$this->assertNull($this->rules()->registerEntryRefusal('zaken', []));
	}//end testTheSlugFallsBackToTheKeyAndSchemasToNone()

	public function testAnotherRegisterIsRefused(): void {
		$this->assertSame(self::OTHER_REGISTER, $this->rules()->registerEntryRefusal('other', ['slug' => 'other']));
	}//end testAnotherRegisterIsRefused()

	public function testAnEntryKeyedOtherThanItsSlugIsRefused(): void {
		$this->assertSame(self::OTHER_REGISTER, $this->rules()->registerEntryRefusal('other', ['slug' => 'zaken']));
		$this->assertSame(self::OTHER_REGISTER, $this->rules()->registerEntryRefusal('zaken', ['slug' => 'other']));
	}//end testAnEntryKeyedOtherThanItsSlugIsRefused()

	public function testANonArraySchemaListIsRefused(): void {
		$this->assertSame(self::OTHER_REGISTER, $this->rules()->registerEntryRefusal('zaken', ['slug' => 'zaken', 'schemas' => 'case']));
	}//end testANonArraySchemaListIsRefused()

	public function testLinkingASchemaFromOutsideTheRegisterIsRefused(): void {
		$this->assertSame(
			self::FOREIGN_LINK,
			$this->rules()->registerEntryRefusal('zaken', ['slug' => 'zaken', 'schemas' => ['case', 'invoice']])
		);
	}//end testLinkingASchemaFromOutsideTheRegisterIsRefused()

	public function testAnOwnSchemaIsAllowed(): void {
		$this->assertNull($this->rules()->schemaEntryRefusal('case', ['slug' => 'case', 'title' => 'Case']));
		$this->assertNull($this->rules()->schemaEntryRefusal('note', ['title' => 'Note']));
	}//end testAnOwnSchemaIsAllowed()

	public function testASchemaTheRegisterDoesNotOwnIsRefused(): void {
		$this->assertSame(self::FOREIGN_SCHEMA, $this->rules()->schemaEntryRefusal('invoice', ['slug' => 'invoice']));
	}//end testASchemaTheRegisterDoesNotOwnIsRefused()

	public function testASchemaRenamedUnderAnOwnKeyIsRefused(): void {
		$this->assertSame(self::FOREIGN_SCHEMA, $this->rules()->schemaEntryRefusal('case', ['slug' => 'invoice']));
	}//end testASchemaRenamedUnderAnOwnKeyIsRefused()

	public function testANonArraySchemaDocumentIsRefused(): void {
		$this->assertSame(self::FOREIGN_SCHEMA, $this->rules()->schemaEntryRefusal('case', 'case'));
	}//end testANonArraySchemaDocumentIsRefused()

	public function testWideningARuleAcrossOrganisationsIsRefused(): void {
		$document = ['slug' => 'case', 'authorization' => ['read' => [['group' => 'g', 'crossOrganisation' => true]]]];

		$this->assertSame(self::CROSS_ORG, $this->rules()->schemaEntryRefusal('case', $document));
	}//end testWideningARuleAcrossOrganisationsIsRefused()

	public function testKeepingAnAlreadyCrossOrganisationRuleIsAllowed(): void {
		$authorization = ['read' => [['group' => 'g', 'crossOrganisation' => true]]];

		$this->assertNull(
			$this->rules(ownAuthorization: $authorization)->schemaEntryRefusal('case', ['slug' => 'case', 'authorization' => $authorization])
		);
	}//end testKeepingAnAlreadyCrossOrganisationRuleIsAllowed()
}//end class
