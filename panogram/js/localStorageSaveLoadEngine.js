/**
 * SaveLoadEngine is responsible for automatic and manual save and load operations.
 * 
 * This version will use local storage instead of an ajax call.
 *
 * @class LocalStorageSaveLoadEngine
 * @constructor
 */


var LocalStorageProbandDataLoader = Class.create( {
    initialize: function(localStorageKey) {
    	this._localStorageKey = localStorageKey; 
        this.probandData = undefined;
        var me = this;
        window.addEventListener('storage',  function(storageEvent) {
        	if (storageEvent.storageArea == window.localStorage && storageEvent.key == me._localStorageKey){
        		// the data has been updated
        		me.load();
        	}
        }, false);
    },    
    
    load: function(callWhenReady) {
    	var data = JSON.parse(localStorage.getItem(this._localStorageKey));
    	var trimmedData = undefined;
    	if (data && data.firstName){
    		if (!trimmedData){
    			trimmedData = {};
    		}
    		trimmedData.firstName = data.firstName;
    	}
    	if (data && data.lastName){
    		if (!trimmedData){
    			trimmedData = {};
    		}
    		trimmedData.lastName = data.lastName;
    	}
    	if (data && data.gender){
    		if (!trimmedData){
    			trimmedData = {};
    		}
    		trimmedData.gender = data.gender;
    	}
    	this.probandData = trimmedData;
        console.log("Proband data: " + stringifyObject(this.probandData));
    	if (callWhenReady){
    		callWhenReady();
    	}
    }
});


var LocalStorageSaveLoadEngine = Class.create(SaveLoadEngine, {

    initialize: function(localStorageKey, saveAs, closeOnSave) {
        this._context = undefined;
        this._localStorageKey = localStorageKey;
        this._saveAs = saveAs || 'internal';
        this._closeOnSave = closeOnSave;
        var me = this;
        window.addEventListener('storage',  function(storageEvent) {
        	if (storageEvent.storageArea == window.localStorage && storageEvent.key == me._localStorageKey){
        		// the data has been updated
        		me.load();
        	}
        }, false);
    },
    
    save: function() {

    	var jsonData = null;
    	if (this._saveAs === "FHIRJSON"){
    		var patientFhirRef = (this._context) ? this._context.patientFhirRef : null;
    		jsonData = PedigreeExport.exportAsFHIR(editor.getGraph().DG, "all", patientFhirRef);
    	}
    	else if (this._saveAs === "simpleJSON"){
    		jsonData = PedigreeExport.exportAsSimpleJSON(editor.getGraph().DG, "all");;
    	}
    	else {
    		jsonData = this.serialize();	
    	}
    	var data = {};
    	data.value = jsonData;
    	if (this._context){
    		data.context = this._context;
    	}
    	localStorage.setItem(this._localStorageKey, JSON.stringify(data));
    	
        console.log("[SAVE] to local storage : " + this._localStorageKey + " as " + this._saveAs);
        
        if (this._closeOnSave){
        	console.log("Attempt to close the window");
        	window.close();
        }
    },

    load: function() {
    	
        console.log("initiating load process from local storage : " + this._localStorageKey + " as " + this._saveAs);

        var data = JSON.parse(localStorage.getItem(this._localStorageKey));
        var clear = true;
        var createCalled = false;
        if (data){
            if(data.context){
                this._context = data.context;
            }
            else {
            	this._context = undefined;
            }
        
            jsonData  = data.value;
            if (jsonData){
            	createCalled = true;
            	if (this._saveAs === "FHIRJSON" || this._saveAs === "simpleJSON"){
            		if (this.createGraphFromImportData(jsonData, this._saveAs, {}, false /* add to undo stack */, true /*center around 0*/)){
            			// loaded
            			clear = false;
            		}
            	}
            	else {
                	jsonData = editor.getVersionUpdater().updateToCurrentVersion(jsonData);
                    this.createGraphFromSerializedData(jsonData);
                    // the createGraphFromSerializedData method will clear if it errors
        			clear = false;
            	}
            }
        }
        if (createCalled){
            if (clear){
            	// empty
            	document.fire("pedigree:load:finish");        	
            }
        }        
        else {
        	console.log("No data to load");
        	console.log("Clearing graph");
        	document.fire("pedigree:graph:clear");
        }
    }//end of load
});
