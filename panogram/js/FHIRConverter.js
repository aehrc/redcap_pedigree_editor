FHIRConverter = function() {
};

FHIRConverter.prototype = {};

/* ===============================================================================================
 *
 * Creates and returns a BaseGraph from a text string in the "FHIR JSON" format.
 *
 * We will support 2 different styles of fhir resource, a composition in the format used to export the
 * pedigree and a List of FamilyMemberHistory resources.
 * ===============================================================================================
 */

FHIRConverter.initFromFHIR = function(inputText) {
	try {
		var inputResource = JSON.parse(inputText);
	} catch (err) {
		throw "Unable to import pedigree: input is not a valid JSON string "
				+ err;
	}
	if (inputResource.resourceType === "Composition") {
		// first see if we have extension with raw data
		if (inputResource.extension) {
			var exArr = inputResource.extension;
			for (var i = 0; i < exArr.length; i++) {
				if (exArr[i].url === "https://github.com/aehrc/panogram/panogram-data-extension") {
					var jsonDataString = exArr[i].valueAttachment.data;
					var jsonData = decodeURIComponent(escape(window
							.atob(jsonDataString)));

					return PedigreeImport.initFromSimpleJSON(jsonData);
				}
			}
		}
	}
	if (inputResource.resourceType === "Composition"
			|| inputResource.resourceType === "List") {

		var containedResourcesLookup = {};
		var familyHistoryResources = [];
		if (inputResource.contained) {
			var containedArr = inputResource.contained;
			for (var i = 0; i < containedArr.length; i++) {
				containedResourcesLookup["#" + containedArr[i].id] = containedArr[i];
				if (containedArr[i].resourceType === "FamilyMemberHistory") {
					familyHistoryResources.push(containedArr[i]);
				}
			}
		}
		var subjectRef = inputResource.subject;
		var subjectResource = null;
		if (subjectRef && subjectRef.reference
				&& subjectRef.reference[0] == "#") {
			// we have a contained patient
			subjectResource = containedResourcesLookup[subjectRef.reference];
		}
		var newG = new BaseGraph();

		var nameToID = {};
		var externalIDToID = {};
		var ambiguousReferences = {};
		var hasID = {}

		var nodeData = [];
		// first pass: add all vertices and assign vertex IDs
		for (var i = 0; i < familyHistoryResources.length; i++) {
			var nextPerson = this.extractDataFromFMH(familyHistoryResources[i],
					subjectResource, containedResourcesLookup);
			nodeData.push(nextPerson);

			if (!nextPerson.properties.hasOwnProperty("id")
					&& !nextPerson.properties.hasOwnProperty("fName")
					&& !nextPerson.properties.hasOwnProperty("externalId")) {
				throw "Unable to import pedigree: a node with no ID or name is found";
			}

			var pedigreeID = newG._addVertex(null, TYPE.PERSON, {},
					newG.defaultPersonNodeWidth);

			if (nextPerson.properties.id) {
				if (externalIDToID.hasOwnProperty(nextPerson.properties.id)) {
					throw "Unable to import pedigree: multiple persons with the same ID ["
							+ nextPerson.properties.id + "]";
				}
				if (nameToID.hasOwnProperty(nextPerson.properties.id)
						&& nameToID[nextPerson.properties.id] != pedigreeID) {
					delete nameToID[nextPerson.properties.id];
					ambiguousReferences[nextPerson.properties.id] = true;
				} else {
					externalIDToID[nextPerson.properties.id] = pedigreeID;
					hasID[nextPerson.properties.id] = true;
				}
			}
			if (nextPerson.properties.fName) {
				if (nameToID.hasOwnProperty(nextPerson.properties.fName)
						&& nameToID[nextPerson.properties.fName] != pedigreeID) {
					// multiple nodes have this first name
					delete nameToID[nextPerson.properties.fName];
					ambiguousReferences[nextPerson.properties.fName] = true;
				} else if (externalIDToID
						.hasOwnProperty(nextPerson.properties.fName)
						&& externalIDToID[nextPerson.properties.fName] != pedigreeID) {
					// some other node has this name as an ID
					delete externalIDToID[nextPerson.properties.fName];
					ambiguousReferences[nextPerson.properties.fName] = true;
				} else {
					nameToID[nextPerson.properties.fName] = pedigreeID;
				}
			}
			// only use externalID if id is not present
			if (nextPerson.properties.hasOwnProperty("externalId")
					&& !hasID.hasOwnProperty(pedigreeID)) {
				externalIDToID[nextPerson.properties.externalId] = pedigreeID;
				hasID[pedigreeID] = true;
			}

			newG.properties[pedigreeID] = nextPerson.properties;
		}

		var getPersonID = function(person) {
			if (person.properties.hasOwnProperty("id"))
				return externalIDToID[person.properties.id];

			if (person.hasOwnProperty("fName"))
				return nameToID[person.properties.fName];
		};

		var findReferencedPerson = function(reference, refType) {
			if (ambiguousReferences.hasOwnProperty(reference))
				throw "Unable to import pedigree: ambiguous reference to ["
						+ reference + "]";

			if (externalIDToID.hasOwnProperty(reference))
				return externalIDToID[reference];

			if (nameToID.hasOwnProperty(reference))
				return nameToID[reference];

			throw "Unable to import pedigree: ["
					+ reference
					+ "] is not a valid "
					+ refType
					+ " reference (does not correspond to a name or an ID of another person)";
		};

		var defaultEdgeWeight = 1;

		var relationshipTracker = new RelationshipTracker(newG,
				defaultEdgeWeight);

		// second pass (once all vertex IDs are known): process parents/children & add edges
		for (var i = 0; i < nodeData.length; i++) {
			var nextPerson = nodeData[i];

			var personID = getPersonID(nextPerson);

			var motherLink = nextPerson.hasOwnProperty("mother") ? nextPerson["mother"]
					: null;
			var fatherLink = nextPerson.hasOwnProperty("father") ? nextPerson["father"]
					: null;

			if (motherLink == null && fatherLink == null)
				continue;

			// create a virtual parent in case one of the parents is missing       
			if (fatherLink == null) {
				var fatherID = newG._addVertex(null, TYPE.PERSON, {
					"gender" : "M",
					"comments" : "unknown"
				}, newG.defaultPersonNodeWidth);
			} else {
				var fatherID = findReferencedPerson(fatherLink, "father");
				if (newG.properties[fatherID].gender == "F")
					throw "Unable to import pedigree: a person declared as female is also declared as being a father ("
							+ fatherLink + ")";
			}
			if (motherLink == null) {
				var motherID = newG._addVertex(null, TYPE.PERSON, {
					"gender" : "F",
					"comments" : "unknown"
				}, newG.defaultPersonNodeWidth);
			} else {
				var motherID = findReferencedPerson(motherLink, "mother");
				if (newG.properties[motherID].gender == "M")
					throw "Unable to import pedigree: a person declared as male is also declared as being a mother ("
							+ motherLink + ")";
			}

			if (fatherID == personID || motherID == personID)
				throw "Unable to import pedigree: a person is declared to be his or hew own parent";

			// both motherID and fatherID are now given and represent valid existing nodes in the pedigree

			// if there is a relationship between motherID and fatherID the corresponding childhub is returned
			// if there is no relationship, a new one is created together with the chldhub
			var chhubID = relationshipTracker.createOrGetChildhub(motherID,
					fatherID);

			newG.addEdge(chhubID, personID, defaultEdgeWeight);
		}

		PedigreeImport.validateBaseGraph(newG);

		return newG;
	} else {

		throw "Unable to import pedigree: input is not a resource type we understand";
	}

};

