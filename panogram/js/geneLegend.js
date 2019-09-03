/**
 * Class responsible for keeping track of candidate genes.
 * This information is graphically displayed in a 'Legend' box.
 *
 * @class GeneLegend
 * @constructor
 */
var GeneLegend = Class.create( Legend, {

    initialize: function($super) {
        $super('Candidate Genes', true);
        
        this._termCache = {};
    },

    _getPrefix: function(id) {
        return "gene";
    },

    
    /**
     * Returns the GeneTerm object with the given ID. If object is not in cache yet
     * returns a newly created one which may have the term name & other attributes not loaded yet
     *
     * @method getTerm
     * @return {Object}
     */    
    getTerm: function(geneID) {
    	geneID = GeneTerm.sanitizeID(geneID);
        if (!this._termCache.hasOwnProperty(geneID)) {
            var whenNameIsLoaded = function() { this._updateTermName(geneID); }
            this._termCache[geneID] = new GeneTerm(geneID, null, whenNameIsLoaded.bind(this));            
        }
        return this._termCache[geneID];
    },
    
    /**
     * Updates the displayed gene name for the given gene
     *
     * @method _updateTermName
     * @param {Number} id The identifier of the gene to update
     * @private
     */    
    _updateTermName: function(id) {
//        console.log("updating phenotype display for " + id + ", name = " + this.getTerm(id).getName());
        var name = this._legendBox.down('li#' + this._getPrefix() + '-' + id + ' .disorder-name');
        name.update(this.getTerm(id).getName());
    },
    
    /**
     * Registers an occurrence of a gene. If gene hasn't been documented yet,
     * designates a color for it.
     *
     * @method addCase
     * @param {Number|String} geneID ID for this gene taken from the HGNC database
     * @param {String} geneName The name of the gene
     * @param {Number} nodeID ID of the Person who has this disorder
     */
    addCase: function($super, geneID, geneName, nodeID) {
        if (!this._termCache.hasOwnProperty(geneID))
            this._termCache[geneID] = new GeneTerm(geneID, geneName);

        $super(geneID, geneName, nodeID);
    },
    
    /**
     * Generate the element that will display information about the given disorder in the legend
     *
     * @method _generateElement
     * @param {String} geneID The id for the gene
     * @param {String} name The human-readable gene description
     * @return {HTMLLIElement} List element to be insert in the legend
     */
    _generateElement: function($super, geneID, name) {
        if (!this._objectColors.hasOwnProperty(geneID)) {
            var color = this._generateColor(geneID);
            this._objectColors[geneID] = color;
            document.fire('gene:color', {'id' : geneID, color: color});
        }

        return $super(geneID, name);
    },

    /**
     * Callback for dragging an object from the legend onto nodes
     *
     * @method _onDropGeneric
     * @param {Person} Person node
     * @param {String|Number} id ID of the gene being dropped
     */
    _onDropObject: function(node, geneID) {
        if (node.isPersonGroup()) {
            return;
        }
        var currentGenes = node.getGenes().slice(0);
        if (currentGenes.indexOf(geneID) == -1) {   // only if the node does not have this gene yet
            currentGenes.push(geneID);
            editor.getView().unmarkAll();
            var properties = { "setGenes": currentGenes };
            var event = { "nodeID": node.getID(), "properties": properties };
            document.fire("pedigree:node:setproperty", event);
        } else {
            alert("This person already has the selected candidate gene");
        }
    },

    /**
     * Generates a CSS color.
     * Has preference for some predefined colors that can be distinguished in gray-scale
     * and are distint from disorder colors.
     *
     * @method generateColor
     * @return {String} CSS color
     */
    _generateColor: function(geneID) {
        if(this._objectColors.hasOwnProperty(geneID)) {
            return this._objectColors[geneID];
        }

        var usedColors = Object.values(this._objectColors),
        // green palette
        prefColors = ['#81a270', '#c4e8c4', '#56a270', '#b3b16f', '#4a775a', '#65caa3'];
        usedColors.each( function(color) {
            prefColors = prefColors.without(color);
        });
        if(prefColors.length > 0) {
            return prefColors[0];
        }
        else {
            var randomColor = Raphael.getColor();
            while(randomColor == "#ffffff" || usedColors.indexOf(randomColor) != -1) {
                randomColor = "#"+((1<<24)*Math.random()|0).toString(16);
            }
            return randomColor;
        }
    }
});
