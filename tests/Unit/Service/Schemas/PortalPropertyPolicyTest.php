<?php

/**
 * The read and write rules of the `portal` property key.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Schemas
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/a-property-says-what-the-portal-may-do-with-it/specs/schema-property-exploration/spec.md#requirement-a-property-declares-what-the-portal-may-do-with-it
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Schemas;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Schemas\PortalPropertyPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The spec's `case` schema, read and written on behalf of a resident.
 */
#[CoversClass(PortalPropertyPolicy::class)]
#[UsesClass(Schema::class)]
class PortalPropertyPolicyTest extends TestCase {

	/**
	 * The case schema: `subject` readable, `phone` editable, `internalNote` unmarked.
	 *
	 * @return Schema The schema.
	 */
	private function caseSchema(): Schema {
		$schema = new Schema();
		$schema->setProperties(
			[
				'subject'      => ['type' => 'string', 'portal' => 'read'],
				'phone'        => ['type' => 'string', 'portal' => 'edit'],
				'internalNote' => ['type' => 'string'],
				'priority'     => ['type' => 'string', 'portal' => 'none'],
			]
		);

		return $schema;
	}//end caseSchema()

	/**
	 * An unmarked, a `none` and an unknown level all read as none; the others as declared.
	 *
	 * @return void
	 */
	public function testTheLevelOfAPropertyDefaultsToNone(): void {
		$this->assertSame(expected: 'none', actual: PortalPropertyPolicy::levelOf(definition: ['type' => 'string']));
		$this->assertSame(expected: 'none', actual: PortalPropertyPolicy::levelOf(definition: ['portal' => 'none']));
		$this->assertSame(expected: 'none', actual: PortalPropertyPolicy::levelOf(definition: ['portal' => 'write']));
		$this->assertSame(expected: 'none', actual: PortalPropertyPolicy::levelOf(definition: null));
		$this->assertSame(expected: 'read', actual: PortalPropertyPolicy::levelOf(definition: ['portal' => 'read']));
		$this->assertSame(expected: 'edit', actual: PortalPropertyPolicy::levelOf(definition: ['portal' => ' EDIT ']));
	}//end testTheLevelOfAPropertyDefaultsToNone()

	/**
	 * Scenario "an internal note stays off the portal": subject and phone stay, the note and the none property go.
	 *
	 * @return void
	 */
	public function testAnInternalNoteStaysOffThePortal(): void {
		$object = [
			'id'           => 'case-1',
			'subject'      => 'Bin not collected',
			'phone'        => '0612345678',
			'internalNote' => 'caller was rude',
			'priority'     => 'high',
			'undeclared'   => 'kept by nobody',
			'@self'        => ['id' => 'case-1'],
		];

		$this->assertSame(
			expected: ['id' => 'case-1', 'subject' => 'Bin not collected', 'phone' => '0612345678', '@self' => ['id' => 'case-1']],
			actual: (new PortalPropertyPolicy())->readable(properties: $this->caseSchema()->getProperties(), object: $object)
		);
	}//end testAnInternalNoteStaysOffThePortal()

	/**
	 * Scenario "the resident changes only what they may change": a change to subject is refused, one to phone is not.
	 *
	 * @return void
	 */
	public function testTheResidentChangesOnlyWhatTheyMayChange(): void {
		$policy = new PortalPropertyPolicy();
		$stored = ['subject' => 'Bin not collected', 'phone' => '0612345678', 'internalNote' => 'caller was rude'];

		$this->assertSame(expected: ['subject'], actual: $policy->refusedChanges(properties: $this->caseSchema()->getProperties(), incoming: ['subject' => 'changed'], existing: $stored));
		$this->assertSame(expected: [], actual: $policy->refusedChanges(properties: $this->caseSchema()->getProperties(), incoming: ['phone' => '0699999999'], existing: $stored));
		$this->assertSame(
			expected: ['subject', 'internalNote'],
			actual: $policy->refusedChanges(properties: $this->caseSchema()->getProperties(), incoming: ['phone' => '1', 'subject' => 'x', 'internalNote' => 'y'], existing: $stored)
		);
	}//end testTheResidentChangesOnlyWhatTheyMayChange()

	/**
	 * Re-sending a stored value unchanged, request options and the envelope are no change.
	 *
	 * @return void
	 */
	public function testAnUnchangedValueAndRequestOptionsAreNoChange(): void {
		$stored = ['subject' => 'Bin not collected', 'phone' => '0612345678'];

		$this->assertSame(
			expected: [],
			actual: (new PortalPropertyPolicy())->refusedChanges(
				properties: $this->caseSchema()->getProperties(),
				incoming: ['subject' => 'Bin not collected', 'phone' => '1', '_extend' => ['x'], '@self' => ['status' => 'x'], 'id' => 'case-1'],
				existing: $stored
			)
		);
	}//end testAnUnchangedValueAndRequestOptionsAreNoChange()

	/**
	 * A create sets every property it sends, so only `edit` properties may be sent.
	 *
	 * @return void
	 */
	public function testACreateMaySetOnlyEditableProperties(): void {
		$policy = new PortalPropertyPolicy();

		$this->assertSame(expected: ['subject'], actual: $policy->refusedChanges(properties: $this->caseSchema()->getProperties(), incoming: ['subject' => 'new', 'phone' => '1'], existing: null));
		$this->assertSame(expected: [], actual: $policy->refusedChanges(properties: $this->caseSchema()->getProperties(), incoming: ['phone' => '1'], existing: null));
	}//end testACreateMaySetOnlyEditableProperties()

	/**
	 * A replace that leaves out a stored value empties it, so that is a change too; a merge leaves it alone.
	 *
	 * @return void
	 */
	public function testAReplaceThatDropsAStoredValueIsAChange(): void {
		$policy = new PortalPropertyPolicy();
		$stored = ['subject' => 'Bin not collected', 'phone' => '0612345678', 'internalNote' => 'caller was rude'];

		$this->assertSame(
			expected: ['subject', 'internalNote'],
			actual: $policy->refusedChanges(properties: $this->caseSchema()->getProperties(), incoming: ['phone' => '1'], existing: $stored, replace: true)
		);
		$this->assertSame(expected: [], actual: $policy->refusedChanges(properties: $this->caseSchema()->getProperties(), incoming: ['phone' => '1'], existing: $stored, replace: false));
		$this->assertSame(
			expected: [],
			actual: $policy->refusedChanges(properties: $this->caseSchema()->getProperties(), incoming: $stored + ['phone' => '1'], existing: $stored, replace: true)
		);
	}//end testAReplaceThatDropsAStoredValueIsAChange()

	/**
	 * An object value re-sent with its keys in another order is no change.
	 *
	 * @return void
	 */
	public function testAnObjectValueInAnotherKeyOrderIsNoChange(): void {
		$properties = ['address' => ['type' => 'object', 'portal' => 'read']];

		$this->assertSame(
			expected: [],
			actual: (new PortalPropertyPolicy())->refusedChanges(
				properties: $properties,
				incoming: ['address' => ['city' => 'Utrecht', 'street' => 'Plein']],
				existing: ['address' => ['street' => 'Plein', 'city' => 'Utrecht']]
			)
		);
	}//end testAnObjectValueInAnotherKeyOrderIsNoChange()
}//end class
