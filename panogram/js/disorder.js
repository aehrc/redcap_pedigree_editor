/*
 * Disorder is a class for storing genetic disorder info and loading it from the
 * the OMIM database. These disorders can be attributed to an individual in the Pedigree.
 *
 * @param disorderID the id number for the disorder, taken from the OMIM database
 * @param name a string representing the name of the disorder e.g. "Down Syndrome"
 */

var Disorder = Class.create( {

    initialize: function(disorderID, name, callWhenReady) {
    	 console.log("NEW DISORDER: disorder id = " + disorderID + ", name = " + name);
        // user-defined disorders
        if (name == null && !Disorder.isValidID(Disorder.desanitizeID(disorderID))) {
            name = Disorder.desanitizeID(disorderID);
        }

        this._disorderID = Disorder.sanitizeID(disorderID);
        this._name       = name ? name : "loading...";

        if (!name && callWhenReady)
            this.load(callWhenReady);
    },

    /*
     * Returns the disorderID of the disorder
     */
    getDisorderID: function() {
        return this._disorderID;
    },

    /*
     * Returns the name of the disorder
     */
    getName: function() {
        return this._name;
    },

    load: function(callWhenReady) {
        var queryURL = editor.getDisorderLookupUrl() + '&code=' + Disorder.desanitizeID(this._disorderID);
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
            console.log("LOADED DISORDER: disorder id = " + this._disorderID + ", name = " + this._name);
        } catch (err) {
            console.log("[LOAD DISORDER] Error: " +  err);
        }
    },
    
    onDataFail : function(error) {
    	console.log("Failed to load disorder " + this._disorderID + " setting name to ID");
    	this._name = Disorder.desanitizeID(this.__disorderID);
    }
    
});

/*
 * IDs are used as part of HTML IDs in the Legend box, which breaks when IDs contain some non-alphanumeric symbols.
 * For that purpose these symbols in IDs are converted in memory (but not in the stored pedigree) to some underscores.
 */
Disorder.sanitizeID = function(disorderID) {
    if (isInt(disorderID))
        return disorderID;
    var temp = disorderID;
    temp = temp.replace(/[\(\[]/g, '_L_');
    temp = temp.replace(/[\)\]]/g, '_J_');
    temp = temp.replace(/[:]/g, '_C_');
    temp = temp.replace(/[.]/g, '_D_');
    temp = temp.replace(/\//g, '_S_');
    return temp.replace(/[^a-zA-Z0-9,;_\-*]/g, '__');
}

Disorder.desanitizeID = function(disorderID) {
    var temp = disorderID;
    temp = temp.replace(/_L_/g, "(");
    temp = temp.replace(/_C_/g, ":");
    temp = temp.replace(/_D_/g, ".");
    temp = temp.replace(/_S_/g, "/");
    temp = temp.replace(/_J_/g, ")");
    temp = temp.replace(/__/g, " ");
    return temp;
}

Disorder.isValidID = function(id) {
    var pattern = /^http:\/\//;
    
    return pattern.test(id) || isInt(id);
}
