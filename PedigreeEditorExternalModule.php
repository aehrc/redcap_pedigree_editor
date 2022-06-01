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
            $strlen = strlen($systemOntologyServer);
            if ('/' === $systemOntologyServer[$strlen - 1]){
                $systemOntologyServer = substr($systemOntologyServer, 0, $strlen - 1);
            }
            $metadata = http_get($systemOntologyServer . '/metadata');
            if ($metadata == false){
                $errors .= "Failed to get metadata for fhir server at '" . $systemOntologyServer . "'/metadata\n";
            }
        }
        $authType = $settings['authentication_type'];
        if ($authType === 'cc') {
            $authEndpoint = $settings['cc_token_endpoint'];
            $clientId = $settings['cc_client_id'];
            $clientSecret = $settings['cc_client_secret'];
            // get the access token
            $params = array('grant_type' => 'client_credentials');
            $headers = ['Authorization: Basic ' . base64_encode($clientId . ':' . $clientSecret)];

            try {
                $response = $this->httpPost($authEndpoint, $params, 'application/x-www-form-urlencoded', $headers);

                if ($response === false) {
                    $r = implode("", $http_response_header);
                    $errors .= "Failed to get Authentication Token for fhir server at '" . $authEndpoint . "' response = false, r='" . $r . "'\n";
                } else {
                    $responseJson = json_decode($response, true);
                    if (!array_key_exists('access_token', $responseJson)) {
                        $errors .= "Failed to get Authentication Token for fhir server at '" . $authEndpoint . "'$response\n";
                    }
                }
            } catch (\Exception $e) {
                $errors .= "Failed to get Authentication Token for fhir server at '" . $authEndpoint . "' got exception $e\n";
            }
        }

        $keyNameLookup = [
            'disorder_system' => 'Disorder System',
            'disorder_valueset' => 'Disorder Valueset',
            'phenotype_system' => 'Phenotype System',
            'phenotype_valueset' => 'Phenotype Valueset',
            'gene_system' => 'Gene System',
            'gene_valueset' => 'Gene Valueset',
        ];
        $regexNameLookup = [
            'disorder_regex' => 'Disorder Regex',
            'phenotype_regex' => 'Phenotype Regex',
            'gene_regex' => 'Gene Regex',
        ];
        $systemDefTerminology = $settings['system_def_terminology'];
        if ($systemDefTerminology === 'CUSTOM'){
            // need to check something has been entered for terminology
            foreach($keyNameLookup as $setting => $name){
                if (!$settings['system_'.$setting]) {
                    $errors .= "Custom " . $name . " is required.\n";
                }
            }
            foreach($regexNameLookup as $setting => $name){
                if (!$settings['system_'.$setting]) {
                    $errors .= "Custom " . $name . " is required.\n";
                } else if (preg_match('/'.$settings['system_'.$setting].'/', '') === false){
                    $errors .= "Custom " . $name . " is not a valid regular expression.\n";
                }
            }
        }
        $projectDefTerminology = $settings['project_def_terminology'];
        if ($projectDefTerminology === 'CUSTOM'){
            // need to check something has been entered for terminology
            foreach($keyNameLookup as $setting => $name){
                if (!$settings['project_'.$setting]) {
                    $errors .= "Custom " . $name . " is required.\n";
                }
            }
            foreach($regexNameLookup as $setting => $name){
                if (!$settings['project_'.$setting]) {
                    $errors .= "Custom " . $name . " is required.\n";
                } else if (preg_match('/'.$settings['project_'.$setting].'/', '') === false){
                    $errors .= "Custom " . $name . " is not a valid regular expression.\n";
                }
            }
        }

        return $errors;
    }

    function redcap_survey_page ( $project_id, $record, $instrument, $event_id, $group_id, $survey_hash, $response_id, $repeat_instance) {
        $this->add_pedigree_to_form($project_id, $record, $instrument, $event_id, $group_id, $repeat_instance);
    }


    function redcap_data_entry_form ($project_id, $record, $instrument, $event_id, $group_id, $repeat_instance) {
        $this->add_pedigree_to_form($project_id, $record, $instrument, $event_id, $group_id, $repeat_instance);
    }

    function add_pedigree_to_form ($project_id, $record, $instrument, $event_id, $group_id, $repeat_instance) {

        // At one stage these things were going to be in the settings for the editor
        // maybe in the future they will be exposed.
        $hpoEditorPage = 'open-pedigree/localEditor.html?mode=HPO';
        $sctEditorPage = 'open-pedigree/localEditor.html?mode=SCT';
        $customEditorPage = 'open-pedigree/localEditor.html?mode=CUSTOM';
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

        $compression = ($projectCompression) ?: $systemCompression;

        $systemDefTerminology = $this->getSystemSetting('system_def_terminology');
        $projectDefTerminology = $this->getProjectSetting('project_def_terminology', $project_id);
        $customTerminology = array();

        $defTerminology = (!$projectDefTerminology || $projectDefTerminology === 'SYSTEM') ? $systemDefTerminology
            : $projectDefTerminology;

        if (!$projectDefTerminology || $projectDefTerminology === 'SYSTEM'){
            if ($defTerminology === 'CUSTOM'){
                $customTerminology['disorderSystem'] = $this->getSystemSetting('system_disorder_system');
                $customTerminology['disorderValueset'] = $this->getSystemSetting('system_disorder_valueset');
                $customTerminology['disorderRegex'] = $this->getSystemSetting('system_disorder_regex');
                $customTerminology['phenotypeSystem'] = $this->getSystemSetting('system_phenotype_system');
                $customTerminology['phenotypeValueset'] = $this->getSystemSetting('system_phenotype_valueset');
                $customTerminology['phenotypeRegex'] = $this->getSystemSetting('system_phenotype_regex');
                $customTerminology['geneSystem'] = $this->getSystemSetting('system_gene_system');
                $customTerminology['geneValueset'] = $this->getSystemSetting('system_gene_valueset');
                $customTerminology['geneRegex'] = $this->getSystemSetting('system_gene_regex');
            }
        } else if ($projectDefTerminology === 'CUSTOM') {
            $customTerminology['disorderSystem'] = $this->getProjectSetting('system_disorder_system', $project_id);
            $customTerminology['disorderValueset'] = $this->getProjectSetting('system_disorder_valueset', $project_id);
            $customTerminology['disorderRegex'] = $this->getProjectSetting('system_disorder_regex', $project_id);
            $customTerminology['phenotypeSystem'] = $this->getProjectSetting('system_phenotype_system', $project_id);
            $customTerminology['phenotypeValueset'] = $this->getProjectSetting('system_phenotype_valueset', $project_id);
            $customTerminology['phenotypeRegex'] = $this->getProjectSetting('system_phenotype_regex', $project_id);
            $customTerminology['geneSystem'] = $this->getProjectSetting('system_gene_system', $project_id);
            $customTerminology['geneValueset'] = $this->getProjectSetting('system_gene_valueset', $project_id);
            $customTerminology['geneRegex'] = $this->getProjectSetting('system_gene_regex', $project_id);
        }



        // Get the data dictionary for the current instrument in array format
        try {
            $dd_array = \REDCap::getDataDictionary($project_id, 'array', false, null, $instrument);
        } catch (\Exception $e) {
            // error reading data dictionary
            return;
        }

        $fieldsOfInterest = array();
        
        foreach ($dd_array as $field_name=>$field_attributes)
        {
            if ($field_attributes['field_type'] === 'notes'){
                if (preg_match(
                    '/@PEDIGREE(_(HPO|SCT))?(=(HIDE_TEXT|SHOW_TEXT|NEVER_COMPRESS|COMPRESS_LARGE|ALWAYS_COMPRESS)(,(HIDE_TEXT|SHOW_TEXT|NEVER_COMPRESS|COMPRESS_LARGE|ALWAYS_COMPRESS))?)?/',
                    $field_attributes['field_annotation'], $matches) === 1){

                    $mode = $matches[2] ?: $defTerminology;
                    $hide = $hideText;
                    $fCompress = $compression;
                    $option1 = $matches[4];
                    $option2 = $matches[6];
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
                    $row['mode'] = $mode ?: $defTerminology;
                    $row['hideText'] = $hide;
                    $row['compress'] = $fCompress;
                    $fieldsOfInterest[] = $row;
                }
            }
        }


        if (empty($fieldsOfInterest)) {
            return;
        }
        

        $ontologyServer = urlencode($this->getUrl('TerminologyService.php', false, true));

        $systemFormat = $this->getSystemSetting('system_format');
        $projectFormat = $this->getProjectSetting('project_format', $project_id);

        $format = ($projectFormat) ?: $systemFormat;
        $this->getSystemSetting('system_compression');
        $hpoEditorPage = $hpoEditorPage . '&format=' . $format;
        $sctEditorPage = $sctEditorPage . '&format=' . $format;
        $customEditorPage = $customEditorPage . '&format=' . $format;

        if ($ontologyServer){
            $hpoEditorPage = $hpoEditorPage . '&redcapTerminolgyUrl=' . $ontologyServer;
            $sctEditorPage = $sctEditorPage . '&redcapTerminolgyUrl=' . $ontologyServer;
            $customEditorPage = $customEditorPage . '&redcapTerminolgyUrl=' . $ontologyServer;
        }
        if ($customTerminology){
            foreach ($customTerminology as $key=>$val){
                $customEditorPage = $customEditorPage . '&'.$key .'=' . urlencode($val);
            }
        }
        // the local url build wants to put a '?' on the end which breaks paramaters, so add one to soak the extra
        $hpoEditorPage = $hpoEditorPage . '&broken=redcap';
        $sctEditorPage = $sctEditorPage . '&broken=redcap';
        $customEditorPage = $customEditorPage . '&broken=redcap';

        $fieldsOfInterestJson = json_encode($fieldsOfInterest);
        if ($editorPageLocal){
            $hpoEditorUrl = $this->getLocalUrl($hpoEditorPage);
            $sctEditorUrl = $this->getLocalUrl($sctEditorPage);
            $customEditorUrl = $this->getLocalUrl($customEditorPage);
        }
        else {
            $hpoEditorUrl = $hpoEditorPage;
            $sctEditorUrl = $sctEditorPage;
            $customEditorUrl = $customEditorPage;
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
    pedigreeEditorEM.customEditorPage = '{$customEditorUrl}';
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
        // the API endpoint seems to break things, so we won't use it even for shib installations.
        $ext_path = $this->getUrl($path);
        echo '<script src="' . $ext_path . '"></script>';
    }
    
    /**
     *
     * @param string $path
     *   The relative path to the js file.
     */
    protected function getLocalUrl($path) {
        // the API endpoint seems to break things, so we won't use it even for shib installations.
        return $this->getUrl($path);
    }

    /**
     * @param $fullUrl
     */
    private function outputGet($fullUrl)
    {
        $headers = [];
        $authToken = $this->getAuthToken();
        if ($authToken !== false) {
            $headers[] = 'Authorization: Bearer ' . $this->getAuthToken();
        }
        $result = $this->httpGet($fullUrl, $headers);

        if ($result === false) {
            $error = ['error' => 'Internal Error', 'error_description' => 'Failed to retrieve data from FHIR server - '.$fullUrl];
            header('Content-type: application/json');
            http_response_code(400);
            echo json_encode($error, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        } else {
            header('Content-type: application/json');
            echo $result;
        }
    }

    private function getFhirServerUri()
    {
        $ontologyServer = $this->getSystemSetting('system_ontology_server');
        if (!$ontologyServer){
            $ontologyServer = 'https://r4.ontoserver.csiro.au/fhir'; // default
        }
        if ($ontologyServer) {
            $strlen = strlen($ontologyServer);
            if ('/' === $ontologyServer[$strlen - 1]) {
                // remove trailing /
                $ontologyServer = substr($ontologyServer, 0, $strlen - 1);
            }
        }
        return $ontologyServer;
    }

    public function lookupTerminologyCode($system, $code){
        $params = ["_format" => "json", "system" => $system, "code" => $code];
        $fullUrl = $this->getFhirServerUri() . '/CodeSystem/$lookup?' . http_build_query($params);
        $this->outputGet($fullUrl);
    }

    public function queryTerminology($valueSet, $filter, $count){

        $params = ["url" => $valueSet, "filter" => $filter, "count" => $count];
        $fullUrl = $this->getFhirServerUri() . '/ValueSet/$expand?' . http_build_query($params);
        $this->outputGet($fullUrl);
    }


    private function httpGet($fullUrl, $headers)
    {
        // if curl isn't install the default version of http_get in init_functions doesn't include the headers.
        if (function_exists('curl_init') || empty($headers)) {
            return http_get($fullUrl, null, '', $headers, null);
        }
        if (ini_get('allow_url_fopen')) {
            // Set http array for file_get_contents
            $headerText = '';
            foreach ($headers as $hvalue) {
                $headerText .= $hvalue . "\r\n";
            }
            $http_array = array('method' => 'GET', 'header' => $headerText);
            // If using a proxy
            if (!sameHostUrl($fullUrl) && PROXY_HOSTNAME != '') {
                $http_array['proxy'] = str_replace(array('http://', 'https://'), array('tcp://', 'tcp://'), PROXY_HOSTNAME);
                $http_array['request_fulluri'] = true;
                if (PROXY_USERNAME_PASSWORD != '') {
                    $proxy_auth = "Proxy-Authorization: Basic " . base64_encode(PROXY_USERNAME_PASSWORD);
                    if (isset($http_array['header'])) {
                        $http_array['header'] .= $proxy_auth . "\r\n";
                    } else {
                        $http_array['header'] = $proxy_auth . "\r\n";
                    }
                }
            }
            // Use file_get_contents
            $content = @file_get_contents($fullUrl, false, stream_context_create(array('http' => $http_array)));
        } else {
            $content = false;
        }
        // Return the response
        return $content;
    }

    private function httpPost($fullUrl, $postData, $contentType, $headers)
    {
        // if curl isn't install the default version of http_post in init_functions doesn't include the headers.
        if (function_exists('curl_init') || empty($headers)) {
            return http_post($fullUrl, $postData, null, $contentType, '', $headers);
        }
        // If params are given as an array, then convert to query string format, else leave as is
        if ($contentType == 'application/json') {
            // Send as JSON data
            $param_string = (is_array($postData)) ? json_encode($postData) : $postData;
        } elseif ($contentType == 'application/x-www-form-urlencoded') {
            // Send as Form encoded data
            $param_string = (is_array($postData)) ? http_build_query($postData, '', '&') : $postData;
        } else {
            // Send params as is (e.g., Soap XML string)
            $param_string = $postData;
        }
        if (ini_get('allow_url_fopen')) {
            // Set http array for file_get_contents
            // Set http array for file_get_contents
            $headerText = '';
            foreach ($headers as $hvalue) {
                $headerText .= $hvalue . "\r\n";
            }

            $http_array = array('method' => 'POST',
                'header' => "Content-type: $contentType" . "\r\n" . $headerText . "Content-Length: " . strlen($param_string) . "\r\n",
                'content' => $param_string
            );
            // If using a proxy
            if (!sameHostUrl($fullUrl) && PROXY_HOSTNAME != '') {
                $http_array['proxy'] = str_replace(array('http://', 'https://'), array('tcp://', 'tcp://'), PROXY_HOSTNAME);
                $http_array['request_fulluri'] = true;
                if (PROXY_USERNAME_PASSWORD != '') {
                    $http_array['header'] .= "Proxy-Authorization: Basic " . base64_encode(PROXY_USERNAME_PASSWORD) . "\r\n";
                }
            }

            // Use file_get_contents
            $content = @file_get_contents($fullUrl, false, stream_context_create(array('http' => $http_array)));

            // Return the content
            if ($content !== false) {
                return $content;
            } // If no content, check the headers to see if it's hiding there (why? not sure, but it happens)
            else {
                $content = implode("", $http_response_header);
                //  If header is a true header, then return false, else return the content found in the header
                return (substr($content, 0, 5) == 'HTTP/') ? false : $content;
            }
        }
        return false;
    }


    private function getAuthToken()
    {
        $authType = $this->getSystemSetting('authentication_type');
        if ($authType === 'cc') {
            $authEndpoint = $this->getSystemSetting('cc_token_endpoint');
            $clientId = $this->getSystemSetting('cc_client_id');
            $clientSecret = $this->getSystemSetting('cc_client_secret');

            return $this->getClientCredentialsToken($authEndpoint, $clientId, $clientSecret);
        }
        return false;
    }

    private function getClientCredentialsToken($tokenEndpoint, $clientId, $clientSecret)
    {
        $now = time();
        if (array_key_exists('PEDIGREE_FHIR_ONTOLOGY_TOKEN_EXPIRES', $_SESSION) &&
            array_key_exists('PEDIGREE_FHIR_ONTOLOGY_TOKEN', $_SESSION)) {
            $expire = $_SESSION['PEDIGREE_FHIR_ONTOLOGY_TOKEN_EXPIRES'];
            if ($now < $expire) {
                // not expired.
                return $_SESSION['PEDIGREE_FHIR_ONTOLOGY_TOKEN'];
            }
        }

        // get the access token
        $params = array(
            'grant_type' => 'client_credentials'
        );
        $headers = ['Authorization: Basic ' . base64_encode($clientId . ':' . $clientSecret)];

        $clear = true;
        try {
            $response = $this->httpPost($tokenEndpoint, $params, 'application/x-www-form-urlencoded', $headers);
            $responseJson = json_decode($response, true);
            if (array_key_exists('access_token', $responseJson)) {
                $clear = false;
                $_SESSION['PEDIGREE_FHIR_ONTOLOGY_TOKEN'] = $responseJson['access_token'];
                if (array_key_exists('expires_in', $responseJson)) {
                    $_SESSION['PEDIGREE_FHIR_ONTOLOGY_TOKEN_EXPIRES'] = $now + ($responseJson['expires_in'] * 1000);
                } else {
                    $_SESSION['PEDIGREE_FHIR_ONTOLOGY_TOKEN_EXPIRES'] = $now + (60 * 60 * 1000);
                }
            } elseif (array_key_exists('error', $responseJson)) {
                error_log("Failed to negotiate auth token : " . $responseJson['error'] . " - " . $responseJson['error_description']);
            } else {
                error_log("Failed to negotiate auth token : " . $response);
            }
        } catch (\Exception $e) {
            $error_code = $e->getCode();
            $error_message = $e->getMessage();
            error_log("Failed to negotiate auth token : {$error_code} - {$error_message}");
        }
        if ($clear) {
            unset($_SESSION['PEDIGREE_FHIR_ONTOLOGY_TOKEN_EXPIRES']);
            unset($_SESSION['PEDIGREE_FHIR_ONTOLOGY_TOKEN']);
            return false;
        }
        return $_SESSION['PEDIGREE_FHIR_ONTOLOGY_TOKEN'];
    }
}