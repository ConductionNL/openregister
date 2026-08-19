#!/usr/bin/env bash
# SPDX-License-Identifier: EUPL-1.2
#
# test_gate_3_bodiless_declaration.sh — gate-3's `caller-identity-ignored` rule
# must not judge a BODILESS declaration (.github#291).
#
# WHAT WENT WRONG
# ---------------
# The rule extracts a method body with
#
#     awk -v start=N 'NR >= start { print; if (NR > start && /^    \}/) exit }'
#
# whose terminator is a line matching `^    \}`. An INTERFACE file contains no
# such line: its methods end in `;` and the interface's own closing brace sits
# at column 0. So for an interface method the awk ran to EOF and called the
# result "the body", the `< 4` skip (whose own comment says it means to skip
# "abstract/interface forwards") never fired, and the parameter appeared
# exactly once — on the signature — so the method was reported.
#
# Measured on pipelinq, `lib/Service/Cti/CtiAdapterInterface.php:78`:
#
#     public function subscribeToPresence(string $userId, string $extension): void;
#
# 92-line file, declaration at line 78, `grep -cE '^    \}'` = 0, extraction =
# 15 lines. Reported `rule=caller-identity-ignored param=$userId`.
#
# WHY IT IS UNCLOSABLE
# --------------------
# The parameter is part of the interface CONTRACT and is genuinely used by the
# implementor. A declaration cannot reference anything, so the only edits that
# silence it are deleting the parameter (breaking the contract) or moving the
# method away from the end of the file. Neither is a fix.
#
# Run: bash scripts/lib/test_gate_3_bodiless_declaration.sh
set -uo pipefail

LIB_DIR="$(cd "$(dirname "${BASH_SOURCE[0]:-$0}")" && pwd)"
RUNNER="${LIB_DIR}/../run-hydra-gates.sh"

_fail_count=0
_pass_count=0
_ok()  { echo "  PASS — $1"; _pass_count=$((_pass_count + 1)); }
_bad() { echo "  FAIL — $1"; _fail_count=$((_fail_count + 1)); }

[ -f "${RUNNER}" ] || { echo "FAIL — runner not found at ${RUNNER}"; exit 1; }

# Run gate-3 over a repo containing one file; echo the caller-identity findings.
# Args: <relative-path> <file-content>
_run_gate3() {
	local rel="$1" content="$2" root logdir out
	root="$(mktemp -d "${TMPDIR:-/tmp}/g3fixture.XXXXXX")" || return 1
	mkdir -p "${root}/$(dirname "${rel}")"
	printf '%s' "${content}" > "${root}/${rel}"
	(
		cd "${root}" || exit 1
		git init -q .
		git config user.email t@example.com
		git config user.name t
		git config commit.gpgsign false
		git add -A && git commit -qm base
	) >/dev/null 2>&1
	logdir="$(mktemp -d "${TMPDIR:-/tmp}/g3logs.XXXXXX")"
	# The runner's exit status is DELIBERATELY not captured: it aggregates 60+
	# gates and says nothing about gate-3. The verdict is the log.
	out="$(cd "${root}" && HYDRA_GATE_LOG_DIR="${logdir}" bash "${RUNNER}" . 2>&1)"
	# READ THE LOG, NOT THE EXIT CODE — the runner's status aggregates 60+ gates.
	grep 'caller-identity-ignored' "${logdir}/hydra-gate-stub-scan.log" 2>/dev/null
	# Surface gate-3's own verdict line on stderr so a human debugging this
	# suite can see whether the gate ran at all rather than guessing from an
	# empty result — an unrun gate and a clean one look identical otherwise.
	printf '%s\n' "${out}" | grep -E '\[gate-3\]' >&2
	rm -rf "${root}" "${logdir}"
	return 0
}

# The pipelinq fixture: a long interface whose LAST method declares $userId.
# The trailing docblocks matter — they are what put the declaration >=4 lines
# from EOF, which is the condition under which the old code reported it.
# shellcheck disable=SC2016  # $userId is PHP source, not a shell expansion — single quotes are REQUIRED here
_INTERFACE='<?php

namespace OCA\Pipelinq\Service\Cti;

/**
 * Adapter contract for a CTI provider.
 */
interface CtiAdapterInterface
{
    /**
     * Place an outbound call.
     *
     * @param string $extension The extension to dial.
     *
     * @return void
     */
    public function dial(string $extension): void;

    /**
     * Subscribe to presence updates for a user.
     *
     * @param string $userId    The user whose presence to watch.
     * @param string $extension The extension to bind.
     *
     * @return void
     */
    public function subscribeToPresence(string $userId, string $extension): void;

    /**
     * Verify a webhook payload signature.
     *
     * @param string $payload   Raw body.
     * @param string $signature Provider signature header.
     *
     * @return bool
     */
    public function verifySignature(string $payload, string $signature): bool;
}
'

