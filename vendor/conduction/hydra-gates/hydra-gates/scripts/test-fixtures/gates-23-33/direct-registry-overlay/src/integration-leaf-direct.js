/**
 * THE SECOND CANONICAL REGISTRATION FORM — `.github#349`.
 *
 * The convenience wrapper exported from @conduction/nextcloud-vue is the form
 * gate-24 already knew. The registry object it wraps is equally canonical and
 * is called directly, as below — including by nextcloud-vue's OWN built-in
 * leaves (src/integrations/builtin/files.js).
 *
 * gate-24's JS probe knew only the wrapper. An app registering this way was
 * told, verbatim, "this repo registers no integration leaves at all", which
 * routes the gate to `na` — NOT counted against coverage — instead of
 * `structural`, which is. So the one gate that exists to correlate the server
 * and JS halves of a leaf switched itself off in exactly the repos that have
 * halves to correlate. Measured live on decidesk, whose `decidesk-decisions`
 * id was in `window.OCA.OpenRegister.integrations.list()` in the same repo's
 * Playwright run while gate-24 reported the set empty.
 *
 * ⚠️ THIS COMMENT MUST NOT NAME THE WRAPPER FUNCTION FOLLOWED BY AN OPEN
 * PAREN. gate-24's probes are plain greps over file TEXT, comments included,
 * so an explanatory sentence spelling the wrapper call out is indistinguishable
 * from a registration. The first draft of this file did exactly that, and the
 * arm below went GREEN against the PRE-#349 runner — a dead test that looked
 * like coverage. Same family as `.github#358` (gates 19 and 26 parse prose),
 * encountered while writing the fixture for a different gate.
 *
 * This file is an OVERLAY dropped on top of `clean/`, which has neither a
 * parity wrapper nor any leaf. `clean/` alone is legitimately NOT APPLICABLE;
 * `clean/` + this file must be SKIPPED (structural).
 */
;(function (target) {
	target.OCA.OpenRegister.integrations.register({
		id: 'gateplant-direct-agent',
		renderMode: 'mount',
	})
})(window)
