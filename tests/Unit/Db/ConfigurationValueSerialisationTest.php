<?php

/**
 * ConfigurationValue serialisation tests.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use DateTimeZone;
use OCA\OpenRegister\Db\ConfigurationValue;
use PHPUnit\Framework\TestCase;

/**
 * The stored value is wrapped as {value: ...}; readers and JSON see it unwrapped.
 */
class ConfigurationValueSerialisationTest extends TestCase {

	/**
	 * readValue() unwraps the stored value.
	 *
	 * @return void
	 */
	public function testReadValueUnwrapsTheStoredValue(): void {
		$value = new ConfigurationValue();
		$value->setConfigValue(['value' => ['dark' => true]]);

		$this->assertSame(['dark' => true], $value->readValue());
	}//end testReadValueUnwrapsTheStoredValue()

	/**
	 * Without a stored value, or without its value key, readValue() is null.
	 *
	 * @return void
	 */
	public function testReadValueWithoutValueIsNull(): void {
		$empty   = new ConfigurationValue();
		$keyless = new ConfigurationValue();
		$keyless->setConfigValue(['other' => 1]);

		$this->assertNull($empty->readValue());
		$this->assertNull($keyless->readValue());
	}//end testReadValueWithoutValueIsNull()

	/**
	 * jsonSerialize() names the key and deployment fields and formats dates as ATOM.
	 *
	 * @return void
	 */
	public function testJsonSerializeExposesTheUnwrappedValue(): void {
		$utc   = new DateTimeZone('UTC');
		$value = new ConfigurationValue();
		$value->setId(3);
		$value->setUuid('v-1');
		$value->setLayer('register');
		$value->setLayerRef('reg-7');
		$value->setConfigKey('title');
		$value->setConfigValue(['value' => 'Zaken']);
		$value->setDeploymentUuid('dep-1');
		$value->setBundleUuid('bun-1');
		$value->setUpdatedBy('jan');
		$value->setCreated(new DateTime('2026-01-02T03:04:05', $utc));
		$value->setUpdated(new DateTime('2026-02-03T04:05:06', $utc));

		$this->assertSame(
			[
				'id' => 3,
				'uuid' => 'v-1',
				'layer' => 'register',
				'layerRef' => 'reg-7',
				'key' => 'title',
				'value' => 'Zaken',
				'deployment' => 'dep-1',
				'bundle' => 'bun-1',
				'updatedBy' => 'jan',
				'created' => '2026-01-02T03:04:05+00:00',
				'updated' => '2026-02-03T04:05:06+00:00',
			],
			$value->jsonSerialize()
		);
	}//end testJsonSerializeExposesTheUnwrappedValue()

	/**
	 * Missing dates serialise as null.
	 *
	 * @return void
	 */
	public function testJsonSerializeWithoutDates(): void {
		$json = (new ConfigurationValue())->jsonSerialize();

		$this->assertNull($json['created']);
		$this->assertNull($json['updated']);
		$this->assertNull($json['value']);
	}//end testJsonSerializeWithoutDates()
}//end class
