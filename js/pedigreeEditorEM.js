
//****************************************************************************************************
//
//****************************************************************************************************

var pedigreeEditorEM = pedigreeEditorEM || {};

// The handoff with the editor window (pedigree-editor-window-transport). Each Edit issues a
// handoff id for its field; the editor names it in every message. This tab's own editor window
// name, the ids it has issued (id -> field) and the record they belong to are kept in
// sessionStorage - never the pedigree - so a reload or Save & Stay of this same record's form
// still answers the editor it opened.
pedigreeEditorEM.windowName = null;
pedigreeEditorEM.handoffs = {};
pedigreeEditorEM.editorWindow = null;
pedigreeEditorEM.HANDOFF_STORE_KEY = 'pedigreeEditorHandoffs';
pedigreeEditorEM.MAX_HANDOFFS = 20;

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
		pedigreeEditorEM.restoreHandoffs();
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
	// A new handoff each time, for this field.
	var handoff = pedigreeEditorEM.randomId();
	// (appended to the URL as PHP built it, rather than re-serialising its query)
	var url = pageToOpen + (pageToOpen.indexOf('?') === -1 ? '?' : '&') + 'pedigreeHandoff=' + encodeURIComponent(handoff);
	var editorWindow = window.open(url, pedigreeEditorEM.windowName);
	if (!editorWindow) {
		// (an editor already open keeps its handoff)
		alert('Your browser blocked the pedigree editor window. Allow pop-ups for this site, then try again.');
		return true;
	}
	pedigreeEditorEM.editorWindow = editorWindow;
	pedigreeEditorEM.addHandoff(handoff, field);
	editorWindow.focus();
	return true;
}

// This form page's record, from its URL: a stored handoff is only taken up again by the same
// record's form (a missing event_id matches any).
pedigreeEditorEM.pageRecord = function() {
	var query = new URLSearchParams(window.location.search);
	return {
		pid: query.get('pid') || '', id: query.get('id') || '', page: query.get('page') || '',
		instance: query.get('instance') || '1', event: query.get('event_id') || ''
	};
};

pedigreeEditorEM.sameRecord = function(a, b) {
	return Boolean(a && b) && a.pid === b.pid && a.id === b.id && a.page === b.page
		&& a.instance === b.instance && (!a.event || !b.event || a.event === b.event);
};

// The key this tab's handoffs are stored under. A window opened from this one starts with a copy
// of its sessionStorage but has its own window.name, so it never takes these up.
pedigreeEditorEM.tabKey = function() {
	if (!window.name || window.name.indexOf('pedigreeForm_') !== 0) {
		window.name = 'pedigreeForm_' + pedigreeEditorEM.randomId();
	}
	return pedigreeEditorEM.HANDOFF_STORE_KEY + ':' + window.name;
};

pedigreeEditorEM.restoreHandoffs = function() {
	var stored = null;
	try {
		stored = JSON.parse(window.sessionStorage.getItem(pedigreeEditorEM.tabKey()));
	} catch (e) { /* storage blocked or unreadable: start afresh */ }
	if (stored && typeof stored.windowName === 'string' && stored.handoffs && typeof stored.handoffs === 'object'
			&& pedigreeEditorEM.sameRecord(stored.record, pedigreeEditorEM.pageRecord())) {
		pedigreeEditorEM.windowName = stored.windowName;
		pedigreeEditorEM.handoffs = stored.handoffs;
	} else {
		// One editor window per form tab: Edit again here reuses it, but an editor opened from
		// another tab never does.
		pedigreeEditorEM.windowName = 'pedigreeEditor_' + pedigreeEditorEM.randomId();
		pedigreeEditorEM.handoffs = {};
	}
	pedigreeEditorEM.storeHandoffs();
};

pedigreeEditorEM.storeHandoffs = function() {
	try {
		window.sessionStorage.setItem(pedigreeEditorEM.tabKey(), JSON.stringify({
			windowName: pedigreeEditorEM.windowName,
			record: pedigreeEditorEM.pageRecord(),
			handoffs: pedigreeEditorEM.handoffs
		}));
	} catch (e) { /* storage blocked: handoffs last as long as this page */ }
};

pedigreeEditorEM.addHandoff = function(id, field) {
	var ids = Object.keys(pedigreeEditorEM.handoffs);
	for (var i = 0; i <= ids.length - pedigreeEditorEM.MAX_HANDOFFS; i++) {
		delete pedigreeEditorEM.handoffs[ids[i]];
	}
	pedigreeEditorEM.handoffs[id] = field;
	pedigreeEditorEM.storeHandoffs();
};

// Whether a message came from this tab's editor window: the one it opened, or - after this form
// was reloaded and has lost that handle - a window this tab opened.
pedigreeEditorEM.fromOwnEditor = function(source) {
	if (!source) {
		return false;
	}
	if (source === pedigreeEditorEM.editorWindow) {
		return true;
	}
	try {
		return source.opener === window;
	} catch (e) {
		return false;
	}
};

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

