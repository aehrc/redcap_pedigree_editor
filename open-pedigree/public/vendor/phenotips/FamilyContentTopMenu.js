document.observe("xwiki:dom:loaded",function(){var a={"delete-family":"Are you sure you want to unlink all family members? The pedigree will be deleted for the following patient records:","delete-family-unlink":"Are you sure you want to unlink all family members? The pedigree will be deleted for the following patient records:","delete-family-members":"Are you sure you want to delete the pedigree and all the members of this family? The following patient records will be deleted from the database:"};$$(".delete-family").invoke("observe","click",function(b){b.stop();var e=b.element();e.blur();if(e.disabled){return}else{var c=e.down('input[type="hidden"]');if(c){c=c.value}var d=e.readAttribute("href")+"&confirm=1"+(Prototype.Browser.Opera?"":"&ajax=1");new XWiki.widgets.ConfirmedAjaxRequest(d,{onCreate:function(){e.disabled=true},onSuccess:function(){window.location=new XWiki.Document("WebHome",XWiki.Document.currentSpace).getURL("view")},onFailure:function(){e.disabled=false}},{confirmationText:(a[e.id]||"Are you sure you wish to move this document to the recycle bin?")+(c||"")})}})});(function(){var a=function(d){var c=$("mainContentArea");var e=$("record-actions");if(c&&e){var b=new StickyBox(e,c,{offsetTop:0})}};(XWiki.domIsLoaded&&a())||document.observe("xwiki:dom:loaded",a)})();document.observe("xwiki:dom:loaded",function(){var a=$$(" .export-link");a.invoke("observe","click",function(b){b.stop();var c=window.open("","_blank");PhenoTips.widgets.FormUtils.getFormState().saveIfFormDirty(function(){c.location=b.findElement().href})})});