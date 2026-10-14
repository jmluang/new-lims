# Integration on main 9409c26

- F2 production source: all 91 file hashes match aggregate `ec71288388d0d6db6f8af4910c76012472c9427933fdcebdb059955a3286623e`.
- Rust Busy regressions: 4/4 passed in the recorded Rust 1.90 Linux build. Original-report Java/F2 overload and normal-output comparisons remain recorded in F2-RESULTS.md.
- Laravel SQLite regression: 54 tests, 613 assertions passed (draft, lifecycle, handwritten workflow and signing notification).
- Frontend full initial integration run: 99 test files / 575 tests passed; three static layout assertions required integration with metadata refresh. All affected layout, resume-refresh and lifecycle tests subsequently passed: five files / 31 tests.
- Vite backend asset build passed.
- Full TypeScript project check exceeded the prescribed small CLI heap allowance; changed-file and transitive-import type checking passed for all 11 changed TypeScript files, including the Vite client declarations. This limitation is not a Rust service OOM.

Published-report metadata is refreshed on entry; editing stays locked while that refresh is pending. Live workflow recovery from the newer main branch is retained. Browser interaction regressions confirm a refreshed cancelled/rejected workflow permits replanning, while a refreshed published revision remains read-only.

Private credentials, user reports, generated assets and compiled binaries are excluded from Git. Real testing-site login and external TSA readiness require separate deployment verification.