# The TRUE POSITIVE: a real class method with a body that ignores $userId.
# This is decidesk#45's shape, the defect the rule exists for.
# shellcheck disable=SC2016  # $userId is PHP source, not a shell expansion — single quotes are REQUIRED here
_STUB_CLASS='<?php

namespace OCA\Pipelinq\Service;

class PresenceService
{
    /**
     * Subscribe to presence updates.
     *
     * @param string $userId    The caller.
     * @param string $extension The extension.
     *
     * @return void
     */
    public function subscribeToPresence(string $userId, string $extension): void
    {
        $this->logger->info("subscribing");
        $this->bus->dispatch(new Subscribe($extension));
        return;
    }
}
'

# The other true positive: a real class method that DOES use $userId.
# shellcheck disable=SC2016  # $userId is PHP source, not a shell expansion — single quotes are REQUIRED here
_GOOD_CLASS='<?php

namespace OCA\Pipelinq\Service;

class PresenceService
{
    /**
     * Subscribe to presence updates.
     *
     * @param string $userId    The caller.
     * @param string $extension The extension.
     *
     * @return void
     */
    public function subscribeToPresence(string $userId, string $extension): void
    {
        $this->logger->info("subscribing " . $userId);
        $this->bus->dispatch(new Subscribe($userId, $extension));
        return;
    }
}
'

echo "== gate-3: a bodiless declaration has no body to ignore a param in (#291) =="
echo

# ARM 1 — the false positive is GONE.
_out="$(_run_gate3 "lib/Service/Cti/CtiAdapterInterface.php" "${_INTERFACE}" 2>/dev/null)"
_n="$(printf '%s' "${_out}" | grep -c . || true)"
if [ "${_n}" -eq 0 ]; then
	_ok "arm 1: an interface declaration is not reported (pipelinq's shape)"
else
	_bad "arm 1: expected 0 findings, got ${_n}:"
	printf '%s\n' "${_out}" | sed 's/^/         /'
fi

# ARM 2 — THE TRUE POSITIVE MUST STILL FIRE. Same method name, same parameter,
# but a real body that never touches it: decidesk#45's unfinished stub.
_out="$(_run_gate3 "lib/Service/PresenceService.php" "${_STUB_CLASS}" 2>/dev/null)"
_n="$(printf '%s' "${_out}" | grep -c . || true)"
if [ "${_n}" -eq 1 ] && printf '%s' "${_out}" | grep -q 'method=subscribeToPresence'; then
	_ok "arm 2: a real method body that ignores \$userId is STILL reported"
else
	_bad "arm 2: expected exactly 1 finding for subscribeToPresence, got ${_n}:"
	printf '%s\n' "${_out}" | sed 's/^/         /'
fi

# ARM 3 — THE ABUSE CONTROL. The skip keys on the SIGNATURE TERMINATOR, not on
# "the file mentions an interface" and not on the method name. A class method
# whose signature ends in `{` must be judged normally — otherwise a rule that
# meant to spare declarations would spare every method whose return type
# happens to be written like one.
_out="$(_run_gate3 "lib/Service/PresenceService.php" "${_GOOD_CLASS}" 2>/dev/null)"
_n="$(printf '%s' "${_out}" | grep -c . || true)"
if [ "${_n}" -eq 0 ]; then
	_ok "arm 3: a real method that USES \$userId is not reported"
else
	_bad "arm 3: expected 0 findings, got ${_n}:"
	printf '%s\n' "${_out}" | sed 's/^/         /'
fi

# ARM 4 — a SHORT interface, to show the `< 4` skip was never the safety net it
# was taken for. Measured against the unfixed runner this arm ALSO reports:
# the extraction from the declaration to EOF is exactly 4 lines, so `< 4` does
# not fire and a two-method interface is judged like a stub. The bug was never
# about being last in the file, only about there being no `^    \}` to stop at.
# shellcheck disable=SC2016  # $userId is PHP source, not a shell expansion — single quotes are REQUIRED here
_out="$(_run_gate3 "lib/Service/Cti/OtherInterface.php" '<?php

interface OtherInterface
{
    public function subscribeToPresence(string $userId, string $extension): void;

    public function dial(string $extension): void;
}
' 2>/dev/null)"
_n="$(printf '%s' "${_out}" | grep -c . || true)"
if [ "${_n}" -eq 0 ]; then
	_ok "arm 4: a mid-file interface declaration stays unreported"
else
	_bad "arm 4: expected 0 findings, got ${_n}:"
	printf '%s\n' "${_out}" | sed 's/^/         /'
fi

echo
echo "== summary: ${_pass_count} passed, ${_fail_count} failed =="
[ "${_fail_count}" -eq 0 ] || exit 1
exit 0
