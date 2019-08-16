
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

		window.addEventListener('message', pedigreeEditorEM.onMessageEvent,
				false);
		window.addEventListener('unload', function(event) {
			// clear out the editor data
			if (pedigreeEditorEM.editorWindow) {
				pedigreeEditorEM.editorWindow.postMessage({
					"messageType" : "panogram",
					"panogramData" : null
				}, pedigreeEditorEM.editorPageOrigin);
			}
		});
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
	if (fieldData.type == "notes") {
		result = $('textarea[name="' + fieldData.field + '"]', tr);
	}
	var haveData = false;
	if (result) {
		result.attr('readonly', true);
		if (pedigreeEditorEM.hideInput) {
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
	if (fieldData.type == "notes") {
		currentValue = $('textarea[name="' + fieldData.field + '"]', tr).val();
	}
	var data = {};
	data.context = {
		'field' : field
	};
	data.value = currentValue;

	pedigreeEditorEM.sendWhenReady = true;
	pedigreeEditorEM.messageData = data;

	pedigreeEditorEM.editorWindow = window.open(pedigreeEditorEM.editorPage,
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
	if (fieldData.type == "text") {
		$('input[name="' + fieldData.field + '"]', tr).val(value);
	} else if (fieldData.type == "notes") {
		$('textarea[name="' + fieldData.field + '"]', tr).val(value);
	}

	var imageId = field + '_pedigreeEditorEM_icon';
	var icon = value ? pedigreeEditorEM.dataIcon : pedigreeEditorEM.emptyIcon;
	$('#' + imageId).attr("src", icon);
	window.focus();

}

pedigreeEditorEM.onMessageEvent = function(event) {

	if (event.source == pedigreeEditorEM.editorWindow
			&& event.origin == pedigreeEditorEM.editorPageOrigin) {
		// the message is from editor window
		if (event.data.messageType === "panogram_control") {
			if (event.data.message == "started"
					&& pedigreeEditorEM.sendWhenReady) {

				console.log("Sending data to editor");

				pedigreeEditorEM.editorWindow.postMessage({
					"messageType" : "panogram",
					"panogramData" : pedigreeEditorEM.messageData
				}, pedigreeEditorEM.editorPageOrigin);
				pedigreeEditorEM.sendWhenReady = false;
			}
		} else if (event.data.messageType === "panogram") {
			console.log("Got data from editor");
			if (event.data.panogramData) {
				pedigreeEditorEM.save(event.data.panogramData.context.field,
						event.data.panogramData.value);

			}
		}
	}
}

$(document).ready(function() {
	pedigreeEditorEM.start();
});