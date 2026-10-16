# Test document identity and deployment readiness

The Rust signer and existing Java certificate policy require a document-signing
leaf with `CA=false`, RSA of at least 2048 bits, signing KeyUsage
(`digitalSignature` or `nonRepudiation`), and **documentSigning EKU
`1.3.6.1.5.5.7.3.36`**. The chain must be valid and end at an explicitly authorized
root. A PFX that can be decrypted can still fail this policy.

The TSA has a separate identity: its leaf needs critical `timeStamping` EKU.
Do not use the document certificate or its key as the TSA identity.

## Credential-free static regression

From the repository root, run:

```sh
python3 -B services/rust/deploy/generate-test-document-identity.py --check-only
python3 -B -m unittest discover -s services/rust/deploy/tests -p 'test_*.py' -v
```

These checks do not generate credentials, invoke OpenSSL, connect to a server,
modify grants, or change service/workflow state. They cover the EKU omission,
minimum grant contract, explicit generation opt-in, and refusal to overwrite an
existing output directory. A static PASS is not an actual signing or TLS verdict.

## Explicitly authorized isolated generation

After resource admission and authorization to create isolated test credentials,
run this on the chosen test host with Python 3.9+ and OpenSSL 3:

```sh
python3 services/rust/deploy/generate-test-document-identity.py \
  --generate --output-dir /private/test-identities/new-document-identity
```

The parent must already exist. Choose a new directory outside the source checkout;
the helper refuses an existing path, including an empty one. The path above is a
placeholder, not an installation location. The helper has no server, Docker,
application, or account dependency and does not configure a service or policy.

The private directory is mode 0700. It contains an encrypted `signer.p12` and a
mode 0400 `pfx-password`, public certificates and `public-identity.json`. The helper
checks the generated EKU, certificate chain, validity, private/public key match,
PFX password, and public leaf/root in the PFX. Passwords go to OpenSSL through
stdin, never command arguments or output. Only public metadata is printed.
The CA lifetime is 30 days and the document lifetime 14 days. Raw CA and document
PEM keys are removed after generation, including on a failure after directory
creation. A partial private output directory is retained for operator review;
do not blindly rerun, overwrite, or publish it. No system trust store is changed.

Mount only the necessary PFX into the signing service read-only. Supply its
password through the established private secret mechanism. Never commit the
output, copy a private key into evidence, or infer public/legal trust from this
isolated test CA. Retain prior authorized roots needed for old test outputs.
A replacement certificate has a new fingerprint: create a coherent **new immutable
policy**, retain old policies/history, and use normal application replanning when
an old workflow has frozen the previous fingerprint. Do not edit old policy rows
or failed operations to force the new identity into them. With the raw CA key
removed, certificate reissue requires new authorized test material, not an in-place
extension change to the old certificate.

## Minimum database privileges

Use the existing service identity and review the
[grant example](../../pdf-renderer-java/deploy/mysql/pdf_execution_ledger_grants.sql.example).
Its `CREATE USER` line is a new-install template, not an instruction to create or
reset an existing account. Changes to an existing deployment require their own
authorization; the helper and static tests never execute SQL.

On `pdf_signing_operations`, column-level SELECT must include:

- `document_evidence_hold_mask`, read by claim/private-key authorization gates;
- `lease_owner`, read by result/status lease checks.

The only UPDATE column on that table remains `java_gate_version`. The identity
also needs SELECT on policies, SELECT/INSERT/UPDATE on execution rows, and INSERT
on execution events, as in the example. Do not broaden this to table-wide
operation writes or access to users, workflows, revisions, challenges or signature
image paths. The shared policy-count health probe can return 200 even when a
required operation column is denied.

## Verify before asking a user to sign

1. Check the exact runtime PFX public leaf, required extensions, key match, chain,
   authorized root pin, and validity. Match the leaf fingerprint, policy/config
   hashes, reserved size and trust bundle to the selected immutable policy.
2. With the actual service database identity, read the two columns above and use
   **EXPLAIN** on the unchanged claim-gate SQL. Do not execute a gate UPDATE or
   replay a failed operation as a diagnostic. Confirm no active/unknown execution
   before a necessary service reload.
3. Check loopback health, HMAC/Redis readiness, private TSA reachability, TSA pin,
   policy OID and a normal RFC3161 response under the authorized test trust.
   Health alone does not validate the document EKU, TSA, every database privilege,
   or browser assignee permissions.
4. Check source/appearance hashes, target field and self-only lock, input/result
   size limits, writable persistent result/spool mounts, and service resource
   limits. Preserve CSRF, HMAC, evidence holds and the durable private-key barrier.
5. The authorized user submits a fresh appearance/challenge in the selected
   workflow. Only a completed operation plus verification of the actual new PDF
   proves signing success. Independently check CMS, timestamps, imprint binding,
   pinned test chains, old signatures and incremental prefixes; confirm publication
   and browser download separately.
