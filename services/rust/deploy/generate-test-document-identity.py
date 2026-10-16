#!/usr/bin/env python3
"""Create an opt-in, isolated test document identity; never configure a service.

--check-only validates the certificate plan without OpenSSL, secrets or file writes.
--generate creates a new private directory outside this checkout, never overwrites
an identity, and prints only public certificate metadata. Not for production trust.
"""
import argparse
import hashlib
import json
import os
from pathlib import Path
import secrets
import subprocess

DOCUMENT_SIGNING_OID = '1.3.6.1.5.5.7.3.36'
ROOT_EXTENSIONS = (
    'basicConstraints=critical,CA:TRUE,pathlen:0',
    'keyUsage=critical,keyCertSign,cRLSign',
)
DOCUMENT_EXTENSIONS = (
    'basicConstraints=critical,CA:FALSE',
    'keyUsage=critical,digitalSignature,nonRepudiation',
    'extendedKeyUsage=critical,' + DOCUMENT_SIGNING_OID,
    'subjectKeyIdentifier=hash',
    'authorityKeyIdentifier=keyid,issuer',
)


def certificate_plan():
    return {'test_only': True, 'rsa_bits': 2048, 'root_days': 30,
            'document_days': 14, 'root_extensions': list(ROOT_EXTENSIONS),
            'document_extensions': list(DOCUMENT_EXTENSIONS)}


def validate_plan(plan):
    extensions = dict(item.split('=', 1) for item in plan['document_extensions'])
    eku = set(extensions.get('extendedKeyUsage', '').split(','))
    if eku != {'critical', DOCUMENT_SIGNING_OID}:
        raise ValueError('Test document leaf requires critical documentSigning EKU')
    if extensions.get('basicConstraints') != 'critical,CA:FALSE':
        raise ValueError('Test document leaf must have CA=false')
    if extensions.get('keyUsage') != 'critical,digitalSignature,nonRepudiation':
        raise ValueError('Test document leaf requires signing KeyUsage')
    if plan['rsa_bits'] < 2048 or not 1 <= plan['document_days'] <= plan['root_days'] <= 30:
        raise ValueError('Invalid isolated-test key strength or certificate lifetime')
    if set(plan['root_extensions']) != set(ROOT_EXTENSIONS) or not plan['test_only']:
        raise ValueError('Isolated test CA constraints are required')


def new_output_directory(value):
    if value is None:
        raise ValueError('--generate requires --output-dir')
    output = Path(value).expanduser().resolve()
    repository = Path(__file__).resolve().parents[3]
    if output == repository or repository in output.parents:
        raise ValueError('Test identity material must stay outside the source checkout')
    if output.exists():
        raise ValueError('Output already exists; inspect it, never overwrite or regenerate blindly')
    return output


