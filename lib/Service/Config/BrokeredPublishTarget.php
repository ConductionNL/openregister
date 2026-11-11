<?php

/**
 * BrokeredPublishTarget — a publish target with the broker credential that writes it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Config
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Config;

/**
 * A publish target together with the broker credential that custodies the
 * GitHub token used to write it; built once per publish() call and carried
 * through the repo-preparation steps.
 *
 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
 */
final class BrokeredPublishTarget {

	/**
	 * Where to publish
	 *
	 * @var ConfigPublishTarget
	 */
	public readonly ConfigPublishTarget $target;

	/**
	 * The broker credential id custodying the GitHub token
	 *
	 * @var string
	 */
	public readonly string $credentialId;

	/**
	 * Constructor
	 *
	 * @param ConfigPublishTarget $target       Where to publish
	 * @param string              $credentialId The broker credential id
	 */
	public function __construct(ConfigPublishTarget $target, string $credentialId) {
		$this->target = $target;
		$this->credentialId = $credentialId;
	}//end __construct()

	/**
	 * The repo owner and name, each '' when the `owner/repo` lacks it.
	 *
	 * @return array{0: string, 1: string}
	 *
	 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
	 */
	public function ownerAndName(): array {
		return array_pad(explode('/', $this->target->repo, 2), 2, '');
	}//end ownerAndName()

	/**
	 * The JSON body of a repo-create call for this target.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
	 */
	public function createBody(): string {
		return (string) json_encode(['name' => $this->ownerAndName()[1], 'private' => $this->target->private, 'auto_init' => true]);
	}//end createBody()
}//end class
