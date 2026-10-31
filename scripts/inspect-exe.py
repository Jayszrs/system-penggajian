"""Record reproducible native-code evidence without executing the legacy EXE.

Usage: python scripts/inspect-exe.py --exe <gaji.exe> --output <private report.json>
The offsets below are specific to the SHA-256-identified supplied executable.
"""
import argparse
import hashlib
import json
import pathlib
import struct

parser = argparse.ArgumentParser()
parser.add_argument('--exe', type=pathlib.Path, default=pathlib.Path.home() / 'Downloads' / 'gaji.exe')
parser.add_argument('--output', type=pathlib.Path, default=pathlib.Path('storage/legacy/exe-analysis.json'))
args = parser.parse_args()
data = args.exe.read_bytes()
digest = hashlib.sha256(data).hexdigest()
expected = 'c67af47aec7f89253c833a0cea0d0fa342c82608271017ed0111c2589222e926'
if digest != expected:
    raise SystemExit('Executable differs from the analysed build. Offset-based mappings must be reverified.')
image_base = 0x400000
objects = []
for index in range(25):
    record = 0x16054 + index * 48
    name_ptr = struct.unpack_from('<I', data, record + 24)[0] - image_base
    name = data[name_ptr:name_ptr + 100].split(b'\0')[0].decode('ascii')
    objects.append({'name': name, 'descriptor_offset': hex(record), 'kind': 'module' if name == 'Module1' else 'form'})
handlers = {
    'employee_defaults': (0x49BAA0, 0x49E280),
    'daily_and_consumption': (0x4A3130, 0x4A3C60),
    'absence_from_izin': (0x4A3E90, 0x4A4780),
    'absence_from_sick': (0x4A4780, 0x4A50E0),
    'overtime_lembur': (0x4A1740, 0x4A1B30),
    'infal': (0x4A1B30, 0x4A1F80),
    'overtime': (0x4A2510, 0x4A2960),
    'income_total': (0x4A1F80, 0x4A2510),
    'deductions_and_net': (0x4A50E0, 0x4A5760),
    'insert_getters_and_values': (0x492D38, 0x493DC0),
}
report = {
    'sha256': digest,
    'format': 'PE32 x86 / Visual Basic 6 native code',
    'vb_header_offset': '0x2e94',
    'executed': False,
    'objects': objects,
    'handlers': {name: {'start_va': hex(start), 'end_va': hex(end),
                       'code_sha256': hashlib.sha256(data[start-image_base:end-image_base]).hexdigest()}
                 for name, (start, end) in handlers.items()},
    'documentation': 'docs/PEMETAAN_LEGACY.md',
    'note': 'Function ranges and bindings were traced by disassembly. This report fingerprints that evidence; it does not claim full source-code recovery.',
}
args.output.parent.mkdir(parents=True, exist_ok=True)
args.output.write_text(json.dumps(report, indent=2), encoding='utf-8')
print(f'Inspected {len(objects)-1} forms and one module; {len(handlers)} native handler ranges fingerprinted.')
