<?php

/**
 * OpenRegister Example Set Access Links
 *
 * The access links an example set mints over its objects: checked, minted
 * through AccessLinkService as the declared account after the mint guard
 * admits it, proved and deleted. The record holds a link's id and uuid,
 * never its anchor.
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
 * @spec openspec/changes/example-set-access-links-and-grants/specs/example-sets/spec.md#requirement-a-fragment-may-declare-access-links-on-its-objects
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

use OCA\OpenRegister\Db\AccessLink;
use OCA\OpenRegister\Db\AccessLinkMapper;
use OCA\OpenRegister\Service\Sharing\AccessLinkMintGuard;
use OCA\OpenRegister\Service\Sharing\AccessLinkService;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Checks, mints, proves and deletes the access links of an example set.
 *
 * @spec openspec/changes/example-set-access-links-and-grants/specs/example-sets/spec.md#requirement-a-fragment-may-declare-access-links-on-its-objects
 */
class ExampleSetAccessLinks extends ExampleSetEngineRows {
	/**
	 * The link fields a fragment may set.
	 */
	public const FIELDS = ['object', 'createdBy', 'expiresAt', 'capabilities', 'label'];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Hands out the link services, the mapper and the acting scope on first use.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * The fragment and record part access links live in.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/example-set-access-links-and-grants/specs/example-sets/spec.md#requirement-a-fragment-may-declare-access-links-on-its-objects
	 */
	public function part(): string {
		return 'accessLinks';
	}//end part()

	/**
	 * The word for one access link, in messages.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/example-set-access-links-and-grants/specs/example-sets/spec.md#requirement-a-fragment-may-declare-access-links-on-its-objects
	 */
	public function noun(): string {
		return 'access link';
	}//end noun()

	/**
	 * Why a filled link cannot be minted, or '', without the set's objects at hand.
	 *
	 * @param array<string, mixed> $data  The filled link fields.
	 * @param array<int, string>   $users The account ids the set creates or adopts.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/example-set-access-links-and-grants/specs/example-sets/spec.md#requirement-a-fragment-may-declare-access-links-on-its-objects
	 */
	public function refusal(array $data, array $users): string {
		$unknown = array_diff(array_keys($data), self::FIELDS);
		if ($unknown !== []) {
			return 'field "'.implode('", "', $unknown).'" is not an access link field a set may write';
		}

		if (is_string($data['createdBy'] ?? null) === false || in_array($data['createdBy'], $users, true) === false) {
			return 'createdBy '.(string) json_encode($data['createdBy'] ?? null).' is no account the set creates or adopts';
		}

		if (isset($data['label']) === true && is_string($data['label']) === false) {
			return 'label is not text';
		}

		return $this->capabilitiesRefusal(capabilities: ($data['capabilities'] ?? []));
	}//end refusal()

	/**
	 * Why a filled link cannot be minted in this run, or '': refusal(), then the object and the expiry.
	 *
	 * @param array<string, mixed> $data    The filled link fields.
	 * @param array<int, string>   $users   The account ids the set creates or adopts.
	 * @param array<string, mixed> $context The context: the set's `objects` and the install day.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/example-set-access-links-and-grants/specs/example-sets/spec.md#requirement-a-fragment-may-declare-access-links-on-its-objects
	 */
	protected function refusalIn(array $data, array $users, array $context): string {
		$why = $this->refusal(data: $data, users: $users);
		if ($why !== '') {
			return $why;
		}

		if ($this->isObjectOfSet(uuid: ($data['object'] ?? null), context: $context) === false) {
			return 'object '.(string) json_encode($data['object'] ?? null).' is not an object the set writes';
		}

		if (isset($data['expiresAt']) === false) {
			return 'an access link needs an expiry (expiresAt)';
		}

		return $this->dateRefusal(field: 'expiresAt', value: $data['expiresAt'], context: $context, isEnd: true);
	}//end refusalIn()

