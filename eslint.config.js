const noJquery = require('eslint-plugin-no-jquery');

// Scoped narrowly to the jQuery DOM-injection patterns that are this
// repo's actual XSS threat model (see pedigree-editor-trunk-based-workflow-
// migration's pedigree-editor-inline-js-extraction capability, and the
// ontology-provider-security-audit precedent this mirrors) - not a general
// style linter. js/pako.min.js is excluded: a vendored third-party
// compression library, not this module's own code.
module.exports = [
  {
    ignores: ['js/pako.min.js'],
  },
  {
    files: ['js/**/*.js'],
    languageOptions: {
      globals: {
        $: 'readonly',
        jQuery: 'readonly',
        pako: 'readonly',
      },
    },
    plugins: {
      'no-jquery': noJquery,
    },
    rules: {
      'no-jquery/no-html': 'error',
      'no-jquery/no-append-html': 'error',
      'no-jquery/no-parse-html': 'error',
      'no-jquery/no-parse-html-literal': 'error',
    },
  },
];
