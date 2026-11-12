<?php

/**
 * OpenRegister Example Set Link Row Kinds
 *
 * The writers of the links an example set puts on its objects: party links, email links, access links and grants.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

/**
 * The writers of the links on a set's objects, bundled for the engine kinds.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
 */
final readonly class ExampleSetLinkRowKinds {
	/**
	 * Bundle the writers; a writer left out refuses its rows.
	 *
	 * @param ExampleSetParties|null     $parties     Writes the party links a fragment declares; null refuses them.
	 * @param ExampleSetEmailLinks|null  $emailLinks  Writes the mail a fragment links to its objects; null refuses it.
	 * @param ExampleSetAccessLinks|null $accessLinks Mints the access links a fragment puts on its objects; null refuses them.
	 * @param ExampleSetGrants|null      $grants      Makes the grants a fragment puts on its objects; null refuses them.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
	 */
	public function __construct(
		public ?ExampleSetParties $parties=null,
		public ?ExampleSetEmailLinks $emailLinks=null,
		public ?ExampleSetAccessLinks $accessLinks=null,
		public ?ExampleSetGrants $grants=null,
	) {
	}//end __construct()

	/**
	 * The writers by fragment part; null where this OpenRegister has none.
	 *
	 * @return array<string, ExampleSetEngineRows|null>
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
	 */
	public function writers(): array {
		return [
			'parties'     => $this->parties,
			'emailLinks'  => $this->emailLinks,
			'accessLinks' => $this->accessLinks,
			'grants'      => $this->grants,
		];
	}//end writers()
}//end class
