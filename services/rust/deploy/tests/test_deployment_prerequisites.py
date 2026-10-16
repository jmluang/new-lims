"""Credential-free regression for deployment prerequisites; no OpenSSL or network."""
import contextlib
import copy
import importlib.util
import io
import json
from pathlib import Path
import re
import tempfile
import unittest
from unittest.mock import patch

DEPLOY = Path(__file__).resolve().parents[1]
ROOT = DEPLOY.parents[2]
SCRIPT = DEPLOY / 'generate-test-document-identity.py'
spec = importlib.util.spec_from_file_location('test_document_identity', SCRIPT)
identity = importlib.util.module_from_spec(spec)
spec.loader.exec_module(identity)


class TestDocumentIdentityPlan(unittest.TestCase):
    def test_missing_document_signing_eku_is_rejected(self):
        plan = identity.certificate_plan()
        plan['document_extensions'] = [value for value in plan['document_extensions']
                                       if not value.startswith('extendedKeyUsage=')]
        with self.assertRaisesRegex(ValueError, 'documentSigning'):
            identity.validate_plan(plan)

    def test_tsa_eku_cannot_replace_document_signing_eku(self):
        plan = identity.certificate_plan()
        plan['document_extensions'] = [
            'extendedKeyUsage=critical,timeStamping' if value.startswith('extendedKeyUsage=')
            else value for value in plan['document_extensions']]
        with self.assertRaisesRegex(ValueError, 'documentSigning'):
            identity.validate_plan(plan)

    def test_ca_leaf_and_weak_key_are_rejected(self):
        original = identity.certificate_plan()
        for value in ('basicConstraints=critical,CA:TRUE', 'basicConstraints=CA:FALSE'):
            plan = copy.deepcopy(original)
            plan['document_extensions'][0] = value
            with self.assertRaisesRegex(ValueError, 'CA=false'):
                identity.validate_plan(plan)
        plan = copy.deepcopy(original)
        plan['rsa_bits'] = 1024
        with self.assertRaisesRegex(ValueError, 'strength'):
            identity.validate_plan(plan)

    def test_check_only_has_no_secret_process_or_filesystem_side_effect(self):
        output = io.StringIO()
        with patch.object(identity.subprocess, 'run', side_effect=AssertionError('process')), \
             patch.object(identity.secrets, 'token_urlsafe', side_effect=AssertionError('secret')), \
             patch.object(identity.secrets, 'randbits', side_effect=AssertionError('serial')), \
             patch.object(identity.os, 'umask', side_effect=AssertionError('umask')), \
             patch.object(Path, 'mkdir', side_effect=AssertionError('mkdir')), \
             patch.object(Path, 'open', side_effect=AssertionError('file access')), \
             contextlib.redirect_stdout(output):
            identity.main(['--check-only', '--openssl', '/not-an-installed-openssl'])
        result = json.loads(output.getvalue())
        self.assertEqual(result['status'], 'PASS_STATIC_CERTIFICATE_PLAN')
        self.assertFalse(result['credentials_generated'])
        self.assertFalse(result['files_written'])
        self.assertFalse(result['openssl_executed'])
        identity.validate_plan(result['plan'])

    def test_generation_needs_explicit_mode_and_output_directory(self):
        with contextlib.redirect_stderr(io.StringIO()), self.assertRaises(SystemExit) as error:
            identity.main([])
        self.assertEqual(error.exception.code, 2)
        with self.assertRaisesRegex(ValueError, 'output-dir'):
            identity.new_output_directory(None)

    def test_existing_output_and_source_checkout_are_rejected(self):
        with tempfile.TemporaryDirectory() as directory:
            with self.assertRaisesRegex(ValueError, 'already exists'):
                identity.new_output_directory(directory)
        with self.assertRaisesRegex(ValueError, 'outside the source checkout'):
            identity.new_output_directory(ROOT / 'not-a-real-test-identity')

    def test_raced_existing_directory_does_not_delete_existing_files(self):
        # Simulate a path created between validation and mkdir. No real credential.
        with tempfile.TemporaryDirectory() as directory:
            output = Path(directory)
            sentinel = output / 'ca.key'
            sentinel.write_text('not-a-private-key: preserve this existing file')
            with patch.object(identity.subprocess, 'run', side_effect=AssertionError('process')), \
                 patch.object(identity.secrets, 'token_urlsafe', side_effect=AssertionError('secret')):
                with self.assertRaises(FileExistsError):
                    identity.generate(output, identity.certificate_plan(), 'not-openssl')
            self.assertEqual(sentinel.read_text(), 'not-a-private-key: preserve this existing file')


class TestMinimumDeploymentContract(unittest.TestCase):
    def test_minimum_grant_covers_hold_and_lease_without_operation_write_expansion(self):
        sql = (ROOT / 'services/pdf-renderer-java/deploy/mysql/'
               'pdf_execution_ledger_grants.sql.example').read_text()
        columns = re.search(r'GRANT SELECT\s*\((.*?)\)\s*ON', sql, re.S).group(1)
        selected = {name.strip() for name in columns.split(',')}
        self.assertTrue({'document_evidence_hold_mask', 'lease_owner', 'lease_epoch',
                         'lease_expires_at', 'java_gate_version', 'operation_uuid'} <= selected)
        updated = re.search(r'GRANT UPDATE\s*\((.*?)\)\s*ON', sql, re.S).group(1)
        self.assertEqual({name.strip() for name in updated.split(',')}, {'java_gate_version'})
        self.assertNotRegex(sql, r'GRANT\s+(?:ALL|DELETE)\b')
        self.assertNotRegex(sql, r'GRANT\s+SELECT\s+ON\s+`new-lims`\.pdf_signing_operations')

    def test_operator_guide_explains_eku_and_static_check_boundary(self):
        guide = (ROOT / 'services/rust/docs/TEST-IDENTITY-AND-READINESS.md').read_text()
        for required in (identity.DOCUMENT_SIGNING_OID, '--check-only', '--generate',
                         'document_evidence_hold_mask', 'lease_owner', 'EXPLAIN',
                         'new immutable', 'health', 'timeStamping'):
            self.assertIn(required, guide)

    def test_generator_has_no_site_or_account_wiring(self):
        source = SCRIPT.read_text()
        for old_site_value in ('/Users/', '/home/', 'docker exec', 'ssh ', '.env'):
            self.assertNotIn(old_site_value, source)
        self.assertNotRegex(source, r'\b10(?:\.\d{1,3}){3}\b')
        self.assertNotRegex(source, r'https?://')
        self.assertNotIn('-----BEGIN PRIVATE KEY-----', source)
        self.assertNotIn('-----BEGIN RSA PRIVATE KEY-----', source)


if __name__ == '__main__':
    unittest.main()
