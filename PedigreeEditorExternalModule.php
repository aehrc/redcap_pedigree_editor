<?php
/**
 * @file
 * Provides ExternalModule class for Pedigree Editor.
 */

namespace AEHRC\PedigreeEditorExternalModule;

use ExternalModules\AbstractExternalModule;


/**
 * ExternalModule class for Pedigree Editor.
 */
class PedigreeEditorExternalModule extends AbstractExternalModule {

    public $defaultTag = "@PEDIGREE_EDITOR";
    public $defaultEditorPage = "panogram_editor.html";
    public $defaultEditorPageLocal = true;

    
    function redcap_data_entry_form ($project_id, $record, $instrument, $event_id, $group_id, $repeat_instance) {
        $tag = $this->getSystemSetting('tag');
        if (!$tag){
            $tag = $this->defaultTag;
        }
        $hideText = $this->getSystemSetting('hide_text');
        
        // Get the data dictionary for the current instrument in array format
        $dd_array = \REDCap::getDataDictionary($project_id, 'array',  false, null, $instrument);
        $dd_json = \REDCap::getDataDictionary($project_id, 'json',  false, null, $instrument);
        
        $fieldsOfInterest = array();
        
        foreach ($dd_array as $field_name=>$field_attributes)
        {
            $pos = strpos($field_attributes['field_annotation'], $tag);
            if ($pos !== FALSE && $field_attributes['field_type'] === 'notes'){
                $row = array();
                $row['field'] = $field_name;
                $row['type'] = $field_attributes['field_type'];
                $row['label'] = $field_attributes['field_label'];
                $fieldsOfInterest[] = $row;
            }
        }

        
//         if (empty($fieldsOfInterest)) {
//             return;
//         }
        
        $editorPage = $this->getSystemSetting('editor_page');
        $editorPageLocal = $this->getSystemSetting('editor_page_local');
        if (!$editorPage){
            $editorPage = $this->defaultEditorPage;
            $editorPageLocal = $this->defaultEditorPageLocal;
        }
        
        
        
        $fieldsOfInterestJson = json_encode($fieldsOfInterest);
        if ($editorPageLocal){
            $editorUrl = $this->getLocalUrl($editorPage);
        }
        else {
            $editorUrl = $editorPage;
        }
        $urlData = parse_url($editorUrl);
        $scheme   = isset($urlData['scheme']) ? $urlData['scheme'] . '://' : '';
        $host     = isset($urlData['host']) ? $urlData['host'] : '';
        $port     = isset($urlData['port']) ? ':' . $urlData['port'] : '';
        $editorUrlOrigin = $scheme . $host . $port;
        
        $emptyIcon = $this->getLocalUrl('empty_pedigree.svg');
        $dataIcon = $this->getLocalUrl('pedigree_with_data.svg');
        $hideInput = $hideText ? 'true' : 'false';
        
        $dialog = <<<EOD
        
<script type="text/javascript">
    var pedigreeEditorEM = pedigreeEditorEM || {};
    pedigreeEditorEM.fieldsOfInterest = {$fieldsOfInterestJson};
    pedigreeEditorEM.editorPage = '{$editorUrl}';
    pedigreeEditorEM.emptyIcon = '{$emptyIcon}';
    pedigreeEditorEM.dataIcon = '{$dataIcon}';
    pedigreeEditorEM.windowName = 'pedigreeEditor';
    pedigreeEditorEM.localStorageKey = 'PedigreeEditorExternalModuleTransfer';
    pedigreeEditorEM.editorWindow = null;
    pedigreeEditorEM.editorPageOrigin = '{$editorUrlOrigin}';
    pedigreeEditorEM.sendWhenReady = false;
    pedigreeEditorEM.hideInput = {$hideInput};
    pedigreeEditorEM.dd = {$dd_json};
</script>

EOD;
        
        echo $dialog;
        
        $this->includeJs('js/pedigreeEditorEM.js');
    }
    
    /**
     * @inheritdoc
     */
    function redcap_every_page_top($project_id) {
        
        $tag = $this->getSystemSetting('tag');
        if (!$tag){
            $tag = $this->defaultTag;
        }
        
        if (PAGE == 'Design/online_designer.php' && $project_id) {

            echo "<script>var pedigreeEditorEM = pedigreeEditorEM || {};</script>";
            echo "<script>pedigreeEditorEM.actionTag = '" . $tag . "';</script>";

//             $this->includeJs('js/helper.js');
        }

        
    }

    /**
     * Includes a local JS file - uses the API endpoint if auth type is shib
     *
     * @param string $path
     *   The relative path to the js file.
     */
    protected function includeJs($path) {
        // For shib installations, it is necessary to use the API endpoint for resources
        global $auth_meth;
        $ext_path = $auth_meth == 'shibboleth' ? $this->getUrl($path, true, true) : $this->getUrl($path);
        echo '<script src="' . $ext_path . '"></script>';
    }
    
    /**
     *
     * @param string $path
     *   The relative path to the js file.
     */
    protected function getLocalUrl($path) {
        // For shib installations, it is necessary to use the API endpoint for resources
        global $auth_meth;
        return ($auth_meth == 'shibboleth') ? $this->getUrl($path, true, true) : $this->getUrl($path);
        
    }
    
    
}