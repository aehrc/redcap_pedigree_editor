#!/usr/bin/env node
// Rewrites open-pedigree's FHIR URLs from the retired github.com/aehrc/open-pedigree/ base to the
// published one (fhirUrls.ts), in each file named on the command line: plain, JSON-escaped (\/)
// and XML/HTML-escaped forms. Identifier systems (?externalID) and plain repository links are left
// alone. Also turns the `adopted` extension's old valueBoolean true into valueCode "in". Meant for
// open-pedigree's stored JSON (plain, JSON-escaped or XML-escaped, as hosts store it), not FHIR XML
// or JavaScript source; it moves every name under the retired base, published or not.
// redcap_pedigree_editor's copy also rewrites inside compressed values ('GZ:' + base64 gzip, as
// the module stores a large pedigree), and re-compresses them.
// Usage: node scripts/rewrite-fhir-urls.js <file>...
const fs = require('fs');
const zlib = require('zlib');

const OLD = 'https://github.com/aehrc/open-pedigree/';
const NEW = 'https://aehrc.github.io/open-pedigree/fhir/';

function rewrite(text) {
  let out = text
    .replace(/https:\/\/github\.com\/aehrc\/open-pedigree\/(questionnaire-[a-z-]+)/g, NEW + 'StructureDefinition/$1')
    .replace(/https:\/\/github\.com\/aehrc\/open-pedigree\/((?:StructureDefinition|Questionnaire)\/)/g, NEW + '$1')
    // JSON-escaped slashes
    .replace(/https:\\\/\\\/github\.com\\\/aehrc\\\/open-pedigree\\\/(questionnaire-[a-z-]+)/g,
      NEW.replace(/\//g, '\\/') + 'StructureDefinition\\/$1')
    .replace(/https:\\\/\\\/github\.com\\\/aehrc\\\/open-pedigree\\\/((?:StructureDefinition|Questionnaire)\\\/)/g,
      NEW.replace(/\//g, '\\/') + '$1');
  // The adopted extension's value: true -> "in", in an object that names the adopted url, whichever
  // order its keys are in, in any quoting style (plain, JSON-escaped, XML-escaped).
  out = out.replace(/\{[^{}]*\}/g, (object) => {
    // (open-pedigree's own adopted extension, by then on the published base)
    if (!/aehrc\.github\.io\\?\/open-pedigree\\?\/fhir\\?\/StructureDefinition\\?\/adopted(?:&quot;|\\?")/.test(object)) {
      return object;
    }
    return object.replace(/valueBoolean((?:&quot;|\\?")\s*:\s*)true/, (m, sep) => {
      const quote = sep.startsWith('&quot;') ? '&quot;' : sep.startsWith('\\"') ? '\\"' : '"';
      return 'valueCode' + sep + quote + 'in' + quote;
    });
  });
  return out;
}

// rewrite(), and inside each compressed value too.
function rewriteAll(text) {
  return rewrite(text).replace(/GZ:([A-Za-z0-9+/]+=*)/g, (whole, base64) => {
    let inner;
    try {
      inner = zlib.gunzipSync(Buffer.from(base64, 'base64')).toString('utf8');
    } catch (e) {
      return whole;
    }
    const rewritten = rewrite(inner);
    return rewritten === inner ? whole : 'GZ:' + zlib.gzipSync(Buffer.from(rewritten, 'utf8')).toString('base64');
  });
}

if (require.main === module) {
  for (const file of process.argv.slice(2)) {
    const before = fs.readFileSync(file, 'utf8');
    const after = rewriteAll(before);
    if (after !== before) {
      fs.writeFileSync(file, after);
      console.log('rewrote ' + file);
    }
  }
}
module.exports = { rewrite, rewriteAll, OLD, NEW };