FHIRConverter.extractDataFromFMH = function(familyHistoryResource,
		subjectResource, containedResourcesLookup) {
	var properties = [];
	var result = {
		"properties" : properties
	};

	properties.id = familyHistoryResource.id;
	properties.gender = "U";

	if (familyHistoryResource.sex) {
		var foundCode = false;
		if (familyHistoryResource.sex.coding) {
			var codings = familyHistoryResource.sex.coding;
			for (var i = 0; i < codings.length; i++) {
				if (codings[i].system === "http://hl7.org/fhir/administrative-gender") {
					foundCode = true;
					if (codings[i].code === "male") {
						properties.gender = "M";
					}
					if (codings[i].code === "female") {
						properties.gender = "F";
					}
					break;
				}
			}
		}
		if (!foundCode && familyHistoryResource.sex.text) {
			if (familyHistoryResource.sex.text.toLowerCase() == "male") {
				properties.gender = "M";
			} else if (familyHistoryResource.sex.text.toLowerCase() == "female") {
				properties.gender = "F";
			}
		}
	}
	if (familyHistoryResource.name) {
		// everything but the last word is the first name
		// a trailing '(name)' will be taken as last name at birth
		var nameSplitter = /^(.*?)( ([^ (]*)) ?(\(([^)]*)\))?$/;
		var split = nameSplitter.exec(familyHistoryResource.name);
		if (split == null) {
			properties.fName = familyHistoryResource.name;
		} else {
			properties.fName = split[1];
			properties.lName = split[3];
			if (split[5]) {
				properties.lNameAtB = split[5];
			}
		}
	}
	var dateSplitter = /([0-9]([0-9]([0-9][1-9]|[1-9]0)|[1-9]00)|[1-9]000)(-(0[1-9]|1[0-2])(-(0[1-9]|[1-2][0-9]|3[0-1]))?)?/;
	if (familyHistoryResource.bornDate) {
		var split = dateSplitter.exec(familyHistoryResource.bornDate);
		if (split == null) {
			// failed to parse the data
		} else {
			var year = split[1];
			var month = (split[5]) ? split[5] : "01";
			var day = (split[7]) ? split[7] : "01";
			properties.dob = day + "/" + month + "/" + year;
		}
	}
	if (familyHistoryResource.deceasedDate) {
		var split = dateSplitter.exec(familyHistoryResource.deceasedDate);
		if (split == null) {
			// failed to parse the data
		} else {
			var year = split[1];
			var month = (split[5]) ? split[5] : "01";
			var day = (split[7]) ? split[7] : "01";
			properties.dod = day + "/" + month + "/" + year;
		}
	}
	if (familyHistoryResource.note && familyHistoryResource.note[0].text) {
		properties.comments = familyHistoryResource.note[0].text;
	}
	if (familyHistoryResource.condition) {
		var disorders = [];
		var disorderSystem = LookupManager.getCodeSystem('disorder');//editor.getDisorderSystem();
		for (var i = 0; i < familyHistoryResource.condition.length; i++) {
			var condition = familyHistoryResource.condition[i].code;
			if (condition && condition.coding) {
				var foundSystem = false;
				for (var cIndex = 0; cIndex < condition.coding.length; cIndex++){
					var coding = condition.coding[cIndex];
					if (coding.system === disorderSystem){
						disorders.push(coding.code);
						foundSystem = true;
						break;
					}
				}
				if (!foundSystem){
					var firstCoding = condition.coding[0];
					if (firstCoding.display) {
						disorders.push(firstCoding.code);
						continue;
					}					
				} else {
					continue;
				}
			}
			if (condition && condition.text) {
				disorders.push(condition.text);
			}
		}
		properties.disorders = disorders;
	}

	if (familyHistoryResource.extension) {
		var motherCodes = [ "NMTH", "MTH", "STPMTH", "ADOPTM" ];
		var fatherCodes = [ "NFTH", "FTH", "STPFTH", "ADOPTF" ];
		var motherRegex = /mother/gi;
		var fatherRegex = /father/gi;
		var extensions = familyHistoryResource.extension;
		var possibleMother = [];
		var possibleFather = [];
		var possibleParent = [];
		for (var i = 0; i < extensions.length; i++) {
			var ex = extensions[i];
			if (ex.url === "http://hl7.org/fhir/StructureDefinition/family-member-history-genetics-parent") {
				var type = null;
				var ref = null;
				var subExtensions = ex.extension;
				for (var j = 0; j < subExtensions.length; j++) {
					var subEx = subExtensions[j];
					if (subEx.url === "type") {
						var codings = subEx.valueCodeableConcept.coding;
						for (var k = 0; k < codings.length; k++) {
							if (codings[k].system === "http://terminology.hl7.org/CodeSystem/v3-RoleCode") {
								if (motherCodes.includes(codings[k].code)) {
									type = "mother";
								} else if (fatherCodes
										.includes(codings[k].code)) {
									type = "father";
								} else {
									type = "parent";
								}
								break;
							} else if (codings[k].display) {
								if (motherRegex.test(codings[k].display)) {
									type = "mother";
								} else if (fatherRegex.test(codings[k].display)) {
									type = "father";
								}
							}
						}
						if (type == null && subEx.valueCodeableConcept.text) {
							if (motherRegex
									.test(subEx.valueCodeableConcept.text)) {
								type = "mother";
							} else if (fatherRegex
									.test(subEx.valueCodeableConcept.text)) {
								type = "father";
							}
						}
						if (type == null) {
							type = "parent";
						}
					} else if (subEx.url === "reference") {
						ref = subEx.valueReference.reference;
					}
				}
				if (ref == null) {
					// we didn't find the reference
					break;
				}
				if (type == null || type == "parent") {
					// check the reference entity for a gender
					if (containedResourcesLookup[ref]) {
						var parentResource = containedResourcesLookup[ref];
						if (parentResource.sex) {
							var foundCode = false;
							if (parentResource.sex.coding) {
								var codings = parentResource.sex.coding;
								for (var c = 0; c < codings.length; c++) {
									if (codings[c].system === "http://hl7.org/fhir/administrative-gender") {
										foundCode = true;
										if (codings[c].code === "male") {
											type = "father";
										}
										if (codings[c].code === "female") {
											type = "mother";
										}
										break;
									}
								}
							}
							if (!foundCode && parentResource.sex.text) {
								if (familyHistoryResource.sex.text
										.toLowerCase() == "male") {
									type = "father";
								} else if (familyHistoryResource.sex.text
										.toLowerCase() == "female") {
									type = "mother";
								}
							}
						}
					}
				}
				var parentId = ref.substring(1); // remove leading #
				if (type == "mother") {
					possibleMother.push(parentId);
				} else if (type == "father") {
					possibleFather.push(parentId);
				} else {
					possibleParent.push(parentId);
				}
			} else if (ex.url === "http://hl7.org/fhir/StructureDefinition/family-member-history-genetics-observation") {
				var observationRef = ex.valueReference.reference;
				var observationResource = containedResourcesLookup[observationRef];
				if (observationResource) {
					var clinical = "fmh_clinical";
					var genes = "fmh_genes"
					var isSympton = false;
					var isGene = false;
					var value = null;
					var hpoSystem = LookupManager.getCodeSystem('phenotype');
					var geneSystem = LookupManager.getCodeSystem('gene');
					if (observationResource.id.substring(0, clinical.length) == clinical) {
						isSympton = true;
					} else if (observationResource.id.substring(0, genes.length) == genes) {
						isGene = false;
					}
					if (observationResource.valueString){
						value = observationResource.valueString;
					}
					else if (observationResource.valueCodeableConcept){
						if (observationResource.valueCodeableConcept.coding){
							for (var cIndex = 0; cIndex < observationResource.valueCodeableConcept.coding.length; cIndex++){
								var coding = observationResource.valueCodeableConcept.coding[cIndex];
								if (coding.system === geneSystem){
									isGene = true;
									value = coding.code;
									break;
								}
								if (coding.system === hpoSystem){
									isSympton = true;
									value = coding.code;
									break;
								}
							}
						}
						if (value == null && observationResource.valueCodeableConcept.text){
							value = observationResource.valueCodeableConcept.text;
						}
					}
					if (value != null) {
						if (isSympton) {
							if (!properties.hpoTerms) {
								properties.hpoTerms = [];
							}
							properties.hpoTerms.push(value);
						} else if (isGene) {
							if (!properties.candidateGenes) {
								properties.candidateGenes = [];
							}
							properties.candidateGenes.push(value);
						}
					}
				}
			}

		}
		if (possibleMother.length == 1) {
			result.mother = possibleMother[0];
		}
		if (possibleFather.length == 1) {
			result.father = possibleFather[0];
		}
		if (!result.father && possibleMother.length > 1) {
			result.father = possibleMother[1];
		}
		if (!result.mother && possibleFather.length > 1) {
			result.mother = possibleFather[1];
		}
		if (possibleParent.length == 1) {
			if (!result.mother) {
				result.mother = possibleParent[0];
			} else if (!result.father) {
				result.father = possibleParent[0];
			}
		}
		if (possibleParent.length > 1) {
			if (!result.mother) {
				result.mother = possibleParent[1];
			} else if (!result.father) {
				result.father = possibleParent[1];
			}
		}
	}

	if (familyHistoryResource.relationship
			&& familyHistoryResource.relationship.coding
			&& familyHistoryResource.relationship.code === "ONESELF") {
		// this is the patient, use the subject resource if we have one
		if (subjectResource) {
			if (subjectResource.gender === "male") {
				properties.gender = "M";
			} else if (subjectResource.gender === "female") {
				properties.gender = "F";
			}
		}
		//@TODO add code to grab patient name from patient resource
	}

	return result;
};
// ===============================================================================================
/* ===============================================================================================
 *
 * Creates and returns a FHIR Composition representing the graph.
 *
 * ===============================================================================================
 */

