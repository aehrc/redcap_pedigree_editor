
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
		// The pedigree goes straight between this form and the editor window it opens
		// (pedigree-editor-window-transport); earlier versions passed it through
		// localStorage, so remove anything they left behind.
		try {
			window.localStorage.removeItem('pedigreeData');
		} catch (e) { /* storage blocked: nothing of ours can be in it */ }
		// One editor window per form page: Edit again here reuses it, but an editor
		// opened from another tab never does.
		pedigreeEditorEM.windowName = 'pedigreeEditor_' + pedigreeEditorEM.randomId();
		window.addEventListener('message', pedigreeEditorEM.onMessage, false);
	});
};

// The icon markup lives in <template> elements (see
// pedigree-editor-inline-js-extraction), not <script type="text/plain">: a
// <template>'s content is parsed into its own .content DocumentFragment
// rather than a normal childNode, so it's read via that instead of jQuery's
// .html() (which would see an empty element).
pedigreeEditorEM.getIconSVG = function(selector) {
	return document.querySelector(selector).content.firstElementChild.outerHTML;
};

// Strips event-handler attributes (onload/onerror/onclick/...) and
// javascript: URIs, and drops <script>/<foreignObject> elements entirely,
// from a parsed SVG element (in place). getPedigreeSVG()'s svg markup can
// originate from a record's own stored/imported field value (decoded from a
// GA4GH FHIR Composition/Bundle), not just this file's own trusted <template>
// icons - inserting it as raw HTML (jQuery .html()/.append() with a string)
// would let an SVG root's own onload attribute (a well-known SVG XSS vector)
// fire as soon as it's inserted, same as innerHTML. Parsing + stripping +
// inserting as real DOM nodes closes that off.
//
// Returns null (rather than the disallowed node) if the root element itself
// is a <script>/<foreignObject> or isn't an <svg> at all - the walker below
// only removes descendants from their parent, which doesn't stop the root
// itself from still being returned and imported/appended by the caller.
pedigreeEditorEM.sanitizeSvgElement = function(svgEl) {
	if (!/^svg$/i.test(svgEl.tagName)) {
		return null;
	}
	var walker = document.createTreeWalker(svgEl, NodeFilter.SHOW_ELEMENT);
	var toRemove = [];
	var node = svgEl;
	do {
		if (/^(script|foreignObject)$/i.test(node.tagName)) {
			toRemove.push(node);
			continue;
		}
		Array.prototype.slice.call(node.attributes || []).forEach(function(attr) {
			var name = attr.name.toLowerCase();
			var isEventHandler = name.indexOf('on') === 0;
			// localName (not attr.name/a fixed "xlink:href" check) so this
			// still matches when the markup binds the XLink namespace to a
			// prefix other than "xlink" (e.g. xmlns:foo="...1999/xlink"
			// foo:href="javascript:...") - qualified-name matching alone
			// missed that.
			var localName = (attr.localName || attr.name).toLowerCase();
			// Browsers strip ASCII tab/newline/CR from a URL before parsing its
			// scheme (WHATWG URL spec), so a scheme check must do the same or a
			// value like "jav\tascript:..." would slip past a literal match.
			var strippedValue = attr.value.replace(/[\t\r\n]/g, '');
			var isJsUri = localName === 'href' && /^\s*javascript:/i.test(strippedValue);
			if (isEventHandler || isJsUri) {
				node.removeAttribute(attr.name);
			}
		});
	} while ((node = walker.nextNode()));
	toRemove.forEach(function(n) {
		if (n.parentNode) {
			n.parentNode.removeChild(n);
		}
	});
	return svgEl;
};

// Safely replaces containerEl's content with the given SVG markup: parses it
// (rather than assigning via innerHTML/jQuery .html()), sanitizes it, and
// inserts the result as real DOM nodes. Used for both this file's own
// trusted <template> icons and svg markup recovered from a record's stored
// value - one path, always sanitized, rather than special-casing by source.
pedigreeEditorEM.setIconSVG = function(containerEl, svgMarkup) {
	var parsed = new DOMParser().parseFromString(svgMarkup, 'image/svg+xml');
	if (parsed.querySelector('parsererror')) {
		pedigreeEditorEM.log('Failed to parse SVG markup, leaving icon empty');
		containerEl.textContent = '';
		return;
	}
	var svgEl = pedigreeEditorEM.sanitizeSvgElement(parsed.documentElement);
	containerEl.textContent = '';
	if (!svgEl) {
		pedigreeEditorEM.log('SVG markup had a disallowed root element, leaving icon empty');
		return;
	}
	containerEl.appendChild(document.importNode(svgEl, true));
};

