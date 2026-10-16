<?php

/**
 * Whether a connector's CallService takes a named argument.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/integration-person-lookup/spec.md#requirement-a-call-may-be-recorded-without-its-bodies
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration;

use ReflectionMethod;

/**
 * Reads the signature of a connector's `call`, so a caller can ask before it passes an option.
 *
 * The connector is another app and a different version of it is installed on
 * every channel; PHP refuses an unknown named argument only when the method
 * runs. Asked of the signature, the answer is the same for every call and no
 * call is made to find out.
 *
 * @spec openspec/specs/integration-person-lookup/spec.md#requirement-a-call-may-be-recorded-without-its-bodies
 */
final class CallSignature {

	/**
	 * Whether `$service->$method()` names the parameter, or collects named arguments.
	 *
	 * @param object $service The connector's CallService.
	 * @param string $method The method asked about.
	 * @param string $name The parameter name.
	 *
	 * @return bool True when the method takes it.
	 *
	 * @spec openspec/specs/integration-person-lookup/spec.md#requirement-a-call-may-be-recorded-without-its-bodies
	 */
	public function accepts(object $service, string $method, string $name): bool {
		if (method_exists($service, $method) === false) {
			return false;
		}

		foreach ((new ReflectionMethod($service, $method))->getParameters() as $parameter) {
			if ($parameter->getName() === $name || $parameter->isVariadic() === true) {
				return true;
			}
		}

		return false;
	}//end accepts()
}//end class
