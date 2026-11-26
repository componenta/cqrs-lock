# Callable lock-key resolvers

**Readiness:** `blocked` — reconcile the unavailable linked design discussion before implementation; any mandatory CQRS5/DI6 migration requires its own settled dependency transition.

## Objective

Allow a command's `#[Lock]` declaration to compute an opaque resource key with a PHP closure. The middleware evaluates the key for the command being dispatched, then applies the same acquisition, reentrancy, and release protocol used for string-template keys.

This is a specification-only draft. The callback contract below is a proposed design, not a claim that the linked conversation already required its exact signature or failure policy. No production code, tests, dependency declarations, or generated artifacts are changed by this document.

## Evidence and scope

The inspected package baseline is `componenta/cqrs-lock` `main` at `dbdfae8f364de5f4f52afa34ade2e5142be1bb9e`. Its `v2` branch is an ancestor of this baseline; it is not a newer implementation target. No `AGENTS.md` or existing specification convention was present in the inspected repository tree.

The baseline requires PHP `>=8.4`, `componenta/cqrs:^4.0.0`, `componenta/config:^3.0.0`, and `symfony/lock:^7.4 || ^8.0`. Released CQRS `v4.0.0` is commit `3e49a7dd8d0497d980cb93bb2a884d924710bcc9`. Separately inspected CQRS `main` at `b2306d6503a6ae1d010c160b24216dc830a7c31a` is the v5 line, requires PHP `^8.5.13` and DI `^6.0`, and is not admitted by the lock package's current Composer constraint. These are distinct compatibility targets.

The required linked design discussion was unavailable during preparation. Its contents have not been incorporated into this draft. Reconcile its requirements before declaring this specification implementation-ready.

In scope: the `Lock` attribute's key input, per-invocation key resolution, error projection, and public-contract verification for both callback and string-template keys.

Out of scope: changing distributed-lock stores, introducing lock refresh or deadlines, changing middleware order, serializing closures, adding a callback registry, resolving callback parameters through a container, or changing the CQRS operation lifecycle.

## Current architecture

- `src/Lock/Attribute/Lock.php` is a final readonly class-target attribute. It accepts a string key, trims its outer whitespace, rejects an empty result, and validates a positive finite TTL. Defaults are `ttl: 300.0` seconds and `blocking: true`.
- `src/Command/Middleware/ResourceLockMiddleware.php` asks `CommandMetadataProviderInterface` for `Lock`, resolves placeholders against the current command, acquires a Symfony lock, invokes the next handler, and releases the lock in a `finally` path.
- Placeholder properties must exist, be initialized, be non-static, and be stored properties without hooks. The existing conversion of null, boolean, scalar, and `Stringable` values belongs to the string-template path.
- `src/Command/Middleware/Internal/LockExecutionState.php` tracks same-key reentrancy separately for the main execution context and each Fiber. A nested same-key dispatch in the same execution context shares the outer acquisition. Another Fiber must acquire its own lock.
- `LockReleaseException` preserves a downstream failure and a release failure when both occur. A release failure after downstream success is still observable as an exception.
- The factory resolves `LockFactory` and `CommandMetadataProviderInterface` from a PSR container. It does not need another service to invoke a closure.

## Required behavior and preservation

- **R1:** A command can declare a callable key without introducing a separate resolver service for a small computation.
- **R2:** Key resolution uses the current command instance on every middleware entry carrying `Lock` metadata.
- **R3:** Callback execution occurs during dispatch, before acquisition or downstream execution, and not during metadata discovery, attribute construction, application build, or metadata lookup.
- **I1:** The resolved key used for reentrancy, acquisition, and release denotes one resource for that invocation. It is not recomputed after the handler changes application state.
- **I2:** No callback result or command instance is retained as a class-level key cache.
- **P1:** Existing valid string-template declarations retain their normalization, placeholder validation and conversion, TTL, blocking, and exception behavior.
- **P2:** Commands without `Lock` metadata continue directly to the next handler.
- **P3:** Existing Fiber isolation, nested same-key behavior, and release-failure reporting remain valid for keys produced by callbacks.
- **P4:** Application configuration continues to own lock/retry/transaction/policy order. A callback does not add a hidden lock layer or retry loop.