FHIRConverter.exportAsFHIR = function(pedigree, privacySetting, fhirPatientReference) {
	// var exportObj = [];
	var today = new Date();
	var tz = today.getTimezoneOffset();
	var tzHours = tz / 60;
	var tzMins = Math.abs(tz - (tzHours * 60));
	var date = today.getFullYear() + '-' + ((today.getMonth() < 9) ? '0' : '' ) + (today.getMonth() + 1) + '-'
			+ ((today.getDate() < 10) ? '0' : '') + today.getDate();
	var time = ((today.getHours() < 10) ? '0' : '') + today.getHours() + ":" + ((today.getMinutes() < 10) ? '0' : '') + today.getMinutes() + ":"
			+ ((today.getSeconds() < 10) ? '0' : '') + today.getSeconds();
	var timezone = ((tzHours >= 0) ? '+' : '') + tzHours + ":"
			+ ((tzMins < 10) ? '0' : '') + tzMins;
	var dateTime = date + 'T' + time + timezone;

	var dataAsJson = btoa(unescape(encodeURIComponent(PedigreeExport
			.exportAsSimpleJSON(pedigree, privacySetting))));

	var pedigreeExtension = {
		"url" : "https://github.com/aehrc/panogram/panogram-data-extension",
		"valueAttachment" : {
			"contentType" : "application/json",
			"data" : dataAsJson
		}
	};

	var patientReference = {
		"type" : "Patient",
		"reference" : fhirPatientReference ? fhirPatientReference : "#pat"
	};

	var containedResources = [];

	var patientEntries = [];
	var patientSection = {
		"title" : "Patient Condition",
		"entry" : patientEntries
	};

	var familyHistoryEntries = [];
	var familyHistorySection = {
		"title" : "Family History",
		"code" : {
			"coding" : {
				"system" : "http://loinc.org",
				"code" : "10157-6",
				"display" : "History of family member diseases"
			}
		},
		"entry" : familyHistoryEntries
	};

	var fhr_json = {
		"resourceType" : "Composition",
		"status" : "preliminary",
		"type" : {
			"coding" : {
				"system" : "http://loinc.org",
				"code" : "11488-4",
				"display" : "Consult note"
			}
		},
		"subject" : patientReference,
		"date" : dateTime,
		"title" : "Pedigree Details",
		"extension" : [ pedigreeExtension ],
		"section" : [ patientSection, familyHistorySection ],
		"contained" : containedResources
	};

	
	if (!fhirPatientReference){
		var fhirPatient = this.buildFhirPatient("pat", pedigree.GG.properties[0],
				privacySetting);

		containedResources.push(fhirPatient);		
	}

	if (pedigree.GG.properties[0]['disorders']) {
		var disorders = pedigree.GG.properties[0]['disorders'];
		var disorderLegend = editor.getDisorderLegend();
		var disorderSystem = LookupManager.getCodeSystem('disorder');//editor.getDisorderSystem();
		
		for (var i = 0; i < disorders.length; i++) {
			var disorderTerm = disorderLegend.getDisorder(disorders[i]);
			if (disorderTerm.getName() === disorders[i]){
				// name and ID the same, must not be from omim
				var fhirCondition = {
						"resourceType" : "Condition",
						"id" : "cond_" + i,
						"subject" : patientReference,
						"code" : {
								"text" : disorders[i]
						}
					};
			}
			else {
				// disorder from omim
				var fhirCondition = {
						"resourceType" : "Condition",
						"id" : "cond_" + i,
						"subject" : patientReference,
						"code" : {
								"coding" : [
									{
										"system" : disorderSystem,
										"code" : disorders[i],
										"display" : disorderTerm.getName()
									}
								]
						}
					};
			}
			
			containedResources.push(fhirCondition);
			patientEntries.push({
				"type" : "Condition",
				"reference" : "#" + fhirCondition.id
			});
		}
	}

	var roleCache = [];
	roleCache[0] = "ONESELF";

	for (var i = 1; i <= pedigree.GG.getMaxRealVertexId(); i++) {
		if (!pedigree.GG.isPerson(i))
			continue;
		roleCache[i] = "";
	}
	this.fillRoleCache(roleCache, pedigree);

	for (var i = 0; i <= pedigree.GG.getMaxRealVertexId(); i++) {
		if (!pedigree.GG.isPerson(i))
			continue;

		var fmhResource = this.buildFhirFMH(i, pedigree, privacySetting,
				roleCache[i], patientReference);

		containedResources.push(fmhResource);

		familyHistoryEntries.push({
			"type" : "FamilyMemberHistory",
			"reference" : "#" + fmhResource.id
		});

		var nodeProperties = pedigree.GG.properties[i];
		var observations = [];
		if (nodeProperties['hpoTerms']) {
			var hpoTerms = nodeProperties['hpoTerms'];
			var hpoLegend = editor.getHPOLegend();
			var hpoSystem =  LookupManager.getCodeSystem('phenotype');

			for (var j = 0; j < hpoTerms.length; j++) {				
				var fhirObservation = {
					"resourceType" : "Observation",
					"id" : "fmh_clinical_" + i + "_" + j,
					"status" : "preliminary",
					
				};
				var hpoTerm = hpoLegend.getTerm(hpoTerms[j]);
				if (hpoTerm.getName() === hpoTerms[j]){
					fhirObservation["valueString"] = hpoTerms[j]
				}
				else {
					fhirObservation["valueCodeableConcept"] = { "coding" : [ { "system" : hpoSystem, "code" : hpoTerms[i], "display" : hpoTerm.getName() } ] };
				}
				if (i == 0) {
					// we are talking about the patient
					fhirObservation['subject'] = patientReference;
				} else {
					fhirObservation['focus'] = {
						"type" : "FamilyMemberHistory",
						"reference" : "#" + fmhResource.id
					};
				}
				observations.push(fhirObservation);
			}
		}

		if (nodeProperties['candidateGenes']) {
			var candidateGenes = nodeProperties['candidateGenes'];
			var geneLegend = editor.getGeneLegend();
			var geneSystem = LookupManager.getCodeSystem('gene');
			for (var j = 0; j < candidateGenes.length; j++) {
				// @TODO change to use http://build.fhir.org/ig/HL7/genomics-reporting/obs-region-studied.html
				var fhirObservation = {
					"resourceType" : "Observation",
					"id" : "fmh_genes_" + i + "_" + j,
					"status" : "preliminary",
				};
				var geneTerm = geneLegend.getTerm(candidateGenes[j]);
				if (geneTerm.getName() === candidateGenes[j]){
					fhirObservation["valueString"] = candidateGenes[j]
				}
				else {
					fhirObservation["valueCodeableConcept"] = { "coding" : [ { "system" : geneSystem, "code" : candidateGenes[i], "display" : geneTerm.getName() } ] };
				}
				if (i == 0) {
					// we are talking about the patient
					fhirObservation['subject'] = patientReference;
				} else {
					fhirObservation['focus'] = {
						"type" : "FamilyMemberHistory",
						"reference" : "#" + fmhResource.id
					};
				}
				observations.push(fhirObservation);
			}
		}

		if (observations.length > 0) {
			var ex = fmhResource['extension'];
			if (!ex) {
				ex = [];
				fmhResource['extension'] = ex;
			}
			for (var j = 0; j < observations.length; j++) {
				containedResources.push(observations[j]);
				var observationRef = {
					"type" : "Observation",
					"reference" : "#" + observations[j].id
				};

				ex
						.push({
							"url" : "http://hl7.org/fhir/StructureDefinition/family-member-history-genetics-observation",
							"valueReference" : observationRef
						});
				if (i == 0) {
					patientEntries.push(observationRef);
				} else {
					familyHistoryEntries.push(observationRef);
				}
			}
		}

	}

	return JSON.stringify(fhr_json);
};

