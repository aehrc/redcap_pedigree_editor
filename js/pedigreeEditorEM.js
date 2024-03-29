
//****************************************************************************************************
//
//****************************************************************************************************

var pedigreeEditorEM = pedigreeEditorEM || {};

pedigreeEditorEM.log = function() {
	// Make console logging more resilient to Redmond
	try {
		console.log.apply(this, arguments);
	} catch (err) {
		// Error trying to apply logs to console (problem with IE11)
		try {
			console.log(arguments);
		} catch (err2) {
			// Can't even do that!  Argh - no logging
			// var d = $('<div></div>').html(JSON.stringify(err)).appendTo($('body'));
		}
	}
};

pedigreeEditorEM.start = function() {
	$(function() {

		// pedigreeEditorEM.log(pedigreeEditorEM.fieldsOfInterest);
		// pedigreeEditorEM.log("length = " +
		// pedigreeEditorEM.fieldsOfInterest.length);

		for (var i = 0; i < pedigreeEditorEM.fieldsOfInterest.length; i++) {
			// pedigreeEditorEM.log("Render field for '" +
			// pedigreeEditorEM.fieldsOfInterest[i].field + "'");
			pedigreeEditorEM.render(pedigreeEditorEM.fieldsOfInterest[i]);
		}
		if (pedigreeEditorEM.transportType == 'local'){
			window.addEventListener('storage', pedigreeEditorEM.onStorageEvent, false);	
		}
		else {
			window.addEventListener('message', pedigreeEditorEM.onMessageEvent,	false);	
		}
		window.addEventListener('unload', function(event) {pedigreeEditorEM.clearDataToEditor()});
	});
};

pedigreeEditorEM.getPedigreeSVG = function(value) {
	var svg = $(pedigreeEditorEM.emptyIcon).html();
	if (value && value.length > 0){
		let foundSVG = false;

		try{
			var valueToParse = value;
			if (value.startsWith('GZ:')){
				valueToParse = pako.ungzip(atob(value.slice(3)),{ to: 'string' });
			}
			if (valueToParse.startsWith('{')){
				var FHIRdata = JSON.parse(valueToParse);
				if (FHIRdata) {
					if ("Composition" === FHIRdata.resourceType){
						if (FHIRdata.contained) {
							for (let containedResourceIndex=0; containedResourceIndex < FHIRdata.contained.length; containedResourceIndex++) {
								let containedResource = FHIRdata.contained[containedResourceIndex]
								if (containedResource.id === 'pedigreeImage') {
									foundSVG = true;
									svg = decodeURIComponent(escape(atob(containedResource.content.attachment.data)));
									svg = svg.replace(/width=".*?"/, 'width="auto"')
										.replace(/height=".*?"/, 'height="auto"');
									break;
								}
							}
						}
					}
					else if ("Bundle" === FHIRdata.resourceType){
						if (FHIRdata.entry) {
							for (let eIndex=0; eIndex < FHIRdata.entry.length; eIndex++) {
								let e = FHIRdata.entry[eIndex];
								let containedResource = e.resource;
								if ('DocumentReference' === containedResource.resourceType &&
									'Pedigree Diagram of Family in SVG format' === containedResource.description){
									foundSVG = true;
									svg = decodeURIComponent(escape(atob(containedResource.content.attachment.data)));
									svg = svg.replace(/width=".*?"/, 'width="auto"')
										.replace(/height=".*?"/, 'height="auto"');
									break;
								}
							}
						}
					}
				}
			}
			else if (valueToParse.startsWith('<')){
				const parser = new DOMParser();
				let doc = parser.parseFromString(valueToParse, "application/xml");
				let errorNode = doc.querySelector("parsererror");
				if (errorNode) {
					console.log('Unable to parse pedigree - ' + errorNode.innerHTML);
				}
				else {
					const imageNode = doc.querySelector('image');
					if (imageNode){
						foundSVG = true;
						svg = imageNode.innerHTML;
						svg = svg.replace(/width=".*?"/, 'width="auto"')
							.replace(/height=".*?"/, 'height="auto"');
					}
				}
			}
		}
		catch (e) {
			console.log(e);
		}
		if (!foundSVG){
			svg = $(pedigreeEditorEM.dataIcon).html();
		}
	}
	return svg;
};

