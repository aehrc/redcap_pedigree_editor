<p align="center">
  <img src="https://repository-images.githubusercontent.com/212736090/2759df80-fe9e-11e9-8fa0-8237e35cbaf7" width="400px" alt="Open Pedigree logo"/>
</p>

<p align="center">
  <a href="https://opensource.org/licenses/LGPL-2.1" target="_blank">
    <img src="https://img.shields.io/badge/license-LGPL--2.1-blue.svg" alt="LGPL-2.1">
  </a>
  <img src="https://img.shields.io/badge/made%20in-canada-red.svg" alt="Made with love">
</p>


## A free and open-source pedigree tool powered by PhenoTips

Open Pedigree is a robust browser-based genomic pedigree drawing solution using [Prototype](prototypejs.org), [Raphaël](https://dmitrybaranovskiy.github.io/raphael/), and [PhenoTips](https://phenotips.org).

<img width="983" alt="image" src="https://user-images.githubusercontent.com/4251264/68103796-e1048080-fe9d-11e9-9353-6b491aae588d.png">


## Features

✔ Robust support for complext families, intergenerational linkages, and consanguinity

✔ Shade nodes with disorders and/or candidate genes

✔ Quickly start with family templates

✔ Automatic consanguinity detection

✔ Import from PED, LINKAGE, GEDCOM (Cyrillic), or BOADICEA


## Getting started

Quickly get started with open pedigree on your computer:
```
git clone git@github.com:phenotips/open-pedigree.git
cd open-pedigree
npm install
npm start
```
Open a browser to http://localhost:9000/



## Contributing

Contributions welcome! Fork the repository and create a pull request to share your improvements with the community.

In order to ensure that the licensing is clear and stays open, you'll be asked to sign a CLA with your first pull request.


## Support

This is free software! Create an issue in GitHub to ask others for help, or try fixing the issue yourself and then make a pull request to contribute it back to the core.

For commercial support or for information about the paid Enterprise version, please contact [Gene42](https://gene42.com/).


## License

Copyright (c) 2019 Gene42 Inc.

Open Pedigree is distributed under the [LGPL-2.1](https://opensource.org/licenses/LGPL-2.1) (GNU Lesser General Public License).

You can easily comply with this license by:
* including prominent notice of the use of Open Pedigree in your software that uses it
* retaining all copyright notices in the software
* ensuring that any and all changes you make to the software are disclosed and open-sourced under the LGPL


# FHIR export/import

The FHIR import/export does not have an actual specification to follow. There is a specification in development, though
we have deviated from it, choosing to use a Composition so that the family history resources and observations can be
bundled together, rather then the List of family history resources in the current specification. Not all data gathered
by the editor has a mapping in the FHIR representation. This means that any  data with no mapping will be lost. 
Some other aspects of the mapping also do not translate directly, where possible
naming conventions are used to try and account for these. For example the FHIR FamilyHistory resource has a single
name field which is a string. The pedigree editor has a first name, last name and a last name at birth, this will
be written into the FamilyHistory resource as <first name> <last name> (<last name at birth>). When importing the FHIR
resource everything but the last word is the first name and a trailing name in brackets will be taken as last name at birth.

- Heredity options - This can be 'Childless' or 'Infertile' in the editor, this is added as an Observation resource with
a predefined name. If the name doesn't match, then the field won't be populated.
- Carrier Status - This can be 'Carrier' or 'Pre-symptomatic' in the editor, this is added as an Observation resource with
a predefined name. If the name doesn't match, then the field won't be populated.
- Evaluated - This is a checkbox in the editor, but is not currently stored on the fhir resource.
- Lost Contact - This is a checkbox in the editor, but is not currently stored on the fhir resource. 
- Multiple Sibling Nodes - In the editor you can create a single node to represent multiple siblings, at the moment these
will be saved as a single familiy history resource with no name and the number of siblings will be lost.

- Phenotype and Candidate Genes are both stored as Observation Resources associated with a family history resource. The
system uses a naming convention to distinguish the two, otherwise it tries to match the code system to try and determine
if the observation represents a Phenotype or Candidate gene.

- Life status - This can be 'unborn', 'stillborn' and 'aborted' with an associated gestation age. This will be written into
the deceasedString field on the family history resource in a form like 'stillborn 34 weeks'.

## Pedigree Diagram
The fhir format now includes a DocumentReference resource which contains an attachement
of an svg representation of the pedigree diagram.