FHIRConverter.fillRoleCache = function(roleCache, pedigree) {

	var isAdopted = pedigree.GG.isAdopted(0);
	var parents = pedigree.GG.getParents(0);

	var mother = pedigree.GG.getMother(0) || -1;
	var father = pedigree.GG.getFather(0) || -2;

	if (mother < 0 || father < 0) {
		// could be no gender

		if (parents.length > 0) {
			if (mother == parents[0]) {
				father = parents[1];
			} else if (mother == parents[1]) {
				father = parents[0];
			} else if (father == parents[0]) {
				mother = parents[1];
			} else if (father == parents[1]) {
				mother = parents[0];
			}
		}
	}
	if (mother > 0) {
		roleCache[mother] = (isAdopted) ? "ADOPTMTH" : "NMTH";
		this.fillParents(roleCache, pedigree, "M", "GR", mother);
	}
	if (father > 0) {
		roleCache[father] = (isAdopted) ? "ADOPTFTH" : "NFTH";
		this.fillParents(roleCache, pedigree, "F", "GR", father);
	}
	for (var i = 0; i < parents.length; i++) {
		if (roleCache[parents[i]] == "") {
			roleCache[parents[i]] = (isAdopted) ? "ADOPTPRN" : "NPRN";
			this.fillParents(roleCache, pedigree, "", "GR", parents[i]);
		}
	}
	// add partners and parents inlaw
	var partners = pedigree.GG.getAllPartners(0);
	for (var i = 0; i < partners.length; i++) {
		console.log("Setting " + partners[i] + " to SIGOTHR : partners = "
				+ partners);
		roleCache[partners[i]] = "SIGOTHR";
		var inlawParents = pedigree.GG.getParents(partners[i]);

		var inlawMother = pedigree.GG.getMother(partners[i]) || -1;
		var inlawFather = pedigree.GG.getFather(partners[i]) || -2;

		if (inlawMother < 0 || inlawFather < 0) {
			// could be no gender

			if (inlawParents.length > 0) {
				if (inlawMother == inlawParents[0]) {
					inlawFather = inlawParents[1];
				} else if (inlawMother == inlawParents[1]) {
					inlawFather = inlawParents[0];
				} else if (father == inlawParents[0]) {
					inlawMother = inlawParents[1];
				} else if (father == inlawParents[1]) {
					inlawMother = inlawParents[0];
				}
			}
		}
		if (inlawMother > 0) {
			roleCache[inlawMother] = "MTHINLAW";
		}
		if (inlawFather > 0) {
			roleCache[inlawFather] = "FTHINLAW";
		}
		for (var j = 0; j < inlawParents.length; i++) {
			if (roleCache[inlawParents[j]] == "") {
				roleCache[inlawParents[j]] = "PRNINLAW";
			}
		}
	}

	for (var i = 0; i < parents.length; i++) {
		this.fillStepParents(roleCache, pedigree, parents[i]);
	}

	var stillToProcess = [];
	var nextIteration = [];
	for (var i = 0; i <= pedigree.GG.getMaxRealVertexId(); i++) {
		if (!pedigree.GG.isPerson(i))
			continue;
		if (roleCache[i] == "") {
			stillToProcess.push(i);
		}
	}
	while (stillToProcess.length > 0) {
		var arrayLength = stillToProcess.length;
		for (var i = 0; i < arrayLength; i++) {
			if (!this.fillExtended(roleCache, pedigree, stillToProcess[i])) {
				nextIteration.push(i);
			}
		}
		if (arrayLength == nextIteration.length) {
			// nothing changed - need to stop
			break;
		}
		stillToProcess = nextIteration;
		nextIteration = [];
	}
};

FHIRConverter.fillParents = function(roleCache, pedigree, modifier, level, node) {
	var parents = pedigree.GG.getParents(node);

	if (parents.length == 0) {
		return;
	}

	var mother = pedigree.GG.getMother(node) || -1;
	var father = pedigree.GG.getFather(node) || -2;

	if (mother < 0 || father < 0) {
		// could be no gender

		if (parents.length > 0) {
			if (mother == parents[0]) {
				father = parents[1];
			} else if (mother == parents[1]) {
				father = parents[0];
			} else if (father == parents[0]) {
				mother = parents[1];
			} else if (father == parents[1]) {
				mother = parents[0];
			}
		}
	}
	if (mother > 0) {
		roleCache[mother] = modifier + level + "MTH";
		this.fillParents(roleCache, pedigree, modifier, "G" + level, mother);
	}
	if (father > 0) {
		roleCache[father] = modifier + level + "FTH";
		this.fillParents(roleCache, pedigree, modifier, "G" + level, father);
	}
	for (var i = 0; i < parents.length; i++) {
		if (roleCache[parents[i]] == "") {
			roleCache[parents[i]] = modifier + level + "PRN";
			this.fillParents(roleCache, pedigree, modifier, "G" + level,
					parents[i]);
		}
	}
};

FHIRConverter.fillStepParents = function(roleCache, pedigree, node) {

	var thisRole = roleCache[node];
	var genderSlice = thisRole.slice(-3);

	var partners = pedigree.GG.getAllPartners(node);

	if (partners.length <= 1) {
		return;
	}
	var roleToSet = "STPPRN";
	if (genderSlice === "MTH") {
		roleToSet = "STPFTH";
	} else if (genderSlice === "FTH") {
		roleToSet = "STPMTH";
	}

	for (var i = 0; i < partners.length; i++) {
		if (roleCache[partners[i]] == "") {
			roleCache[partners[i]] = roleToSet;
		}
	}
};