// A random id for a handoff or a window name: it tells handoffs apart, it isn't a secret (a
// message is only answered from the exact window, on this origin). crypto.randomUUID needs a
// secure context; getRandomValues doesn't.
pedigreeEditorEM.randomId = function() {
	var crypto = window.crypto;
	if (crypto && typeof crypto.randomUUID === 'function') {
		return crypto.randomUUID();
	}
	var words = new Uint32Array(4);
	if (crypto && typeof crypto.getRandomValues === 'function') {
		crypto.getRandomValues(words);
	} else {
		for (var i = 0; i < words.length; i++) {
			words[i] = Math.floor(Math.random() * 0x100000000);
		}
	}
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

pedigreeEditorEM.TOO_LARGE = 'The pedigree is too large for the field, so the form was not updated.';

// Stores a pedigree from the editor in the field and its thumbnail. Returns true, or why the
// field wasn't updated (the editor shows it, and stays open).
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
		return 'The form has no pedigree field ' + field + '.';
	}
	var tr = $('tr[sq_id=' + field + ']');
	// Before the length checks below, so they count what's actually stored.
	value = pedigreeEditorEM.importSafeJson(value);

	if (fieldData.compress === 'always'){
		let compressedValue = 'GZ:' + btoa(pako.gzip(value,{ to: 'string' }))
		if (pedigreeEditorEM.storedLength(compressedValue) > 65300) {
			compressedValue = 'GZ:' + btoa(pako.gzip(pedigreeEditorEM.removeDiagramFromFhir(value),{ to: 'string' }));
			if (pedigreeEditorEM.storedLength(compressedValue) > 65300) {
				return pedigreeEditorEM.TOO_LARGE;
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
						return pedigreeEditorEM.TOO_LARGE;
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
				return pedigreeEditorEM.TOO_LARGE;
			}
			$('textarea[name="' + fieldData.field + '"]', tr).val(compressedValue);
		}
		else {
			$('textarea[name="' + fieldData.field + '"]', tr).val(value);
		}
	}

	// The field is updated; a thumbnail that can't be drawn doesn't undo that.
	try {
		var imageId = field + '_pedigreeEditorEM_icon';
		if (!svg){
			svg = pedigreeEditorEM.getPedigreeSVG(value);
		}
		else {
			svg = String(svg).replace(/width=".*?"/, 'width="auto"')
				.replace(/height=".*?"/, 'height="auto"');
		}
		pedigreeEditorEM.setIconSVG(document.getElementById(imageId), svg);
	} catch (e) {
		console.error('Could not draw the pedigree thumbnail', e);
	}
	return true;
}

/**
 * The form's half of the handoff with the editor window (pedigree-editor-window-transport):
 * - `pedigree:ready` from the editor is answered with this field's current value;
 * - `pedigree:save` is applied to the field, and answered with `pedigree:saved` (`ok` false
 *   and `message` saying why when the field wasn't updated, e.g. the pedigree is too large),
 *   so the editor closes only once its pedigree is here, and otherwise says why.
 * Only a message from this tab's editor window, from this origin, naming a handoff this tab
 * issued is answered, for that handoff's field: never another tab's, or a made-up one.
 */
pedigreeEditorEM.onMessage = function(event) {
	var data = event.data;
	if (event.origin !== window.location.origin || !data || typeof data !== 'object'
			|| typeof data.handoff !== 'string' || !Object.prototype.hasOwnProperty.call(pedigreeEditorEM.handoffs, data.handoff)
			|| !pedigreeEditorEM.fromOwnEditor(event.source)) {
		return;
	}
	// (after a reload, this is how the form gets its editor window back)
	pedigreeEditorEM.editorWindow = event.source;
	// The handoff's own field, whatever the message says.
	var field = pedigreeEditorEM.handoffs[data.handoff];
	var origin = window.location.origin;
	if (data.type === 'pedigree:ready') {
		event.source.postMessage({
			type: 'pedigree:data', handoff: data.handoff,
			value: pedigreeEditorEM.currentValue(field), context: { field: field }
		}, origin);
	} else if (data.type === 'pedigree:save') {
		pedigreeEditorEM.log("Got data from editor");
		var result;
		try {
			result = pedigreeEditorEM.save(field, data.value, data.svg);
		} catch (e) {
			console.error(e);
			result = 'The form could not store the pedigree (' + e + ').';
		}
		// Answered at once, before anything else can hold up this window.
		event.source.postMessage({
			type: 'pedigree:saved', handoff: data.handoff, ok: result === true,
			message: result === true ? undefined : String(result)
		}, origin);
		if (result === true) {
			window.focus();
		}
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