## Proposed desired-state contract

The following decisions must be reconciled with the linked conversation before becoming the implementation target.

### Attribute boundary

Keep the existing attribute and parameter names. Widen only the key input and exposed key value:

```php
/** @param string|(Closure(object): string) $key */
public function __construct(
    string|Closure $key,
    public float $ttl = 300.0,
    public bool $blocking = true,
);

public string|Closure $key;
```

The constructor retains the string-key checks when the value is a string. A `Closure` is retained without invocation, rebinding, cloning, or conversion into a string/method pair. TTL validation applies equally to both forms.

`string` always means the existing template. A string that happens to name a PHP function is not reinterpreted as a callback. Arbitrary callable arrays and invokable objects are not additional attribute forms; callers can create a `Closure` explicitly when constructing metadata programmatically.

The new public union is an opt-in capability: instances created from a string still expose a string. Consumers inspecting metadata must branch on the declared type before treating `key` as text.

### Callback input and result

The callback is invoked as `($attribute->key)($operation->command)`. The argument is the exact command object associated with that operation, with no DTO conversion, clone, service injection, or separate operation parameter. A callback can declare the concrete command class as its parameter type.

The callback must return an actual string. Integer, boolean, null, array, object, and `Stringable` results are rejected rather than cast. The string must not be empty or whitespace-only. The nonblank result is used exactly as returned: no trimming, interpolation, prefixing, hashing, or case conversion. In particular, `"0"` is valid and literal braces in a callback result are not interpreted as placeholders.

The application owns collision avoidance and resource scope, including tenant/account identity when applicable. The middleware cannot infer a domain-specific namespace. A key callback is an application computation, not an authentication or authorization boundary.

A callback should compute a stable key from explicit command data. PHP's `static` syntax does not prove purity: callback code can still use static variables, globals, I/O, or mutable objects. The middleware does not sandbox application code or promise that arbitrary user callbacks are side-effect-free.

### Invocation and failure semantics

For a callback declaration, resolve the key exactly once on each call to `execute()` after metadata materialization succeeds. Resolution precedes both the reentrancy lookup and creation/acquisition of a Symfony lock. A reentrant nested dispatch still resolves its own command's key once, then uses the existing reentrancy protocol.

If invocation throws any `Throwable`, including parameter/return type failures, throw `LockKeyResolutionException` with that throwable as `previous`. If an untyped callback returns an invalid type or a blank string, throw `LockKeyResolutionException` describing the violated callback contract. Diagnostic text must identify the command class and expected result shape without including the command payload or resolved key.

No key-resolution failure creates/acquires a lock, invokes the downstream handler, or mutates lock-ownership bookkeeping. Callback effects that already happened are not rolled back. An attribute-materialization failure remains the metadata provider's failure; it is not relabeled as a key-callback failure.

After a valid key is obtained, enter the existing acquisition and release flow. Do not wrap an acquisition or release failure as `LockKeyResolutionException`.

### Example of the proposed API

```php
use Componenta\CQRS\Lock\Attribute\Lock;

#[Lock(self::resourceKey(...), ttl: 30.0, blocking: false)]
final readonly class RebuildProduct
{
    public function __construct(
        public int $tenantId,
        public int $productId,
    ) {}

    private static function resourceKey(self $command): string
    {
        return 'product:' . $command->tenantId . ':' . $command->productId;
    }
}
```

An inline PHP 8.5 attribute callback uses `static function (RebuildProduct $command): string { ... }` without a `use` capture. Arrow functions, including `static fn`, are not supported in PHP 8.5 constant expressions. First-class function/static-method callables preserve PHP's native scope; the middleware must not reconstruct private methods by name.

Although the syntax is available from PHP 8.5.0, the proposed native first-class-callable attribute scope contract is supported and must be tested on PHP 8.5.13 or later. PHP 8.5.13 fixes trait constant-expression callables resolving against the first consuming class and bypassing visibility checks.

