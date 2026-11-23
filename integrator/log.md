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
