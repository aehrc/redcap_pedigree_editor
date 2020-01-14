# Pedigree Editor External Module

The pedigree editor external module allows a notes field to be marked with an anotation to indicate the field will represent a pedigree diagram.

The module will then hide or disable the notes field and instead spawn a new window to allow the entry of the pedigree diagram. The diagram will then be serialised as a FHIR Composition JSON string and written into the notes field. 
The pedigree editor used is [https://github.com/aehrc/open-pedigree](https://github.com/aehrc/open-pedigree) which is an open version of the phenotips pedigree editor.


## Installing the module

To install first you need to retrieve the distribution. This can be found on the releases page [https://github.com/aehrc/redcap_pedigree_editor/releases](https://github.com/aehrc/redcap_pedigree_editor/releases) page or 
the official redcap repository [https://redcap.vanderbilt.edu/consortium/modules/](https://redcap.vanderbilt.edu/consortium/modules/)

Alternatively you can clone the git repository and generate your own distribution file. To do this, clone the git repository into a working directory, then use the git archive command to build a release.
```
git clone https://github.com/aehrc/redcap_pedigree_editor.git
cd redcap_pedigree_editor
git archive --format=zip  --prefix=redcap_pedigree_editor_v0.1/ -o ../redcap_pedigree_editor_v0.1.zip HEAD
```

This will give you a file redcap_pedigree_editor_v0.1.zip

# Install the distribution

The distribution is installed by unzipping the distribution file into the `redcap/modules/` directory of the redcap installation.

This should result in the new directory `'redcap_pedigree_editor_v0.1'`. The external module directory name must meet a strict naming
convention, if the directory is missing the `'v'` before the version number then the module won't be picked up by redcap, so rename
the directory to match the form `'<module name>_v<version number>'`.

If everything has gone to plan the module should now appear in the list of available modules that can be enabled.

## Configuring the module

Once installed the module has a number of system wide options:

 - *Hide Text* - Flag to indicate if the text area associated with the note should be shown. The format used to store diagram will likely not make sense to anyone so this option should probably set to true.
 - *Ontology Server URL* - The URL for FHIR ontology server used to lookup disorders, phenotypes and genes. If left blank then the default *'https://genomics.ontoserver.csiro.au/fhir/'* will be used. There is a matching project setting, which allows a project to use a different ontology server.
 
![Configure](documentation/system_settings.png)

### Project Settings

Each project can override the *Ontology Server URL* setting. If left blank then the system setting will be used.

![Configure](documentation/project_settings.png)

## Creating a Pedigree field
To make use of the editor a field needs to be created in the online designer and marked with one of two action tags. Only fields of type `Notes Box` are considered.
  - *@PEDIGREE_HPO* - Marks a field to be a pedigree editor using the HPO and OMIM coding systems for phenotypes and disorders.
  - *@PEDIGREE_SCT* - Marks a field to be a pedigree editor using the SNOMED-CT coding system for phenotypes and disorders.

The default 'Hide Text' flag can be overriden in the action tag by appending
  - *=HIDE_TEXT* - Hide the text area.
  - *=SHOW_TEXT* - Show the text area.

An action tag of @PEDIGREE_HPO=HIDE_TEXT will use the HPO code system and hide the text area for the field.

![Online Designer](documentation/designer.png)


## Data Entry

In the data entry page, fields marked with the pedigree editor action tag will show a large image, if this is clicked a new window will open and allow the pedigree diagram to be editted. An empty field will appear as a single diamond, which an entry with some data will show a three element tree.

![Data Entry (empty)](documentation/data_entry_r_1.png)

![Panogram Editor](documentation/panogram.png)

![Data Entry (with data)](documentation/data_entry_r_2.png)

# Limitations

This module uses a FHIR based representation of the pedigree diagram. Unfortunately the specification is still being
developed, so it is still not possible to map all of the data captured by the pedigree editor. This means that any 
data with no mapping will be lost. Some other aspects of the mapping also do not translate directly, where possible
naming conventions are used to try and account for these. For example the FHIR FamilyHistory resource has a single
name field which is a string. The pedigree editor has a first name, last name and a last name at birth, this will
be written into the FamilyHistory resource as <first name> <last name> (<last name at birth>). When importing the FHIR
resource everything but the last word is the first name a trailing '(name)' will be taken as last name at birth.

Heredity options - This can be 'Childless' or 'Infertile' in the editor, but is not currently stored on the fhir resource.
Carrier Status - This can be 'Carrier' or 'Pre-symptomatic' in the editor, but is not currently stored on the fhir resource.
Evaluated - This is a checkbox in the editor, but is not currently stored on the fhir resource.
Lost Contact - This is a checkbox in the editor, but is not currently stored on the fhir resource. 
Multiple Sibling Nodes - In the editor you can create a single node to represent multiple siblings, at the moment these
will be saved as a single familiy history resource with no name and the number of siblings will be lost.

Phenotype and Candidate Genes are both stored as Observation Resources associated with a family history resource. The
system uses a naming convention to distinguish the two, otherwise it tries to match the code system to try and determine
if the observation represents a Phenotype or Candidate gene.

Life status - This can be 'unborn', 'stillborn' and 'aborted' with an associated gestation age. This will be written into
the deceasedString field on the family history resource in a form like 'stillborn 34 weeks'.