FHIRConverter.fillExtended = function(roleCache, pedigree, node) {
	if (roleCache[node] != "") {
		return true; // has a result;
	}
	console.log("Attempt to classify node - " + node + " - "
			+ pedigree.GG.properties[node]['comments']);
	var parents = pedigree.GG.getParents(node);

	if (parents.length == 0) {
		console.log("No parents, can't classify");
		return false; // this node must be a parent of someone else
	}

	var p1Role = roleCache[parents[0]];
	var p2Role = roleCache[parents[1]];
	if (p1Role == "" && p2Role == "") {
		console.log("Parents not classified, can't classify");
		return false;
	}

	var pGender = pedigree.GG.properties[0]['gender'];
	var vGender = pedigree.GG.properties[node]['gender'];

	var roleToSet = "";

	// check for children
	if (p1Role == "ONESELF" || p2Role == "ONESELF") {
		var isAdopted = pedigree.GG.isAdopted(node);
		switch (vGender) {
		case "F":
			roleToSet = (isAdopted) ? "DAUADOPT" : "DAU";
			break;
		case "M":
			roleToSet = (isAdopted) ? "SONADOPT" : "SON";
			break;
		default:
			roleToSet = (isAdopted) ? "CHLDADOPT" : "NCHILD";
		}
		roleCache[node] = roleToSet;
		console.log("Child of ONESELF set to " + roleToSet);

		// add inlaws
		var inlaws = pedigree.GG.getAllPartners(node);
		if (inlaws.length > 0) {
			switch (vGender) {
			case "F":
				roleToSet = "SONINLAW";
				break;
			case "M":
				roleToSet = "DAUINLAW";
				break;
			default:
				roleToSet = "CHLDINLAW";
				break;
			}
			for (var i = 0; i < inlaws.length; i++) {
				if (roleCache[inlaws[i]] == "") {
					roleCache[inlaws[i]] = roleToSet;
				}
			}
		}

		return true;
	}
	// check partners children
	if (p1Role == "SIGOTHR" || p2Role == "SIGOTHR") {
		var isAdopted = pedigree.GG.isAdopted(node);
		switch (vGender) {
		case "F":
			roleToSet = (isAdopted) ? "DAUADOPT" : "STPDAU";
			break;
		case "M":
			roleToSet = (isAdopted) ? "SONADOPT" : "STPSON";
			break;
		default:
			roleToSet = (isAdopted) ? "CHLDADOPT" : "STPCHLD";
		}
		roleCache[node] = roleToSet;
		console.log("Child of SIGOTHR set to " + roleToSet);

		// add inlaws
		var inlaws = pedigree.GG.getAllPartners(node);
		if (inlaws.length > 0) {
			switch (vGender) {
			case "F":
				roleToSet = "SONINLAW";
				break;
			case "M":
				roleToSet = "DAUINLAW";
				break;
			default:
				roleToSet = "CHLDINLAW";
				break;
			}
			for (var i = 0; i < inlaws.length; i++) {
				if (roleCache[inlaws[i]] == "") {
					roleCache[inlaws[i]] = roleToSet;
				}
			}
		}
		return true;
	}

	// check for siblings

	var nPrnCount = 0;
	if (p1Role == "NFTH" || p1Role == "NMTH" || p1Role == "NPRN") {
		nPrnCount++;
	}
	if (p2Role == "NFTH" || p2Role == "NMTH" || p2Role == "NPRN") {
		nPrnCount++;
	}

	if (nPrnCount == 2) {
		if (pedigree.GG.properties[0].hasOwnProperty('twinGroup')
				&& pedigree.GG.properties[node].hasOwnProperty('twinGroup')
				&& pedigree.GG.properties[0]['twinGroup'] == pedigree.GG.properties[node]['twinGroup']) {
			// appear to be twins
			if (pGender == "U" || vGender == "U" || vGender == pGender) {
				switch (vGender) {
				case "F":
					roleToSet = "TWINSIS";
					break;
				case "M":
					roleToSet = "TWINBRO";
					break;
				default:
					roleToSet = "TWIN";
					break;
				}
			} else { // genders are different
				switch (vGender) {
				case "F":
					roleToSet = "FTWINSIS";
					break;
				case "M":
					roleToSet = "FTWINBRO";
					break;
				default:
					// should never enter here
					roleToSet = "TWIN";
					break;
				}
			}
		} else {
			switch (vGender) {
			case "F":
				roleToSet = "NSIS";
				break;
			case "M":
				roleToSet = "NBRO";
				break;
			default:
				roleToSet = "NSIB";
				break;
			}
		}
		console.log("Parents are both NPRN set to " + roleToSet);
	} else if (nPrnCount == 1) {
		// one common natural parent
		switch (vGender) {
		case "F":
			roleToSet = "HSIS";
			break;
		case "M":
			roleToSet = "HBRO";
			break;
		default:
			roleToSet = "HSIB";
			break;
		}
		console.log("One Parent is  NPRN set to " + roleToSet);
	}

	if (roleToSet == "") {
		// check step siblings
		if (p1Role == "STPFTH" || p1Role == "STPMTH" || p1Role == "STPPRN"
				|| p2Role == "STPFTH" || p2Role == "STPMTH"
				|| p2Role == "STPPRN") {
			// child of step parent
			switch (vGender) {
			case "F":
				roleToSet = "STPSIS";
				break;
			case "M":
				roleToSet = "STPBRO";
				break;
			default:
				roleToSet = "STPSIB";
				break;
			}
			console.log("One Parent is  STPPRN set to " + roleToSet);
		}
	}

	if (roleToSet != "") {
		roleCache[node] = roleToSet;
		// add inlaws
		var inlaws = pedigree.GG.getAllPartners(node);
		if (inlaws.length > 0) {
			switch (vGender) {
			case "F":
				roleToSet = "BROINLAW";
				break;
			case "M":
				roleToSet = "SISINLAW";
				break;
			default:
				roleToSet = "SIBINLAW";
				break;
			}
			for (var i = 0; i < inlaws.length; i++) {
				if (roleCache[inlaws[i]] == "") {
					roleCache[inlaws[i]] = roleToSet;
				}
			}
		}
		return true;
	}

	// check children of children
	var childrenRoles = [ "DAUADOPT", "DAU", "SONADOPT", "SON", "CHLDADOPT",
			"NCHILD", "DAUADOPT", "STPDAU", "SONADOPT", "STPSON", "STPCHLD" ];
	if (childrenRoles.includes(p1Role) || childrenRoles.includes(p2Role)) {
		// parent is child
		switch (vGender) {
		case "F":
			roleToSet = "GRNDDAU";
			break;
		case "M":
			roleToSet = "GRNDSON";
			break;
		default:
			roleToSet = "GRNDCHILD";
			break;
		}
		roleCache[node] = roleToSet;
		console.log("One Parent is CHILD set to " + roleToSet);
		return true;
	}

	// check children of siblings
	var siblingRoles = [ "TWINSIS", "TWINBRO", "TWIN", "FTWINSIS", "FTWINBRO",
			"NSIS", "NBRO", "NSIB", "HSIS", "HBRO", "HSIB", "STPSIS", "STPBRO",
			"STPSIB" ];
	if (siblingRoles.includes(p1Role) || siblingRoles.includes(p2Role)) {
		// parent is sibling
		switch (vGender) {
		case "F":
			roleToSet = "NIECE";
			break;
		case "M":
			roleToSet = "NEPHEW";
			break;
		default:
			roleToSet = "NIENEPH";
			break;
		}
		roleCache[node] = roleToSet;
		console.log("One Parent is SIBLING set to " + roleToSet);
		return true;
	}

	// check children of grand children	
	var gcRegex = /(G)*GRND((DAU)|(SON)|(CHILD))/;
	var p1Match = gcRegex.exec(p1Role);
	var p2Match = gcRegex.exec(p2Role);

	var hasMatch = true;
	var depth = "";
	if (p1Match != null && p2Match != null) {
		var depth1 = p1Match[1] || "";
		var depth2 = p2Match[1] || "";
		if (depth1.length < depth2.length) {
			depth = depth1;
		} else {
			depth = depth2;
		}
	} else if (p1Match != null) {
		depth = p1Match[1] || "";
	} else if (p2Match != null) {
		depth = p2Match[1] || "";
	} else {
		hasMatch = false;
	}

	if (hasMatch) {
		// parent is grandchild
		switch (vGender) {
		case "F":
			roleToSet = depth + "GGRNDDAU";
			break;
		case "M":
			roleToSet = depth + "GGRNDSON";
			break;
		default:
			roleToSet = depth + "GGRNDCHILD";
			break;
		}
		roleCache[node] = roleToSet;
		console.log("One Parent is GRANDCHILD set to " + roleToSet);
		return true;
	}

	// check children of grand parents	
	var grRegex = /([MP])?(G)*GR(([FM]TH)|(PRN))/;
	p1Match = grRegex.exec(p1Role);
	p2Match = grRegex.exec(p2Role);

	var mOrP = "";
	var depth = "";
	var hasMatch = true;

	if (p1Match != null && p2Match != null) {
		var mOrP1 = p1Match[1] || "";
		var mOrP2 = p2Match[1] || "";
		if (mOrP1 == mOrP2) {
			mOrP = mOrP1;
		} else if (mOrP1 == "") {
			mOrP = mOrP2;
		} else if (mOrP2 == "") {
			mOrP = mOrP1;
		} else {
			mOrP = "";
		}
		var depth1 = p1Match[2] || "";
		var depth2 = p2Match[2] || "";
		if (depth1.length > depth2.length) {
			depth = depth1;
		} else {
			depth = depth2;
		}
	} else if (p1Match != null) {
		mOrP = p1Match[1] || "";
		depth = p1Match[2] || "";
	} else if (p2Match != null) {
		mOrP = p2Match[1] || "";
		depth = p2Match[2] || "";
	} else {
		hasMatch = false;
	}

	if (hasMatch) {
		// parent is grandparant
		switch (vGender) {
		case "F":
			roleToSet = mOrP + depth + "AUNT";
			break;
		case "M":
			roleToSet = mOrP + depth + "UNCLE";
			break;
		default:
			// there is no gender neutral word
			roleToSet = mOrP + depth + "PIBLING";
			break;
		}
		roleCache[node] = roleToSet;
		console.log("One Parent is GRANDPARENT set to " + roleToSet);
		return true;
	}

	// check children of PIBLINGS	
	var piblingRegex = /([MP])?(G)*((AUNT)|(UNCLE)|(PIBLING))/;
	p1Match = piblingRegex.exec(p1Role);
	p2Match = piblingRegex.exec(p2Role);

	mOrP = "";
	depth = "";
	hasMatch = true;

	if (p1Match != null && p2Match != null) {
		var mOrP1 = p1Match[1] || "";
		var mOrP2 = p2Match[1] || "";
		if (mOrP1 == mOrP2) {
			mOrP = mOrP1;
		} else if (mOrP1 == "") {
			mOrP = mOrP2;
		} else if (mOrP2 == "") {
			mOrP = mOrP1;
		} else {
			mOrP = "";
		}
		var depth1 = p1Match[2] || "";
		var depth2 = p2Match[2] || "";
		if (depth1.length > depth2.length) {
			depth = depth1;
		} else {
			depth = depth2;
		}
	} else if (p1Match != null) {
		mOrP = p1Match[1] || "";
		depth = p1Match[2] || "";
	} else if (p2Match != null) {
		mOrP = p2Match[1] || "";
		depth = p2Match[2] || "";
	} else {
		hasMatch = false;
	}

	if (hasMatch) {
		// parent is PIBLING(aunt or uncle)
		// cousins are gender neutral
		roleCache[node] = mOrP + "COUSN";
		console.log("One Parent is PIBLING set to " + roleToSet);
		return true;
	}

	// check children of niece/nephew

	var nnRegex = /(G)*((NIECE)|(NEPHEW)|(NIENEPH))/;
	p1Match = nnRegex.exec(p1Role);
	p2Match = nnRegex.exec(p2Role);

	hasMatch = true;
	depth = "";
	if (p1Match != null && p2Match != null) {
		var depth1 = p1Match[1] || "";
		var depth2 = p2Match[1] || "";
		if (depth1.length < depth2.length) {
			depth = depth1;
		} else {
			depth = depth2;
		}
	} else if (p1Match != null) {
		depth = p1Match[1] || "";
	} else if (p2Match != null) {
		depth = p2Match[1] || "";
	} else {
		hasMatch = false;
	}

	if (hasMatch) {
		// parent is grandchild
		switch (vGender) {
		case "F":
			roleToSet = depth + "GNIECE";
			break;
		case "M":
			roleToSet = depth + "GNEPHEW";
			break;
		default:
			roleToSet = depth + "GNIENEPH";
			break;
		}
		roleCache[node] = roleToSet;
		console.log("One Parent is NIECE/NEPHEW set to " + roleToSet);
		return true;
	}

	// check children of COUSINS	
	var cousinRegex = /([MP])?COUSN/;
	p1Match = cousinRegex.exec(p1Role);
	p2Match = cousinRegex.exec(p2Role);

	mOrP = "";
	hasMatch = true;

	if (p1Match != null && p2Match != null) {
		var mOrP1 = p1Match[1] || "";
		var mOrP2 = p2Match[1] || "";
		if (mOrP1 == mOrP2) {
			mOrP = mOrP1;
		} else if (mOrP1 == "") {
			mOrP = mOrP2;
		} else if (mOrP2 == "") {
			mOrP = mOrP1;
		} else {
			mOrP = "";
		}
	} else if (p1Match != null) {
		mOrP = p1Match[1] || "";
	} else if (p2Match != null) {
		mOrP = p2Match[1] || "";
	} else {
		hasMatch = false;
	}

	if (hasMatch) {
		// parent is COUSIN
		// cousins are gender neutral
		roleCache[node] = mOrP + "COUSN";
		console.log("One Parent is COUSIN set to " + mOrP + "COUSN");
		return true;
	}

	console.log("No match found - p1Role = " + p1Role + " p2Role = " + p2Role);
	return false;
};

