# Parallel worker harness

These are not part of `./vendor/bin/pest`. They spawn real OS processes against a
shared SQLite file, which is too slow and too environment-dependent for CI, but
it is the only way to show what the Pest suite cannot: that the attempt claim
holds when workers genuinely run at the same instant.

```bash
tests/concurrency/run.sh claim    # 12 workers, one generation
tests/concurrency/run.sh capacity # 8 workers, 8 generations
tests/concurrency/run.sh both
```

Each worker boots the app, spins until a shared wall-clock instant, then acts, so
the contention is real rather than an artefact of staggered start-up.

## What they show

`claim` — 12 workers competing for one generation. Exactly one claim succeeds and
`submit_attempts` lands on 1. Against the read-modify-write this replaced, all 12
proceeded and the counter recorded 2: a lost update that left the provider's
idempotency key as the only thing standing between us and 12 billable generations.

`capacity` — 8 workers submitting 8 different generations with
`PIXELFORGE_MAX_IN_FLIGHT=3`. All 8 go through, ending with 8 in flight. This is
the expected result: the in-flight count is an unsynchronised read and is
advisory by design. Pixelforge enforces the real limit with 429, which the job
honours via Retry-After. Treat a non-8 result as a change in behaviour worth
understanding, not as a pass or a fail.
