/**
 * SaveLoadEngine is responsible for automatic and manual save and load operations.
 * 
 * This version will use local storage instead of an ajax call.
 *
 * @class LocalStorageSaveLoadEngine
 * @constructor
 */


var MessageStorageProbandDataLoader = Class.create( {
    initialize: function(sessionStorageKey) {
    	this._sessionStorageKey = sessionStorageKey; 
        this.probandData = undefined;
        var refURL = new URL(document.referrer);
        this.messageOrigin = refURL.origin;
        var me = this;
        if (window.opener){
        	window.addEventListener('message',  function(event) {
            	
            	if (event.source == window.opener && event.origin == me.messageOrigin){
            		// the message is from our opener
            		if (event.data.messageType === "proband"){
            			if (event.data.probandData){
            				window.sessionStorage.setItem(me._sessionStorageKey, JSON.stringify(event.data.probandData));
            			}
            			else {
            				window.sessionStorage.setItem(me._sessionStorageKey, null);
            			}        			
                		me.load();
            		}
            	}
            }, false);	
        }
    },    
    
    load: function(callWhenReady) {
    	var data = JSON.parse(sessionStorage.getItem(this._sessionStorageKey));
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


var MessageStorageSaveLoadEngine = Class.create(SaveLoadEngine, {

    initialize: function(sessionStorageKey, saveAs, closeOnSave) {
        this._context = undefined;
        this._sessionStorageKey = sessionStorageKey;
        this._saveAs = saveAs || 'internal';
        this._closeOnSave = closeOnSave;
        var refURL = new URL(document.referrer);
        this.messageOrigin = refURL.origin;
        var me = this;
        if (window.opener){
        	window.addEventListener('message',  function(event) {
//        		console.log("Got Message - " + stringifyObject(event));
            	if (event.source == window.opener && event.origin == me.messageOrigin){
            		// the message is from our opener
            		if (event.data.messageType === "panogram"){
            			if (event.data.panogramData){
//            				console.log("Got data - " + stringifyObject(event.data));
            				window.sessionStorage.setItem(me._sessionStorageKey, JSON.stringify(event.data.panogramData));
            			}
            			else {
            				window.sessionStorage.setItem(me._sessionStorageKey, null);
            			}        			
                		me.load();
            		}
            	}
            }, false);
        	
        }
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
    	window.sessionStorage.setItem(this._sessionStorageKey, JSON.stringify(data));
    	
    	// pass message to opener
    	if (window.opener){
    		window.opener.postMessage({messageType: "panogram", panogramData: data}, this.messageOrigin);	
    	}
    
        console.log("[SAVE] to local storage : " + this._sessionStorageKey + " as " + this._saveAs);
        
        if (this._closeOnSave){
        	console.log("Attempt to close the window");
        	window.close();
        }
    },

    load: function() {
    	
        console.log("initiating load process from local storage : " + this._sessionStorageKey + " as " + this._saveAs );

        var data = JSON.parse(window.sessionStorage.getItem(this._sessionStorageKey));
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

var MessageStorageController = Class.create( {

    initialize: function(){
    	var refURL = new URL(document.referrer);
        this.messageOrigin = refURL.origin;
        var me = this;
        if (window.opener){
        	window.addEventListener('message',  function(event) {
        		if (me){
        			me.handleMessageEvent(event);
        		}
        		
        	}, false);
        }
    },
    start: function(){
    	window.opener.postMessage({messageType: "panogram_control", message: "started"}, this.messageOrigin);
    },
    
    handleMessageEvent: function(event){
    	
    	if (event.source == window.opener && event.origin == this.messageOrigin){
    		// the message is from our opener
    		if (event.data.messageType === "panogram_control"){
    			if (event.data.panogramData){
    			
    			}
    		}
    	}
    }
    
});