pedigreeEditorEM.render = function(fieldData) {
	// Get TR Element
	var tr = $('tr[sq_id=' + fieldData.field + ']');
	//pedigreeEditorEM.log("tr field for '" + fieldData.field + "' - " + tr);

	// Get Label
	var label = $('td.labelrc:last', tr);
	//pedigreeEditorEM.log('label');pedigreeEditorEM.log($(label));

	// Get Data (not always present - depends on rendering options)
	var data = $('td.data', tr);

	var result;
	result = $('textarea[name="' + fieldData.field + '"]', tr);
	var haveData = false;
	if (result) {
		if (!pedigreeEditorEM.allowEdit){
			result.attr('readonly', true);
		}
		if (fieldData.hideText) {
			result.hide();
		}
		if (result.val().length > 0) {
			haveData = true;
		}
	}
	if (data) {
		var onClickText = "pedigreeEditorEM.edit('" + fieldData.field + "')";
		var imageId = fieldData.field + '_pedigreeEditorEM_icon';
		var svg = pedigreeEditorEM.getPedigreeSVG(result.val());
		$(data).prepend(
				'<a href="javascript:;" tabindex="-1" onclick="' + onClickText +'">'
						+ '<div id="' + imageId + '">'
						+ svg
						+ '</div></a>');
	}
}

pedigreeEditorEM.edit = function(field) {
	var fieldData;
	for (var i = 0; i < pedigreeEditorEM.fieldsOfInterest.length; i++) {
		if (pedigreeEditorEM.fieldsOfInterest[i].field == field) {
			fieldData = pedigreeEditorEM.fieldsOfInterest[i];
			break;
		}
	}
	if (!fieldData) {
		pedigreeEditorEM.log("Failed to find data for field '" + field + "'");
		return;
	}

	var tr = $('tr[sq_id=' + field + ']');

	var currentValue;
    currentValue = $('textarea[name="' + fieldData.field + '"]', tr).val();
    try {
		if (currentValue.startsWith('GZ:')){
			currentValue = pako.ungzip(atob(currentValue.slice(3)),{ to: 'string' });
		}
	}
	catch (e) {
		console.log('Error decompressing', e);
		currentValue=''
	}
	var data = {};
	data.context = {
		'field' : field
	};
	data.value = currentValue;
	pedigreeEditorEM.sendDataToEditor(data);
	pedigreeEditorEM.log("Opening editor window");
	var pageToOpen = (fieldData.mode == 'HPO') ? pedigreeEditorEM.hpoEditorPage :
		(fieldData.mode == 'CUSTOM') ? pedigreeEditorEM.customEditorPage : pedigreeEditorEM.sctEditorPage;
	pedigreeEditorEM.editorWindow = window.open(pageToOpen,
			pedigreeEditorEM.windowName);
	pedigreeEditorEM.editorWindow.focus();
	return true;
}

