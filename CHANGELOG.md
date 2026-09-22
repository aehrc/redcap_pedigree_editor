# Changelog

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
