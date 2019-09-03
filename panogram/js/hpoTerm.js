/*
 * HPOTerm is a class for storing phenotype information and loading it from the
 * the HPO database. These phenotypes can be attributed to an individual in the Pedigree.
 *
 * @param hpoID the id number for the HPO term, taken from the HPO database
 * @param name a string representing the name of the term e.g. "Abnormality of the eye"
 */

var HPOTerm = Class.create( {

    initialize: function(hpoID, name, callWhenReady) {
        // user-defined terms
        if (name == null && !HPOTerm.isValidID(HPOTerm.desanitizeID(hpoID))) {
            name = HPOTerm.desanitizeID(hpoID);
        }

        this._hpoID  = HPOTerm.sanitizeID(hpoID);
        this._name   = name ? name : "loading...";

        if (!name && callWhenReady)
            this.load(callWhenReady);
    },

    /*
     * Returns the hpoID of the phenotype
     */
    getID: function() {
        return this._hpoID;
    },

    /*
     * Returns the name of the term
     */
    getName: function() {
        return this._name;
    },

    load: function(callWhenReady) {
        var queryURL = editor.getPhenotypeLookupUrl() + '&code=' + HPOTerm.desanitizeID(this._hpoID);
        //console.log("queryURL: " + queryURL);
        new Ajax.Request(queryURL, {
            method: "GET",
            requestHeaders: {"Accept": "application/json"},
            onSuccess: this.onDataReady.bind(this),
            onFailure: this.onDataFail.bind(this),
            //onComplete: complete.bind(this)
            onComplete: callWhenReady ? callWhenReady : {}
        });
    },

    onDataReady : function(response) {
        try {
            var parsed = JSON.parse(response.responseText);
            //console.log(stringifyObject(parsed));
            if (parsed.parameter){
            	for (var i = 0; i < parsed.parameter.length; i++){
            		if (parsed.parameter[i].name == 'display'){
            			this._name = parsed.parameter[i].valueString;
            			break;
            		}
            	}
            }
            console.log("LOADED HPO TERM: id = " + this._hpoID + ", name = " + this._name);
        } catch (err) {
            console.log("[LOAD HPO TERM] Error: " +  err);
        }
    },
    
    onDataFail : function(error) {
    	console.log("Failed to load hpo term " + this._hpoID + " setting name to ID");
    	this._name = HPOTerm.desanitizeID(this._hpoID);
    }
});

/*
 * IDs are used as part of HTML IDs in the Legend box, which breaks when IDs contain some non-alphanumeric symbols.
 * For that purpose these symbols in IDs are converted in memory (but not in the stored pedigree) to some underscores.
 */
HPOTerm.sanitizeID = function(id) {
	
    var temp = id;
    var start = "http://";
    if (id.substring(0, start.length) === start){
    	temp = "H_" + id.substring(start.length);
    }
    temp = temp.replace(/[\(\[]/g, '_L_');
    temp = temp.replace(/[\)\]]/g, '_J_');
    temp = temp.replace(/[:]/g, '_C_');
    temp = temp.replace(/[.]/g, '_D_');
    temp = temp.replace(/\//g, '_S_');
    return temp.replace(/[^a-zA-Z0-9,;_\-*]/g, '__');
}

HPOTerm.desanitizeID = function(id) {
	var temp = id;
    var start = "http://";
    temp = temp.replace(/^H_/, start);
    temp = temp.replace(/__/g, " ");
    temp = temp.replace(/_C_/g, ":");
    temp = temp.replace(/_L_/g, "(");
    temp = temp.replace(/_J_/g, ")");
    temp = temp.replace(/_D_/g, ".");
    temp = temp.replace(/_S_/g, "/");
    return temp;
}

HPOTerm.isValidID = function(id) {
    var pattern = /^(http:\/\/)|(HP:)/;
    return pattern.test(id);
}



