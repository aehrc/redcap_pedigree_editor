# Changelog

## [1.0.0](https://github.com/aehrc/redcap_pedigree_editor/compare/v0.4.1...v1.0.0) (2026-09-24)


### ⚠ BREAKING CHANGES

* removes the `@PEDIGREE_HPO`/`@PEDIGREE_SCT` action tags. Terminology is now always taken from the project/system *Default Terminology* setting - existing fields tagged `@PEDIGREE_HPO`/`@PEDIGREE_SCT` will stop being recognised as pedigree fields; update them to a bare `@PEDIGREE` tag and set *Default Terminology* accordingly.

### Features

* add repeating-instrument linking, re-targeted onto RecordLinkProvider ([#9](https://github.com/aehrc/redcap_pedigree_editor/issues/9)) ([a1a8566](https://github.com/aehrc/redcap_pedigree_editor/commit/a1a85668735820676629d5597e76a28473d7da5b))
* close the Edit in REDCap window after its row is saved ([#19](https://github.com/aehrc/redcap_pedigree_editor/issues/19)) ([d274c95](https://github.com/aehrc/redcap_pedigree_editor/commit/d274c95802f302137ed8854d14d1adc26ad71c91))
* edit linked rows in REDCap's own form, re-importing on close ([#14](https://github.com/aehrc/redcap_pedigree_editor/issues/14)) ([b97f823](https://github.com/aehrc/redcap_pedigree_editor/commit/b97f823dc0d1eb2189f680d0daec4231ede626b0))
* gate record linking on record existence and scope it to the current record ([#13](https://github.com/aehrc/redcap_pedigree_editor/issues/13)) ([1651213](https://github.com/aehrc/redcap_pedigree_editor/commit/165121333f25aa1e5cf8afba2dbb9557b646119d))


### Bug Fixes

* add CI/CD pipeline; fix reflected XSS in TerminologyService ([#10](https://github.com/aehrc/redcap_pedigree_editor/issues/10)) ([412a73a](https://github.com/aehrc/redcap_pedigree_editor/commit/412a73abb737a945af9811bd28e54c8178ee78ff))
* read checkbox options whose codes REDCap renames in exports ([#15](https://github.com/aehrc/redcap_pedigree_editor/issues/15)) ([80f2f30](https://github.com/aehrc/redcap_pedigree_editor/commit/80f2f30b4a66e2a4d7807a45ad709f4905ab6940))
* read the linked instrument from one event per arm in longitudinal projects ([#18](https://github.com/aehrc/redcap_pedigree_editor/issues/18)) ([8346839](https://github.com/aehrc/redcap_pedigree_editor/commit/83468392b6c8a2de260f71d206bad60e6ad8a14d))
* send cleared REDCap fields to the pedigree so re-import clears them ([#16](https://github.com/aehrc/redcap_pedigree_editor/issues/16)) ([dd6ca77](https://github.com/aehrc/redcap_pedigree_editor/commit/dd6ca7718e10f0db7236e9ccf5bbea3e15a3fd6f))

## [0.4.1] - 2025-07-31
- Fix a bug in project settings

## [0.4] - 2023-11-15
- Add support for alternative pedigree storage formats (in addition to FHIR), enabling simple pedigrees to be built by piping in earlier form fields

## [0.3.2] - 2022-06-01
- Add a `@PEDIGREE` action tag allowing the terminology to use to be configured via module settings, rather than hard-coded to SNOMED CT/HPO as `@PEDIGREE_SCT`/`@PEDIGREE_HPO` do

## [0.3.1] - 2021-12-02
- Upgrade `open-pedigree`: fix loading pedigrees with a gene/phenotype that has no terminology, fix gene colour display in nodes, more graceful error handling

## [0.3] - 2021-11-19
- Upgrade `open-pedigree` to support the new GA4GH FHIR storage format
- Fetch terminology data via a REDCap web service backed by a FHIR terminology server, instead of binding directly

## [0.2.1] - 2020-11-13
- Don't fail FHIR server URL validation when `curl` isn't available in the PHP install
- Stop referencing `open-pedigree` files via the REDCap API endpoint, which didn't work

## [0.2] - 2020-06-29
- Upgrade `open-pedigree` to store an SVG rendering of the diagram (via a `DocumentReference`) alongside the FHIR data, and show that instead of the interactive editor where possible
- Add a "Compress Data" setting (never / only if over 65K / always) to handle the SVG addition pushing stored data past REDCap's field size limit

## [0.1.1] - 2020-06-23
- Allow the pedigree editor control to activate inside a survey, not just a standard data entry form

## [0.1] - 2020-01-28
- Initial release: a notes field tagged with an annotation is hidden/disabled and replaced with a pedigree editor window, serialising the diagram as FHIR `Composition` JSON back into the field
