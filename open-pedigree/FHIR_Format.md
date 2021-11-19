#FHIR format

This is a breakdown of how the data is currently being stored, when saved as FHIR.

The FHIR format uses a Composition as a container for the data.
https://www.hl7.org/fhir/composition.html


The composition contains 2-3 sections:
- Patient Condition - Contains information on the proband
- Family History - Contains information on family members
- Pedigree Diagram (may not be present) - If present will contain the pedigree diagram

The base composition will look like:
```json
{
  "resourceType" : "Composition", 
  "status" : "preliminary", 
  "type" : {
      "coding" : {
        "system" : "http://loinc.org", 
        "code" : "11488-4",
        "display" : "Consult note"
      }
    }, 
  "subject" : {
      "type": "Patient",
      "reference": "#pat"
    },
  "date" : "2019-11-11T11:44:25-10:00",
  "title" : "Pedigree Details",	  
  "section" : [
          {
            "title": "Patient Condition",
            "entry": [
              {
                "type": "Condition",
                "reference": "#cond_0"
              },
              {
                "type": "Observation",
                "reference": "#fmh_clinical_0_0"
              }
            ]
          },
          {
            "title": "Family History",
            "code": {
              "coding": {
                "system": "http://loinc.org",
                "code": "10157-6",
                "display": "History of family member diseases"
              }
            },
            "entry": [
              {
                "type": "FamilyMemberHistory",
                "reference": "#FMH_0"
              },
              {
                "type": "FamilyMemberHistory",
                "reference": "#FMH_1"
              },
              {
                "type": "FamilyMemberHistory",
                "reference": "#FMH_2"
              }
            ]
          },
          {
            "title" : "Pedigree Diagram",
            "entry" : [{
              "type" : "DocumentReference",
              "reference" : "#pedigreeImage"
            }]
          }
        ], 
  "contained" : [
          //the resources
  ]
}
```

Within the pedigree editor each node contains the following data.
- parents - parent nodes - Are encoded in the fmh resource using an extension.
- partners - nodes who are/were a partner of the person
- twins - siblings associated with same birth
- monozygotic - a flag to indicate twin type   
- disorders - Set of disorders associated with the person
- hpo terms - Set of phenotypes associated with the person
- candidate genes - Set of genes associated with the person
- carrier status - flag to indicate if the person is a carrier of the disorder
- childless status - flag to indicate if the person is childless
- Last name
- First name
- Last name at birth
- gender
- Date of Birth
- Date of Death
- life status - This can be 'unborn', 'stillborn' and 'aborted'.
- gestation age - Used with life status to indicate gestation age for the status 
- comments
- external ID

Each node is converted into a Family Member History resource, including the proband.
https://www.hl7.org/FHIR/familymemberhistory.html

Three different extensions are used to add data to the family member history resource
- http://hl7.org/fhir/StructureDefinition/family-member-history-genetics-parent - This extension is used to
  indicate the family members whom are the direct parents of this family member. The standard relationship field
  in family member history is how the person is related to the patient, not how other family members relate to
  each other.
- http://hl7.org/fhir/StructureDefinition/family-member-history-genetics-sibling - This extension is used to
  indicate the family member who is a sibling of this family member. This is used primarily to indicate twins or
  similar same birth siblings. 
- http://hl7.org/fhir/StructureDefinition/family-member-history-genetics-observation - Links an observation
  resource to this family member. Normally in FHIR observations relate to the patient.

Disorders are encoded as a condition in the family member history record, which is not a separate resource. The
probands disorders will also have an associated Condition resource added (in the Patient Condition section).

Phenotypes and Genes are encoded as Observation resources. 

Here is an example family member history resource
```json
{
      "resourceType": "FamilyMemberHistory",
      "id": "FMH_0",
      "status": "completed",
      "patient": {
        "type": "Patient",
        "reference": "#pat"
      },
      "name": "Jane Smith (Cooper)",
      "sex": {
        "coding": [
          {
            "system": "http://hl7.org/fhir/administrative-gender",
            "code": "female",
            "display": "Female"
          }
        ]
      },
      "relationship": {
        "coding": [
          {
            "system": "http://terminology.hl7.org/CodeSystem/v3-RoleCode",
            "code": "ONESELF",
            "display": "self"
          }
        ]
      },
      "extension": [
        {
          "url": "http://hl7.org/fhir/StructureDefinition/family-member-history-genetics-parent",
          "extension": [
            {
              "url": "type",
              "valueCodeableConcept": {
                "coding": [
                  {
                    "system": "http://terminology.hl7.org/CodeSystem/v3-RoleCode",
                    "code": "NMTH",
                    "display": "natural mother"
                  }
                ]
              }
            },
            {
              "url": "reference",
              "valueReference": {
                "reference": "#FMH_1"
              }
            }
          ]
        },
        {
          "url": "http://hl7.org/fhir/StructureDefinition/family-member-history-genetics-parent",
          "extension": [
            {
              "url": "type",
              "valueCodeableConcept": {
                "coding": [
                  {
                    "system": "http://terminology.hl7.org/CodeSystem/v3-RoleCode",
                    "code": "NFTH",
                    "display": "natural father"
                  }
                ]
              }
            },
            {
              "url": "reference",
              "valueReference": {
                "reference": "#FMH_2"
              }
            }
          ]
        },
        {
          "url": "http://hl7.org/fhir/StructureDefinition/family-member-history-genetics-observation",
          "valueReference": {
            "type": "Observation",
            "reference": "#fmh_clinical_0_0"
          }
        }
      ],
      "bornDate": "1970-02-10",
      "note": [
        {
          "text": "This is the comment"
        }
      ],
      "condition": [
        {
          "code": {
            "coding": [
              {
                "system": "http://snomed.info/sct",
                "code": "59494005",
                "display": "Congenital septal defect of heart"
              }
            ]
          }
        }
      ]
    }
```

