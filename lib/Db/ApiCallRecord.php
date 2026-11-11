<?php

/**
 * One caller, one route, one contract version, and how often.
 *
 * The record that turns a deprecation into a conversation. Without it, "we are
 * withdrawing version 1 in March" is a sentence a gemeente sends to a mailing
 * list and confirms by breaking; with it, they can name the two leveranciers
 * still calling and ring them.
 *
 * 🔴 THERE IS NO PAYLOAD FIELD AND THERE MUST NEVER BE ONE. Principal, route,
 * method, version, count, first and last seen. Nothing else. Recording what a
 * caller sent would put a second copy of case data in a log, and a log is
 * exactly where nobody looks for personal data when an erasure request arrives
 * (design D-4).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/api-surface-governance/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Class ApiCallRecord
 *
 * @method string|null getPrincipal()
 * @method void setPrincipal(?string $principal)
 * @method string|null getRoute()
 * @method void setRoute(?string $route)
 * @method string|null getMethod()
 * @method void setMethod(?string $method)
 * @method string|null getApiVersion()
 * @method void setApiVersion(?string $apiVersion)
 * @method int|null getCallCount()
 * @method void setCallCount(?int $callCount)
 * @method DateTime|null getFirstSeen()
 * @method void setFirstSeen(?DateTime $firstSeen)
 * @method DateTime|null getLastSeen()
 * @method void setLastSeen(?DateTime $lastSeen)
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 */
class ApiCallRecord extends Entity implements JsonSerializable {
	use TypedFieldsJsonTrait;

	/**
	 * The Nextcloud uid, or the empty string for an anonymous caller.
	 *
	 * @var string|null
	 */
	protected $principal = '';

	/**
	 * The route pattern, not the expanded URL.
	 *
	 * @var string|null
	 */
	protected $route = '';

	/**
	 * The HTTP method.
	 *
	 * @var string|null
	 */
	protected $method = 'GET';

	/**
	 * The contract version that served the call.
	 *
	 * @var string|null
	 */
	protected $apiVersion = '1';

	/**
	 * How many calls this caller made to this route on this version.
	 *
	 * @var integer|null
	 */
	protected $callCount = 0;

	/**
	 * When this combination was first seen.
	 *
	 * @var DateTime|null
	 */
	protected $firstSeen = null;

	/**
	 * When this combination was last seen.
	 *
	 * @var DateTime|null
	 */
	protected $lastSeen = null;

	/**
	 * Constructor.
	 *
	 * @return void
	 */
	public function __construct() {
		$this->addType(fieldName: 'principal', type: 'string');
		$this->addType(fieldName: 'route', type: 'string');
		$this->addType(fieldName: 'method', type: 'string');
		$this->addType(fieldName: 'apiVersion', type: 'string');
		$this->addType(fieldName: 'callCount', type: 'integer');
		$this->addType(fieldName: 'firstSeen', type: 'datetime');
		$this->addType(fieldName: 'lastSeen', type: 'datetime');

	}//end __construct()

	/**
	 * The serialised values that differ from the stored ones, by field name.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/api-surface-governance/spec.md#requirement-every-api-call-records-its-caller-and-a-caller-carries-a-limit-and-an-address-binding-req-avs-003
	 */
	protected function jsonOverrides(): array {
		return [
			'principal' => $this->principalOrAnonymous(),
			'route' => (string)$this->route,
			'method' => (string)$this->method,
			'apiVersion' => (string)$this->apiVersion,
			'callCount' => (int)$this->callCount,
		];
	}//end jsonOverrides()

	/**
	 * Field name to the key it is serialised under.
	 *
	 * @return array<string, string>
	 */
	protected function jsonRenames(): array {
		return ['apiVersion' => 'version', 'callCount' => 'calls'];
	}//end jsonRenames()

	/**
	 * The principal, or 'anonymous' when there is none.
	 *
	 * @return string
	 */
	private function principalOrAnonymous(): string {
		$principal = (string)$this->principal;
		if ($principal === '') {
			return 'anonymous';
		}

		return $principal;
	}//end principalOrAnonymous()
}//end class
