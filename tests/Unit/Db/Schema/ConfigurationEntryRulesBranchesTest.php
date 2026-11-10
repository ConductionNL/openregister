<?php

/**
 * ConfigurationEntryRules keeps, converts or drops each schema configuration entry.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db\Schema
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\Schema;

use InvalidArgumentException;
use OCA\OpenRegister\Db\Schema\ConfigurationEntryRules;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\Schema\ConfigurationEntryRules
 * @uses   \OCA\OpenRegister\Service\Calendar\CalendarDateDeclarationCheck
 * @uses   \OCA\OpenRegister\Service\Calendar\ObjectDateDeclaration
 * @uses   \OCA\OpenRegister\Db\Schema\ConfigurationValueRules
 * @uses   \OCA\OpenRegister\Db\Schema\LinkedTypesRule
 * @uses   \OCA\OpenRegister\Db\Schema\VocabularyEntries
 * @uses   \OCA\OpenRegister\Db\Schema\WriteOnlyPathsDeclaration
 */
class ConfigurationEntryRulesBranchesTest extends TestCase {

	/**
	 * Rules for a schema with a `secret` property.
	 *
	 * @return ConfigurationEntryRules
	 */
	private static function rules(): ConfigurationEntryRules {
		return new ConfigurationEntryRules(['secret' => ['type' => 'object']]);
	}//end rules()

	/**
	 * A valid allowedTags list is kept as given.
	 *
	 * @return void
	 */
	public function testAllowedTagsAreKept(): void {
		$this->assertSame([['urgent', 'late']], self::rules()->validateConfigurationEntry('allowedTags', ['urgent', 'late']));
	}//end testAllowedTagsAreKept()

	/**
	 * An allowedTags list with a non-string is refused.
	 *
	 * @return void
	 */
	public function testAllowedTagsWithANonStringAreRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage("All values in 'allowedTags' must be strings");

		self::rules()->validateConfigurationEntry('allowedTags', ['urgent', 3]);
	}//end testAllowedTagsWithANonStringAreRefused()

	/**
	 * A valid mail template is kept, an invalid one refused.
	 *
	 * @return void
	 */
	public function testMailObjectTemplateIsValidatedThenKept(): void {
		$this->assertSame([['subject' => 'title']], self::rules()->validateConfigurationEntry('mailObjectTemplate', ['subject' => 'title']));

		$this->expectException(InvalidArgumentException::class);
		self::rules()->validateConfigurationEntry('mailObjectTemplate', 'title');
	}//end testMailObjectTemplateIsValidatedThenKept()

	/**
	 * A null draft lifetime is dropped; a numeric string becomes whole days.
	 *
	 * @return void
	 */
	public function testDraftLifetimeIsDroppedWhenNullAndConvertedOtherwise(): void {
		$this->assertSame([], self::rules()->validateConfigurationEntry('draftLifetimeDays', null));
		$this->assertSame([14], self::rules()->validateConfigurationEntry('draftLifetimeDays', '14'));
		$this->assertSame([false], self::rules()->validateConfigurationEntry('draftLifetimeDays', false));
	}//end testDraftLifetimeIsDroppedWhenNullAndConvertedOtherwise()

	/**
	 * Party kinds are normalised as party kinds, link roles as link roles.
	 *
	 * @return void
	 */
	public function testPartyKindsAndLinkRolesAreNormalised(): void {
		$this->assertSame(
			[[['key' => 'person', 'label' => 'Person', 'roles' => ['applicant']]]],
			self::rules()->validateConfigurationEntry('partyKinds', [['key' => 'person', 'label' => 'Person', 'roles' => ['applicant']]])
		);
		$this->assertSame(
			[[['key' => 'author', 'label' => 'author']]],
			self::rules()->validateConfigurationEntry('linkRoles', ['author'])
		);
	}//end testPartyKindsAndLinkRolesAreNormalised()

	/**
	 * Write-only paths are checked against the schema's own properties.
	 *
	 * @return void
	 */
	public function testWriteOnlyPathsAreCheckedAgainstTheProperties(): void {
		$this->assertSame([['secret.pin']], self::rules()->validateConfigurationEntry('x-openregister-writeonly-paths', ['secret.pin', 'secret.pin']));
	}//end testWriteOnlyPathsAreCheckedAgainstTheProperties()

	/**
	 * A known annotation is kept, an unknown one and an unknown key are dropped.
	 *
	 * @return void
	 */
	public function testAnnotationsAreKeptOnlyWhenKnown(): void {
		$this->assertSame([['states' => []]], self::rules()->validateConfigurationEntry('x-openregister-lifecycle', ['states' => []]));
		$this->assertSame([], self::rules()->validateConfigurationEntry('x-openregister-made-up', ['a' => 1]));
		$this->assertSame([], self::rules()->validateConfigurationEntry('somethingElse', 'x'));
	}//end testAnnotationsAreKeptOnlyWhenKnown()

	/**
	 * A non-array calendarProvider passes through unvalidated.
	 *
	 * @return void
	 */
	public function testANonArrayCalendarProviderPassesThrough(): void {
		$this->assertSame(['caldav'], self::rules()->validateConfigurationEntry('calendarProvider', 'caldav'));
	}//end testANonArrayCalendarProviderPassesThrough()
	/**
	 * A boolean field is kept when boolean or null, refused otherwise.
	 *
	 * @return void
	 */
	public function testABooleanFieldIsValidatedThenKept(): void {
		$this->assertSame([true], self::rules()->validateConfigurationEntry('allowFiles', true));
		$this->assertSame([null], self::rules()->validateConfigurationEntry('orphanCleanup', null));

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage("Configuration 'allowFiles' must be a boolean or null");
		self::rules()->validateConfigurationEntry('allowFiles', 'yes');
	}//end testABooleanFieldIsValidatedThenKept()

	/**
	 * An array calendarProvider is validated: kept when disabled, refused when enabled without its fields.
	 *
	 * @return void
	 */
	public function testAnArrayCalendarProviderIsValidated(): void {
		$this->assertSame([['enabled' => false]], self::rules()->validateConfigurationEntry('calendarProvider', ['enabled' => false]));

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('calendarProvider.dtstart is required when calendar provider is enabled');
		self::rules()->validateConfigurationEntry('calendarProvider', ['enabled' => true]);
	}//end testAnArrayCalendarProviderIsValidated()

	/**
	 * linkedTypes is validated before it is kept: null is kept, a non-array refused.
	 *
	 * @return void
	 */
	public function testLinkedTypesAreValidatedThenKept(): void {
		$this->assertSame([null], self::rules()->validateConfigurationEntry('linkedTypes', null));

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage("Configuration 'linkedTypes' must be an array or null");
		self::rules()->validateConfigurationEntry('linkedTypes', 'mail');
	}//end testLinkedTypesAreValidatedThenKept()
	/**
	 * A string field goes through string validation rather than being passed through raw.
	 *
	 * @return void
	 */
	public function testAStringFieldIsValidated(): void {
		$this->assertSame(['title'], self::rules()->validateConfigurationEntry('objectNameField', 'title'));
		$this->assertSame([null], self::rules()->validateConfigurationEntry('objectSlugField', ''));
	}//end testAStringFieldIsValidated()
}//end class
