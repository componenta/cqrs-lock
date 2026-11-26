# Callable lock-key integration verification

## Dependency target

The feature targets the next v4 add-on line: PHP `^8.5.13`, CQRS `^5.0`, and Config `^4.0`. CQRS5 uses DI6, Reflection3, and Identity1.1. The inspected current DI/CQRS/Identity sources needed small compatibility updates to admit Config4 and Reflection3. Those changes live on `fix/callback-dependency-baseline-2026-11-26` in the corresponding repositories.

The production manifest keeps semantic major requirements. Development and CI explicitly select the actual support branches in an ignored `.composer-integration.json`; each package declares its own truthful branch alias. No path repository overrides a package version, and no previous-major source is relabeled as the new major. Config4 and Reflection3 are installed from their released versions.

## Reproduce the current development graph

Use PHP 8.5.13 or later and Composer 2. The following commands run from a clean checkout of this feature branch. The alternate manifest leaves the tracked `composer.json` unchanged. The commands pin the exact verified revisions on the matching dependency branch names so Composer uses their declared branch aliases.

```bash
git clone --branch fix/callback-dependency-baseline-2026-11-26 --single-branch https://github.com/componenta/cqrs.git .ci/cqrs
git clone --branch fix/callback-dependency-baseline-2026-11-26 --single-branch https://github.com/componenta/di.git .ci/di
git clone --branch fix/callback-dependency-baseline-2026-11-26 --single-branch https://github.com/componenta/identity.git .ci/identity
git -C .ci/cqrs switch -C fix/callback-dependency-baseline-2026-11-26 9d3da0fcb8a0d59d9639d2c5da2adb942dc7953f
git -C .ci/di switch -C fix/callback-dependency-baseline-2026-11-26 e4f19ab9a9f6bd18da85fc52c8d478433a32baec
git -C .ci/identity switch -C fix/callback-dependency-baseline-2026-11-26 3feb94e0d64956ecdf7b22abed4a8bd9f597abeb
cp composer.json .composer-integration.json
export COMPOSER=.composer-integration.json
composer config --json repositories.componenta-cqrs '{"type":"path","url":".ci/cqrs"}'
composer config --json repositories.componenta-di '{"type":"path","url":".ci/di"}'
composer config --json repositories.componenta-identity '{"type":"path","url":".ci/identity"}'
composer require --no-update \
  componenta/cqrs:dev-fix/callback-dependency-baseline-2026-11-26 \
  componenta/di:dev-fix/callback-dependency-baseline-2026-11-26 \
  componenta/identity:dev-fix/callback-dependency-baseline-2026-11-26
composer update --with-all-dependencies --no-interaction --prefer-dist --no-progress
composer test
composer analyse
```

Pull requests and ordinary main pushes follow this development recipe. The PHP matrix covers 8.5.13 and the current 8.5 patch.

A main push whose commit message starts with `Release v` verifies the tracked `composer.json` using normal stable dependency resolution. The release path skips all development-source checkouts and manifest generation. Both the main verification and the minimum Symfony Lock gate use that stable graph before the publish job can run. The minimum gate narrows only Symfony Lock to 8.1.0, which is already admitted by the production constraint. Until stable releases satisfying the declared CQRS5/DI6/Config4 graph exist, release verification must fail; successful development-branch integration does not make a stable release publishable.

## Verification record

Verified on 2026-11-26 with PHP 8.5.13, Composer 2.11.2, Pest 4.7.8, PHPUnit 12.5.33, and PHPStan 2.3.7.

| Dependency | Verified revision |
|---|---|
| CQRS5 compatibility branch | `9d3da0fcb8a0d59d9639d2c5da2adb942dc7953f` |
| DI6 compatibility branch | `e4f19ab9a9f6bd18da85fc52c8d478433a32baec` |
| Identity1.1 compatibility branch | `3feb94e0d64956ecdf7b22abed4a8bd9f597abeb` |
| Config | `v4.0.0` (`4685e71d86500a7b51f4a773bb3022dd787fba7f`) |
| Reflection | `v3.0.0` |

### Contract evidence

- Before the feature, the existing package tests passed on the migrated dependency graph: 25 tests, 112 assertions. The 15 new callback cases failed because the baseline `Lock` constructor accepted only strings.
- After implementing callback keys, the complete public test suite verifies native attributes, exact resource bytes, invalid results, exception identity, same-key callback/template reentrancy, Fiber contention, cleanup after handler failure, and real Config4/DI6 provider wiring.
- `composer analyse` checks production code and a typed command consumer at maximum level. `Lock<TCommand>` allows concrete command parameter types; no type-ignore baseline is used. Private trait callable scope is exercised by the runtime metadata tests.
- Final current graph: **42 tests / 226 assertions passed** with Symfony Lock **8.1.12**; maximum-level PHPStan passed, Composer strict validation passed, and all production platform requirements passed.
- The agreed minimum Symfony Lock **8.1.0** gate also passed **42 tests / 226 assertions** and maximum-level PHPStan on PHP 8.5.13. The working dependency graph was then restored to 8.1.12.
- The CI workflow YAML and declared PHP matrix were parsed, and the actual dependency-install recipe was executed locally. Remote Actions execution is separate from these local results.

The complete package suite, maximum-level analysis, and lock/retry composition check were rerun after the DI6 assembly-readiness update at the revision above. The release, ordinary-push, and pull-request workflow paths were checked separately. Stable production resolution currently rejects the unavailable stable CQRS5 requirement; the release path preserves that failure instead of substituting development sources.

The targeted Symfony constraint is `^8.1`. CI checks the latest admitted release and the minimum 8.1.0 API baseline. Symfony 7.4 is part of the old package baseline, not the current compatibility target.

### Lock/retry composition

The executable check uses native attributes, the real CQRS5 `PipelineHandler`, and Symfony's real `InMemoryStore`. It loads the companion retry source explicitly and introduces no circular development dependency:

```bash
git clone --branch spec/attribute-callbacks-2026-11-26 --single-branch https://github.com/componenta/cqrs-retry.git .ci/cqrs-retry
php integration/lock-retry.php .ci/cqrs-retry/src
```

Observed result on the current feature implementations:

| Order | Handler attempts | Condition decisions | Key resolutions | Acquisitions | Releases |
|---|---:|---|---:|---:|---:|
| Lock → Retry | 3 | `[1, 2]` | 1 | 1 | 1 |
| Retry → Lock | 3 | `[1, 2]` | 3 | 3 | 3 |

The check also proves that an independent lock contender fails during every handler attempt, the resource becomes available after the pipeline, every attempt sees the original operation, and the successful handler operation is returned exactly.
