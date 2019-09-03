/*
 * GeneTerm is a class for storing gene information and loading it from the
 * the ontology server. These genes can be attributed to an individual in the Pedigree.
 *
 * @param geneID the id number for the gene term, taken from the gene database
 * @param name a string representing the name of the term e.g. "AD5"
 */

var GeneTerm = Class.create( {

    initialize: function(geneID, name, callWhenReady) {
        // user-defined terms
        if (name == null && !GeneTerm.isValidID(GeneTerm.desanitizeID(geneID))) {
            name = GeneTerm.desanitizeID(geneID);
        }

        this._geneID  = GeneTerm.sanitizeID(geneID);
        this._name   = name ? name : "loading...";

        if (!name && callWhenReady)
            this.load(callWhenReady);
    },

    /*
     * Returns the geneID of the phenotype
     */
    getID: function() {
        return this._geneID;
    },

    /*
     * Returns the name of the term
     */
    getName: function() {
        return this._name;
    },

    load: function(callWhenReady) {
        var queryURL = editor.getGeneLookupUrl() + '&code=' + GeneTerm.desanitizeID(this._geneID);
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
            console.log("LOADED GENE TERM: id = " + this._geneID + ", name = " + this._name);
        } catch (err) {
            console.log("[LOAD GENE TERM] Error: " +  err);
        }
    },
    
    onDataFail : function(error) {
    	console.log("Failed to load gene term " + this._geneID + " setting name to ID");
    	this._name = GeneTerm.desanitizeID(this._geneID);
    }
});

/*
 * IDs are used as part of HTML IDs in the Legend box, which breaks when IDs contain some non-alphanumeric symbols.
 * For that purpose these symbols in IDs are converted in memory (but not in the stored pedigree) to some underscores.
 */
GeneTerm.sanitizeID = function(id) {
	var start = "HGNC:";
    var temp = id;
    if (id.substring(0, start.length) === start){
    	temp = "D_" + id.substring(start.length);
    }
    var temp = temp.replace(/[\(\[]/g, '_L_');
    temp = temp.replace(/[\)\]]/g, '_J_');
    temp = temp.replace(/[:]/g, '_C_');
    temp = temp.replace(/[.]/g, '_D_');
    temp = temp.replace(/\//g, '_S_');
    return temp.replace(/[^a-zA-Z0-9,;_\-*]/g, '__');
}

GeneTerm.desanitizeID = function(id) {
	var temp = id;
	var start = "HGNC:";
	
	temp = temp.replace(/^D_/, start);
    temp = temp.replace(/_C_/g, ":");
    temp = temp.replace(/_L_/g, "(");
    temp = temp.replace(/_J_/g, ")");
    temp = temp.replace(/_D_/g, ".");
    temp = temp.replace(/_S_/g, "/");
    temp = temp.replace(/__/g, " ");
    return temp;
}

GeneTerm.isValidID = function(id) {
    var pattern = /^HGNC:/;
    return pattern.test(id);
}
