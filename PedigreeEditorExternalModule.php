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
            if ($metadata == false){
                $errors .= "Failed to get metadata for fhir server at '" . $systemOntologyServer . "'metadata\n";
            }
        }
        $projectOntologyServer = $settings['project_ontology_server'];
        if ($projectOntologyServer){
            $metadata = http_get($projectOntologyServer . 'metadata', null, $info);
            if ($metadata == false){
                $errors .= "Failed to get metadata for fhir server at '" . $projectOntologyServer . "'metadata\n" . json_encode($info);
            }
        }
        return $errors;
    }

    function redcap_survey_page ( $project_id, $record, $instrument, $event_id, $group_id, $survey_hash, $response_id, $repeat_instance) {
        $this->add_pedigree_to_form($project_id, $instrument);
    }


    function redcap_data_entry_form ($project_id, $record, $instrument, $event_id, $group_id, $repeat_instance) {
        $this->add_pedigree_to_form($project_id, $instrument);
    }

    function add_pedigree_to_form ($project_id, $instrument) {

        // At one stage these things were going to be in the settings for the editor
        // maybe in the future they will be exposed.
        $hpoEditorPage = 'open-pedigree/localEditor.html?mode=HPO';
        $sctEditorPage = 'open-pedigree/localEditor.html?mode=SCT';
        $editorPageLocal = true;
        $hpoTag = '@PEDIGREE_HPO';
        $sctTag = '@PEDIGREE_SCT';
        $hideTextOption = 'HIDE_TEXT';
        $showTextOption = 'SHOW_TEXT';
        $neverCompressOption = 'NEVER_COMPRESS';
        $compressLargeOption = 'COMPRESS_LARGE';
        $alwaysCompressOption = 'ALWAYS_COMPRESS';
        $transportType = 'local';
        
        $hideText = $this->getSystemSetting('hide_text');
        $systemCompression = $this->getSystemSetting('system_compression');
        $projectCompression = $this->getProjectSetting('project_compression', $project_id);

        $compression = ($projectCompression) ? $projectCompression : $systemCompression;
        
        // Get the data dictionary for the current instrument in array format
        $dd_array = \REDCap::getDataDictionary($project_id, 'array',  false, null, $instrument);
        
        $fieldsOfInterest = array();
        
        foreach ($dd_array as $field_name=>$field_attributes)
        {
            if ($field_attributes['field_type'] === 'notes'){
                if (preg_match(
                    '/@PEDIGREE_(HPO|SCT)(=(HIDE_TEXT|SHOW_TEXT|NEVER_COMPRESS|COMPRESS_LARGE|ALWAYS_COMPRESS)(,(HIDE_TEXT|SHOW_TEXT|NEVER_COMPRESS|COMPRESS_LARGE|ALWAYS_COMPRESS))?)?/',
                    $field_attributes['field_annotation'], $matches) === 1){

                    $mode = $matches[1];
                    $hide = $hideText;
                    $fCompress = $compression;
                    $option1 = $matches[3];
                    $option2 = $matches[5];
                    if ($option1 === $hideTextOption || $option2 === $hideTextOption){
                        $hide = true;
                    }
                    if ($option1 === $showTextOption || $option2 === $showTextOption){
                        $hide = false;
                    }
                    if ($option1 === $neverCompressOption || $option2 === $neverCompressOption){
                        $fCompress = 'never';
                    }
                    if ($option1 === $compressLargeOption || $option2 === $compressLargeOption){
                        $fCompress = 'large';
                    }
                    if ($option1 === $alwaysCompressOption || $option2 === $alwaysCompressOption){
                        $fCompress = 'always';
                    }

                    $row = array();
                    $row['field'] = $field_name;
                    $row['label'] = $field_attributes['field_label'];
                    $row['mode'] = $mode;
                    $row['hideText'] = $hide;
                    $row['compress'] = $fCompress;
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
    pedigreeEditorEM.openPedigreeDataKey = 'pedigreeData';
EOD;
        }
        
        $dialog = <<<EOD
        
<script type="text/javascript">
    var pedigreeEditorEM = pedigreeEditorEM || {};
    pedigreeEditorEM.fieldsOfInterest = {$fieldsOfInterestJson};
    pedigreeEditorEM.hpoEditorPage = '{$hpoEditorUrl}';
    pedigreeEditorEM.sctEditorPage = '{$sctEditorUrl}';
    pedigreeEditorEM.emptyIcon = '#__pedigree_empty_svg';
    pedigreeEditorEM.dataIcon = '#__pedigree_with_data_svg';
    pedigreeEditorEM.windowName = 'pedigreeEditor';
    pedigreeEditorEM.editorWindow = null;
    pedigreeEditorEM.transportType = '{$transportType}';
{$transportOptions}
</script>

<script id="__pedigree_empty_svg" type="text/plain">
<svg  version="1.1" xmlns="http://www.w3.org/2000/svg" style="overflow: hidden; position: relative; top: -0.78125px;" viewBox="-30 134 180 180" width="auto" height="200" xmlns:xlink="http://www.w3.org/1999/xlink" preserveAspectRatio="xMinYMin">
  <defs style="-webkit-tap-highlight-color: rgba(0, 0, 0, 0);">
    <linearGradient id="grad1" x1="0" y1="1" x2="1" y2="0" gradientTransform="matrix(1,0,0,1,0,0)" style="-webkit-tap-highlight-color: rgba(0, 0, 0, 0);">
      <stop offset="0%" stop-color="#ffffff" stop-opacity="1" style="-webkit-tap-highlight-color: rgba(0, 0, 0, 0);"></stop>
      <stop offset="100%" stop-color="#b8b8b8" stop-opacity="1" style="-webkit-tap-highlight-color: rgba(0, 0, 0, 0);"></stop>
    </linearGradient>
  </defs>
  <rect style="opacity:0.3;fill:#808080;fill-opacity:1;stroke:none;stroke-width:2.01989603" transform="rotate(45)" ry="0" rx="0" height="41.896275" width="41.896275" y="130.93452" x="214.61206" />
  <rect style="opacity:1;fill:url(#grad1);fill-opacity:1;stroke:#333333;stroke-width:3.70329618" transform="rotate(45)" ry="0" rx="0" height="45.249874" width="45.249874" y="127.24451" x="210.91718" />
  <path style="opacity:1;fill:#595959;stroke:none;stroke-width:0.67330515" d="m 26.896757,301.03298 c 0,0 -5.596984,5.5955 -5.596984,5.5955 0,0 2.017491,2.01749 2.017491,2.01749 0,0 5.595503,-5.59624 5.595799,-5.59624 -2.96e-4,0 1.513563,1.51311 1.513563,1.51311 0,0 1.83974,-6.86643 1.83974,-6.86643 0,0 -6.866434,1.83974 -6.866434,1.83974 0,0 1.496825,1.49683 1.496825,1.49645 0,3.8e-4 0,3.8e-4 0,3.8e-4" />
  <text style="font-style:normal;font-weight:normal;font-size:40px;line-height:1.25;font-family:sans-serif;letter-spacing:0px;word-spacing:0px;fill:#000000;fill-opacity:1;stroke:none" x="143.82912" y="175.18643">
    <tspan x="60.762714" y="175.18643" style="text-align:center;text-anchor:middle">Create</tspan>
    <tspan x="60.762714" y="225.18643" style="text-align:center;text-anchor:middle">Diagram</tspan>
  </text>
</svg>
</script>

<script id="__pedigree_with_data_svg" type="text/plain">
<svg  version="1.1"  xmlns="http://www.w3.org/2000/svg"  style="overflow: hidden; position: relative; top: -0.78125px;" viewBox="-174 -90 468 452" width="auto" height="auto" xmlns:xlink="http://www.w3.org/1999/xlink" preserveAspectRatio="xMinYMin">
  <defs style="-webkit-tap-highlight-color: rgba(0, 0, 0, 0);">
    <linearGradient id="grad1" x1="0" y1="1" x2="1" y2="0" gradientTransform="matrix(1,0,0,1,0,0)" style="-webkit-tap-highlight-color: rgba(0, 0, 0, 0);">
      <stop offset="0%" stop-color="#ffffff" stop-opacity="1" style="-webkit-tap-highlight-color: rgba(0, 0, 0, 0);"></stop>
      <stop offset="100%" stop-color="#b8b8b8" stop-opacity="1" style="-webkit-tap-highlight-color: rgba(0, 0, 0, 0);"></stop>
    </linearGradient>
    <linearGradient id="grad2" x1="0" y1="0" x2="1" y2="0" gradientTransform="matrix(1,0,0,1,0,0)" style="-webkit-tap-highlight-color: rgba(0, 0, 0, 0);">
      <stop offset="0%" stop-color="#ffffff" stop-opacity="1" style="-webkit-tap-highlight-color: rgba(0, 0, 0, 0);"></stop>
      <stop offset="100%" stop-color="#b8b8b8" stop-opacity="1" style="-webkit-tap-highlight-color: rgba(0, 0, 0, 0);"></stop>
    </linearGradient>
  </defs>
  <path fill="none" stroke="#333333" d="M60,0L60,128" stroke-width="3" style="-webkit-tap-highlight-color: rgba(0, 0, 0, 0);"></path>
  <path fill="none" stroke="#333333" d="M60,128L60,128" stroke-width="3" style="-webkit-tap-highlight-color: rgba(0, 0, 0, 0);"></path>
  <path fill="none" stroke="#333333" d="M60,128L60,272" stroke-width="3" style="-webkit-tap-highlight-color: rgba(0, 0, 0, 0);"></path>
  <path fill="none" stroke="#333333" d="M-84,0L60,0" stroke-width="3" style="-webkit-tap-highlight-color: rgba(0, 0, 0, 0);"></path>
  <path fill="none" stroke="#333333" d="M60,0L204,0" stroke-width="3" style="-webkit-tap-highlight-color: rgba(0, 0, 0, 0);"></path>
  <rect x="28.88730" y="240.88730" width="62.22539" height="62.22539" rx="0" ry="0" fill="#808080" stroke="none" opacity="0.3" fill-opacity="1" stroke-width="3" transform="matrix(0.7071,0.7071,-0.7071,0.7071,209.9066,41.4832)" style="-webkit-tap-highlight-color: rgba(0, 0, 0, 0); opacity: 0.3; fill-opacity: 1;"></rect>
  <rect x="28.88730" y="240.88730" width="62.22539" height="62.22539" rx="0" ry="0" fill="url('#grad1')" stroke="#333333" opacity="1" fill-opacity="1" stroke-width="5.09259" transform="matrix(0.7637,0.7637,-0.7637,0.7637,221.8992,18.4598)" style="-webkit-tap-highlight-color: rgba(0, 0, 0, 0); opacity: 1; fill-opacity: 1;"></rect>
  <path fill="#595959" stroke="none" d="M9.18280,25.3319C9.18280,25.3319,0.870100,33.6424,0.870100,33.6424C0.870100,33.6424,3.866500,36.6388,3.866500,36.6388C3.866500,36.6388,12.17700,28.3272,12.17744,28.3272C12.17700,28.3272,14.42540,30.57450,14.42540,30.57450C14.42540,30.57450,17.1578,20.37640,17.1578,20.37640C17.1578,20.37640,6.95970,23.10880,6.95970,23.10880C6.95970,23.10880,9.18280,25.3319,9.18280,25.33135C9.18280,25.3319,9.18280,25.3319,9.18280,25.3319" opacity="1" transform="matrix(1,0,0,1,2.8873,291.1127)" style="-webkit-tap-highlight-color: rgba(0, 0, 0, 0); opacity: 1;" stroke-width="1"></path>
  <circle cx="204" cy="0" r="40" fill="#808080" stroke="none" opacity="0.3" fill-opacity="1" stroke-width="3" transform="matrix(1,0,0,1,3,3)" style="-webkit-tap-highlight-color: rgba(0, 0, 0, 0); opacity: 0.3; fill-opacity: 1;"></circle>
  <circle cx="204" cy="0" r="40" fill="url('#grad2')" stroke="#333333" opacity="1" fill-opacity="1" stroke-width="3" style="-webkit-tap-highlight-color: rgba(0, 0, 0, 0); opacity: 1; fill-opacity: 1;"></circle>
  <rect x="-124" y="-40" width="80" height="80" rx="0" ry="0" fill="#808080" stroke="none" opacity="0.3" fill-opacity="1" stroke-width="3" transform="matrix(1,0,0,1,3,3)" style="-webkit-tap-highlight-color: rgba(0, 0, 0, 0); opacity: 0.3; fill-opacity: 1;"></rect>
  <rect x="-124" y="-40" width="80" height="80" rx="0" ry="0" fill="url('#grad2')" stroke="#333333" opacity="1" fill-opacity="1" stroke-width="3" style="-webkit-tap-highlight-color: rgba(0, 0, 0, 0); opacity: 1; fill-opacity: 1;"></rect>
  <circle cx="60" cy="0" r="6.5" fill="#dc7868" stroke="#000000" stroke-width="2" style="-webkit-tap-highlight-color: rgba(0, 0, 0, 0);"></circle>
  <text transform="matrix(0.65748443,-0.27101059,0.53587732,1.3000636,0,0)" y="144.3916" x="-285.4653"
     style="font-style:normal;font-weight:normal;font-size:94.25886536px;line-height:1.25;font-family:sans-serif;letter-spacing:0px;word-spacing:0px;fill:#ff0000;fill-opacity:1;stroke:none;stroke-width:2.35647154">
    <tspan style="stroke-width:2.35647154" y="144.3916" x="-285.4653">Placeholder</tspan>
  </text>
</svg>
</script>

EOD;
        
        echo $dialog;
        
        $this->includeJs('js/pedigreeEditorEM.js');
        $this->includeJs('js/pako.min.js');
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