## Metadata, generated artifacts, and lifetime

Source attribute declarations remain authoritative. Released CQRS v4 constructs a fresh native attribute on each metadata lookup. CQRS v5 additionally caches bounded `ReflectionAttribute` descriptors and still calls `newInstance()` for each lookup. Neither inspected path requires storing a materialized `Lock` or a computed key in the shared cache.

The package must accept the `Closure` supplied by the metadata provider without adding its own closure-instance or command cache. It must not make stronger closure-identity or reset guarantees than native PHP evaluation provides.

The inspected `cqrs-app` builder exports handler and listener registration maps only. It does not export `Lock`/`Retry` metadata. No closure exporter, generated closure source, or serialized callback belongs in this change. A deployed command's source and its executable code must already be part of the same application deployment, as for existing attributes.

The existing TTL remains a duration in seconds passed to Symfony. It does not become a deadline, renewal schedule, or promise that a remote lease cannot expire during execution. `blocking` remains Symfony-store behavior. Preserve the existing explicit `autoRelease: false` and release protocol.

## Contract impact and change surface

| Source or consumer | Intended disposition |
|---|---|
| `src/Lock/Attribute/Lock.php` | Widen `key` to `string|Closure`; preserve string and TTL validation. |
| `src/Command/Middleware/ResourceLockMiddleware.php` | Select callback or existing template resolution before the existing lock protocol. |
| `src/Command/Exception/LockKeyResolutionException.php` | Reuse its existing `previous` support; no new exception is needed. |
| `src/Command/Middleware/Internal/LockExecutionState.php` | Retain the existing responsibility; no alternate callback-specific ownership mechanism. |
| `src/Command/Factory/ResourceLockMiddlewareFactory.php` and `src/Lock/ConfigProvider.php` | No new registration or callback dependency is needed. |
| Existing application `#[Lock('resource:{id}')]` declarations | Continue to use the template path. |
| Third-party metadata providers / metadata-inspecting consumers | May supply or observe the documented union; must not assume every key is a string. |
| `cqrs-app` generated registration maps | No format change or migration. |

The implementation will update `tests/LockAttributeTest.php`, `tests/ResourceLockMiddlewarePropertyTest.php`, `tests/ResourceLockMiddlewareReentrancyTest.php`, native attribute fixtures in the existing test tree, and `README.md`. Test files are not changed during specification work.

## Verification strategy

Use Pest as declared by this package. Test `ResourceLockMiddleware::execute()` and native `CommandMetadataProviderInterface` composition through observable effects. Do not call private key-resolution methods, inspect private caches, or assert helper structure. Use existing Symfony test-store seams to observe which resource is held, and use recording handlers for dispatch outcomes.

| Contract | Observable acceptance proof |
|---|---|
| R1, R3 | A native first-class private static method and an inline static closure materialize without running their bodies; dispatch runs the callback and locks the returned resource. |
| R2, I2 | Two commands of the same class with different tenant/resource IDs produce their own keys through both fresh and repeated metadata lookup. Changing a callback-visible command value between separate entries affects the later key. |
| I1 | A handler changing command-accessible state does not cause a second key computation or release a different resource. |
| Callback result shape | Non-string, empty, and whitespace-only values fail before acquisition/handler execution; `"0"`, nonblank whitespace-containing keys, and literal braces retain their exact bytes. |
| Callback faults | A thrown exception and a signature type failure become `LockKeyResolutionException` with the exact original throwable as `previous`; ownership is not entered. |
| P1 | Existing template normalization, private stored properties, scalar/Stringable conversions, and rejection of missing/uninitialized/static/hooked properties retain their contractual outcomes. |
| P2 | Missing metadata does not invoke a key callback or acquire a resource. |
| P3 | A callback and a template that resolve to the same key share the existing same-context reentrant lock; distinct Fibers cannot share ownership; success and failure release the actual held resource. |
| P3 | A release failure after success, and simultaneous handler/release failures, retain the existing `LockReleaseException` public fields. |
| P4 | Composing lock outside retry holds the resolved lock across attempts; composing it inside retry resolves/acquires/releases on each middleware entry. |
| Scope / metadata | On PHP 8.5.13+, two command classes using the same trait and private static first-class callbacks retain their own class scope and visibility through first and repeated metadata resolution; callback body state is neither precomputed nor deliberately rebound. |

