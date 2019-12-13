
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
		result.attr('readonly', true);
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
		var icon = haveData ? pedigreeEditorEM.dataIcon
				: pedigreeEditorEM.emptyIcon;
		$(data).prepend(
				'<a href="javascript:;" tabindex="-1" onclick="' + onClickText
						+ '"><img id="' + imageId + '" src="'
						+ icon
						+ '" alt="Edit" width="225" height="225"></a>');
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
	var data = {};
	data.context = {
		'field' : field
	};
	data.value = currentValue;
	pedigreeEditorEM.sendDataToEditor(data);
	pedigreeEditorEM.log("Opening editor window");
	var pageToOpen = (fieldData.mode == 'HPO') ? pedigreeEditorEM.hpoEditorPage : pedigreeEditorEM.sctEditorPage; 
	pedigreeEditorEM.editorWindow = window.open(pageToOpen,
			pedigreeEditorEM.windowName);

	return true;
}

pedigreeEditorEM.save = function(field, value) {
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
	$('textarea[name="' + fieldData.field + '"]', tr).val(value);

	var imageId = field + '_pedigreeEditorEM_icon';
	var icon = value ? pedigreeEditorEM.dataIcon : pedigreeEditorEM.emptyIcon;
	$('#' + imageId).attr("src", icon);
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
				pedigreeEditorEM.save(event.data.openPedigreeData.context.field, event.data.openPedigreeData.value);

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
			pedigreeEditorEM.save(data.context.field, data.value);
		}
	}
}



$(document).ready(function() {
	pedigreeEditorEM.start();
});