pedigreeEditorEM.getPedigreeSVG = function(value) {
	var svg = pedigreeEditorEM.getIconSVG(pedigreeEditorEM.emptyIcon);
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
			svg = pedigreeEditorEM.getIconSVG(pedigreeEditorEM.dataIcon);
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
	// data.length check (rather than the previously-always-true `if (data)` -
	// $(...) never returns falsy, even matching nothing) mirrors jQuery
	// .prepend()'s own silent-no-op-on-empty-collection behavior, now that
	// insertBefore() is a plain DOM call instead (see no-jquery/no-append-html).
	// data[0] only (not a loop over every match) is intentional, not a
	// narrowing: imageId below is derived from fieldData.field alone, and
	// edit() looks the icon back up via that same fixed, non-indexed id
	// (document.getElementById(imageId)) - inserting into more than one match
	// would create duplicate ids and silently break edit()'s update for every
	// match after the first, which .prepend() never handled correctly either.
	if (data.length > 0) {
		var imageId = fieldData.field + '_pedigreeEditorEM_icon';
		var svg = pedigreeEditorEM.getPedigreeSVG(result.val());

		var iconContainer = document.createElement('div');
		iconContainer.id = imageId;
		pedigreeEditorEM.setIconSVG(iconContainer, svg);

		var link = document.createElement('a');
		link.href = 'javascript:;';
		link.tabIndex = -1;
		link.addEventListener('click', function() {
			pedigreeEditorEM.edit(fieldData.field);
		});
		link.appendChild(iconContainer);

		data[0].insertBefore(link, data[0].firstChild);
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

	pedigreeEditorEM.log("Opening editor window");
	var pageToOpen = (fieldData.mode == 'HPO') ? pedigreeEditorEM.hpoEditorPage :
		(fieldData.mode == 'CUSTOM') ? pedigreeEditorEM.customEditorPage : pedigreeEditorEM.sctEditorPage;
	// A new handoff each time: the editor names it in every message, so an editor
	// opened for an earlier Edit (or another field) is no longer answered.
	var handoff = pedigreeEditorEM.randomId();
	var url = new URL(pageToOpen, window.location.href);
	url.searchParams.set('pedigreeHandoff', handoff);
	var editorWindow = window.open(url.toString(), pedigreeEditorEM.windowName);
	if (!editorWindow) {
		pedigreeEditorEM.handoff = null;
		alert('Your browser blocked the pedigree editor window. Allow pop-ups for this site, then try again.');
		return true;
	}
	pedigreeEditorEM.handoff = { id: handoff, field: field, window: editorWindow };
	editorWindow.focus();
	return true;
}

// The field's stored pedigree as the editor reads it: decompressed when stored as 'GZ:'.
pedigreeEditorEM.currentValue = function(field) {
	var tr = $('tr[sq_id=' + field + ']');
	var value = $('textarea[name="' + field + '"]', tr).val() || '';
	try {
		if (value.startsWith('GZ:')){
			value = pako.ungzip(atob(value.slice(3)),{ to: 'string' });
		}
	}
	catch (e) {
		console.log('Error decompressing', e);
		value = '';
	}
	return value;
};

// A random id for a handoff or a window name. crypto.randomUUID needs a secure
// context; getRandomValues doesn't.
pedigreeEditorEM.randomId = function() {
	if (window.crypto && typeof window.crypto.randomUUID === 'function') {
		return window.crypto.randomUUID();
	}
	var words = new Uint32Array(4);
	window.crypto.getRandomValues(words);
	return Array.prototype.map.call(words, function(word) { return word.toString(16); }).join('-');
};

/**
 * REDCap's data import - a project's XML file, the Data Import Tool, the API -
 * turns every \" in an imported value into " (Records::saveData), which breaks a
 * JSON pedigree: a linked person's snapshot, and any text with a quote in it, hold
 * escaped quotes. So a JSON pedigree is stored with its escaped
 * quotes and backslashes written as \u0022 and \u005c instead, which JSON.parse
 * reads the same. (A backslash too: an escaped one before a closing quote would
 * leave a \" behind.) Other formats (PED, DADA2) are returned unchanged.
 */
pedigreeEditorEM.importSafeJson = function(value) {
	if (typeof value !== 'string' || !/^\s*[{[]/.test(value)) {
		return value;
	}
	try {
		JSON.parse(value);
	}
	catch (e) {
		return value;
	}
	// Each match is one whole escape, taken left to right, so an escaped backslash
	// is never mistaken for the start of the next escape.
	return value.replace(/\\([\s\S])/g, function(escape, escaped) {
		return escaped === '"' ? '\\u0022' : escaped === '\\' ? '\\u005c' : escape;
	});
};

/**
 * How many bytes a value takes once stored: the browser submits a textarea with CRLF
 * line breaks, and REDCap's data table holds UTF-8 in a MySQL TEXT column (65,535
 * bytes), which cuts anything longer short without an error. So the size limit is
 * checked against this, not the value's length in characters.
 */
pedigreeEditorEM.storedLength = function(value) {
	return new TextEncoder().encode(value.replace(/\r?\n/g, '\r\n')).length;
};

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
		return false;
	}
	var tr = $('tr[sq_id=' + field + ']');
	// Before the length checks below, so they count what's actually stored.
	value = pedigreeEditorEM.importSafeJson(value);

	if (fieldData.compress === 'always'){
		let compressedValue = 'GZ:' + btoa(pako.gzip(value,{ to: 'string' }))
		if (pedigreeEditorEM.storedLength(compressedValue) > 65300) {
			compressedValue = 'GZ:' + btoa(pako.gzip(pedigreeEditorEM.removeDiagramFromFhir(value),{ to: 'string' }));
			if (pedigreeEditorEM.storedLength(compressedValue) > 65300) {
				alert('Pedigree Diagram is too large, even when compressed, not updating');
				window.focus();
				return false;
			}
		}
		$('textarea[name="' + fieldData.field + '"]', tr).val(compressedValue);
	}
	else if (fieldData.compress === 'large'){
		if (pedigreeEditorEM.storedLength(value) > 65300) {
			var compressedValue = 'GZ:' + btoa(pako.gzip(value,{ to: 'string' }))
			if (pedigreeEditorEM.storedLength(compressedValue) > 65300) {
				compressedValue = pedigreeEditorEM.removeDiagramFromFhir(value);
				if (pedigreeEditorEM.storedLength(compressedValue) > 65300) {
					compressedValue = 'GZ:' + btoa(pako.gzip(compressedValue,{ to: 'string' }));
					if (pedigreeEditorEM.storedLength(compressedValue) > 65300) {
						alert('Pedigree Diagram is too large, even when compressed, not updating');
						window.focus();
						return false;
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
		if (pedigreeEditorEM.storedLength(value) > 65300) {
			var compressedValue = pedigreeEditorEM.removeDiagramFromFhir(value);
			if (pedigreeEditorEM.storedLength(compressedValue) > 65300) {
				alert('Pedigree Diagram is too large, not updating');
				window.focus();
				return false;
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
	pedigreeEditorEM.setIconSVG(document.getElementById(imageId), svg);

	window.focus();
	return true;
}

/**
 * The form's half of the handoff with the editor window (pedigree-editor-window-transport):
 * - `pedigree:ready` from the editor is answered with this field's current value;
 * - `pedigree:save` is applied to the field, and answered with `pedigree:saved` (`ok` false
 *   when the field wasn't updated, e.g. the pedigree is too large), so the editor closes
 *   only once its pedigree is here.
 * Only a message from the editor window this form opened, from this origin, naming the
 * current handoff is answered: never another tab's, or a superseded editor's.
 */
pedigreeEditorEM.onMessage = function(event) {
	var handoff = pedigreeEditorEM.handoff;
	var data = event.data;
	if (!handoff || event.origin !== window.location.origin || event.source !== handoff.window
			|| !data || typeof data !== 'object' || data.handoff !== handoff.id) {
		return;
	}
	var origin = window.location.origin;
	if (data.type === 'pedigree:ready') {
		event.source.postMessage({
			type: 'pedigree:data', handoff: handoff.id,
			value: pedigreeEditorEM.currentValue(handoff.field), context: { field: handoff.field }
		}, origin);
	} else if (data.type === 'pedigree:save') {
		pedigreeEditorEM.log("Got data from editor");
		// The field is this handoff's, whatever the message says.
		var ok = pedigreeEditorEM.save(handoff.field, data.value, data.svg) === true;
		event.source.postMessage({ type: 'pedigree:saved', handoff: handoff.id, ok: ok }, origin);
	}
};

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
				return pedigreeEditorEM.importSafeJson(JSON.stringify(fhir, null, 2));
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