pedigreeEditorEM.save = function(field, value, svg) {
	var fieldData;

	for (var i = 0; i < pedigreeEditorEM.fieldsOfInterest.length; i++) {
		if (pedigreeEditorEM.fieldsOfInterest[i].field == field) {
			fieldData = pedigreeEditorEM.fieldsOfInterest[i];
			break;
		}
	}
	if (!fieldData) {
		pedigreeEditorEM.log("Failed to find data for field '" + field + "'");
		return;
	}
	var tr = $('tr[sq_id=' + field + ']');

	if (fieldData.compress === 'always'){
		let compressedValue = 'GZ:' + btoa(pako.gzip(value,{ to: 'string' }))
		if (compressedValue.length > 65300) {
			compressedValue = 'GZ:' + btoa(pako.gzip(pedigreeEditorEM.removeDiagramFromFhir(value),{ to: 'string' }));
			if (compressedValue.length > 65300) {
				alert('Pedigree Diagram is too large, even when compressed, not updating');
				window.focus();
				return;
			}
		}
		$('textarea[name="' + fieldData.field + '"]', tr).val(compressedValue);
	}
	else if (fieldData.compress === 'large'){
		if (value.length > 65300) {
			var compressedValue = 'GZ:' + btoa(pako.gzip(value,{ to: 'string' }))
			if (compressedValue.length > 65300) {
				compressedValue = pedigreeEditorEM.removeDiagramFromFhir(value);
				if (compressedValue.length > 65300) {
					compressedValue = 'GZ:' + btoa(pako.gzip(compressedValue,{ to: 'string' }));
					if (compressedValue.length > 65300) {
						alert('Pedigree Diagram is too large, even when compressed, not updating');
						window.focus();
						return;
					}
				}
			}
			$('textarea[name="' + fieldData.field + '"]', tr).val(compressedValue);
		}
		else {
			$('textarea[name="' + fieldData.field + '"]', tr).val(value);
		}
	}
	else {
		if (value.length > 65300) {
			var compressedValue = pedigreeEditorEM.removeDiagramFromFhir(value);
			if (compressedValue.length > 65300) {
				alert('Pedigree Diagram is too large, not updating');
				window.focus();
				return;
			}
			$('textarea[name="' + fieldData.field + '"]', tr).val(compressedValue);
		}
		else {
			$('textarea[name="' + fieldData.field + '"]', tr).val(value);
		}
	}

	var imageId = field + '_pedigreeEditorEM_icon';
	if (!svg){
		svg = pedigreeEditorEM.getPedigreeSVG(value);
	}
	else {
		svg = svg.replace(/width=".*?"/, 'width="auto"')
			.replace(/height=".*?"/, 'height="auto"');
	}
	$('#' + imageId).html(svg);

	window.focus();
}

pedigreeEditorEM.sendDataToEditor = function(data){
	if (pedigreeEditorEM.transportType == 'local'){
		// window.localStorage.setItem(pedigreeEditorEM.probandStorageKey, '');
		window.localStorage.setItem(pedigreeEditorEM.openPedigreeDataKey, JSON.stringify(data));
	}
	else {
		// doesn't get sent until get the ready signal
		pedigreeEditorEM.sendWhenReady = true;
		pedigreeEditorEM.messageData = data;
	}
};

pedigreeEditorEM.clearDataToEditor = function(){
	pedigreeEditorEM.log("Clearing transport data");
	if (pedigreeEditorEM.transportType == 'local'){
		// window.localStorage.setItem(pedigreeEditorEM.probandStorageKey, null);
		window.localStorage.setItem(pedigreeEditorEM.openPedigreeDataKey, null);
	}
	else {
		if (pedigreeEditorEM.editorWindow) {
			pedigreeEditorEM.editorWindow.postMessage({"messageType" : "openPedigree_data","openPedigreeData" : null}, pedigreeEditorEM.editorPageOrigin);
			// pedigreeEditorEM.editorWindow.postMessage({"messageType" : "openPedigree_proband","probandData" : null}, pedigreeEditorEM.editorPageOrigin);
		}
	}
};

pedigreeEditorEM.onMessageEvent = function(event) {

	if (event.source == pedigreeEditorEM.editorWindow
			&& event.origin == pedigreeEditorEM.editorPageOrigin) {
		// the message is from editor window
		if (event.data.messageType === "openPedigree_control") {
			if (event.data.message == "started"
					&& pedigreeEditorEM.sendWhenReady) {

				pedigreeEditorEM.log("Sending data to editor");

				// pedigreeEditorEM.editorWindow.postMessage({"messageType" : "openPedigree_proband", "probandData" : null}, pedigreeEditorEM.editorPageOrigin);
				pedigreeEditorEM.editorWindow.postMessage({"messageType" : "openPedigree_data", "openPedigreeData" : pedigreeEditorEM.messageData}, 
						pedigreeEditorEM.editorPageOrigin);
				pedigreeEditorEM.sendWhenReady = false;
			}
		} else if (event.data.messageType === "openPedigree_data") {
			pedigreeEditorEM.log("Got data from editor");
			if (event.data.openPedigreeData) {
				pedigreeEditorEM.save(event.data.openPedigreeData.context.field, event.data.openPedigreeData.value, event.data.openPedigreeData.svg);

			}
		}
	}
}