FHIRConverter.familyHistoryLookup = {
	"notFound" : {
		"system" : "http://terminology.hl7.org/CodeSystem/v3-RoleCode",
		"code" : "EXT",
		"display" : "extended family member"
	},
	"ONESELF" : {
		"system" : "http://terminology.hl7.org/CodeSystem/v3-RoleCode",
		"code" : "ONESELF",
		"display" : "self"
	},
	"FAMMEMB" : {
		"system" : "http://terminology.hl7.org/CodeSystem/v3-RoleCode",
		"code" : "FAMMEMB",
		"display" : "family member"
	},
	"NMTH" : {
		"system" : "http://terminology.hl7.org/CodeSystem/v3-RoleCode",
		"code" : "NMTH",
		"display" : "natural mother"
	},
	"NFTH" : {
		"system" : "http://terminology.hl7.org/CodeSystem/v3-RoleCode",
		"code" : "NFTH",
		"display" : "natural father"
	},
	"NPRN" : {
		"system" : "http://terminology.hl7.org/CodeSystem/v3-RoleCode",
		"code" : "NPRN",
		"display" : "natural parent"
	},
	"ADOPTMTH" : {
		"system" : "http://terminology.hl7.org/CodeSystem/v3-RoleCode",
		"code" : "ADOPTM",
		"display" : "adoptive mother"
	},
	"ADOPTFTH" : {
		"system" : "http://terminology.hl7.org/CodeSystem/v3-RoleCode",
		"code" : "ADOPTF",
		"display" : "adoptive father"
	},
	"ADOMPTPRN" : {
		"system" : "http://terminology.hl7.org/CodeSystem/v3-RoleCode",
		"code" : "ADOPTP",
		"display" : "adoptive parent"
	},
	"DAU" : {
		"system" : "http://terminology.hl7.org/CodeSystem/v3-RoleCode",
		"code" : "DAU",
		"display" : "natural daughter"
	},
	"SON" : {
		"system" : "http://terminology.hl7.org/CodeSystem/v3-RoleCode",
		"code" : "SON",
		"display" : "natural son"
	},
	"NCHILD" : {
		"system" : "http://terminology.hl7.org/CodeSystem/v3-RoleCode",
		"code" : "NCHILD",
		"display" : "natural child"
	},
	"DAUADOPT" : {
		"system" : "http://terminology.hl7.org/CodeSystem/v3-RoleCode",
		"code" : "DAUADOPT",
		"display" : "adopted daughter"
	},
	"SONADOPT" : {
		"system" : "http://terminology.hl7.org/CodeSystem/v3-RoleCode",
		"code" : "SONADOPT",
		"display" : "adopted son"
	},
	"CHLDADOPT" : {
		"system" : "http://terminology.hl7.org/CodeSystem/v3-RoleCode",
		"code" : "CHLDADOPT",
		"display" : "adopted child"
	},
	"DAUINLAW" : {
		"system" : "http://terminology.hl7.org/CodeSystem/v3-RoleCode",
		"code" : "DAUINLAW",
		"display" : "daughter in-law"
	},
	"SONINLAW" : {
		"system" : "http://terminology.hl7.org/CodeSystem/v3-RoleCode",
		"code" : "SONINLAW",
		"display" : "son in-law"
	},
	"CHLDINLAW" : {
		"system" : "http://terminology.hl7.org/CodeSystem/v3-RoleCode",
		"code" : "CHLDINLAW",
		"display" : "child-in-law"
	},
	"SIGOTHR" : {
		"system" : "http://terminology.hl7.org/CodeSystem/v3-RoleCode",
		"code" : "SIGOTHR",
		"display" : "significant other"
	},
	"STPDAU" : {
		"system" : "http://terminology.hl7.org/CodeSystem/v3-RoleCode",
		"code" : "STPDAU",
		"display" : "stepdaughter"
	},
	"STPSON" : {
		"system" : "http://terminology.hl7.org/CodeSystem/v3-RoleCode",
		"code" : "STPSON",
		"display" : "stepson"
	},
	"STPCHLD" : {
		"system" : "http://terminology.hl7.org/CodeSystem/v3-RoleCode",
		"code" : "STPCHLD",
		"display" : "step child"
	},
	"TWINSIS" : {
		"system" : "http://terminology.hl7.org/CodeSystem/v3-RoleCode",
		"code" : "TWINSIS",
		"display" : "twin sister"
	},
	"TWINBRO" : {
		"system" : "http://terminology.hl7.org/CodeSystem/v3-RoleCode",
		"code" : "TWINBRO",
		"display" : "twin brother"
	},
	"TWIN" : {
		"system" : "http://terminology.hl7.org/CodeSystem/v3-RoleCode",
		"code" : "TWIN",
		"display" : "twin"
	},
	"FTWINSIS" : {
		"system" : "http://terminology.hl7.org/CodeSystem/v3-RoleCode",
		"code" : "FTWINSIS",
		"display" : "fraternal twin sister"
	},
	"FTWINBRO" : {
		"system" : "http://terminology.hl7.org/CodeSystem/v3-RoleCode",
		"code" : "FTWINBRO",
		"display" : "fraternal twin brother"
	},
	"NSIS" : {
		"system" : "http://terminology.hl7.org/CodeSystem/v3-RoleCode",
		"code" : "NSIS",
		"display" : "natural sister"
	},
	"NBRO" : {
		"system" : "http://terminology.hl7.org/CodeSystem/v3-RoleCode",
		"code" : "NBRO",
		"display" : "natural brother"
	},
	"NSIB" : {
		"system" : "http://terminology.hl7.org/CodeSystem/v3-RoleCode",
		"code" : "NSIB",
		"display" : "natural sibling"
	},
	"HSIS" : {
		"system" : "http://terminology.hl7.org/CodeSystem/v3-RoleCode",
		"code" : "HSIS",
		"display" : "half-sister"
	},
	"HBRO" : {
		"system" : "http://terminology.hl7.org/CodeSystem/v3-RoleCode",
		"code" : "HBRO",
		"display" : "half-brother"
	},
	"HSIB" : {
		"system" : "http://terminology.hl7.org/CodeSystem/v3-RoleCode",
		"code" : "HSIB",
		"display" : "half-sibling"
	},
	"BROINLAW" : {
		"system" : "http://terminology.hl7.org/CodeSystem/v3-RoleCode",
		"code" : "BROINLAW",
		"display" : "brother-in-law"
	},
	"SISINLAW" : {
		"system" : "http://terminology.hl7.org/CodeSystem/v3-RoleCode",
		"code" : "SISINLAW",
		"display" : "sister-in-law"
	},
	"SIBINLAW" : {
		"system" : "http://terminology.hl7.org/CodeSystem/v3-RoleCode",
		"code" : "SIBINLAW",
		"display" : "sibling in-law"
	},
	"GRNDDAU" : {
		"system" : "http://terminology.hl7.org/CodeSystem/v3-RoleCode",
		"code" : "GRNDDAU",
		"display" : "granddaughter"
	},
	"GRNDSON" : {
		"system" : "http://terminology.hl7.org/CodeSystem/v3-RoleCode",
		"code" : "GRNDSON",
		"display" : "grandson"
	},
	"GRNDCHILD" : {
		"system" : "http://terminology.hl7.org/CodeSystem/v3-RoleCode",
		"code" : "GRNDCHILD",
		"display" : "grandchild"
	},
	"NIECE" : {
		"system" : "http://terminology.hl7.org/CodeSystem/v3-RoleCode",
		"code" : "NIECE",
		"display" : "niece"
	},
	"NEPHEW" : {
		"system" : "http://terminology.hl7.org/CodeSystem/v3-RoleCode",
		"code" : "NEPHEW",
		"display" : "nephew"
	},
	"NIENEPH" : {
		"system" : "http://terminology.hl7.org/CodeSystem/v3-RoleCode",
		"code" : "NIENEPH",
		"display" : "niece/nephew"
	},
	"MCOUSN" : {
		"system" : "http://terminology.hl7.org/CodeSystem/v3-RoleCode",
		"code" : "MCOUSN",
		"display" : "maternal cousin"
	},
	"PCOUSN" : {
		"system" : "http://terminology.hl7.org/CodeSystem/v3-RoleCode",
		"code" : "PCOUSN",
		"display" : "paternal cousin"
	},
	"COUSN" : {
		"system" : "http://terminology.hl7.org/CodeSystem/v3-RoleCode",
		"code" : "COUSN",
		"display" : "cousin"
	},
	"MTHINLAW" : {
		"system" : "http://terminology.hl7.org/CodeSystem/v3-RoleCode",
		"code" : "MTHINLAW",
		"display" : "mother-in-law"
	},
	"FTHINLAW" : {
		"system" : "http://terminology.hl7.org/CodeSystem/v3-RoleCode",
		"code" : "FTHINLAW",
		"display" : "father-in-law"
	},
	"PRNINLAW" : {
		"system" : "http://terminology.hl7.org/CodeSystem/v3-RoleCode",
		"code" : "PRNINLAW",
		"display" : "parent in-law"
	},
	"MAUNT" : {
		"system" : "http://terminology.hl7.org/CodeSystem/v3-RoleCode",
		"code" : "MAUNT",
		"display" : "maternal aunt"
	},
	"PAUNT" : {
		"system" : "http://terminology.hl7.org/CodeSystem/v3-RoleCode",
		"code" : "PAUNT",
		"display" : "paternal aunt"
	},
	"AUNT" : {
		"system" : "http://terminology.hl7.org/CodeSystem/v3-RoleCode",
		"code" : "AUNT",
		"display" : "aunt"
	},
	"MUNCLE" : {
		"system" : "http://terminology.hl7.org/CodeSystem/v3-RoleCode",
		"code" : "MUNCLE",
		"display" : "maternal uncle"
	},
	"PUNCLE" : {
		"system" : "http://terminology.hl7.org/CodeSystem/v3-RoleCode",
		"code" : "PUNCLE",
		"display" : "paternal uncle"
	},
	"UNCLE" : {
		"system" : "http://terminology.hl7.org/CodeSystem/v3-RoleCode",
		"code" : "UNCLE",
		"display" : "uncle"
	},
	"GGRPRN" : {
		"system" : "http://terminology.hl7.org/CodeSystem/v3-RoleCode",
		"code" : "GGRPRN",
		"display" : "great grandparent"
	},
	"GGRFTH" : {
		"system" : "http://terminology.hl7.org/CodeSystem/v3-RoleCode",
		"code" : "GGRFTH",
		"display" : "great grandfather"
	},
	"MGGRFTH" : {
		"system" : "http://terminology.hl7.org/CodeSystem/v3-RoleCode",
		"code" : "MGGRFTH",
		"display" : "maternal great-grandfather"
	},
	"PGGRFTH" : {
		"system" : "http://terminology.hl7.org/CodeSystem/v3-RoleCode",
		"code" : "PGGRFTH",
		"display" : "paternal great-grandfather"
	},
	"GGRMTH" : {
		"system" : "http://terminology.hl7.org/CodeSystem/v3-RoleCode",
		"code" : "GGRMTH",
		"display" : "great grandmother"
	},
	"MGGRMTH" : {
		"system" : "http://terminology.hl7.org/CodeSystem/v3-RoleCode",
		"code" : "MGGRMTH",
		"display" : "maternal great-grandmother"
	},
	"PGGRMTH" : {
		"system" : "http://terminology.hl7.org/CodeSystem/v3-RoleCode",
		"code" : "PGGRMTH",
		"display" : "paternal great-grandmother"
	},
	"MGGRPRN" : {
		"system" : "http://terminology.hl7.org/CodeSystem/v3-RoleCode",
		"code" : "MGGRPRN",
		"display" : "maternal great-grandparent"
	},
	"PGGRPRN" : {
		"system" : "http://terminology.hl7.org/CodeSystem/v3-RoleCode",
		"code" : "PGGRPRN",
		"display" : "paternal great-grandparent"
	},
	"GRPRN" : {
		"system" : "http://terminology.hl7.org/CodeSystem/v3-RoleCode",
		"code" : "GRPRN",
		"display" : "grandparent"
	},
	"GRFTH" : {
		"system" : "http://terminology.hl7.org/CodeSystem/v3-RoleCode",
		"code" : "GRFTH",
		"display" : "grandfather"
	},
	"MGRFTH" : {
		"system" : "http://terminology.hl7.org/CodeSystem/v3-RoleCode",
		"code" : "MGRFTH",
		"display" : "maternal grandfather"
	},
	"PGRFTH" : {
		"system" : "http://terminology.hl7.org/CodeSystem/v3-RoleCode",
		"code" : "PGRFTH",
		"display" : "paternal grandfather"
	},
	"GRMTH" : {
		"system" : "http://terminology.hl7.org/CodeSystem/v3-RoleCode",
		"code" : "GRMTH",
		"display" : "grandmother"
	},
	"MGRMTH" : {
		"system" : "http://terminology.hl7.org/CodeSystem/v3-RoleCode",
		"code" : "MGRMTH",
		"display" : "maternal grandmother"
	},
	"PGRMTH" : {
		"system" : "http://terminology.hl7.org/CodeSystem/v3-RoleCode",
		"code" : "PGRMTH",
		"display" : "paternal grandmother"
	},
	"MGRPRN" : {
		"system" : "http://terminology.hl7.org/CodeSystem/v3-RoleCode",
		"code" : "MGRPRN",
		"display" : "maternal grandparent"
	},
	"PGRPRN" : {
		"system" : "http://terminology.hl7.org/CodeSystem/v3-RoleCode",
		"code" : "PGRPRN",
		"display" : "paternal grandparent"
	},
	"STPMTH" : {
		"system" : "http://terminology.hl7.org/CodeSystem/v3-RoleCode",
		"code" : "STPMTH",
		"display" : "stepmother"
	},
	"STPFTH" : {
		"system" : "http://terminology.hl7.org/CodeSystem/v3-RoleCode",
		"code" : "STPFTH",
		"display" : "stepfather"
	},
	"STPPRN" : {
		"system" : "http://terminology.hl7.org/CodeSystem/v3-RoleCode",
		"code" : "STPPRN",
		"display" : "step parent"
	},
	"STPSIS" : {
		"system" : "http://terminology.hl7.org/CodeSystem/v3-RoleCode",
		"code" : "STPSIS",
		"display" : "stepsister"
	},
	"STPBRO" : {
		"system" : "http://terminology.hl7.org/CodeSystem/v3-RoleCode",
		"code" : "STPBRO",
		"display" : "stepbrother"
	},
	"STPSIB" : {
		"system" : "http://terminology.hl7.org/CodeSystem/v3-RoleCode",
		"code" : "STPSIB",
		"display" : "step sibling"
	},
};

