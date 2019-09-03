/*
 * LookupTerm is a base class for data that has been 'looked' up.
 *
 * @param ID the id of the lookup term
 * @param name a string representing the name of the term e.g. "Down Syndrome"
 */

var LookupTerm = Class.create( {

    initialize: function(ID, name, callWhenReady) {
        // user-defined disorders
    	var desanitizedID = LookupManager.desanitizeID(ID);
        if (name == null && !LookupManager.isValidID(this.getLookupType(), desanitizedID)) {
            name = desanitizedID;
        }

        this._ID = LookupManager.sanitizeID(ID);
        this._name       = name ? name : "loading...";

        if (!name && callWhenReady)
            this.load(callWhenReady);
    },

    /*
     * Returns the disorderID of the disorder
     */
    getID: function() {
        return this._ID;
    },

    /*
     * Returns the name of the disorder
     */
    getName: function() {
        return this._name;
    },

    load: function(callWhenReady) {
        var queryURL = LookupManager.getLookupURL(this.getLookupType(), this.getID());
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
        	this._name = LookupManager.processLookupResponse(this.getLookupType(), response);
        } catch (err) {
            console.log("[LOAD " + this.getLookupType() + "] Error: " +  err);
            this._name = LookupManager.desanitizeID(this.getID());
        }
    },
    
    onDataFail : function(error) {
    	console.log("Failed to load " + this.getLookupType() + " " + this._disorderID + " setting name to ID");
    	this._name = LookupManager.desanitizeID(this.getID());
    }
    
});

var Disorder = Class.create( LookupTerm, {

    initialize: function($super, disorderID, name, callWhenReady) {
    	 console.log("NEW DISORDER: disorder id = " + disorderID + ", name = " + name);
    	 $super(disorderID, name, callWhenReady);
    },
    getLookupType: function(){
    	return 'disorder';
    },
    getDisorderID: function() {
    	return this.getID();
    }
});

Disorder.desanitizeID = function(id){
	return LookupManager.desanitizeID(id);
};

Disorder.sanitizeID = function(id){
	return LookupManager.sanitizeID(id);
};

Disorder.isValidID = function(id) {
	return LookupManager.isValidID('disorder', id);
};

var HPOTerm = Class.create( LookupTerm, {

    initialize: function($super, hpoID, name, callWhenReady) {
    	 console.log("NEW HPOTerm: hpo id = " + HPOTerm + ", name = " + name);
    	 $super(hpoID, name, callWhenReady);
    },
    getLookupType: function(){
    	return 'phenotype';
    }
});

HPOTerm.desanitizeID = function(id){
	return LookupManager.desanitizeID(id);
};

HPOTerm.sanitizeID = function(id){
	return LookupManager.sanitizeID(id);
};

HPOTerm.isValidID = function(id) {
	return LookupManager.isValidID('phenotype', id);
};

var GeneTerm = Class.create( LookupTerm, {

    initialize: function($super, geneID, name, callWhenReady) {
    	 console.log("NEW GeneTerm: gene id = " + geneID + ", name = " + name);
    	 $super(geneID, name, callWhenReady);
    },
    getLookupType: function(){
    	return 'gene';
    }
});

GeneTerm.desanitizeID = function(id){
	return LookupManager.desanitizeID(id);
};

GeneTerm.sanitizeID = function(id){
	return LookupManager.sanitizeID(id);
};

GeneTerm.isValidID = function(id) {
	return LookupManager.isValidID('gene', id);
};

var EthnicityTerm = Class.create( LookupTerm, {

    initialize: function($super, id, name, callWhenReady) {
    	 console.log("NEW EthnicityTerm: id = " + id + ", name = " + name);
    	 $super(id, name, callWhenReady);
    },
    getLookupType: function(){
    	return 'ethnicity';
    }
});
