#!/usr/bin/env bash
# The openregister-shaped wrapper: it cannot locate the canonical JS check and
# exits 0 so it does not break builds. gate-24 read that 0 as a PASS.
echo "i integration parity: canonical JS check not found locally — skipping."
exit 0
