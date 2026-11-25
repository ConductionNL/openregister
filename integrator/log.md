2026-11-23T15:26:53Z NORESULT prs=7667,7828,7830,8084,8344,8456,8819,8951 main=223a653f28 integrator-cloud (suite green after inherited main reds; floor PASS; checker floor NORESULT twice: gitleaks absent, install refused)
2026-11-23T16:25:48Z GREEN prs=7667,7828,7830,8084,8106,8344,8456,8819 main=ec3f0f77d4 integrator-cloud (only #7667 landed: merge step reads PR state through GraphQL, which this sandbox refuses; postcheck verified by hand, local script copy switched to REST; rest rebuilt)
2026-11-23T16:39:49Z GREEN prs=7828,7830,8084,8106,8344,8456,8819,8951 main=4588c723e4 integrator-cloud
2026-11-23T16:45:21Z RED prs=8996,8999,9011,9023,9029,9053,9066,9083 main=4588c723e4 integrator-cloud (ClassAliasStubTest, ComponentDependencyRulesTest; retrying each alone)
2026-11-23T16:49:49Z RED prs=8996 main=4588c723e4 integrator-cloud (solo=8996)
2026-11-23T17:02:10Z GREEN prs=8999 main=cac4b4b4db integrator-cloud
2026-11-23T17:13:58Z GREEN prs=9011 main=757c57ad64 integrator-cloud
2026-11-23T17:25:17Z GREEN prs=9023 main=ca0cfc43d7 integrator-cloud
2026-11-23T17:29:44Z RED prs=9029 main=ca0cfc43d7 integrator-cloud (solo=9029)
2026-11-23T17:34:05Z RED prs=9053 main=ca0cfc43d7 integrator-cloud (solo=9053)
2026-11-23T17:38:20Z RED prs=9066 main=ca0cfc43d7 integrator-cloud (solo=9066)
2026-11-23T19:16:23Z GREEN prs=8397,9083,9092,9103,9163,9199,9219 main=ff0f786ea8 integrator-cloud (NORESULT once on deps: composer clone raced the tree build; retest green; #9131 tree conflict, deferred)
2026-11-23T19:28:12Z GREEN prs=9236,9277,9340,9370,9404,9419,9445,9472 main=66648adda0 integrator-cloud
2026-11-23T19:33:22Z RED prs=9532,9555,9565,9594,9709,9760,9781,9816 main=66648adda0 integrator-cloud (MailAppScriptListenerGoldenTest, PushClientScriptListenerTest; retrying each alone)
2026-11-23T19:43:40Z GREEN prs=9532 main=b0d1d382a9 integrator-cloud
2026-11-23T19:53:29Z GREEN prs=9555 main=d28a757bfe integrator-cloud
2026-11-23T20:03:05Z GREEN prs=9565 main=5fdce3598e integrator-cloud
2026-11-23T20:12:46Z GREEN prs=9594 main=feac21ae52 integrator-cloud
2026-11-23T20:21:57Z GREEN prs=9709 main=bc610a4c65 integrator-cloud
2026-11-23T20:30:10Z GREEN prs=9760 main=20730103f6 integrator-cloud
2026-11-23T20:33:42Z RED prs=9781 main=2073010362 integrator-cloud (solo=9781; MailAppScriptListenerGoldenTest, PushClientScriptListenerTest)
2026-11-23T20:42:30Z GREEN prs=9816 main=4421348cf8 integrator-cloud
2026-11-23T20:53:02Z GREEN prs=8996,9066,9801,9817,9823,9824,9835,9836 main=165d3bcb82 integrator-cloud
2026-11-23T21:04:09Z GREEN prs=9837,9844,9845,9846,9847,9860,9864,10995 main=afdda82ad1 integrator-cloud
2026-11-23T21:12:50Z GREEN prs=10997 main=74e57194ae integrator-cloud
2026-11-23T22:01:46Z RED prs=9029 main=6e870ca5e2 integrator-cloud (solo=9029; ComponentDependencyRulesTest)
2026-11-23T22:12:53Z RED prs=9841 main=6e870ca5e2 integrator-cloud (not the PR: main 6e870ca5 (#10999, merged outside this lane) fails ComponentDependencyRulesTest::testNoComponentEdgeBeyondTheBaseline with 4 Files->Core edges; failed-solo rows for 9029 and 9841 at this main removed; integrator stopped until main is fixed)
2026-11-23T22:43:21Z RED prs=9029,9798,9818,9841 main=6e870ca5e2 integrator-cloud
2026-11-23T23:38:45Z GREEN prs=9029,9059,9818,9841 main=4401ff3862 integrator-cloud (first test NORESULT: deps race with prepare's background install; retried)
2026-11-24T00:16:06Z GREEN prs=9053 main=85629dd492 integrator-cloud
2026-11-24T01:09:32Z GREEN prs=9869 main=751c2764d2 integrator-cloud
2026-11-24T02:15:56Z GREEN prs=9798 main=7569191ed2 integrator-cloud
2026-11-24T02:23:02Z GREEN prs=11000 main=6fbf58fb9e integrator-cloud
2026-11-24T03:20:32Z GREEN prs=9131 main=677fdcdc51 integrator-cloud
2026-11-24T04:16:32Z GREEN prs=11001 main=f1c351debe integrator-cloud
2026-11-24T09:12:37Z GREEN prs=3847,9909,9915,9921,9940,10075,10129,10132 main=cac830e00f integrator-cloud (first verdict NORESULT: apt gitleaks 8.16 / go-built 8.30 too slow, secretscanning hit the 120s timeout; release binary 8.30.0 fixed it)
2026-11-24T09:19:18Z RED prs=10156,10188,10214,10234,10239,10243 main=cac830e00f integrator-cloud (jest src/services/initial-data.golden.spec.js; PRs retried alone)
2026-11-24T09:25:22Z RED prs=10156 main=cac830e00f integrator-cloud (solo=10156; jest initial-data.golden.spec.js EditSource starts from the recorded state)
2026-11-24T09:38:19Z GREEN prs=10188 main=0ca840a80e integrator-cloud
2026-11-24T09:51:43Z GREEN prs=10214 main=a1a5ce8848 integrator-cloud
2026-11-24T10:04:53Z GREEN prs=10234 main=1c8383c69a integrator-cloud
2026-11-24T10:18:03Z GREEN prs=10239 main=ee90cb1834 integrator-cloud
2026-11-24T10:30:17Z GREEN prs=10243 main=3844ed2cc6 integrator-cloud
2026-11-24T10:37:20Z RED prs=10182,10244,10247,10343,10366 main=3844ed2cc6 integrator-cloud
2026-11-24T10:43:00Z RED prs=10182 main=3844ed2cc6 integrator-cloud (solo=10182)
2026-11-24T10:56:38Z GREEN prs=10244 main=87414418e3 integrator-cloud
2026-11-24T11:09:09Z GREEN prs=10247 main=79cbd08d45 integrator-cloud
2026-11-24T11:22:08Z GREEN prs=10343 main=9bd7e0b3c8 integrator-cloud
2026-11-24T11:35:16Z GREEN prs=10366 main=58e8e45047 integrator-cloud
2026-11-24T11:41:56Z RED prs=10154,10190,10232,10269,10277,10314 main=58e8e45047 integrator-cloud
2026-11-24T11:47:25Z RED prs=10190 main=58e8e45047 integrator-cloud (solo=10190)
2026-11-24T12:01:23Z GREEN prs=10154 main=f1790ee333 integrator-cloud
2026-11-24T12:14:42Z GREEN prs=10232 main=09df7dea3d integrator-cloud
2026-11-24T12:26:31Z GREEN prs=10269 main=aa1f24b922 integrator-cloud
2026-11-24T13:52:12Z RED prs=10139,10277,10283,10314,10358,10363 main=aa1f24b922 integrator-cloud
2026-11-24T14:04:36Z FLOOR prs=10139 main=aa1f24b922 integrator-cloud
2026-11-24T14:15:10Z NORESULT prs=10139 main=aa1f24b922 integrator-cloud (corrects the FLOOR line above: suite green, maintainability floor pass, checker floor NORESULT twice: gitleaks exceeds the checker's 120 s limit on a 4-CPU sandbox)
2026-11-24T15:20:19Z GREEN prs=10139 main=856b8b5dbb integrator-cloud (solo retry with gitleaks 8.30.0 release binary; checker floor IMPROVED)
2026-11-24T15:32:50Z GREEN prs=10277 main=68b89c9394 integrator-cloud (solo retry)
2026-11-24T15:37:53Z RED prs=10283 main=68b89c9394 integrator-cloud (solo=10283)
2026-11-24T15:49:33Z GREEN prs=10314 main=322d1734d6 integrator-cloud
2026-11-24T16:00:16Z GREEN prs=10358 main=ab5b6ad1a5 integrator-cloud
2026-11-24T16:04:51Z RED prs=10363 main=ab5b6ad1a5 integrator-cloud (solo=10363)
2026-11-24T16:10:00Z RED prs=10300,10381,10395,10398,10427,10435,10450,10451 main=ab5b6ad1a5 integrator-cloud
2026-11-24T16:10:11Z NOTE prs=10300,10381,10398,10427,10435 main=ab5b6ad1a5 integrator-cloud (corrects the RED line above: only these 5 were built; 10395,10450,10451 conflicted within the tree)
2026-11-24T16:14:43Z RED prs=10300 main=ab5b6ad1a5 integrator-cloud (solo=10300)
2026-11-24T16:25:29Z GREEN prs=10381 main=ed085c0cbb integrator-cloud
2026-11-24T16:36:48Z GREEN prs=10398 main=40c42d78c8 integrator-cloud
2026-11-24T16:47:35Z GREEN prs=10427 main=58479c0d04 integrator-cloud
2026-11-24T16:58:25Z GREEN prs=10435 main=29ee788b9b integrator-cloud
2026-11-24T17:05:40Z RED prs=10182,10395,10450,10483,10491,10493 main=29ee788b9b integrator-cloud
2026-11-24T17:10:18Z RED prs=10182 main=29ee788b9b integrator-cloud (solo=10182)
2026-11-24T17:15:47Z AUTOPARK prs=10395 main=29ee788b9b integrator-cloud (VERDICT-RC 5 AUTOPARK)
2026-11-24T17:26:15Z MERGE-RC5 prs=10450 main=807c5e84cb integrator-cloud (green tree, merge exit 5)
2026-11-24T17:38:24Z GREEN prs=10483 main=3848cdb322 integrator-cloud
2026-11-24T17:50:45Z GREEN prs=10491 main=f22f5cae4b integrator-cloud
2026-11-24T18:02:42Z GREEN prs=10493 main=cf0644219f integrator-cloud
2026-11-24T18:14:51Z GREEN prs=10450 main=9536da6816 integrator-cloud
2026-11-24T18:25:40Z GREEN prs=10490 main=2503e6c023 integrator-cloud
2026-11-24T18:37:07Z GREEN prs=10190 main=d202825e7b integrator-cloud
2026-11-24T19:10:04Z RED prs=10494,10504,10509,10512,10520,10522 main=d202825e7b integrator-cloud (suspects 10494,10504 FilesRegistrar)
2026-11-24T19:30:44Z GREEN prs=10509,10510,10512,10520,10522,10524 main=415a0faf50 integrator-cloud
2026-11-24T19:45:55Z GREEN prs=10487,10528,10532,10534,10538,10539,10542,10552 main=5037a3463f integrator-cloud
2026-11-24T19:46:05Z NOTE previous line: 10539,10552 were dropped at build (tree conflict), not merged; merged=10487,10528,10532,10534,10538,10542 integrator-cloud
2026-11-24T20:00:41Z GREEN prs=10182,10497,10519,10531,10562,10569,10608 main=dfcbe075ef integrator-cloud
2026-11-24T20:15:58Z GREEN prs=10527,10541,10557,10581,10622,10629,10630,10632 main=110034ca45 integrator-cloud
2026-11-24T20:30:45Z GREEN prs=10567,10621,10638,10869,10964,10978,10979,10980 main=46dd353489 integrator-cloud
2026-11-24T20:45:07Z GREEN prs=10981,10984,11002,11004,11010,11012,11014 main=429aa82150 integrator-cloud
2026-11-24T21:00:26Z GREEN prs=10521,11028,11030,11034,11041,11042,11044,11048 main=98ffe7cec3 integrator-cloud
2026-11-24T21:06:32Z AUTOPARK prs=10503,10627,11050,11054,11060,11061 main=98ffe7cec3 integrator-cloud (parked 10627: sbom)
2026-11-24T21:17:40Z GREEN prs=10503,11050,11054,11060,11061 main=b3424e04f4 integrator-cloud
2026-11-24T21:30:27Z GREEN prs=10637,11057 main=a29bad1e21 integrator-cloud
2026-11-24T21:43:46Z GREEN prs=10363,10458,10494 main=0bdacec631 integrator-cloud
2026-11-24T21:56:24Z GREEN prs=11017,11049 main=58d3af3f40 integrator-cloud
2026-11-24T22:08:59Z GREEN prs=10283,10506,10539 main=85883fab13 integrator-cloud
2026-11-24T22:22:30Z GREEN prs=10552,10623,10625,10633 main=b8bc4718b4 integrator-cloud
2026-11-24T22:28:38Z AUTOPARK prs=10587,10635,10640,10643 main=b8bc4718b4 integrator-cloud (parked 10635: sbom)
2026-11-24T22:33:45Z AUTOPARK prs=10587,10640,10643 main=b8bc4718b4 integrator-cloud (parked 10640: sbom)
2026-11-24T22:45:36Z GREEN prs=10587,10643 main=037f95a841 integrator-cloud
2026-11-24T23:35:28Z NORESULT prs=10553,10983,11003,11013,11033,11058,11043 main=037f95a841 integrator-cloud (deps race; retried)
2026-11-24T23:35:28Z GREEN prs=10553,10983,11003,11013,11033,11058,11043 main=037f95a841 integrator-cloud (merged; main now 7bf08ec0b9)
2026-11-24T23:46:42Z GREEN prs=11063,11073,11078,11098 main=7bf08ec0b9 integrator-cloud (merged; main now 06bd93550e)
2026-11-24T23:56:52Z GREEN prs=10518,10496,10498 main=06bd93550e integrator-cloud (merged; main now b9f7ab7b8b; 10526 tree-conflict)
2026-11-25T00:08:47Z AUTOPARK prs=10525,10529,10550,10965 main=b9f7ab7b8b integrator-cloud (parked 10550: floor module_coupling)
2026-11-25T00:15:56Z GREEN prs=10525,10965 main=b9f7ab7b8b integrator-cloud (merged; main now c3d20aa1db)
2026-11-25T00:20:01Z RED prs=10966,11005,11006,11015 main=c3d20aa1db integrator-cloud (RED tree 6cd5df5839 prs 4 front=skip: Tests: 65659, Assertions: 232403, Errors: 9, Failures: 2, Deprecations: 4, PHPUnit Deprecations: 1, Notices: 1, )
2026-11-25T00:28:16Z GREEN prs=10966,11006,11015 main=c3d20aa1db integrator-cloud (merged 10966,11006,11015; main now fb766ac742; merge rc=0)
2026-11-25T00:31:24Z RED prs=11005 main=fb766ac742 integrator-cloud (RED tree 567df30583 prs 1 front=skip: Tests: 65659, Assertions: 232403, Errors: 9, Failures: 2, Deprecations: 4, PHPUnit Deprecations: 1, Notices: 1, )
2026-11-25T00:39:57Z GREEN prs=11062,11090 main=fb766ac742 integrator-cloud (merged 11062,11090; main now 0d540fff55; merge rc=0)
2026-11-25T00:44:25Z RED prs=10005,10513,10631,11016 main=0d540fff55 integrator-cloud (RED tree 7b0825a7e3 prs 3 front=ok: Tests: 65718, Assertions: 232637, Errors: 9, Failures: 2, Deprecations: 4, PHPUnit Deprecations: 1, Notices: 1, Sk)
2026-11-25T00:58:48Z GREEN prs=10005,11016 main=0d540fff55 integrator-cloud (merged 10005,11016; main now 894bdac71c; merge rc=0)
2026-11-25T01:02:57Z RED prs=10513 main=894bdac71c integrator-cloud (RED tree a6ae0a602e prs 1 front=skip: Tests: 65718, Assertions: 232637, Errors: 9, Failures: 2, Deprecations: 4, PHPUnit Deprecations: 1, Notices: 1, )
2026-11-25T01:14:43Z GREEN prs=11046,11072,11100 main=894bdac71c integrator-cloud (merged 11046,11072,11100; main now bebb35ed84; merge rc=0)
2026-11-25T01:25:52Z GREEN prs=10456,11102 main=bebb35ed84 integrator-cloud (merged 10456,11102; main now 6a98afdf59; merge rc=0)
2026-11-25T01:38:12Z GREEN prs=10530,10533,10543,10549 main=6a98afdf59 integrator-cloud (merged 10530,10543; main now 69b48eaf5e; merge rc=0)
2026-11-25T01:50:34Z GREEN prs=11022,11056 main=69b48eaf5e integrator-cloud (merged 11022,11056; main now 9ddfde9dc8; merge rc=0)
2026-11-25T02:03:00Z GREEN prs=10529,10537,10595,11025 main=9ddfde9dc8 integrator-cloud (merged 10529,10595,11025; main now 613a9b807f; merge rc=0)
2026-11-25T02:16:23Z GREEN prs=10395,10554,11045,11047 main=613a9b807f integrator-cloud (merged 10395,10554,11045,11047; main now 0202f3a808; merge rc=0)
2026-11-25T02:29:10Z GREEN prs=11070,11076,11118 main=0202f3a808 integrator-cloud (merged 11070,11076,11118; main now 13bc5dc2f5; merge rc=0)
2026-11-25T02:34:30Z RED prs=11005,11053,11064,11065 main=13bc5dc2f5 integrator-cloud (RED tree 59c6ab67e7 prs 4 front=skip: Tests: 66023, Assertions: 233732, Errors: 9, Failures: 2, Warnings: 1, Deprecations: 4, PHPUnit Deprecations: 1,)
2026-11-25T02:45:58Z GREEN prs=11005,11053,11065 main=13bc5dc2f5 integrator-cloud (merged 11005,11053,11065; main now f7904f9dd7; merge rc=0)
2026-11-25T02:50:20Z RED prs=11064 main=f7904f9dd7 integrator-cloud (RED tree 23375e3a4f prs 1 front=skip: Tests: 66023, Assertions: 233732, Errors: 9, Failures: 2, Deprecations: 4, PHPUnit Deprecations: 1, Notices: 1, )
2026-11-25T03:55:10Z NORESULT prs=11075,11085,11095 main=f7904f9dd7 integrator-cloud (tree b07247e55c deps failed: psalm 7.16.0 lock needs php>=8.3.16, container 8.3.6; vendor installed with --ignore-platform-req=php, retried)
2026-11-25T03:55:10Z GREEN prs=11075,11085,11095 main=f7904f9dd7 integrator-cloud (merged 11075,11085,11095; main now 9d807a8d7f; merge rc=0)
2026-11-25T04:08:28Z GREEN prs=10535,11055,11105,11120 main=9d807a8d7f integrator-cloud (merge rc=0; main now b48bfec939)
2026-11-25T04:20:51Z GREEN prs=10568,11115 main=b48bfec939 integrator-cloud (merge rc=0; main now ee68d246f0)
2026-11-25T04:32:35Z GREEN prs=11088,11125 main=ee68d246f0 integrator-cloud (merge rc=0; main now 28d6ce8f88)
2026-11-25T07:58:18Z NORESULT prs=11059 main=28d6ce8f88 integrator-cloud (tree 45e26b3ba3 suite green, maintainability floor PASS; checker floor NO RESULT: adrvalidator unmeasured, static.developer.overheid.nl refused by this sandbox network policy (Default env allows only package managers + 3 hosts); not retried, deterministic; #11069 build-conflict with #11059)
2026-11-25T08:26:10Z NORESULT prs=none main=28d6ce8f88 integrator-cloud (no tree built: checker floor still cannot reach static.developer.overheid.nl (proxy 403, Default env unchanged); deps also fail on psalm 7.16.0 needing php>=8.3.16 vs 8.3.6; gitleaks not installed. Ready once unblocked: #11059 and #11069 prechecked GREEN but build-conflict with each other)
2026-11-25T09:16:31Z NORESULT prs=11059 main=28d6ce8f88 integrator-cloud (deps failed: php 8.3.6 < psalm 7.16 needs 8.3.16; checker floor host static.developer.overheid.nl still proxy 403; gitleaks absent until go install. Cause: this routine runs in ARCHIVED env 'Default' env_01Wjsu1ZPKwtVve24SGSSQm2 (no setup script, 3 hosts); the fixed env is the other 'Default' env_01YLHY8C83yhz6Fypux5C1nU (unrestricted, setup script). #11069 still build-conflicts with #11059)
2026-11-25T09:42:24Z GREEN prs=11059 main=51f2f1f526 integrator-cloud (merged; tree 84f67697a0; composer via composer-cloud.sh php-version fallback; gitleaks v8.30.0 via go install, apt 8.16 lacks 'dir'; stale cached secretscanning result removed; #11069 build-conflicted with #11059, retried next tree)
2026-11-25T09:48:29Z RED prs=10156,10300,10499 main=51f2f1f526 integrator-cloud (jest initial-data.golden.spec.js EditSource snapshot gains runAs/schedule; suspect #10156; #10451 build-conflict)
2026-11-25T10:01:31Z GREEN prs=10300,10499 main=c6eb5eb60b integrator-cloud (merged; tree a32d701230)
2026-11-25T10:06:55Z RED prs=10156,10523 main=c6eb5eb60b integrator-cloud (same initial-data.golden EditSource snapshot; #10156 again)
2026-11-25T10:19:20Z GREEN prs=10523,10504,10540 main=1e257c721f integrator-cloud (merged)
2026-11-25T10:46:32Z GREEN prs=11023,11071,11081,11112,11122 main=03d2528a7e integrator-cloud (merged)
2026-11-25T11:02:24Z GREEN prs=10547,11020,11064,11074,11084,11087,11091,11097 main=d5dd89eaac integrator-cloud (merged)
2026-11-25T11:17:17Z GREEN prs=11094,11101,11103,11107,11121,11135 main=55f2aa3e63 integrator-cloud (merged)
2026-11-25T11:30:17Z GREEN prs=11104,11123 main=1c939e16cd integrator-cloud (merged)
2026-11-25T11:45:06Z GREEN prs=10508,10550,11096,11134 main=5aa365abba integrator-cloud (merged)
2026-11-25T11:59:33Z GREEN prs=11068,11106,11116,11117,11140,11144 main=76bbb15a14 integrator-cloud (merged)
