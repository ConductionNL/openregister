# LANE-LOG f-risky (openregister)

- Branch `fix/apphost-tests-coverage-metadata` from origin/development (b156216f82), commit 2b62ba9e67.
- Source: PR #4136 run 36453586195, job 109037592909 (PHPUnit PHP 8.3, NC stable35, pgsql). Head run: 797 risky; base run: 789 risky, 1 failure (ObjectServiceRunAsAnonymousTest, on the merge base).
- Finding: openregister phpunit.xml has failOnRisky="false". The risky tests do NOT redden the job; #4136 is red from the coverage guard (-2.08% on its own 7 files). But php-code-coverage throws away the coverage of a risky test, so these 797 tests contributed nothing to clover; declaring @uses makes their coverage count.
- Scripted: parsed all 797 entries into 199 test files x 592 (file, class) pairs, inserted `@uses` after the last class-level @covers/@uses/@coversDefaultClass. Diff = 199 files, 592 insertions, nothing else (checked by grep of every +/- line). php -l clean on all 199.
- No coverage driver locally.
- check:strict exit 1 from the "No code coverage driver" runner warning only (24327 tests, 0 failures; lint, phpcs, phpmd, psalm and phpstan clean). Pushed 2b62ba9e67, PR https://github.com/ConductionNL/openregister/pull/4155. CI read once: still pending at read time, not re-polled.
