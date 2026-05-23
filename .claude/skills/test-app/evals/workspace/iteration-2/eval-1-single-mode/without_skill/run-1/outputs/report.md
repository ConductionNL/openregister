# CANNOT_TEST Report

**Test target:** openregister Nextcloud app
**Date:** 2026-04-13
**Run:** eval-1-single-mode / without_skill / run-1
**Status:** CANNOT_TEST

## Reason

Subagent had no Bash, WebFetch, or browser MCP tools available. Could not perform any connectivity check or browser automation.

## What was attempted

| Step | Action | Result |
|------|--------|--------|
| 1 | Invoke Bash to check for Playwright and test connectivity | Permission denied |
| 2 | Search deferred tools for any browser/Playwright MCP | No browser tools found |
| 3 | Invoke WebFetch to reach `http://nextcloud.local` | Permission denied |
| 4 | Invoke WebFetch to reach `http://localhost:8080` | Permission denied |

## Conclusion

No tests were executed. Zero pass/fail results to report. The subagent environment did not provide the tools necessary to interact with a web application.

This is an infrastructure issue, not a skill-quality issue. The without-skill baseline can only meaningfully be evaluated against the with-skill run if both have access to the same toolset.
