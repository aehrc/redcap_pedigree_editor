var XWiki=(function(c){var a=c.widgets=c.widgets||{};a.FullScreen=Class.create({margin:0,buttonSize:16,initialize:function(){this.buttons=$(document.body).down(".bottombuttons");if(!this.buttons){this.buttons=new Element("div",{"class":"bottombuttons"}).update(new Element("div",{"class":"buttons"}));this.buttons._x_isCustom=true;document.body.appendChild(this.buttons.hide())}this.buttonsPlaceholder=new Element("span");this.toolbarPlaceholder=new Element("span");this.createCloseButtons();$$("textarea",".maximizable").each(function(e){this.addBehavior(e)}.bind(this));document.observe("xwiki:dom:updated",function(e){e.memo.elements.each(function(f){f.select("textarea",".maximizable").each(function(g){this.addBehavior(g)}.bind(this))}.bind(this))}.bind(this));$$(".xRichTextEditor").each(function(e){this.addBehavior(e)}.bind(this));this.addWysiwygListeners();this.maximizedReference=$(document.body).down("input[name='x-maximized']");if(this.maximizedReference&&this.maximizedReference.value!=""){var d=$$(this.maximizedReference.value);if(d&&d.length>0){this.makeFullScreen(d[0])}}this.unloadHandler=this.cleanup.bind(this);Event.observe(window,"unload",this.unloadHandler)},addBehavior:function(d){if(this.isWysiwyg20Content(d)){this.addWysiwyg20ContentButton(d)}else{if(this.isWysiwyg10Content(d)){this.addWysiwyg10ContentButton(d)}else{if(this.isWikiContent(d)){this.addWikiContentButton(d)}else{if(this.isWysiwyg20Field(d)){this.addWysiwyg20FieldButton(d)}else{if(this.isWikiField(d)){this.addWikiFieldButton(d)}else{if(this.isWysiwyg10Field(d)){this.addWysiwyg10FieldButton(d)}else{this.addElementButton(d)}}}}}}},addWysiwygListeners:function(){document.observe("xwiki:wysiwyg:created",this.wysiwyg20Created.bindAsEventListener(this));document.observe("xwiki:tinymce:created",this.wysiwyg10Created.bindAsEventListener(this))},wysiwyg10Created:function(e){var d=$(e.memo.instance);this.removeTextareaLink(d);this.addBehavior(d)},wysiwyg20Created:function(e){var d=$(e.memo.instance.getRichTextArea()).up(".xRichTextEditor");this.removeTextareaLink(d);this.addBehavior(d)},removeTextareaLink:function(d){while(true){if(!d){return}else{if(d.previous(".fullScreenEditLinkContainer")){d.previous(".fullScreenEditLinkContainer").remove();return}}d=d.up()}},isWikiContent:function(d){return d.name=="content"&&d.visible()},isWysiwyg10Content:function(d){return d.name=="content"&&(Prototype.Browser.IE?d.previous(".mceEditorContainer"):d.next(".mceEditorContainer"))},isWysiwyg20Content:function(d){return d.hasClassName("xRichTextEditor")&&d.up("div[id^=content_container]")},isWikiField:function(d){return d.visible()},isWysiwyg10Field:function(d){return !d.visible()&&d.name!="content"&&(Prototype.Browser.IE?d.previous(".mceEditorContainer"):d.next(".mceEditorContainer"))},isWysiwyg20Field:function(d){return d.hasClassName("xRichTextEditor")&&!d.up("div[id^=content_container]")},addWikiContentButton:function(d){d._toolbar=$(document.body).down(".leftmenu2");if(d._toolbar){d._toolbar.insert({top:this.createOpenButton(d)})}else{this.addWikiFieldButton(d)}},addWysiwyg10ContentButton:function(h){var e=(Prototype.Browser.IE?h.previous(".mceEditorContainer"):h.next(".mceEditorContainer"));if(!e){return false}var g=e.down(".mceToolbar");if(!g){return false}var d=new Element("span",{"class":"mce_editor_fullscreentoolbar"});var f=new Element("a",{"class":"mceButtonNormal"});d.insert(new Element("img",{"class":"mceSeparatorLine",height:15,width:1,src:g.down("img.mceSeparatorLine").src}));d.insert(f.insert(this.createOpenButton(e)));g.insert(d);e._toolbar=g;return true},addWysiwyg20ContentButton:function(e){var d=e.down(".gwt-MenuBar");if(!d){if(!e._x_fullScreenLoader){e._x_fullScreenLoader_iterations=0;e._x_fullScreenLoader=new PeriodicalExecuter(function(f){if(f._x_fullScreenLoader_iteration>100){f._x_fullScreenLoader.stop();f._x_fullScreenLoader=false;return}f._x_fullScreenLoader_iteration++;this.addWysiwyg20ContentButton(f)}.bind(this,e),0.2)}return false}d.insert({top:this.createOpenButton(e)});e._toolbar=d;if(e._x_fullScreenLoader){e._x_fullScreenLoader.stop();e._x_fullScreenLoader=false}return true},addElementButton:function(d){Element.insert(d,{before:this.createOpenLink(d)})},addWikiFieldButton:function(d){Element.insert(d,{before:this.createOpenLink(d)})},addWysiwyg10FieldButton:function(d){this.addWysiwyg10ContentButton(d)},addWysiwyg20FieldButton:function(d){this.addWysiwyg20ContentButton(d)},createOpenButton:function(e){var d=new Element("img",{"class":"fullScreenEditButton",title:"Maximize",alt:"Maximize",src:"/resources/icons/silk/arrow_out.png"});d.observe("click",this.makeFullScreen.bind(this,e));d.observe("mousedown",this.preventDrag.bindAsEventListener(this));e._x_fullScreenActivator=d;d._x_maximizedElement=e;return d},createOpenLink:function(e){var f=new Element("div",{"class":"fullScreenEditLinkContainer"});var d=new Element("a",{"class":"fullScreenEditLink",title:"Maximize"});d.update("Maximize &raquo;");d.observe("click",this.makeFullScreen.bind(this,e));f.update(d);e._x_fullScreenActivator=d;d._x_maximizedElement=e;return f},createCloseButtons:function(){this.closeButton=new Element("img",{"class":"fullScreenCloseButton",title:"Exit full screen",alt:"Exit full screen",src:"/resources/icons/silk/arrow_in.png"});this.closeButton.observe("click",this.closeFullScreen.bind(this));this.closeButton.observe("mousedown",this.preventDrag.bindAsEventListener(this));this.closeButton.hide();this.actionCloseButton=new Element("input",{type:"button","class":"button",value:"Exit full screen"});this.actionCloseButtonWrapper=new Element("span",{"class":"buttonwrapper"});this.actionCloseButtonWrapper.update(this.actionCloseButton);this.actionCloseButton.observe("click",this.closeFullScreen.bind(this));this.actionCloseButtonWrapper.hide();this.buttons.down(".buttons").insert({top:this.actionCloseButtonWrapper})},makeFullScreen:function(g){document.fire("xwiki:fullscreen:enter",{target:g});if(this.maximizedReference){if(g.id){this.maximizedReference.value=g.tagName+"[id='"+g.id+"']"}else{if(g.name){this.maximizedReference.value=g.tagName+"[name='"+g.name+"']"}else{if(g.className){this.maximizedReference.value=g.tagName+"."+g.className}}}}this.maximized=g;if(typeof g.setSelectionRange=="function"){var j=g.selectionStart;var l=g.selectionEnd;var e=g.scrollTop}g._originalStyle={width:g.style.width,height:g.style.height};if(g.hasClassName("xRichTextEditor")){var f=g.down(".gwt-RichTextArea");g._richTextAreaOriginalStyle={width:f.style.width,height:f.style.height}}else{if(g.hasClassName("mceEditorContainer")){var f=g.down(".mceEditorIframe");f._originalStyle={width:f.style.width,height:f.style.height};var i=g.down(".mceEditorSource");i._originalStyle={width:i.style.width,height:i.style.height}}}var d=g.up();d.addClassName("fullScreenWrapper");if(g._toolbar){if(g._toolbar.hasClassName("leftmenu2")){d.insert({top:g._toolbar.replace(this.toolbarPlaceholder)})}g._x_fullScreenActivator.replace(this.closeButton)}d.insert(this.buttons.replace(this.buttonsPlaceholder).show());var k=g.up();g._x_fullScreenActivator.hide();while(k!=document.body){k._originalStyle={overflow:k.style.overflow,position:k.style.position,width:k.style.width,height:k.style.height,left:k.style.left,right:k.style.right,top:k.style.top,bottom:k.style.bottom,padding:k.style.padding,margin:k.style.margin};k.setStyle({overflow:"visible",position:"absolute",width:"100%",height:"100%",left:0,top:0,right:0,bottom:0,padding:0,margin:0});k.siblings().each(function(m){m._originalDisplay=m.style.display;m.setStyle({display:"none"})});k=k.up()}document.body._originalStyle={overflow:k.style.overflow,width:k.style.width,height:k.style.height};var h=$(document.body).up();h._originalStyle={overflow:h.style.overflow,width:h.style.width,height:h.style.height};$(document.body).setStyle({overflow:"hidden",width:"100%",height:"100%"});h.setStyle({overflow:"hidden",width:"100%",height:"100%"});this.resizeListener=this.resizeTextArea.bind(this,g);Event.observe(window,"resize",this.resizeListener);this.closeButton.show();this.actionCloseButtonWrapper.show();this.resizeTextArea(g);if(g._toolbar){g._toolbar.viewportOffset()}if(typeof g.setSelectionRange=="function"){g.scrollTop=e;g.selectionStart=j;g.selectionEnd=l}document.fire("xwiki:fullscreen:entered",{target:g})},closeFullScreen:function(){var g=this.maximized;document.fire("xwiki:fullscreen:exit",{target:g});if(typeof g.setSelectionRange=="function"){var k=g.selectionStart;var m=g.selectionEnd;var d=g.scrollTop}this.closeButton.hide();this.actionCloseButtonWrapper.hide();Event.stopObserving(window,"resize",this.resizeListener);g.up().removeClassName("fullScreenWrapper");if(g.hasClassName("xRichTextEditor")){var e=g.down(".gwt-RichTextArea");e.setStyle(g._richTextAreaOriginalStyle)}else{if(g.hasClassName("mceEditorContainer")){var e=g.down(".mceEditorIframe");e.setStyle(e._originalStyle);var h=g.down(".mceEditorSource");h.setStyle(h._originalStyle)}}var l=g.up();var j=[];while(l!=document.body){j.push(l);l=l.up()}var f=j.length;while(f--){l=j[f];l.setStyle(l._originalStyle);l.siblings().each(function(i){i.style.display=i._originalDisplay||""})}document.body.setStyle(document.body._originalStyle);$(document.body).up().setStyle($(document.body).up()._originalStyle);this.buttonsPlaceholder.replace(this.buttons);if(this.buttons._x_isCustom){this.buttons.hide()}if(g._toolbar){if(g._toolbar.hasClassName("leftmenu2")){this.toolbarPlaceholder.replace(g._toolbar)}this.closeButton.replace(g._x_fullScreenActivator)}if(Prototype.Browser.IE){setTimeout(function(){g._x_fullScreenActivator.show();this.setStyle(this._originalStyle)}.bind(g),500)}else{g._x_fullScreenActivator.show();g.setStyle(g._originalStyle)}delete this.maximized;if(this.maximizedReference){this.maximizedReference.value=""}if(typeof g.setSelectionRange=="function"){g.scrollTop=d;g.selectionStart=k;g.selectionEnd=m}document.fire("xwiki:fullscreen:exited",{target:g})},resizeTextArea:function(e){if(!this.maximized){return}var d=document.viewport.getHeight();var f=document.viewport.getWidth();if(f<=0){f=document.body.clientWidth;d=document.body.clientHeight}f=f-this.margin;d=d-e.positionedOffset().top-this.margin-this.buttons.getHeight();e.setStyle({width:f+"px",height:d+"px"});if(e.hasClassName("xRichTextEditor")){e.down(".gwt-RichTextArea").setStyle({width:f+"px",height:d-e.down(".xToolbar").getHeight()-e.down(".gwt-MenuBar").getHeight()+"px"})}else{if(e.hasClassName("mceEditorContainer")){e.down(".mceEditorIframe").setStyle({width:f+"px",height:d-e._toolbar.getHeight()+"px"});e.down(".mceEditorSource").setStyle({width:f+"px",height:d-e._toolbar.getHeight()+"px"})}}document.fire("xwiki:fullscreen:resized",{target:e})},preventDrag:function(d){d.stop()},cleanup:function(){Event.stopObserving(window,"unload",this.unloadHandler);this.actionCloseButtonWrapper.remove()}});function b(){return new a.FullScreen()}(c.domIsLoaded&&b())||document.observe("xwiki:dom:loaded",b);return c}(XWiki||{}));