def generate(output, plan, openssl):
    def run(arguments, data=None):
        result = subprocess.run([openssl, *arguments], input=data,
                                capture_output=True, timeout=30)
        if result.returncode:
            # Never print raw diagnostics, passwords, PFX contents or key material.
            raise RuntimeError('OpenSSL step failed: ' + arguments[0])
        return result.stdout

    created = False
    previous_umask = os.umask(0o077)
    root_key, document_key = output / 'ca.key', output / 'document.key'
    try:
        output.mkdir(mode=0o700)  # Existing paths and races fail without overwriting.
        created = True
        password = secrets.token_urlsafe(40)
        (output / 'pfx-password').write_text(password + '\n')
        (output / 'pfx-password').chmod(0o400)
        root, leaf, pfx = output / 'ca.crt', output / 'document.crt', output / 'signer.p12'
        for key in (root_key, document_key):
            run(['genpkey', '-algorithm', 'RSA', '-pkeyopt',
                 'rsa_keygen_bits:' + str(plan['rsa_bits']), '-out', str(key)])
        run(['req', '-x509', '-new', '-sha256', '-key', str(root_key),
             '-days', str(plan['root_days']), '-subj',
             '/CN=Isolated PDF Test Root CA/O=Local Test Only',
             *[part for ext in plan['root_extensions'] for part in ('-addext', ext)],
             '-out', str(root)])
        csr, extension_file = output / 'document.csr', output / 'document.ext'
        run(['req', '-new', '-sha256', '-key', str(document_key), '-subj',
             '/CN=Isolated PDF Test Document Signer/O=Local Test Only', '-out', str(csr)])
        extension_file.write_text('\n'.join(plan['document_extensions']) + '\n')
        run(['x509', '-req', '-in', str(csr), '-CA', str(root), '-CAkey', str(root_key),
             '-set_serial', str(secrets.randbits(120) + 1), '-days',
             str(plan['document_days']), '-sha256', '-extfile', str(extension_file),
             '-out', str(leaf)])
        public_key = run(['pkey', '-in', str(document_key), '-pubout'])
        if public_key != run(['x509', '-in', str(leaf), '-pubkey', '-noout']):
            raise RuntimeError('Document certificate and private key do not match')
        text = run(['x509', '-in', str(leaf), '-text', '-noout']).decode()
        if ('X509v3 Extended Key Usage: critical' not in text
                or not any(value in text for value in (DOCUMENT_SIGNING_OID, 'Document Signing'))
                or 'CA:FALSE' not in text):
            raise RuntimeError('Generated leaf is missing required document certificate extensions')
        run(['verify', '-check_ss_sig', '-CAfile', str(root), str(leaf)])
        for certificate in (root, leaf):
            run(['x509', '-in', str(certificate), '-checkend', '3600', '-noout'])
        run(['pkcs12', '-export', '-inkey', str(document_key), '-in', str(leaf),
             '-certfile', str(root), '-name', 'Isolated test document signer',
             '-out', str(pfx), '-passout', 'stdin'], (password + '\n').encode())
        pfx.chmod(0o400)
        run(['pkcs12', '-info', '-noout', '-in', str(pfx), '-passin', 'stdin'],
            (password + '\n').encode())
        public_leaf = run(['pkcs12', '-in', str(pfx), '-passin', 'stdin', '-clcerts', '-nokeys'],
                          (password + '\n').encode())
        leaf_der = run(['x509', '-in', str(leaf), '-outform', 'DER'])
        if run(['x509', '-outform', 'DER'], public_leaf) != leaf_der:
            raise RuntimeError('PFX public leaf does not match the verified document certificate')
        root_der = run(['x509', '-in', str(root), '-outform', 'DER'])
        public_root = run(['pkcs12', '-in', str(pfx), '-passin', 'stdin', '-cacerts', '-nokeys'],
                          (password + '\n').encode())
        if run(['x509', '-outform', 'DER'], public_root) != root_der:
            raise RuntimeError('PFX trust chain does not match the verified test root')
        result = {'status': 'PASS_ISOLATED_TEST_DOCUMENT_IDENTITY', 'test_only': True,
                  'document_signing_eku': DOCUMENT_SIGNING_OID,
                  'document_sha256': hashlib.sha256(leaf_der).hexdigest(),
                  'root_sha256': hashlib.sha256(root_der).hexdigest(),
                  'document_certificate': run(['x509', '-in', str(leaf), '-noout',
                                               '-subject', '-issuer', '-dates']).decode().strip(),
                  'root_certificate': run(['x509', '-in', str(root), '-noout',
                                           '-subject', '-issuer', '-dates']).decode().strip(),
                  'raw_private_keys_retained': False, 'public_or_legal_trust': False,
                  'service_policy_or_database_modified': False}
        (output / 'public-identity.json').write_text(json.dumps(result, indent=2) + '\n')
        return result
    finally:
        # No reusable CA key or unencrypted leaf key is needed after packaging.
        if created:
            for key in (root_key, document_key):
                key.unlink(missing_ok=True)
        os.umask(previous_umask)


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    modes = parser.add_mutually_exclusive_group(required=True)
    modes.add_argument('--check-only', action='store_true')
    modes.add_argument('--generate', action='store_true')
    parser.add_argument('--output-dir')
    parser.add_argument('--openssl', default='openssl')
    args = parser.parse_args(argv)
    try:
        plan = certificate_plan()
        validate_plan(plan)
        if args.check_only:
            print(json.dumps({'status': 'PASS_STATIC_CERTIFICATE_PLAN',
                              'credentials_generated': False, 'files_written': False,
                              'openssl_executed': False, 'plan': plan}, indent=2))
        else:
            output = new_output_directory(args.output_dir)
            print(json.dumps(generate(output, plan, args.openssl), indent=2))
    except (ValueError, RuntimeError, OSError, subprocess.SubprocessError) as error:
        parser.exit(1, 'Test identity setup failed: ' + str(error) + '\n')


if __name__ == '__main__':
    main()
