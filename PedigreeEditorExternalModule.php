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

    public function validateSettings($settings){
        $errors='';
                
        $systemOntologyServer = $settings['system_ontology_server'];
        if ($systemOntologyServer){
            $metadata = http_get($systemOntologyServer . 'metadata');
            if ($metadata == FALSE){
                $errors .= "Failed to get metadata for fhir server at '" . $systemOntologyServer . "'metadata\n";
            }
        }
        $projectOntologyServer = $settings['project_ontology_server'];
        if ($projectOntologyServer){
            $metadata = http_get($projectOntologyServer . 'metadata', null, $info);
            if ($metadata == FALSE){
                $errors .= "Failed to get metadata for fhir server at '" . $projectOntologyServer . "'metadata\n" . json_encode($info);
            }
        }
        return $errors;
    }
    
    function redcap_data_entry_form ($project_id, $record, $instrument, $event_id, $group_id, $repeat_instance) {
        
        // At one stage these things were going to be in the settings for the editor
        // maybe in the future they will be exposed.
        $hpoEditorPage = 'panogram/localEditor.html?mode=HPO';
        $sctEditorPage = 'panogram/localEditor.html?mode=SCT';
        $editorPageLocal = TRUE;
        $hpoTag = '@PEDIGREE_HPO';
        $sctTag = '@PEDIGREE_SCT';
        $hideTextOption = 'HIDE_TEXT';
        $showTextOption = 'SHOW_TEXT';
        $transportType = 'local';
        
        $hideText = $this->getSystemSetting('hide_text');
        
        // Get the data dictionary for the current instrument in array format
        $dd_array = \REDCap::getDataDictionary($project_id, 'array',  false, null, $instrument);
        
        $fieldsOfInterest = array();
        
        foreach ($dd_array as $field_name=>$field_attributes)
        {
            if ($field_attributes['field_type'] === 'notes'){
                $hpoPos = strpos($field_attributes['field_annotation'], $hpoTag);
                $mode = null;
                if ($hpoPos !== FALSE){
                    $mode = 'HPO';
                }
                else {
                    $sctPos = strpos($field_attributes['field_annotation'], $sctTag);
                    if ($sctPos !== FALSE){
                        $mode = 'SCT';
                    }
                }
                if ($mode !== null){
                    $hide = $hideText;
                    $hidePos = strPos($field_attributes['field_annotation'], $mode . '=' . $hideTextOption);
                    if ($hidePos !== FALSE){
                        $hide = TRUE;
                    }
                    else {
                        $showPos = strPos($field_attributes['field_annotation'], $mode . '=' . $showTextOption);
                        if ($showPos !== FALSE){
                            $hide = FALSE;
                        }
                    }
                    $row = array();
                    $row['field'] = $field_name;
                    $row['label'] = $field_attributes['field_label'];
                    $row['mode'] = $mode;
                    $row['hideText'] = $hide;
                    $fieldsOfInterest[] = $row;
                }
            }
        }


        if (empty($fieldsOfInterest)) {
            return;
        }
        
        
        $systemOntologyServer = $this->getSystemSetting('system_ontology_server');
        $projectOntologyServer = $this->getProjectSetting('project_ontology_server', $project_id);
        
        $ontologyServer = ($projectOntologyServer) ? $projectOntologyServer : $systemOntologyServer;

        if ($ontologyServer){
            $hpoEditorPage = $hpoEditorPage . '&ontologyServer=' . $ontologyServer;
            $sctEditorPage = $sctEditorPage . '&ontologyServer=' . $ontologyServer;
        }
        // the local url build wants to put a '?' on the end which breaks paramaters, so add one to soak the extra
        $hpoEditorPage = $hpoEditorPage . '&broken=redcap';
        $sctEditorPage = $sctEditorPage . '&broken=redcap';
        
        $fieldsOfInterestJson = json_encode($fieldsOfInterest);
        if ($editorPageLocal){
            $hpoEditorUrl = $this->getLocalUrl($hpoEditorPage);
            $sctEditorUrl = $this->getLocalUrl($sctEditorPage);
        }
        else {
            $hpoEditorUrl = $hpoEditorPage;
            $sctEditorUrl = $sctEditorPage;
        }
        
        if ($transportType == 'message'){
            $urlData = parse_url($hpoEditorUrl);
            $scheme   = isset($urlData['scheme']) ? $urlData['scheme'] . '://' : '';
            $host     = isset($urlData['host']) ? $urlData['host'] : '';
            $port     = isset($urlData['port']) ? ':' . $urlData['port'] : '';
            $editorUrlOrigin = $scheme . $host . $port;
            
            $transportOptions = <<<EOD
    pedigreeEditorEM.sendWhenReady = false;
    pedigreeEditorEM.messageData = null;
    pedigreeEditorEM.editorPageOrigin = '{$editorUrlOrigin}';
EOD;
        }
        else {
            $transportOptions = <<<EOD
    pedigreeEditorEM.panogramDataKey = 'PANOGRAM_DIAGRAM_DATA';
    pedigreeEditorEM.probandDataKey = 'PANOGRAM_PROBAND_DATA';
EOD;
        }
        
        
        $emptyIcon = $this->getLocalUrl('empty_pedigree.svg');
        $dataIcon = $this->getLocalUrl('pedigree_with_data.svg');
        
        $dialog = <<<EOD
        
<script type="text/javascript">
    var pedigreeEditorEM = pedigreeEditorEM || {};
    pedigreeEditorEM.fieldsOfInterest = {$fieldsOfInterestJson};
    pedigreeEditorEM.hpoEditorPage = '{$hpoEditorUrl}';
    pedigreeEditorEM.sctEditorPage = '{$sctEditorUrl}';
    pedigreeEditorEM.emptyIcon = '{$emptyIcon}';
    pedigreeEditorEM.dataIcon = '{$dataIcon}';
    pedigreeEditorEM.windowName = 'pedigreeEditor';
    pedigreeEditorEM.editorWindow = null;
    pedigreeEditorEM.transportType = '{$transportType}';
{$transportOptions}
</script>

EOD;
        
        echo $dialog;
        
        $this->includeJs('js/pedigreeEditorEM.js');
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