<?php

/**
 * RegisterSlugResolver: reads which slug a register actually carries here.
 *
 * The implementation of {@see RegisterSlugResolverInterface}. It answers from
 * the `openregister_registers` table and from nothing else.
 *
 * It deliberately does NOT consult `IAppManager`. Whether an app is installed
 * says nothing about which slug its register carries: the app id and the
 * register slug are moved by two different repair steps, and either can run
 * first, so an instance running `buildiq` can hold a register row still slugged
 * `openbuild`. {@see \OCA\OpenRegister\Support\FleetAppId} answers the app-id
 * question from the app manager; this class answers the slug question from the
 * register table. Using one to predict the other is how a probe reports a
 * present register as absent.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Contract\RegisterSlugResolution;
use OCA\OpenRegister\Contract\RegisterSlugResolverInterface;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Support\RegisterSlugAliases;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolves a register by any of the slugs it has answered to.
 *
 * @spec openspec/specs/register-slug-resolution/spec.md
 */
class RegisterSlugResolver implements RegisterSlugResolverInterface {

	/**
	 * Request-scoped memo, keyed by the candidate list.
	 *
	 * A sweep asks for the same register on every call site of the same tick.
	 * The probe is one indexed read, but it is a read per call site, and the
	 * answer cannot change inside a request.
	 *
	 * @var array<string, RegisterSlugResolution>
	 */
	private array $memo = [];