FHIRConverter.sexLookup = {
	"male" : {
		"system" : "http://hl7.org/fhir/administrative-gender",
		"code" : "male",
		"display" : "Male"
	},
	"female" : {
		"system" : "http://hl7.org/fhir/administrative-gender",
		"code" : "female",
		"display" : "Female"
	},
	"other" : {
		"system" : "http://hl7.org/fhir/administrative-gender",
		"code" : "other",
		"display" : "Other"
	},
	"unknown" : {
		"system" : "http://hl7.org/fhir/administrative-gender",
		"code" : "unknown",
		"display" : "Unknown"
	}
};

FHIRConverter.buildFhirPatient = function(containedId, properties,
		privacySetting) {

	var patientResource = {
		"id" : containedId,
		"resourceType" : "Patient",
	};
	if (properties.gender) {
		if (properties.gender == "M") {
			patientResource.gender = "male";
		} else if (properties.gender == "F") {
			patientResource.gender = "female";
		} else {
			patientResource.gender = "unknown";
		}
	}
	if (properties.twinGroup) {
		patientResource.multipleBirthBoolean = true;
	}
	if (properties.dod && privacySetting != "all") {
		patientResource.deceasedBoolean = true;
	}
	if (privacySetting == "all") {

		if (properties.dob) {
			var d = new Date(properties.dob);
			patientResource.birthDate = d.getFullYear() + '-'
					+ (d.getMonth() + 1) + '-' + d.getDate();
		}
		if (properties.dod) {
			var d = new Date(properties.dod);
			patientResource.deceasedDateTime = d.getFullYear() + '-'
					+ (d.getMonth() + 1) + '-' + d.getDate();
		}
		if (property.lName || property.fName || property.lNameAtB) {
			patientResource.name = [];
			if (property.lName || property.fName) {
				var name = {};
				if (property.lName) {
					name.family = property.lName;
				}
				if (property.fName) {
					name.given = [ property.fName ];
				}
				patientResource.Name.push(name);
			}
			if (property.lNameAtB) {
				var name = {
					"use" : "old",
					"family" : property.lNameAtB
				};
				patientResource.Name.push(name);
			}
		}
	}
	return patientResource;
};

