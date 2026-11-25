# Summarise object-create.sh results and decide pass/fail.
#
# Input: one line per timed run, "<seconds> <http> <schema_scans> <register_scans> <commits>".
# Variables (-v):
#   floor        instance floor in ms, measured in the SAME run (required)
#   floorbudget  ms the write path may cost above the floor at p95 (spec: 50)
#   budget       absolute p95 wall budget in ms; empty = report only
#   maxs         schema seq-scan bound per write
#   maxc         commit bound per write
#
# The binding gate is p95(wall) - floor < floorbudget
# (openspec/changes/object-write-at-instance-floor, task 14). The wall figure is
# still reported, because it is what a caller experiences.
#
# Exit: 0 pass, 1 over a budget or bound, 2 no runs.
#
# SPDX-License-Identifier: EUPL-1.2
# SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
{
    t[NR] = $1 * 1000
    if ($2 !~ /^2/) bad++
    if ($3 > worst_schema) worst_schema = $3
    if ($5 > worst_commits) worst_commits = $5
    sum_schema += $3; sum_commits += $5
}
END {
    n = NR
    if (n == 0) { print "[perf] no runs"; exit 2 }
    # insertion sort — n is small and this avoids depending on asort()
    for (i = 2; i <= n; i++) { v = t[i]; j = i - 1; while (j > 0 && t[j] > v) { t[j+1] = t[j]; j-- } t[j+1] = v }
    # nearest-rank p95: ceil(0.95 * n)
    p95i = int(n * 0.95); if (p95i < n * 0.95) p95i++; if (p95i < 1) p95i = 1
    med = (n % 2) ? t[int(n/2)+1] : (t[n/2] + t[n/2+1]) / 2
    over = t[p95i] - floor

    printf "[perf] wall           min=%.0fms  median=%.0fms  p95=%.0fms  max=%.0fms\n", t[1], med, t[p95i], t[n]
    printf "[perf] minus floor    min=%.0fms  median=%.0fms  p95=%.0fms   (floor %.0fms)\n", \
        (t[1]-floor < 0 ? 0 : t[1]-floor), (med-floor < 0 ? 0 : med-floor), \
        (over < 0 ? 0 : over), floor
    printf "[perf] schema seq scans  avg=%.1f  worst=%d  (bound %d)\n", sum_schema/n, worst_schema, maxs
    printf "[perf] commits           avg=%.1f  worst=%d  (bound %d)\n", sum_commits/n, worst_commits, maxc

    fail = 0
    if (bad) { printf "[perf] FAIL %d run(s) did not return 2xx\n", bad; fail = 1 }
    if (over >= floorbudget) {
        printf "[perf] FAIL p95 minus floor %.0fms is not under %sms\n", over, floorbudget
        fail = 1
    }
    if (budget != "" && t[p95i] > budget + 0) {
        printf "[perf] FAIL p95 %.0fms exceeds the absolute %sms budget\n", t[p95i], budget
        fail = 1
    }
    if (worst_schema > maxs)  { printf "[perf] FAIL %d schema seq scans exceeds %d\n", worst_schema, maxs; fail = 1 }
    if (worst_commits > maxc) { printf "[perf] FAIL %d commits exceeds %d\n", worst_commits, maxc; fail = 1 }
    if (!fail) print "[perf] PASS"
    exit fail
}
