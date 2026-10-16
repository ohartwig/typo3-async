# Changelog

## [1.4.1](https://git.ole-hartwig.eu/ai-ready-platform/platform/typo3-async/compare/v1.4.0...v1.4.1) (2026-10-16)

### :bug: Fixes

* **messenger:** decode only the classes a queued message is made of ([4eba9f8](https://git.ole-hartwig.eu/ai-ready-platform/platform/typo3-async/commit/4eba9f8920f597255acd66ffbdc13ef50ae81ed2))

### :white_check_mark: Tests

* **functional:** stamp the hand-written queue row in UTC ([2a668bb](https://git.ole-hartwig.eu/ai-ready-platform/platform/typo3-async/commit/2a668bb412cfc8f12e826694a553496020f7b86b))

## [1.4.0](https://git.ole-hartwig.eu/ai-ready-platform/platform/typo3-async/compare/v1.3.9...v1.4.0) (2026-10-16)

### :sparkles: Features

* **failed:** prune parked messages after a retention ([4965533](https://git.ole-hartwig.eu/ai-ready-platform/platform/typo3-async/commit/4965533c355cd6025ad71256eb27dc8b768497a6))

## [1.3.9](https://git.ole-hartwig.eu/ai-ready-platform/platform/typo3-async/compare/v1.3.8...v1.3.9) (2026-10-16)

### :bug: Fixes

* **images:** queue variants only for an editor's upload within a pixel limit ([c60ade9](https://git.ole-hartwig.eu/ai-ready-platform/platform/typo3-async/commit/c60ade9670910f74de01efdd8e2adb462d191581))

## [1.3.8](https://git.ole-hartwig.eu/ai-ready-platform/platform/typo3-async/compare/v1.3.7...v1.3.8) (2026-10-16)

### :bug: Fixes

* **commands:** keep the own commands off the scheduler ([b889853](https://git.ole-hartwig.eu/ai-ready-platform/platform/typo3-async/commit/b889853121c3ff9405b44b5198c3d6e01b4ebcfb))

## [1.3.7](https://git.ole-hartwig.eu/ai-ready-platform/platform/typo3-async/compare/v1.3.6...v1.3.7) (2026-10-16)

### :repeat: Continuous Integrations

* drop the TER publish, Packagist is enough ([80580dd](https://git.ole-hartwig.eu/ai-ready-platform/platform/typo3-async/commit/80580dd735b26178bd37f5db06152726104c3ea8))
* publish releases to the TYPO3 Extension Repository ([01832f7](https://git.ole-hartwig.eu/ai-ready-platform/platform/typo3-async/commit/01832f7bad0f5a548340fcc41d62373078094419))

### :repeat: Chores

* **repo-templates:** sync ([a5c084c](https://git.ole-hartwig.eu/ai-ready-platform/platform/typo3-async/commit/a5c084c57c7661dfe01cd48aa13b84d530978e9a))

## [1.3.6](https://git.ole-hartwig.eu/ai-ready-platform/platform/typo3-async/compare/v1.3.5...v1.3.6) (2026-10-14)

### :bug: Fixes

* **deps:** update composer packages to ^7.4.21 ([7a1f793](https://git.ole-hartwig.eu/ai-ready-platform/platform/typo3-async/commit/7a1f7933dd48ed4c8e9cde29ad5485be4631ab29))

## [1.3.5](https://git.ole-hartwig.eu/ai-ready-platform/platform/typo3-async/compare/v1.3.4...v1.3.5) (2026-10-14)

### :bug: Fixes

* **deps:** update dependency symfony/doctrine-messenger to ^7.4.21 ([3956626](https://git.ole-hartwig.eu/ai-ready-platform/platform/typo3-async/commit/3956626091d85ce63502f46b1572ef1b26c8020c))

## [1.3.4](https://git.ole-hartwig.eu/ai-ready-platform/platform/typo3-async/compare/v1.3.3...v1.3.4) (2026-10-13)

### :repeat: Chores

* **deps:** update dependency phpunit/phpunit to ^13.4.2 ([a899632](https://git.ole-hartwig.eu/ai-ready-platform/platform/typo3-async/commit/a899632306655ff1752bdcee602174d167282da1))

## [1.3.3](https://git.ole-hartwig.eu/ai-ready-platform/platform/typo3-async/compare/v1.3.2...v1.3.3) (2026-10-06)

### :repeat: Chores

* **deps:** update dependency phpunit/phpunit to ^13.4.1 ([0703f34](https://git.ole-hartwig.eu/ai-ready-platform/platform/typo3-async/commit/0703f34c3a291c10037abea50e912383df9f7b95))

## [1.3.2](https://git.ole-hartwig.eu/ai-ready-platform/platform/typo3-async/compare/v1.3.1...v1.3.2) (2026-10-04)

### :repeat: Chores

* **repo-templates:** sync ([f60bf78](https://git.ole-hartwig.eu/ai-ready-platform/platform/typo3-async/commit/f60bf7861b03f6ea6ebbbc2970deeb3a1d640931))

## [1.3.1](https://git.ole-hartwig.eu/ai-ready-platform/platform/typo3-async/compare/v1.3.0...v1.3.1) (2026-10-03)

### :repeat: Chores

* **deps:** update dependency phpunit/phpunit to ^13.4.0 ([5d4702f](https://git.ole-hartwig.eu/ai-ready-platform/platform/typo3-async/commit/5d4702ffdc70eb012d574554a709e8931455526f))

## [1.3.0](https://git.ole-hartwig.eu/ai-ready-platform/platform/typo3-async/compare/v1.2.1...v1.3.0) (2026-10-02)

### :sparkles: Features

* commands to see, return and drop parked messages ([a667cb8](https://git.ole-hartwig.eu/ai-ready-platform/platform/typo3-async/commit/a667cb8f9a9241ed44781302cb19212c614183bf))
* the consumer writes queue figures for Prometheus ([70870e5](https://git.ole-hartwig.eu/ai-ready-platform/platform/typo3-async/commit/70870e5817ddb487ee1a466ff0decf01cb1832b5))

## [1.2.1](https://git.ole-hartwig.eu/ai-ready-platform/platform/typo3-async/compare/v1.2.0...v1.2.1) (2026-10-02)

### :bug: Fixes

* **deps:** update composer packages to ^7.4.19 ([a1546f7](https://git.ole-hartwig.eu/ai-ready-platform/platform/typo3-async/commit/a1546f729d1c6ead69f12fa4506f10d2a907e371))

## [1.2.0](https://git.ole-hartwig.eu/ai-ready-platform/platform/typo3-async/compare/v1.1.1...v1.2.0) (2026-10-02)

### :sparkles: Features

* learn image profiles from what the site has rendered ([5fba2a7](https://git.ole-hartwig.eu/ai-ready-platform/platform/typo3-async/commit/5fba2a79afe9b001626ce513538d03ce4334f571))

## [1.1.1](https://git.ole-hartwig.eu/ai-ready-platform/platform/typo3-async/compare/v1.1.0...v1.1.1) (2026-10-02)

### :bug: Fixes

* drop ext_conf_template.txt, extension:setup cannot write settings.php ([e3cef1a](https://git.ole-hartwig.eu/ai-ready-platform/platform/typo3-async/commit/e3cef1a19afa104b5f65ccbab6524a9d4fce62c5))

## [1.1.0](https://git.ole-hartwig.eu/ai-ready-platform/platform/typo3-async/compare/v1.0.2...v1.1.0) (2026-10-02)

### :sparkles: Features

* produce image variants after an upload or a crop change ([c78ea75](https://git.ole-hartwig.eu/ai-ready-platform/platform/typo3-async/commit/c78ea75ee3c8b26784b38600f1feb3210116aec5))

### :white_check_mark: Tests

* cover replace, NEW ids, gone records and the suggest command ([d5f55a2](https://git.ole-hartwig.eu/ai-ready-platform/platform/typo3-async/commit/d5f55a2c0387478f6d8fb41a69280c4d69fe9bc6))

## [1.0.2](https://git.ole-hartwig.eu/ai-ready-platform/platform/typo3-async/compare/v1.0.1...v1.0.2) (2026-10-02)

### :bug: Fixes

* **deps:** update dependency symfony/mime to ^7.4.12 [security] ([472f133](https://git.ole-hartwig.eu/ai-ready-platform/platform/typo3-async/commit/472f133d67b492608228743d307b89fea7f7f591))
* **deps:** update dependency symfony/mailer to ^7.4.12 [security] ([a57dec5](https://git.ole-hartwig.eu/ai-ready-platform/platform/typo3-async/commit/a57dec5fbd495dd391b56670859af628064541ed))

### :repeat: Chores

* **repo-templates:** sync ([a628129](https://git.ole-hartwig.eu/ai-ready-platform/platform/typo3-async/commit/a628129b7be61b03b8b9b8dab4c2d454fef9bba0))

## [1.0.1](https://git.ole-hartwig.eu/ai-ready-platform/platform/typo3-async/compare/v1.0.0...v1.0.1) (2026-10-02)

### :bug: Fixes

* **deps:** update composer packages ([de19d21](https://git.ole-hartwig.eu/ai-ready-platform/platform/typo3-async/commit/de19d2178e756250969a1018b519ce2ae0b9549b))

### :repeat: Continuous Integrations

* mirror release tags to github.com/ohartwig/typo3-async ([937eb59](https://git.ole-hartwig.eu/ai-ready-platform/platform/typo3-async/commit/937eb592f98bccb998a85e603545456be909b218))

## [1.0.0] (2026-10-02)

### :sparkles: Features

* mails leave the request, with retries and a failure queue ([03ae49f](https://git.ole-hartwig.eu/ai-ready-platform/platform/typo3-async/commit/03ae49fc60b4aeef6432c3da469d724c7572e93f))

### :barber: Styles

* **tests:** PER coding style in the functional test ([1e321b1](https://git.ole-hartwig.eu/ai-ready-platform/platform/typo3-async/commit/1e321b1020b05b2fb0300327b8dc4febdbc116d0))

