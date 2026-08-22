/**
 * Pulls in the matchers `@wordpress/jest-console` adds to `expect()`.
 *
 * The package augments the global `jest` namespace but is not an `@types/`
 * package, so TypeScript never loads it and `expect( console ).toHaveLogged()`
 * fails with TS2339.
 */

/// <reference types="@wordpress/jest-console" />

export {};
