<?php

/**
 * Tests for the app config key value object of an example set.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;


use OCA\OpenRegister\Service\ExampleSet\ExampleSetConfigKey;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetConfigValue;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetConfigValue
 */
class ExampleSetConfigValueTest extends TestCase {

	public function testDefaultsToAnExactTextValue(): void {
		$value = new ExampleSetConfigValue(at: new ExampleSetConfigKey(app: 'a', key: 'k'));

		$this->assertSame(['a', 'k', '', 'string', ExampleSetConfigValue::KIND_EXACT], [$value->app, $value->key, $value->value, $value->type, $value->kind]);
	}

	public function testCarriesWhatItIsGiven(): void {
		$value = new ExampleSetConfigValue(at: new ExampleSetConfigKey(app: 'a', key: 'n'), value: '8', type: 'int', kind: ExampleSetConfigValue::KIND_FLOOR);

		$this->assertSame(['8', 'int', 'floor'], [$value->value, $value->type, $value->kind]);
	}
}//end class
