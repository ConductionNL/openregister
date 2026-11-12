<?php

/**
 * Unit tests for an automatic transition whose `inputs` is not a list.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Lifecycle;

use OCA\OpenRegister\Service\Lifecycle\LifecycleTransitionOptionsValidator;
use PHPUnit\Framework\TestCase;

/**
 * `inputs` that is no list declares no required input, so `autoWhen` beside it is not refused for that;
 * a list with a required input is.
 *
 * @covers \OCA\OpenRegister\Service\Lifecycle\LifecycleTransitionOptionsValidator
 */
class LifecycleTransitionOptionsAutoWhenInputsTest extends TestCase {

	/**
	 * The error codes for a transition with autoWhen and the given inputs.
	 *
	 * @param mixed $inputs The inputs.
	 *
	 * @return list<string> The codes.
	 */
	private function codes(mixed $inputs): array {
		$errors = (new LifecycleTransitionOptionsValidator())->validate(
			['from' => ['open'], 'to' => 'closed', 'autoWhen' => ['!!' => ['var' => 'object.done']], 'inputs' => $inputs],
			'close'
		);

		return array_column($errors, 'code');

	}//end codes()

	/**
	 * Non-list inputs do not trip the required-input refusal; a required input does.
	 *
	 * @return void
	 */
	public function testOnlyARequiredInputListIsRefused(): void {
		$this->assertNotContains('lifecycle-autowhen-requires-input', $this->codes('reason'));
		$this->assertNotContains('lifecycle-autowhen-requires-input', $this->codes([['name' => 'reason']]));
		$this->assertContains('lifecycle-autowhen-requires-input', $this->codes([['name' => 'reason', 'required' => true]]));

	}//end testOnlyARequiredInputListIsRefused()
}//end class
