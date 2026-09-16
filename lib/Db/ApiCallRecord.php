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
 * @spec openspec/changes/api-as-a-versioned-surface/specs/api-surface-governance/spec.md
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
	 * The published shape of one record.
	 *
	 * An anonymous caller is published as the explicit string `anonymous`
	 * rather than an empty one, because an empty principal in a report reads
	 * as a bug in the report.
	 *
	 * @return array<string, mixed> The record.
	 *
	 * @spec openspec/changes/api-as-a-versioned-surface/specs/api-surface-governance/spec.md#requirement-every-api-call-records-its-caller-and-a-caller-carries-a-limit-and-an-address-binding-req-avs-003
	 */
	public function jsonSerialize(): array {
		$principal = (string)$this->principal;

		return [
			'id' => $this->id,
			'principal' => ($principal === '') ? 'anonymous' : $principal,
			'route' => (string)$this->route,
			'method' => (string)$this->method,
			'version' => (string)$this->apiVersion,
			'calls' => (int)$this->callCount,
			'firstSeen' => $this->firstSeen?->format('c'),
			'lastSeen' => $this->lastSeen?->format('c'),
		];

	}//end jsonSerialize()
}//end class
