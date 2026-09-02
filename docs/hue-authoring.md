# Creating HUE Packages

This guide accompanies the normative [HUE 1.0 specification](hue-format.md). It
is intended for developers, authors of conversion tools, and AI systems that
create a Kopfuebung from documents or structured prompts.

## Authoring workflow

1. Read the source worksheet and preserve the wording, mathematical notation,
   answers, feedback, and media needed by each question.
2. Create one Moodle XML `<question>` element per activity position.
3. Assign every logical question a UUID. Reuse that UUID when revising or
   re-exporting the same question.
4. Canonicalize each `<question>` element as specified by HUE 1.0 and calculate
   its SHA-256 fingerprint.
5. Create `manifest.json`, keeping `position`, `xml_index`, and the XML question
   order aligned.
6. Validate the JSON manifest, Moodle XML, mappings, fingerprints, and supported
   question types.
7. Create `mimetype`, `manifest.json`, and `questions.xml` at the root of a ZIP
   archive and give the archive a `.hue` extension.

Do not invent missing correct answers. If source material is ambiguous, the
authoring process should request clarification rather than produce a package
that Moodle could grade incorrectly.

## Choosing question types

Use a Moodle core question type when it expresses the task faithfully. STACK or
GeoGebra question XML may be used when the task requires those installed
plugins. A package may contain a mixture of supported question types.

Keep in mind that interactive input is not automatically printable. Authors
should phrase questions so that their essential task remains understandable on
paper when an equivalent static representation is possible.

## Activity data

Copy all reusable Kopfuebung settings into the manifest. Do not include a target
course, question category, students, attempts, results, or other data belonging
to a previous delivery of the activity.

The question order in the manifest is authoritative. Questions are not grouped
into categories by a HUE package; the importing teacher chooses the category.

## Files and rich content

Use Moodle XML's standard file representation for files belonging to question
content. Avoid links to temporary or authenticated source systems. Content MUST
not require scripts or executable files.

HTML fragments and mathematical content must follow Moodle XML rules. Preserve
the source language exactly; do not create translated variants within one HUE
package.

## AI-assisted creation

An AI system should be given:

- this repository and the HUE specification version to target;
- the source DOC, DOCX, PDF, image, or text;
- the desired activity settings if they are not present in the source;
- confirmation of the target Moodle question plugins when STACK or GeoGebra is
  required.

A suitable request is:

```text
Create a HUE 1.0 package from the attached Kopfuebung according to the HUE
specification in this repository. Preserve the source language and question
order, include correct answers, validate the manifest and Moodle XML, and return
one .hue file suitable for import into mod_kopfuebung. Ask before assuming any
missing answer or activity setting.
```

Producing JSON and XML text alone is not the same as producing a valid `.hue`
file. The final result must be packaged and validated. A future repository tool
will automate those steps; until then, package authors must use an equivalent
ZIP and schema-validation workflow.

## Reference files

- Manifest schema: [`schema/hue-manifest.schema.json`](../schema/hue-manifest.schema.json)
- Unpacked example: [`examples/hue/minimal/`](../examples/hue/minimal/)