	/**
	 * Constructor.
	 *
	 * @param RegisterMapper  $registerMapper Reads the register table.
	 * @param LoggerInterface $logger         Diagnostics for the ambiguous and failed cases.
	 */
	public function __construct(
		private readonly RegisterMapper $registerMapper,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Which slug this instance's copy of a register actually answers to.
	 *
	 * @param string       $canonical  The canonical (current) register slug.
	 * @param list<string> $candidates Explicit candidates, newest first; empty
	 *                                 means use the declared alias list.
	 *
	 * @return RegisterSlugResolution The slug to use, or an explicit absence.
	 *
	 * @spec openspec/specs/register-slug-resolution/spec.md
	 */
	public function resolve(string $canonical, array $candidates=[]): RegisterSlugResolution {
		$probe = $this->candidateList(canonical: $canonical, candidates: $candidates);
		if ($probe === []) {
			return new RegisterSlugResolution(
				canonical: $canonical,
				slug: null,
				state: RegisterSlugResolution::ABSENT,
				candidates: [],
				matched: []
			);
		}

		$memoKey = implode('|', $probe);
		if (isset($this->memo[$memoKey]) === true) {
			return $this->memo[$memoKey];
		}

		$matched = $this->matchingSlugs(candidates: $probe);
		$resolution = $this->interpret(canonical: $canonical, probe: $probe, matched: $matched);

		$this->memo[$memoKey] = $resolution;
		return $resolution;
	}//end resolve()

	/**
	 * The slug to read with, or null when the register is not on this instance.
	 *
	 * @param string       $canonical  The canonical (current) register slug.
	 * @param list<string> $candidates Explicit candidates, newest first.
	 *
	 * @return string|null The slug to use, or null.
	 *
	 * @spec openspec/specs/register-slug-resolution/spec.md
	 */
	public function slugOrNull(string $canonical, array $candidates=[]): ?string {
		return $this->resolve(canonical: $canonical, candidates: $candidates)->slug;
	}//end slugOrNull()

	/**
	 * Build the probe list: canonical first, then its declared aliases.
	 *
	 * The canonical slug is always probed first and always present, so a
	 * migrated instance short-circuits on the first candidate and an explicit
	 * candidate list cannot accidentally omit the name the caller asked for.
	 *
	 * @param string       $canonical  The canonical register slug.
	 * @param list<string> $candidates Explicit candidates, or empty.
	 *
	 * @return list<string> Lower-cased candidates, newest first, deduplicated.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) RegisterSlugAliases is a final class
	 *  holding one `const` array and no state. StaticAccess exists to catch a
	 *  hidden dependency that should have been injected; there is nothing here
	 *  to inject and nothing a test would want to swap. Making it a service
	 *  would mean a DI registration and a constructor argument whose only
	 *  purpose is to reach a compile-time constant, and would let a caller
	 *  substitute a different alias map, which is precisely the drift the
	 *  single declared map exists to prevent.
	 */
	private function candidateList(string $canonical, array $candidates): array {
		$key = strtolower(trim($canonical));
		if ($key === '') {
			return [];
		}

		$declared = RegisterSlugAliases::candidatesFor(canonical: $key);
		if ($candidates !== []) {
			$declared = $candidates;
		}

		$probe = [$key];
		foreach ($declared as $candidate) {
			$normalised = strtolower(trim((string)$candidate));
			if ($normalised === '' || in_array($normalised, $probe, true) === true) {
				continue;
			}

			$probe[] = $normalised;
		}

		return $probe;
	}//end candidateList()

	/**
	 * Which of the probed slugs exist here, in probe order.
	 *
	 * A failure to read is NOT a resolution: it is reported as an empty match
	 * with an error logged. The caller then reports the register as
	 * unavailable, which is the honest observable. The one thing that must
	 * never happen is falling back to the canonical slug and letting the
	 * resulting empty result set be read as "this register holds nothing".
	 *
	 * @param list<string> $candidates The lower-cased probe list.
	 *
	 * @return list<string> Matching slugs, in probe order.
	 */
	private function matchingSlugs(array $candidates): array {
		try {
			$idsBySlug = $this->registerMapper->findIdsBySlugs(slugs: $candidates);
		} catch (Throwable $e) {
			$this->logger->error(
				message: '[RegisterSlugResolver] Could not read register slugs; treating the register as unavailable',
				context: [
					'candidates' => $candidates,
					'reason'     => $e->getMessage(),
				]
			);
			return [];
		}

		$matched = [];
		foreach ($candidates as $candidate) {
			$ids = ($idsBySlug[$candidate] ?? []);
			if ($ids === []) {
				continue;
			}

			if (count($ids) > 1) {
				$this->logger->warning(
					message: '[RegisterSlugResolver] More than one register carries the same slug',
					context: ['slug' => $candidate, 'ids' => $ids]
				);
			}

			$matched[] = $candidate;
		}

		return $matched;
	}//end matchingSlugs()

	/**
	 * Turn a match list into a resolution, logging the state that needs an operator.
	 *
	 * @param string       $canonical The canonical register slug.
	 * @param list<string> $probe     The probe list, newest first.
	 * @param list<string> $matched   The slugs that exist here, in probe order.
	 *
	 * @return RegisterSlugResolution The resolution.
	 */
	private function interpret(string $canonical, array $probe, array $matched): RegisterSlugResolution {
		if ($matched === []) {
			return new RegisterSlugResolution(
				canonical: $canonical,
				slug: null,
				state: RegisterSlugResolution::ABSENT,
				candidates: $probe,
				matched: []
			);
		}

		if (count($matched) === 1) {
			return new RegisterSlugResolution(
				canonical: $canonical,
				slug: $matched[0],
				state: RegisterSlugResolution::RESOLVED,
				candidates: $probe,
				matched: $matched
			);
		}

		// Two rows the rename should have collapsed into one. The repair step
		// refuses to merge in this case rather than guessing, so this is an
		// operator's decision, not the resolver's. Reads go to the FIRST
		// candidate, the canonical migrated row, so that a consumer keeps
		// working while it is sorted out.
		$this->logger->warning(
			message: '[RegisterSlugResolver] A register answers to more than one of its slugs; '
				. 'reads use the first. Run the owning app\'s register-slug repair step.',
			context: [
				'canonical' => $canonical,
				'matched'   => $matched,
			]
		);

		return new RegisterSlugResolution(
			canonical: $canonical,
			slug: $matched[0],
			state: RegisterSlugResolution::AMBIGUOUS,
			candidates: $probe,
			matched: $matched
		);
	}//end interpret()
}//end class
