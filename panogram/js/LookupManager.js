
var LookupManager = LookupManager || {};

LookupManager.lookupForType = {};

LookupManager.addLookup = function(type, lookup){
	var old = LookupManager.lookupForType[type];
	LookupManager.lookupForType[type] = lookup;
	return old;
};

LookupManager.desanitizeID = function(id){
	var temp = id;
    temp = temp.replace(/_C_/g, ":");
    temp = temp.replace(/_L_/g, "(");
    temp = temp.replace(/_J_/g, ")");
    temp = temp.replace(/_D_/g, ".");
    temp = temp.replace(/_S_/g, "/");
    temp = temp.replace(/__/g, " ");
    return temp;
};

LookupManager.sanitizeID = function(id){
	var temp = id;
    temp = temp.replace(/[:]/g, '_C_');
    temp = temp.replace(/[\(\[]/g, '_L_');
    temp = temp.replace(/[\)\]]/g, '_J_');
    temp = temp.replace(/[.]/g, '_D_');
    temp = temp.replace(/\//g, '_S_');
    temp = temp.replace(/[^a-zA-Z0-9,;_\-*]/g, '__');
    return temp;
};

LookupManager.isValidID = function(type, id){
	if (LookupManager.lookupForType.hasOwnProperty(type)){
		var lookup = LookupManager.lookupForType[type];
		return lookup.isValidID(id);	
	}
	console.log("Didin't find lookup for " + type);
	return true;
};

LookupManager.getLookupURL = function(type, id){
	return LookupManager.lookupForType[type].getLookupURL(id);
};

LookupManager.processLookupResponse = function(type, response){
	return LookupManager.lookupForType[type].processLookupResponse(response);
};

LookupManager.getSuggestOptions = function(type){
	return LookupManager.lookupForType[type].getSuggestOptions();
};

LookupManager.getCodeSystem = function(type){
	return LookupManager.lookupForType[type].getCodeSystem();
};

LookupManager.hasType = function(type){
	return LookupManager.lookupForType.hasOwnProperty(type);
};


var FhirLookup = Class.create( {
	
	initialize: function(baseUrl, codeSystem, valueSet, validIdRegex, expandCount) {

        this._baseUrl      = baseUrl;
        this._codeSystem   = codeSystem;
        this._valueSet     = valueSet;
        this._validIdRegex = validIdRegex;
        this._expandCount = expandCount || 20;
    },
    
    isValidID: function(id){
    	if (this._validIdRegex){
    		return this._validIdRegex.test(id);	
    	}
    	return true;
    },
    
    getLookupURL: function(id){
    	return this._baseUrl + 'CodeSystem/$lookup?_format=json&system=' + this._codeSystem + '&code=' + LookupManager.desanitizeID(id);
    },
    
    processLookupResponse: function(response){
    	var parsed = JSON.parse(response.responseText);
        //console.log(stringifyObject(parsed));
        if (parsed.parameter){
        	for (var i = 0; i < parsed.parameter.length; i++){
        		if (parsed.parameter[i].name == 'display'){
        			return parsed.parameter[i].valueString;
        			break;
        		}
        	}
        }
        throw "Failed to find result in response";
    },
    
    getSuggestOptions:function(){
    	return {
            script: this._baseUrl + 'ValueSet/$expand?_format=json&url=' + this._valueSet + "&count=" + this._expandCount + "&",
            varname: "filter",
            noresults: "No matching terms",
            resultsParameter : "expansion.contains",
            json: true,
            resultId : "code",
            resultValue : "display",
            resultInfo : {},
            enableHierarchy: false,
            //tooltip : 'gene-info',
            fadeOnClear : false,
            timeout : 30000,
            parentContainer : $('body')
        }
    },
    
    getCodeSystem: function(){
    	return this._codeSystem;
    }
    
});