	/**
	 * Mint one link as its declared account, once the mint guard admits that account to the object.
	 *
	 * @param string               $app  The app that ships the fragment (unused: the link is the account's).
	 * @param string               $key  The link's key in the fragment (unused).
	 * @param array<string, mixed> $data The filled link fields.
	 *
	 * @return array{error: string, entry: array{id: int, uuid: string, object: string}|null} The record entry, or why the mint failed.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $app and $key are the contract of every kind.
	 *
	 * @spec openspec/changes/example-set-access-links-and-grants/specs/example-sets/spec.md#requirement-a-fragment-may-declare-access-links-on-its-objects
	 */
	public function write(string $app, string $key, array $data): array {
		$object    = (string) $data['object'];
		$createdBy = (string) $data['createdBy'];
		try {
			$link = $this->actingAs()->run(
				uid: $createdBy,
				operation: function () use ($object, $createdBy, $data): array {
					if ($this->guard()->mayMint(subjectType: AccessLink::SUBJECT_OBJECT, subjectId: $object) === false) {
						throw new ExampleSetException('"'.$createdBy.'" may not read object "'.$object.'", so may not publish it at a link');
					}

					return $this->links()->mint(
						userId: $createdBy,
						subjectType: AccessLink::SUBJECT_OBJECT,
						subjectId: $object,
						capabilities: array_values((array) ($data['capabilities'] ?? [])),
						expiresAt: (string) $data['expiresAt'],
						label: $this->labelOf(data: $data)
					);
				}
			);
		} catch (Throwable $e) {
			return ['error' => $e->getMessage(), 'entry' => null];
		}

		return ['error' => '', 'entry' => ['id' => (int) $link['id'], 'uuid' => (string) $link['uuid'], 'object' => $object]];
	}//end write()

	/**
	 * Whether a minted link is gone: no row, another row under its id, or revoked.
	 *
	 * @param array<string, mixed> $entry A record entry from write().
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/example-set-access-links-and-grants/specs/example-sets/spec.md#requirement-a-fragment-may-declare-access-links-on-its-objects
	 */
	public function lost(array $entry): bool {
		$link = $this->held(entry: $entry);

		return $link === null || $link->getRevokedAt() !== null;
	}//end lost()

	/**
	 * Delete the row of a link the set minted, so nothing of it stays behind.
	 *
	 * @param array<string, mixed> $entry A record entry from write().
	 *
	 * @return string `removed` (also when it was already gone) or `failed`.
	 *
	 * @spec openspec/changes/example-set-access-links-and-grants/specs/example-sets/spec.md#requirement-a-fragment-may-declare-access-links-on-its-objects
	 */
	public function revert(array $entry): string {
		try {
			$link = $this->mapper()->findById(id: (int) ($entry['id'] ?? 0));
			if ($link === null || $link->getUuid() !== (string) ($entry['uuid'] ?? '')) {
				return 'removed';
			}

			$this->mapper()->delete($link);
		} catch (Throwable) {
			return 'failed';
		}

		return 'removed';
	}//end revert()

	/**
	 * The links a fragment declares, by key, for `{{accessLink:key.id|uuid|url}}`: the minted links the record holds,
	 * their url read from the live row, or stand-ins for the check (`$record` null).
	 *
	 * @param array<string, mixed>      $fragment The fragment.
	 * @param array<string, mixed>|null $record   The set's record, or null for the check.
	 *
	 * @return array<string, array{id: int, uuid: string, url: string}>
	 *
	 * @spec openspec/changes/example-set-access-links-and-grants/specs/example-sets/spec.md#requirement-a-change-may-quote-an-access-link-of-its-fragment
	 */
	public function placeholders(array $fragment, ?array $record): array {
		$links = [];
		foreach (($fragment['accessLinks'] ?? []) as $row) {
			$key = (string) $row['key'];
			if ($record === null) {
				$links[$key] = self::standIn(app: (string) $fragment['app'], key: $key);
				continue;
			}

			$entry = ($record['accessLinks'][$fragment['app'].'/'.$key] ?? null);
			$link  = null;
			if (is_array($entry) === true) {
				$link = $this->held(entry: $entry);
			}

			if ($link !== null) {
				$links[$key] = [
					'id'   => (int) $link->getId(),
					'uuid' => (string) $link->getUuid(),
					'url'  => $this->links()->linkUrl(anchor: (string) $link->getAnchor()),
				];
			}
		}

		return $links;
	}//end placeholders()

