<?php

/**
 * The translation values carry data only and name no translation entity.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\Translation;
use OCA\OpenRegister\Db\TranslationEdit;
use OCA\OpenRegister\Db\TranslationFilter;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @covers \OCA\OpenRegister\Db\TranslationEdit
 * @covers \OCA\OpenRegister\Db\TranslationFilter
 */
final class TranslationValuesTest extends TestCase {
	/**
	 * The entity's outdated status is the filter's value, so the stored string did not change.
	 *
	 * @return void
	 */
	public function testTheOutdatedStatusKeepsItsStoredValue(): void {
		self::assertSame('outdated', TranslationFilter::OUTDATED_STATUS);
		self::assertSame(TranslationFilter::OUTDATED_STATUS, Translation::STATUS_OUTDATED);
		self::assertContains(TranslationFilter::OUTDATED_STATUS, Translation::ALL_STATUSES);
	}//end testTheOutdatedStatusKeepsItsStoredValue()

	/**
	 * An out-of-date search adds the outdated status after the four column filters.
	 *
	 * @return void
	 */
	public function testAnOutOfDateSearchNarrowsToTheOutdatedStatus(): void {
		$filter = new TranslationFilter(language: 'de', status: 'approved', objectUuid: 'obj-1', sourceLanguage: 'en');

		self::assertSame(
			[['language', 'de'], ['status', 'approved'], ['object_uuid', 'obj-1'], ['source_language', 'en'], ['status', 'outdated']],
			$filter->equalities(isOutOfDate: true)
		);
		self::assertCount(4, $filter->equalities(isOutOfDate: false));
	}//end testAnOutOfDateSearchNarrowsToTheOutdatedStatus()

	/**
	 * The edit is a pure value: its constructor arguments and no methods of its own.
	 *
	 * @return void
	 */
	public function testTheEditOnlyCarriesValues(): void {
		$edit = new TranslationEdit(value: 'Hallo', status: 'approved', translator: 'alice', sourceLanguage: 'en');

		self::assertSame(['Hallo', 'approved', 'alice', 'en'], [$edit->value, $edit->status, $edit->translator, $edit->sourceLanguage]);
		$methods = array_map(static fn ($method) => $method->getName(), (new ReflectionClass(TranslationEdit::class))->getMethods());
		self::assertSame(['__construct'], $methods);
	}//end testTheEditOnlyCarriesValues()
}//end class
