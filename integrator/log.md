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