FHIRConverter.buildGeneticsParentExtension = function(index, relationship) {

	var fullRelationship = FHIRConverter.familyHistoryLookup[relationship];
	var ref = "#FMH_" + index;

	return {
		"url" : "http://hl7.org/fhir/StructureDefinition/family-member-history-genetics-parent",
		"extension" : [ {
			"url" : "type",
			"valueCodeableConcept" : {
				"coding" : [ fullRelationship ]
			}
		}, {
			"url" : "reference",
			"valueReference" : {
				"reference" : ref
			}
		} ]
	};

};

FHIRConverter.buildFhirFMH = function(index, pedigree, privacySetting,
		relationship, patientRef) {

	var ref = "FMH_" + index;
	var nodeProperties = pedigree.GG.properties[index];

	var extensions = [];

	var isAdopted = pedigree.GG.isAdopted(index);
	var parents = pedigree.GG.getParents(index);

	var mother = pedigree.GG.getMother(index) || -1;
	var father = pedigree.GG.getFather(index) || -2;

	if (mother < 0 || father < 0) {
		// could be no gender

		if (parents.length > 0) {
			if (mother == parents[0]) {
				father = parents[1];
			} else if (mother == parents[1]) {
				father = parents[0];
			} else if (father == parents[0]) {
				mother = parents[1];
			} else if (father == parents[1]) {
				mother = parents[0];
			}
		}
	}
	for (var i = 0; i < parents.length; i++) {
		if (parents[i] == mother) {
			extensions.push(this.buildGeneticsParentExtension(parents[i],
					(isAdopted) ? "ADOPTMTH" : "NMTH"))
		} else if (parents[i] == father) {
			extensions.push(this.buildGeneticsParentExtension(parents[i],
					(isAdopted) ? "ADOPTFTH" : "NFTH"))
		} else {
			extensions.push(this.buildGeneticsParentExtension(parents[i],
					(isAdopted) ? "ADOPTPRN" : "NPRN"))

		}
	}
	var fullRelationship = FHIRConverter.familyHistoryLookup[relationship];
	if (!fullRelationship) {
		if (relationship) {
			fullRelationship = FHIRConverter.familyHistoryLookup["notFound"];
		} else {
			fullRelationship = FHIRConverter.familyHistoryLookup["FAMMEMB"];
		}
	}
	var name = "Family member " + index;
	if (privacySetting == "all") {
		var lname = nodeProperties['lName'] || "";
		var fname = nodeProperties['fName'] || "";
		if (lname && fname) {
			name = lname + ", " + fname;
		}
		if (nodeProperties['lNameAtB'] && nodeProperties['lNameAtB'] != lname) {
			name = name + " (" + nodeProperties['lNameAtB'] + ")";
		}
	}
	var sexCode = "unknown";
	if (nodeProperties['gender'] === "F") {
		sexCode = "female";
	}
	if (nodeProperties['gender'] === "M") {
		sexCode = "male";
	}

	var fmhResource = {
		"resourceType" : "FamilyMemberHistory",
		"id" : ref,
		"status" : "completed",
		"patient" : patientRef,
		"name" : name,
		"sex" : {
			"coding" : [ this.sexLookup[sexCode] ]
		},
		"relationship" : {
			"coding" : [ fullRelationship ]
		}
	};

	if (extensions.length > 0) {
		fmhResource['extension'] = extensions;
	}
	if (privacySetting == "all") {
		if (nodeProperties['dob']) {
			var d = new Date(nodeProperties['dob']);
			fmhResource['bornDate'] = d.getFullYear() + '-'
					+ (d.getMonth() + 1) + '-' + d.getDate();
		}
		if (nodeProperties['dod']) {
			var d = new Date(nodeProperties['dod']);
			fmhResource['deceasedDate'] = d.getFullYear() + '-'
					+ (d.getMonth() + 1) + '-' + d.getDate();
		}
	}
	if (privacySetting != "minimal" && nodeProperties['comments']) {
		fmhResource['note'] = [ {
			"text" : nodeProperties['comments']
		} ];
	}
	if (nodeProperties['disorders']) {
		var disorders = nodeProperties['disorders'];
		var conditions = [];
		var disorderLegend = editor.getDisorderLegend();
		var disorderSystem = LookupManager.getCodeSystem('disorder');//editor.getDisorderSystem();
			
		for (var i = 0; i < disorders.length; i++) {
			var disorderTerm = disorderLegend.getDisorder(disorders[i]);
			if (disorderTerm.getName() === disorders[i]){
				// name and ID the same, must not be from omim
				conditions.push({
					"code" : {
						"text" : disorders[i]
					}
				});
			} else {
				conditions.push({
					"code" : {
						"coding" : [
							{
								"system" : disorderSystem,
								"code" : disorders[i],
								"display" : disorderTerm.getName()
							}
						]
					}
				});
			}			
		}
		fmhResource['condition'] = conditions;
	}
	return fmhResource;

};

//===============================================================================================

