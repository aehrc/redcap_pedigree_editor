var XWiki = (function (XWiki) {

  /**
   * The search suggest hooks itself on the search input and provide live results as the user types.
   * Search form validation is not affected (user can still type enter and get to the regular search result page)
   */
  XWiki.SearchSuggest = Class.create({

     /**
      * Constructor. Prepares a light modal container on the same model as the modalPopup
      * and registers event listerners.
      */
    initialize: function(searchInput, sources) {

      this.sources = sources;

      this.searchInput = $(searchInput);
      if (!this.searchInput) {
        return;
      }

      this.searchInput.observe("keyup", this.onKeyUp.bindAsEventListener(this));

      document.observe("xwiki:suggest:clearSuggestions", this.onClearSuggestions.bindAsEventListener(this));
      document.observe("xwiki:suggest:containerCreated", this.onSuggestContainerCreated.bindAsEventListener(this));
      document.observe("xwiki:suggest:containerPrepared", this.onSuggestContainerPrepared.bindAsEventListener(this));
      document.observe("xwiki:suggest:updated", this.onSuggestUpdated.bindAsEventListener(this));
      document.observe("xwiki:suggest:selected", this.onSuggestionSelected.bindAsEventListener(this));

      this.createSuggest();
    },

    /**
     * Callback triggered when the original suggest clears its suggestions.
     */
    onClearSuggestions: function(event) {
      if (event.memo.suggest == this.suggest) {
        // Restore bottom border style
        this.searchInput.setStyle({'borderBottomStyle' : this.searchInputBorderBottomSavedStyle});
      }
    },

    /**
     * Callback triggered when the original suggest has created its results container.
     */
    onSuggestContainerCreated: function(event) {
      if (event.memo.suggest == this.suggest) {
        // Save the style of the bottom border of the input field so that we can restore it later on
        this.searchInputBorderBottomSavedStyle = this.searchInput.getStyle('borderBottomStyle');
        // Hide bottom border of input field to not double the container border just under the field
        this.searchInput.setStyle({'borderBottomStyle' : 'none'});
      }
    },

    /**
     * Callback triggered just before the suggest sends a new set of requests to fetch the suggestions from all the
     * configured sources. At this point the list of suggestions is empty and all sources are marked as loading.
     */
    onSuggestContainerPrepared: function(event) {
      // Hide the "No results!" message.
      this.noResultsMessage.addClassName('hidden');
    },

    /**
     * Callback triggered after the suggest receives the list of suggestions from all configured sources (even if the
     * list of suggestions from one source is empty).
     */
    onSuggestUpdated: function(event) {
      // Check if there are any suggestions, taking into account that there is at least one suggestion used to link the
      // search page.
      if (event.memo.container.select('.suggestItem').length == 1) {
        // Show the "No results!" message.
        this.noResultsMessage.removeClassName('hidden').setStyle({'float': 'left'});
      }
    },

    /**
     * Callback triggered when a suggestion is selected.
     * Submits the form or go to a selected page according to selection.
     */
    onSuggestionSelected: function(event) {
      if (event.memo.suggest == this.suggest) {
        event.stop();
        if (!event.memo.id) {
          // Submit form
          this.searchInput.up('form').submit();
        }
        else {
          // Go to page
          window.location = event.memo.id;;
        }
      }
    },

    /**
     * Creates the underlaying suggest widget.
     */
    createSuggest: function() {
      // Create dummy suggestion node to hold the "Go to search page..." option.
      var valueNode = new Element('div')
            .insert(new Element('span', {'class':'suggestId'}))
            .insert(new Element('span', {'class':'suggestValue'}))
            .insert(new Element('span', {'class':'suggestInfo'}));
      this.noResultsMessage = new Element('div', {'class': 'hidden'})
        .update("No results!".escapeHTML());
      var gotoSearchPageMessage = new Element('div')
        .update("Go to search page\u2026"
        .escapeHTML());
      var content = new Element('div').insert(this.noResultsMessage).insert(gotoSearchPageMessage)
        .insert(new Element('div', {'class': 'clearfloats'}));
      var allResultsNode = new XWiki.widgets.XList([
        new XWiki.widgets.XListItem( content, {
          'containerClasses': 'suggestItem',
          'classes': 'showAllResults',
          'eventCallbackScope' : this,
          'noHighlight' : true,
          'value' : valueNode
        } ),
      ],
      {
        'classes' : 'suggestList',
        'eventListeners' : {
          'click': function(event){
            this.searchInput.up('form').submit();
          },
          'mouseover':function(event){
            this.suggest.clearHighlight();
            this.suggest.iHighlighted = event.element();
            event.element().addClassName('xhighlight');
          }
        }
      });
      var allResults = allResultsNode.getElement();
      this.suggest = new XWiki.widgets.Suggest( this.searchInput, {
        parentContainer: $('searchSuggest'),
        className: 'searchSuggest horizontalLayout',
        fadeOnClear: false,
        align: "right",
        minchars: 3,
        sources : this.sources,
        insertBeforeSuggestions : new Element("div", {'class' : 'results'}).update( allResults ),
        displayValue: true,
        displayValueText: "in ",
        timeout: 0,
        width: 500,
        unifiedLoader: true,
        loaderNode: allResults.down("li"),
        shownoresults: false
      });
    },

   /**
    * Callback triggered when a key has been typed on the virtual input.
    */
   onKeyUp: function(event) {
     var key = event.keyCode;
     switch(key) {
       case Event.KEY_RETURN:
         if (!this.suggest.hasActiveSelection()) {
           event.stop();
           this.searchInput.up('form').submit();
         }
     }
   }

  });

  var init = function() {
    /*
                                                                                                                                                                                                */
    var sources = [{"name":"Matching patients","varname":"input","script":"/bin/get/PhenoTips/SuggestPatientsService?query=__INPUT__&nb=5&outputSyntax=plain","icon":"/resources/icons/silk/user.png","highlight":false}];
    new XWiki.SearchSuggest($('headerglobalsearchinput'), sources);
    return true;
  };

  // When the document is loaded, install search suggestions
  (XWiki.isInitialized && init())
  || document.observe('xwiki:dom:loading', init);

  return XWiki;

})(XWiki);