	/**
	 * A stand-in link for the check: an id, a uuid and an address of the shapes a minted link has.
	 *
	 * @param string $app The app that ships the fragment.
	 * @param string $key The link's key.
	 *
	 * @return array{id: int, uuid: string, url: string}
	 */
	private static function standIn(string $app, string $key): array {
		$hash = md5($app.'/'.$key);

		return [
			'id'   => (int) (hexdec(substr($hash, 0, 7)) + 1),
			'uuid' => substr($hash, 0, 8).'-'.substr($hash, 8, 4).'-4'.substr($hash, 13, 3).'-8'.substr($hash, 17, 3).'-'.substr($hash, 20, 12),
			'url'  => 'https://example-set.invalid/access-link/'.rawurlencode($key),
		];
	}//end standIn()

	/**
	 * The live row of a recorded link, or null when there is none under its id and uuid.
	 *
	 * @param array<string, mixed> $entry A record entry from write().
	 *
	 * @return AccessLink|null
	 */
	private function held(array $entry): ?AccessLink {
		try {
			$link = $this->mapper()->findById(id: (int) ($entry['id'] ?? 0));
		} catch (Throwable) {
			return null;
		}

		if ($link === null || $link->getUuid() !== (string) ($entry['uuid'] ?? '')) {
			return null;
		}

		return $link;
	}//end held()

	/**
	 * The declared label, or null when the row names none.
	 *
	 * @param array<string, mixed> $data The filled link fields.
	 *
	 * @return string|null
	 */
	private function labelOf(array $data): ?string {
		if (isset($data['label']) === false) {
			return null;
		}

		return (string) $data['label'];
	}//end labelOf()

	/**
	 * Why declared capabilities cannot be minted, or ''.
	 *
	 * @param mixed $capabilities The filled `capabilities`.
	 *
	 * @return string
	 */
	private function capabilitiesRefusal(mixed $capabilities): string {
		if (is_array($capabilities) === false) {
			return 'capabilities is not a list';
		}

		foreach ($capabilities as $capability) {
			if (is_string($capability) === false || in_array($capability, AccessLink::CAPABILITIES, true) === false) {
				return 'capability '.(string) json_encode($capability).' is not one of '.implode(', ', AccessLink::CAPABILITIES);
			}
		}

		return '';
	}//end capabilitiesRefusal()

	/**
	 * The link service.
	 *
	 * @return AccessLinkService
	 */
	private function links(): AccessLinkService {
		return $this->container->get(AccessLinkService::class);
	}//end links()

	/**
	 * The mint guard, which asks whether the acting account may read the object.
	 *
	 * @return AccessLinkMintGuard
	 */
	private function guard(): AccessLinkMintGuard {
		return $this->container->get(AccessLinkMintGuard::class);
	}//end guard()

	/**
	 * The link mapper.
	 *
	 * @return AccessLinkMapper
	 */
	private function mapper(): AccessLinkMapper {
		return $this->container->get(AccessLinkMapper::class);
	}//end mapper()

	/**
	 * The scope that runs a call as a cast account.
	 *
	 * @return ExampleSetActingAs
	 */
	private function actingAs(): ExampleSetActingAs {
		return $this->container->get(ExampleSetActingAs::class);
	}//end actingAs()
}//end class