For TDD, establish each new public outcome as failing on the baseline, implement the smallest coherent behavior, and rerun the related existing tests. Verify the callback and template success controls alongside rejection cases. Use the verified package commands `composer test` and `composer analyse` once dependencies for the chosen target line are installed. Any supported PHP-version-specific fixtures must be parsed only on a compatible runtime. Tests and syntax checks have not been executed as part of this draft.

## Blocking decisions and bounded evidence work

1. **Linked-conversation requirements.** Read the supplied conversation and extract only decisions affecting callable argument shape, dependency versions, existing syntax, and desired scope. Reconcile them with this document and remove or revise the proposed decisions. Stop when there is an explicit conflict resolution for every material difference. Do not infer inaccessible requirements from the conversation URL.
2. **Dependency reconciliation.** The proposed feature targets the current package line and retains its CQRS4-compatible constraints. Its class definitions can remain compatible with PHP 8.4, but the supported native callable attribute scope contract and its acceptance fixtures require PHP 8.5.13+. Retaining package-wide PHP 8.4 for existing/programmatic usage versus raising the package minimum remains a dependency-reconciliation decision; syntax availability from PHP 8.5.0 does not settle that choice. If the unavailable discussion mandates the DI6/CQRS5 stack or a higher library-wide PHP minimum, that is a separate migration prerequisite: explicitly settle the Composer transition and integration pairing before implementation. The current add-on constraints cannot install CQRS5. Do not describe DI6 integration as delivered merely because both metadata mechanisms can carry a closure.

No production slices are executable while these blockers remain. After they are settled, derive dependency-ordered behavior slices from the contract and acceptance mapping: key callback resolution, failure/isolation behavior, then metadata/composition parity. The final implementation must be compared against this desired state for missing, partial, contradictory, or unrequested behavior.

## Sources

- [Lock attribute at the inspected package baseline](https://github.com/componenta/cqrs-lock/blob/dbdfae8f364de5f4f52afa34ade2e5142be1bb9e/src/Lock/Attribute/Lock.php).
- [Lock middleware and exception ownership](https://github.com/componenta/cqrs-lock/blob/dbdfae8f364de5f4f52afa34ade2e5142be1bb9e/src/Command/Middleware/ResourceLockMiddleware.php).
- [Fiber-scoped reentrancy](https://github.com/componenta/cqrs-lock/blob/dbdfae8f364de5f4f52afa34ade2e5142be1bb9e/src/Command/Middleware/Internal/LockExecutionState.php).
- [CQRS4 native metadata provider](https://github.com/componenta/cqrs/blob/3e49a7dd8d0497d980cb93bb2a884d924710bcc9/src/Command/Metadata/ReflectionCommandMetadataProvider.php).
- [CQRS5 descriptor cache and native materialization](https://github.com/componenta/cqrs/blob/b2306d6503a6ae1d010c160b24216dc830a7c31a/src/Command/Metadata/ReflectionCommandMetadataProvider.php).
- [CQRS application map builder](https://github.com/componenta/cqrs-app/blob/ec60fadebd5eab6a7582a930311e3258fb31df37/src/Build/CqrsBuilder.php).
- [PHP 8.5 closures in constant expressions](https://wiki.php.net/rfc/closures_in_const_expr), [first-class callables and native scope](https://wiki.php.net/rfc/fcc_in_const_expr).
- [Symfony Lock 7.4: acquisition, TTL, release, and ownership](https://symfony.com/doc/7.4/lock.html).
- [PHP 8.5.13 trait first-class-callable scope and visibility fix](https://www.php.net/ChangeLog-8.php#8.5.13).
