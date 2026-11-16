# Goldens whose only change is the call shape (an explicit default no longer passed, key order).
cd /tmp/lanes/ormig/pipelinq
PVM_GOLDEN_RECORD=1 CHANNEL_BACKFILL_GOLDEN_RECORD=1 timeout 600 php vendor/bin/phpunit --no-coverage --filter '/(IngestProductVendorMasterGoldenTest|BackfillContactChannelArraysGoldenTest)::/' > /dev/null 2>&1