pedigreeEditorEM.onStorageEvent = function(storageEvent) {

	if (storageEvent.storageArea == window.localStorage && storageEvent.key == pedigreeEditorEM.openPedigreeDataKey){
		// the data has been updated
		var data = JSON.parse(storageEvent.newValue);
		
		if (data && data.context && data.context.field) {
			pedigreeEditorEM.log("Got data from editor");
			pedigreeEditorEM.save(data.context.field, data.value, data.svg);
		}
	}
}

pedigreeEditorEM.removeDiagramFromFhir = function(rawData) {
	if (rawData.startsWith('{')){
		// probably one of the json formats
		try {
			let fhir = JSON.parse(rawData);
			let foundImageSection = false;
			let foundImageResource = false;
			if ("Composition" === fhir.resourceType){
				if (fhir.section){
					let newSections = [];
					for (let sectionIndex =0; sectionIndex < fhir.section.length; sectionIndex++){
						let section = fhir.section[sectionIndex]
						if (section.title === 'Pedigree Diagram') {
							foundImageSection = true;
						}
						else {
							newSections.push(section);
						}
					}
					if (foundImageSection){
						fhir.section = newSections;
					}
				}
				if (fhir.contained) {
					let newContained = [];
					for (let containedResourceIndex = 0; containedResourceIndex < fhir.contained.length; containedResourceIndex++) {
						let containedResource = fhir.contained[containedResourceIndex]
						if (containedResource.id === 'pedigreeImage') {
							foundImageResource = true;
						}
						else {
							newContained.push(containedResource);
						}
					}
					if (foundImageResource){
						fhir.contained = newContained;
					}
				}
			} else if ("Bundle" === fhir.resourceType){
				let composition = fhir.entry[0].resource;
				let imageSection = null;
				let docRef = null;
				let newSections = [];
				for (let sectionIndex=0; sectionIndex < composition.section.length; sectionIndex++) {
					let section = composition.section[sectionIndex]
					if (section.title === 'Pedigree Diagram') {
						foundImageSection = true;
						imageSection = section;
						docRef = section.entry[0].reference;
					} else {
						newSections.push(section);
					}
				}
				if (foundImageSection){
					composition.section = newSections;
					// replace link to docreference
					let newLink = [];
					let foundLink = false;
					for (let lIndex=0; lIndex < fhir.entry[0].link.length; lIndex++){
						let l = fhir.entry[0].link[lIndex]
						if (l.url === docRef){
							foundLink = true;
						}
						else {
							newLink.push(l);
						}
					}
					if (foundLink){
						fhir.entry[0].link = newLink;
					}
					// replace doc reference entry
					let newEntry = [];
					let foundEntry = false;
					for (let eIndex=0; eIndex < fhir.entry.length; eIndex++){
						let e = fhir.entry[eIndex]
						if (e.fullUrl === docRef){
							foundEntry = true;
						}
						else {
							newEntry.push(e);
						}
					}
					if (foundEntry){
						fhir.entry = newEntry;
					}
				}
			}
			if (foundImageResource || foundImageSection){
				return JSON.stringify(fhir, null, 2);
			}
		}
		catch (e) {
			console.log("Failed to parse FHIR json", e);
		}
	}
	else if (rawData.startsWith('<')){
		// xml format
		const parser = new DOMParser();
		let doc = parser.parseFromString(rawData, "application/xml");
		let errorNode = doc.querySelector("parsererror");
		if (errorNode) {
			console.log('Unable to parse pedigree - ' + errorNode.innerHTML);
		}
		else {
			const imageNode = doc.querySelector('image');
			if (imageNode){
				imageNode.parentNode.removeChild(imageNode);
				return (new XMLSerializer().serializeToString(doc.documentElement));
			}
		}
	}
	return rawData;
}


$(document).ready(function() {
	pedigreeEditorEM.start();
});
