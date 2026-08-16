/**
 * Pulls in the matchers `@wordpress/jest-console` adds to `expect()`.
 *
 * The package ships its own declarations and augments the global `jest`
 * namespace with them, but it is not an `@types/` package, so TypeScript never
 * loads it on its own — and `expect( console ).toHaveLogged()` fails with
 * TS2339 in a test file which is otherwise perfectly correct.
 *
 * The matchers themselves come from the jest preset, which is set up by
 * `@wordpress/scripts` and is not something this plugin configures.
 */

/// <reference types="@wordpress/jest-console" />

export {};
