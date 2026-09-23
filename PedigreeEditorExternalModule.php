<?php
/**
 * @file
 * Provides ExternalModule class for Pedigree Editor.
 */

namespace AEHRC\PedigreeEditorExternalModule;

use ExternalModules\AbstractExternalModule;

// Deployed modules aren't `composer install`-ed, so classes/ can't rely on
// the Composer autoloader (dev/test-only here, see composer.json) - require
// each class explicitly.
require_once __DIR__ . '/classes/PedigreeFieldTag.php';
require_once __DIR__ . '/classes/BranchingLogicTranslator.php';
require_once __DIR__ . '/classes/OntologyValueSetResolver.php';
require_once __DIR__ . '/classes/QuestionnaireDerivation.php';
require_once __DIR__ . '/classes/RedcapInstrumentReference.php';
require_once __DIR__ . '/classes/RedcapInstrumentSearch.php';
require_once __DIR__ . '/classes/RedcapInstrumentRowImporter.php';
require_once __DIR__ . '/classes/RedcapInstrumentGateway.php';


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
            $metadata = http_get($systemOntologyServer . '/metadata', $this->getFhirTimeout());
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
                    // httpPost() doesn't expose response headers back to its caller
                    // (the $http_response_header magic variable is only ever set in
                    // the scope of the file_get_contents() call inside httpPost()
                    // itself, and isn't even populated on the curl-backed path used
                    // whenever curl is installed), so there is nothing more specific
                    // to report here.
                    $errors .= "Failed to get Authentication Token for fhir server at '" . $authEndpoint . "'\n";
                } else {
                    // a false or unparseable response decodes to null, and
                    // array_key_exists(null) is a fatal TypeError on PHP 8
                    $responseJson = is_string($response) ? json_decode($response, true) : null;
                    if (!is_array($responseJson) || !array_key_exists('access_token', $responseJson)) {
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

        $pedigreeImportInstrument = $settings['project_pedigree_import_instrument'] ?? null;
        if ($pedigreeImportInstrument) {
            $projectId = $this->getProjectId();
            if ($projectId) {
                $isRepeating = RedcapInstrumentGateway::isRepeatingInstrument($projectId, $pedigreeImportInstrument);
                if ($isRepeating === false) {
                    $errors .= "The instrument selected for pedigree-instrument import (\"" . $pedigreeImportInstrument
                        . "\") is not configured as a repeating instrument. Enable repeating instruments for it "
                        . "(Project Setup > Enable optional modules > Repeating Instruments and Events) before selecting it here.\n";
                }
            }
        }

        if (($settings['project_pedigree_questionnaire_mode'] ?? null) === 'ADVANCED') {
            $advancedJson = $settings['project_pedigree_advanced_questionnaire'] ?? '';
            if (!$advancedJson) {
                $errors .= "Questionnaire mode is set to \"Advanced\" but no Questionnaire JSON has been supplied.\n";
            } else {
                $parsed = json_decode($advancedJson, true);
                if (!is_array($parsed)) {
                    $errors .= "The advanced Questionnaire setting is not valid JSON.\n";
                } elseif (($parsed['resourceType'] ?? null) !== 'Questionnaire') {
                    $errors .= "The advanced Questionnaire setting must be a FHIR Questionnaire (resourceType: \"Questionnaire\").\n";
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
            $customTerminology['disorderSystem'] = $this->getProjectSetting('project_disorder_system', $project_id);
            $customTerminology['disorderValueset'] = $this->getProjectSetting('project_disorder_valueset', $project_id);
            $customTerminology['disorderRegex'] = $this->getProjectSetting('project_disorder_regex', $project_id);
            $customTerminology['phenotypeSystem'] = $this->getProjectSetting('project_phenotype_system', $project_id);
            $customTerminology['phenotypeValueset'] = $this->getProjectSetting('project_phenotype_valueset', $project_id);
            $customTerminology['phenotypeRegex'] = $this->getProjectSetting('project_phenotype_regex', $project_id);
            $customTerminology['geneSystem'] = $this->getProjectSetting('project_gene_system', $project_id);
            $customTerminology['geneValueset'] = $this->getProjectSetting('project_gene_valueset', $project_id);
            $customTerminology['geneRegex'] = $this->getProjectSetting('project_gene_regex', $project_id);
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
                // Negative lookahead (?![A-Za-z0-9_]) stops this from matching
                // @PEDIGREE_FIELD (a distinct tag, for repeating-instrument
                // fields - see D3) or any other future @PEDIGREE_XXX tag.
                // Terminology mode is always the project/system default
                // (project_def_terminology/system_def_terminology) - a
                // per-field HPO/SCT override used to be supported here but
                // was removed as redundant with that project setting. For a
                // repeating-instrument-derived Questionnaire, a legend-mapped
                // field's terminology is pinned down per-field by whatever
                // ontology provider is bound to it (D5) regardless of this
                // setting anyway - the override was only ever meaningful for
                // the built-in default Questionnaire's hardcoded legend items.
                if (preg_match(
                    '/@PEDIGREE(?![A-Za-z0-9_])(=(HIDE_TEXT|SHOW_TEXT|NEVER_COMPRESS|COMPRESS_LARGE|ALWAYS_COMPRESS)(,(HIDE_TEXT|SHOW_TEXT|NEVER_COMPRESS|COMPRESS_LARGE|ALWAYS_COMPRESS))?)?/',
                    $field_attributes['field_annotation'], $matches) === 1){

                    $hide = $hideText;
                    $fCompress = $compression;
                    $option1 = $matches[2] ?? '';
                    $option2 = $matches[4] ?? '';
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
                    $row['mode'] = $defTerminology;
                    $row['hideText'] = $hide;
                    $row['compress'] = $fCompress;
                    $fieldsOfInterest[] = $row;
                }
            }
        }


        if (empty($fieldsOfInterest)) {
            return;
        }
        

        // useApiEndpoint=false (routes through ExternalModules/index.php, not api/?type=module):
        // the api/ endpoint's dispatcher doesn't support GET at all ("requested method is
        // not implemented") and unconditionally requires a redcap_csrf_token for POST
        // regardless of no-auth-pages status - confirmed live. TerminologyService.php is
        // GET-only (see its own doc comment), so it needs the same routing already used for
        // PedigreeInstrumentService.php.
        $ontologyServer = urlencode($this->getUrl('TerminologyService.php', false, false));

        $systemFormat = $this->getSystemSetting('system_format');
        $projectFormat = $this->getProjectSetting('project_format', $project_id);
        $format = ($projectFormat) ?: $systemFormat;

        $systemAllowEdit = $this->getSystemSetting('system_allow_edit');
        $projectAllowEdit = $this->getProjectSetting('project__allow_edit', $project_id);
        $allowEdit = ($projectAllowEdit) ?: $systemAllowEdit;

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

        // isPedigreeImportConfigured() (an import instrument is set) and mode === 'ADVANCED'
        // are independent settings - an admin can select "Advanced" and supply a custom
        // Questionnaire without configuring (or while clearing) the import instrument, since
        // they only want a custom form, not record-linking. getPedigreeDerivedQuestionnaire()
        // already returns the advanced Questionnaire regardless of whether an instrument is
        // configured, so pedigreeQuestionnaireUrl must be sent whenever either is true -
        // gating it on isPedigreeImportConfigured() alone silently dropped the admin's custom
        // Questionnaire (falling back to open-pedigree's built-in default) whenever only mode
        // was set to ADVANCED.
        $pedigreeImportConfigured = $this->isPedigreeImportConfigured($project_id);
        $pedigreeQuestionnaireConfigured = $pedigreeImportConfigured || $this->getPedigreeQuestionnaireMode($project_id) === 'ADVANCED';
        if ($pedigreeQuestionnaireConfigured) {
            // useApiEndpoint=false (routes through ExternalModules/index.php, not api/?type=module):
            // open-pedigree's questionnaireUrl option fetches via a plain GET, and the api/
            // endpoint's dispatcher only accepts POST for module passthrough requests.
            // No CSRF token needed here: PedigreeInstrumentService.php is GET-only
            // (every action it supports only reads data), and REDCap only requires
            // redcap_csrf_token for POST requests to module pages - a token embedded
            // in this URL would otherwise end up in server access logs and browser
            // history.
            $pedigreeServiceUrl = $this->getUrl('PedigreeInstrumentService.php', false, false);
            $pedigreeImportParams = '&pedigreeQuestionnaireUrl=' . urlencode($pedigreeServiceUrl . '&type=questionnaire');
            // Search/link/import (RedcapInstrumentPatientProvider) genuinely needs a configured
            // instrument to search/import from - unlike the Questionnaire URL above, this is not
            // meaningful in ADVANCED mode alone.
            if ($pedigreeImportConfigured) {
                $pedigreeImportParams .= '&pedigreeImportEndpoint=' . urlencode($pedigreeServiceUrl);
                // Evaluated here, from REDCap's own $record for this page, rather than via
                // a separate AJAX check taking a client-supplied record name: the editor
                // popup is (re)opened from this page, and REDCap reloads this page on every
                // save, so a freshly-rendered value is never stale for the page it's on.
                // It only gates UI (RedcapInstrumentPatientProvider's link/edit/create
                // actions) - nothing server-side writes on the strength of it.
                $pedigreeImportParams .= '&pedigreeRecordExists=' . ($this->recordExists($project_id, $record) ? '1' : '0');
            }
            $hpoEditorPage = $hpoEditorPage . $pedigreeImportParams;
            $sctEditorPage = $sctEditorPage . $pedigreeImportParams;
            $customEditorPage = $customEditorPage . $pedigreeImportParams;
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
        
        $editorUrlOrigin = '';
        if ($transportType == 'message'){
            $urlData = parse_url($hpoEditorUrl);
            $scheme   = isset($urlData['scheme']) ? $urlData['scheme'] . '://' : '';
            $host     = isset($urlData['host']) ? $urlData['host'] : '';
            $port     = isset($urlData['port']) ? ':' . $urlData['port'] : '';
            $editorUrlOrigin = $scheme . $host . $port;
        }

        // Config previously PHP-interpolated into an inline script tag's body is
        // now passed as data-* attributes read by js/pedigree-editor-config.js (see
        // pedigree-editor-inline-js-extraction) - each value HTML-attribute-escaped,
        // not JS-string-escaped, since the browser decodes the attribute before
        // js/pedigree-editor-config.js ever sees the raw string.
        $configDataAttrs = [
            'data-fields-of-interest' => $fieldsOfInterestJson,
            'data-hpo-editor-page' => $hpoEditorUrl,
            'data-sct-editor-page' => $sctEditorUrl,
            'data-custom-editor-page' => $customEditorUrl,
            'data-format' => $format,
            'data-allow-edit' => $allowEdit,
            'data-transport-type' => $transportType,
            'data-editor-page-origin' => $editorUrlOrigin,
        ];
        $configDataAttrsHtml = '';
        foreach ($configDataAttrs as $attrName => $attrValue) {
            // $format/$allowEdit can be null when neither the project nor system
            // setting is configured - htmlspecialchars(null) is deprecated on PHP 8.1+.
            $configDataAttrsHtml .= ' ' . $attrName . '="' . htmlspecialchars((string)$attrValue, ENT_QUOTES) . '"';
        }
        $configScriptSrc = htmlspecialchars($this->getUrl('js/pedigree-editor-config.js'), ENT_QUOTES);

        $dialog = <<<EOD

<script src="{$configScriptSrc}"{$configDataAttrsHtml}></script>

<template id="__pedigree_empty_svg">
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
</template>

<template id="__pedigree_with_data_svg">
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
</template>

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

    // Bounds every outbound call to the ontology/FHIR server, rather than
    // relying on http_get()/http_post()'s unbounded default ($timeout=null).
    private function getFhirTimeout()
    {
        $timeout = $this->getSystemSetting('system_ontology_timeout');
        return (is_numeric($timeout) && $timeout > 0) ? (int)$timeout : 10;
    }

    private function getFhirServerUri()
    {
        $ontologyServer = $this->getSystemSetting('system_ontology_server');
        if (!$ontologyServer){
            $ontologyServer = 'https://tx.ontoserver.csiro.au/fhir'; // default
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

    /**
     * Derives the effective Questionnaire from the configured repeating
     * instrument (tasks 2-5) — backs `questionnaireUrl` fetched by
     * `open-pedigree`'s own `_loadQuestionnaireFromUrl()`. Without this,
     * the derivation engine and `RedcapInstrumentPatientProvider` would
     * have no derived fields for a linked-row import to actually land on;
     * the node menu would still show the built-in default Questionnaire.
     *
     * @return array|null The Questionnaire array, or null if no instrument is configured.
     */
    public function getPedigreeDerivedQuestionnaire($project_id)
    {
        $mode = $this->getPedigreeQuestionnaireMode($project_id);

        if ($mode === 'ADVANCED') {
            return $this->getPedigreeAdvancedQuestionnaire($project_id);
        }

        $instrument = $this->getPedigreeImportInstrument($project_id);
        if (!$instrument) {
            return null;
        }
        $dataDictionary = RedcapInstrumentGateway::fetchDataDictionary($project_id, $instrument);
        $answerValueSets = RedcapInstrumentGateway::resolveAnswerValueSets($project_id, array_keys($dataDictionary));

        if ($mode === 'DEFAULT_PLUS_TAGS') {
            $base = $this->loadDefaultQuestionnaire();
            if ($base === null) {
                error_log('[redcap_pedigree_editor] Could not load the built-in default Questionnaire for project ' . $project_id);
                return null;
            }
            $result = QuestionnaireDerivation::deriveWithBaseQuestionnaire($base, $dataDictionary, $instrument, $answerValueSets);
        } else {
            $result = QuestionnaireDerivation::derive($dataDictionary, $instrument, $answerValueSets);
        }

        foreach ($result['warnings'] as $warning) {
            error_log('[redcap_pedigree_editor] Questionnaire derivation (project ' . $project_id . '): ' . $warning);
        }

        return $result['questionnaire'];
    }

    /**
     * @return string One of `TAGS_ONLY` (default) / `DEFAULT_PLUS_TAGS` / `ADVANCED`.
     */
    private function getPedigreeQuestionnaireMode($project_id)
    {
        $mode = $this->getProjectSetting('project_pedigree_questionnaire_mode', $project_id);
        return in_array($mode, ['TAGS_ONLY', 'DEFAULT_PLUS_TAGS', 'ADVANCED'], true) ? $mode : 'TAGS_ONLY';
    }

    /**
     * @return array|null The admin-supplied Questionnaire (mode "advanced"),
     *   or null if unset/invalid.
     */
    private function getPedigreeAdvancedQuestionnaire($project_id)
    {
        $raw = $this->getProjectSetting('project_pedigree_advanced_questionnaire', $project_id);
        if (!$raw) {
            return null;
        }
        $parsed = json_decode($raw, true);
        if (!is_array($parsed) || ($parsed['resourceType'] ?? null) !== 'Questionnaire') {
            error_log('[redcap_pedigree_editor] project_pedigree_advanced_questionnaire (project ' . $project_id . ') is not valid Questionnaire JSON');
            return null;
        }
        return $parsed;
    }

    /**
     * @return array|null `open-pedigree`'s built-in default Questionnaire
     *   (mode "default + tags"), or null if the embedded copy is missing.
     */
    private function loadDefaultQuestionnaire()
    {
        $path = __DIR__ . '/open-pedigree/dist/defaultQuestionnaire.json';
        if (!file_exists($path)) {
            return null;
        }
        $parsed = json_decode(file_get_contents($path), true);
        return is_array($parsed) ? $parsed : null;
    }

    /**
     * @return string|null The configured instrument name, or null if the
     *   project hasn't configured pedigree-instrument import.
     */
    private function getPedigreeImportInstrument($project_id)
    {
        $instrument = $this->getProjectSetting('project_pedigree_import_instrument', $project_id);
        return $instrument ?: null;
    }

    /**
     * @return string[] Field names configured to search/display a row by.
     */
    private function getPedigreeImportSearchFields($project_id)
    {
        $fields = $this->getProjectSetting('project_pedigree_import_search_fields', $project_id);
        return is_array($fields) ? array_values(array_filter($fields)) : [];
    }

    public function isPedigreeImportConfigured($project_id)
    {
        return $this->getPedigreeImportInstrument($project_id) !== null;
    }

    /**
     * Whether the record has ever been saved: REDCap has no separate
     * "record" row - a record exists only once at least one field value
     * for it is in the data table, on any instrument/event. A brand-new
     * record's data-entry form (auto-numbered or custom-numbered alike)
     * already carries its prospective record name in `$record`, and a new
     * public survey response carries none, so both have to be checked
     * against the data table rather than inferred from `$record` alone.
     *
     * Deliberately `REDCap::getDataTable()`, not a hardcoded `redcap_data`:
     * REDCap 14+ spreads projects across `redcap_data`..`redcap_dataN`.
     */
    private function recordExists($project_id, $record)
    {
        if ($record === null || $record === '') {
            return false;
        }
        $dataTable = \REDCap::getDataTable($project_id);
        $result = $this->query(
            "SELECT 1 FROM $dataTable WHERE project_id = ? AND record = ? LIMIT 1",
            [$project_id, (string) $record]
        );
        // fetch_row() is null when no row, but StatementResult can also hand back false.
        return !empty($result->fetch_row());
    }

    /**
     * @return string|int|null The calling user's Data Access Group
     *   (`group_id`), or null if they aren't DAG-restricted. Must be passed
     *   into every `RedcapInstrumentGateway::fetchInstrumentRows()` call so
     *   a DAG-assigned user's search/import results are restricted to their
     *   own group, same as every other REDCap data-export path.
     */
    private function getCurrentUserGroupId($project_id)
    {
        $rights = $this->getUser()->getRights($project_id);
        $groupId = $rights['group_id'] ?? null;
        return ($groupId !== null && $groupId !== '') ? $groupId : null;
    }

    /**
     * Searches the configured repeating instrument's rows (task 6.2/6.4 —
     * backs `RedcapInstrumentPatientProvider.openPicker`'s AJAX call).
     *
     * @param string[]|null $allowedGenders Gender codes ('M'/'F'/'U') to
     *   restrict results to, or null for no restriction - applied *before*
     *   the result limit (see `RedcapInstrumentSearch::search()`), so an
     *   incompatible-gender row can never crowd a compatible one out of the
     *   returned set.
     * @return array List of `['record', 'instance', 'display', 'ref']`, plus
     *   `'gender'` per match when the instrument has a valid
     *   `mapsTo="gender"` field - see `RedcapInstrumentSearch::search()`.
     */
    public function searchPedigreeInstrumentRows($project_id, $query, $allowedGenders = null)
    {
        $instrument = $this->getPedigreeImportInstrument($project_id);
        if (!$instrument) {
            return [];
        }
        $dataDictionary = RedcapInstrumentGateway::fetchDataDictionary($project_id, $instrument);
        $rows = RedcapInstrumentGateway::fetchInstrumentRows($project_id, array_keys($dataDictionary), $this->getCurrentUserGroupId($project_id));
        $searchFields = $this->getPedigreeImportSearchFields($project_id);
        $genderField = $this->findMapsToFieldName($dataDictionary, 'gender');
        return RedcapInstrumentSearch::search($rows, $searchFields, (string) $query, 20, $genderField, $allowedGenders);
    }

    /**
     * @return string|null The REDCap field name whose `@PEDIGREE_FIELD`
     *   tag has a validly-typed `mapsTo="$target"`, or null if none.
     */
    private function findMapsToFieldName($dataDictionary, $target)
    {
        foreach (QuestionnaireDerivation::resolveTaggedFields($dataDictionary) as $resolved) {
            if ($resolved['mapsTo'] === $target) {
                return $resolved['redcapField'];
            }
        }
        return null;
    }

    /**
     * Fetches one linked row's `@PEDIGREE_FIELD`-tagged answers as a
     * `linkId`-keyed bag (task 6.5 — backs
     * `RedcapInstrumentPatientProvider.openEditor`'s AJAX call).
     *
     * @return array{linkId: string, value: mixed}[]
     */
    public function getPedigreeInstrumentRowAnswers($project_id, $record, $instance)
    {
        $row = $this->findPedigreeInstrumentRow($project_id, $record, $instance, $dataDictionary);
        if ($row === null) {
            return [];
        }
        $instrument = $this->getPedigreeImportInstrument($project_id);

        if ($this->getPedigreeQuestionnaireMode($project_id) === 'ADVANCED') {
            $advanced = $this->getPedigreeAdvancedQuestionnaire($project_id);
            if ($advanced === null) {
                return [];
            }
            $resolvedFields = QuestionnaireDerivation::resolveFieldsFromQuestionnaire($advanced, $instrument, $dataDictionary);
        } else {
            $answerValueSets = RedcapInstrumentGateway::resolveAnswerValueSets($project_id, array_keys($dataDictionary));
            $resolvedFields = QuestionnaireDerivation::resolveTaggedFields($dataDictionary, $answerValueSets);
        }

        return RedcapInstrumentRowImporter::buildAnswers($row['fields'], $resolvedFields);
    }

    /**
     * Resolves a linked row's search/display name. Kept separate from
     * {@see getPedigreeInstrumentRowAnswers()} since which tagged field (if
     * any) represents "the name" is project-specific, whereas the
     * configured search fields already exist for exactly this purpose.
     *
     * Currently unused: this backed `RedcapInstrumentPatientProvider`'s old
     * `lookupPatient` method, which `AbstractRecordLinkProvider` has no
     * equivalent for (see pedigree-editor-redcap-extension-extraction) - the
     * `type=lookup` AJAX endpoint in `PedigreeInstrumentService.php` that
     * calls this is consequently also dead. Left in place rather than
     * removed, in case a future "show linked record name" feature on the
     * Linked Record tab wants it.
     *
     * @return string|null
     */
    public function getPedigreeInstrumentRowDisplayName($project_id, $record, $instance)
    {
        $row = $this->findPedigreeInstrumentRow($project_id, $record, $instance, $dataDictionary);
        if ($row === null) {
            return null;
        }
        $searchFields = $this->getPedigreeImportSearchFields($project_id);
        $matches = RedcapInstrumentSearch::search([$row], $searchFields, '');
        return $matches[0]['display'] ?? null;
    }

    /**
     * @param array|null $dataDictionary Out-param: the resolved instrument's
     *   Data Dictionary, populated whenever a row is found (or the
     *   instrument is configured at all, even if the row itself is not
     *   found) so callers can reuse it without re-fetching.
     */
    private function findPedigreeInstrumentRow($project_id, $record, $instance, &$dataDictionary)
    {
        $dataDictionary = [];
        $instrument = $this->getPedigreeImportInstrument($project_id);
        if (!$instrument) {
            return null;
        }
        $dataDictionary = RedcapInstrumentGateway::fetchDataDictionary($project_id, $instrument);
        $rows = RedcapInstrumentGateway::fetchInstrumentRows(
            $project_id,
            array_keys($dataDictionary),
            $this->getCurrentUserGroupId($project_id),
            [(string) $record]
        );

        foreach ($rows as $row) {
            if ($row['record'] === (string) $record && $row['instance'] === (int) $instance) {
                return $row;
            }
        }
        return null;
    }


    private function httpGet($fullUrl, $headers)
    {
        // if curl isn't install the default version of http_get in init_functions doesn't include the headers.
        if (function_exists('curl_init') || empty($headers)) {
            return http_get($fullUrl, $this->getFhirTimeout(), '', $headers, null);
        }
        if (ini_get('allow_url_fopen')) {
            // Set http array for file_get_contents
            $headerText = '';
            foreach ($headers as $hvalue) {
                $headerText .= $hvalue . "\r\n";
            }
            $http_array = array('method' => 'GET', 'header' => $headerText, 'timeout' => $this->getFhirTimeout());
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
            return http_post($fullUrl, $postData, $this->getFhirTimeout(), $contentType, '', $headers);
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
                'content' => $param_string,
                'timeout' => $this->getFhirTimeout()
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
            // a false or unparseable response decodes to null, and array_key_exists(null)
            // is a fatal TypeError on PHP 8
            $responseJson = is_string($response) ? json_decode($response, true) : null;
            if (!is_array($responseJson)) {
                error_log("Failed to negotiate auth token : no parseable response from " . $tokenEndpoint);
            } elseif (array_key_exists('access_token', $responseJson)) {
                $clear = false;
                $_SESSION['PEDIGREE_FHIR_ONTOLOGY_TOKEN'] = $responseJson['access_token'];
                // expires_in is SECONDS (RFC 6749) and $now is seconds - the previous
                // * 1000 cached a 3600s token for roughly 41 days. Renew early by
                // margin = min(60, floor(lifetime / 2)): a minute early for normal
                // lifetimes, halfway through for very short ones, and never an expiry
                // beyond the real one.
                $lifetime = array_key_exists('expires_in', $responseJson)
                    ? (int)$responseJson['expires_in']
                    : 3600;
                if ($lifetime < 1) {
                    $lifetime = 1;
                }
                $margin = (int)min(60, floor($lifetime / 2));
                $_SESSION['PEDIGREE_FHIR_ONTOLOGY_TOKEN_EXPIRES'] = $now + $lifetime - $margin;
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