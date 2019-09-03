/**
 * The UI Element for browsing and selecting pre-defined Pedigree templates
 *
 * @class TemplateSelector
 * @constructor
 * @param {Boolean} isStartupTemplateSelector Set to True if no pedigree has been loaded yet
 */

var TemplateSelector = Class.create( {

    initialize: function(isStartupTemplateSelector) {
        this._isStartupTemplateSelector = isStartupTemplateSelector;
        this.mainDiv = new Element('div', {'class': 'template-picture-container'});
        this.mainDiv.update("Loading list of templates...");
        var closeShortcut = isStartupTemplateSelector ? [] : ['Esc'];
        this.dialog = new PhenoTips.widgets.ModalPopup(this.mainDiv, {close: {method : this.hide.bind(this), keys : closeShortcut}}, {extraClassName: "pedigree-template-chooser", title: "Please select a pedigree template", displayCloseButton: !isStartupTemplateSelector, verticalPosition: "top"});
        isStartupTemplateSelector && this.dialog.show();
        new Ajax.Request(new XWiki.Document('WebHome').getRestURL('objects/PhenoTips.PedigreeClass/index.xml').substring(1), {
            method: 'GET',
            onSuccess: this._onTemplateListAvailable.bind(this)
        });
    },

    /**
     * Returns True if this template selector is the one displayed on startup
     *
     * @method isStartupTemplateSelector
     * @return {Boolean}
     */
    isStartupTemplateSelector: function() {
        return this._isStartupTemplateSelector;
    },

    /**
     * Displays the templates once they have been downloaded
     *
     * @param response
     * @private
     */
    _onTemplateListAvailable: function(response) {
        this.mainDiv.update();
        var objects = response.responseXML.documentElement.getElementsByTagName('objectSummary');
        for (var i = 0; i < objects.length; ++i) {
            var pictureBox = new Element('div', {'class': 'picture-box'});
            pictureBox.update("Loading...");
            this.mainDiv.insert(pictureBox);
            var href = getSelectorFromXML(objects[i], "link", "rel", "http://www.xwiki.org/rel/properties").getAttribute("href").substring(1);
            new Ajax.Request(href, {
                method: 'GET',
                onSuccess: this._onTemplateAvailable.bind(this, pictureBox)
            });
        }
    },

    /**
     * Creates a clickable template thumbnail once the information has been downloaded
     *
     * @param pictureBox
     * @param response
     * @private
     */
    _onTemplateAvailable: function(pictureBox, response) {
        pictureBox.innerHTML = getSubSelectorTextFromXML(response.responseXML, "property", "name", "image", "value").replace(/&amp;/, '&');
        pictureBox.pedigreeData = getSubSelectorTextFromXML(response.responseXML, "property", "name", "data", "value");
        pictureBox.type         = 'internal';
        pictureBox.description  = getSubSelectorTextFromXML(response.responseXML, "property", "name", "description", "value");
        pictureBox.title        = pictureBox.description;

        //console.log("[Data from Template] - " + stringifyObject(pictureBox.pedigreeData));

        // TODO: render images with JavaScript instead
        if (window.SVGSVGElement &&
            document.implementation.hasFeature("http://www.w3.org/TR/SVG11/feature#Image", "1.1")) {
            pictureBox.update(getSubSelectorTextFromXML(response.responseXML, "property", "name", "image", "value"));
        } else {
            pictureBox.innerHTML = "<table bgcolor='#FFFAFA'><tr><td><br>&nbsp;" + pictureBox.description + "&nbsp;<br><br></td></tr></table>";
        }
        pictureBox.observe('click', this._onTemplateSelected.bindAsEventListener(this, pictureBox));
    },

    /**
     * Loads the template once it has been selected
     *
     * @param event
     * @param pictureBox
     * @private
     */
    _onTemplateSelected: function(event, pictureBox) {
        //console.log("observe onTemplateSelected");
        this.dialog.close();
        if (pictureBox.type == 'internal') {
            editor.getSaveLoadEngine().createGraphFromSerializedData(pictureBox.pedigreeData, false /* add to undo stack */, true /*center around 0*/);
        } else if (pictureBox.type == 'simpleJSON') {
            editor.getSaveLoadEngine().createGraphFromImportData(pictureBox.pedigreeData, 'simpleJSON', {}, false /* add to undo stack */, true /*center around 0*/);
        }
    },

    /**
     * Displays the template selector
     *
     * @method show
     */
    show: function() {
        this.dialog.show();
    },

    /**
     * Removes the the template selector
     *
     * @method hide
     */
    hide: function() {
        this.dialog.closeDialog();
    }
});
