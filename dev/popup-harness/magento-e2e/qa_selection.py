#!/usr/bin/env python3
"""LOCAL TEST ONLY (BNPL-1664). Runs the QA selection of pf_e2e.py scenarios against the LOCAL MOCK: local Magento
(docker-magento), the Aplazo API replaced by stub-module/ and Aplazo's window served by mock_https.py. Nothing reaches
Aplazo, STG or any real payment. See QA_SELECTION.md.

  MAGENTO_DIR=~/Developer/Aplazo/magento248 python3 -I qa_selection.py [--no-setup] [<key> ...]

It reuses pf_e2e.py as is (same scenarios, same Browser, same pf.jsonl evidence) and only adds: prerequisite checks,
one verdict per scenario (PASS / FAIL / ERROR / NOT_RUN) and a summary file. Exit code 0 only when every selected
scenario passed.
"""
import json, os, ssl, subprocess, sys, time, urllib.request

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)

LABEL = 'LOCAL MOCK ONLY: local Magento + stub-module + mock_https.py; no Aplazo API, no STG, no real payment'

# key: (what it covers, pf_e2e.TESTS name, name it records in pf.jsonl)
SELECTION = {
    'start_no_order': ('Start (two tabs at once) creates one attempt and one createLoan; no order, no reservation',
                       'double_start', 'double_start_two_tabs'),
    'close_unpaid': ('Window closed without paying: no order or reservation, cart kept; reopening reuses attempt and '
                     'loan (no second createLoan)', 'close_unpaid', 'close_unpaid_and_reuse'),
    'uncertain_start': ('createLoan fails (uncertain); the same click again gets "uncertain" and no other createLoan',
                        'us_uncertain_reclick', 'uncertain_start_uncertain_reclick'),
    'paid_once': ('Paid: one order (attempt number), one invoice, native -1 reservation; repeated complete, webhook and '
                  'reconcile change nothing', 'paid_message', 'paid_success_message_once'),
    'concurrent_finalization': ('Webhook and complete at the same time: one order, one invoice, one reservation',
                                'concurrent_webhook_first', 'concurrent_webhook_first'),
    'redirect_unchanged': ('Redirect mode: order before Aplazo, no attempt, no popup, no mode=popup; reservations '
                           'as before', 'redirect_regression', 'redirect_mode_unchanged'),
}


def uncertain_verdict(d):
    """us_uncertain_reclick only records its steps (ok=None in pf_e2e.py). Explicit criteria, from its docstring."""
    steps = {s['step']: s for s in d.get('steps', [])}
    need = ('first_start', 'second_start_same_cart', 'reconcile_keeps_it')
    if any(k not in steps for k in need):
        return False, 'missing steps: %s' % [k for k in need if k not in steps]
    first, second, last = (steps[k] for k in need)
    checks = {
        'first start answers uncertain': (first.get('response') or {}).get('status') == 'uncertain',
        'second start answers uncertain': (second.get('response') or {}).get('status') == 'uncertain',
        'one createLoan in total': last.get('create_loan_calls') == 1,
        'one attempt, still uncertain': last.get('attempts_for_quote') == 1
        and [a['status'] for a in last.get('attempts', [])] == ['uncertain'],
        'no order': d.get('orders') == 0,
        'no reservation': d.get('reservations') == [],
    }
    failed = [k for k, v in checks.items() if not v]
    return not failed, ('failed: %s' % failed) if failed else 'all criteria met'


EXPLICIT = {'uncertain_start_uncertain_reclick': uncertain_verdict}


def prerequisites(m):
    """Returns the list of problems; empty when the local stack can run the selection."""
    problems = []
    if not m or not os.path.isfile(os.path.join(m, 'bin', 'clinotty')):
        return ['MAGENTO_DIR must point at the docker-magento directory (bin/clinotty)']
    ctx = ssl.create_default_context(); ctx.check_hostname = False; ctx.verify_mode = ssl.CERT_NONE
    for label, url in (('Magento https://magento.test', 'https://magento.test/'),
                       ('mock_https.py on 127.0.0.1:8443', 'https://127.0.0.1:8443/__hits')):
        try:
            urllib.request.urlopen(url, context=ctx, timeout=15).read(1)
        except Exception as e:  # noqa: BLE001
            problems.append('%s not reachable: %s' % (label, type(e).__name__))
    chrome = os.environ.get('CHROME', '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome')
    if not os.path.isfile(chrome):
        problems.append('Chrome not found at %s (set CHROME)' % chrome)
    try:
        status = subprocess.run(['bin/clinotty', 'bin/magento', 'module:status', 'LocalDev_AplazoStub'], cwd=m,
                                capture_output=True, text=True, timeout=120).stdout
        if 'Module is enabled' not in status:
            problems.append('LocalDev_AplazoStub is not enabled (the stub replaces the Aplazo API)')
    except Exception as e:  # noqa: BLE001
        problems.append('bin/clinotty failed: %s' % type(e).__name__)
    return problems


def main(argv):
    setup = '--no-setup' not in argv
    keys = [a for a in argv if not a.startswith('--')] or list(SELECTION)
    unknown = [k for k in keys if k not in SELECTION]
    if unknown:
        sys.exit('unknown keys %s; available: %s' % (unknown, ', '.join(SELECTION)))
    m = os.path.expanduser(os.environ.get('MAGENTO_DIR', ''))
    started = time.strftime('%Y-%m-%d %H:%M:%S %Z')
    results = {}
    problems = prerequisites(m)
    if problems:
        for k in keys:
            results[k] = dict(verdict='NOT_RUN', reason='environment: ' + '; '.join(problems))
        return finish(started, keys, results, problems)

    import pf_e2e  # noqa: E402  (reads MAGENTO_DIR at import)
    jsonl = os.path.join(pf_e2e.OUT, 'pf.jsonl')
    if setup:
        pf_e2e.setup()
    for k in keys:
        covers, test, recorded = SELECTION[k]
        offset = os.path.getsize(jsonl) if os.path.exists(jsonl) else 0
        t0 = time.time()
        try:
            pf_e2e.TESTS[test]()
        except Exception as e:  # noqa: BLE001  same handling as pf_e2e.py's own loop
            import traceback
            pf_e2e.record(test, False, error='%s: %s' % (type(e).__name__, e), where=traceback.format_exc().splitlines()[-3:])
        finally:
            pf_e2e.reset_switches()
        entries = []
        if os.path.exists(jsonl):
            with open(jsonl) as fh:
                fh.seek(offset)
                entries = [json.loads(line) for line in fh if line.strip()]
        mine = [e for e in entries if e.get('test') in (recorded, test)]
        res = dict(scenario=test, recorded=recorded, seconds=round(time.time() - t0, 1), evidence='pf.jsonl')
        if not mine:
            res.update(verdict='ERROR', reason='the scenario recorded nothing')
        elif 'error' in mine[-1]:
            res.update(verdict='ERROR', reason='scenario crashed: %s' % mine[-1]['error'], where=mine[-1].get('where'))
        elif recorded in EXPLICIT:
            ok, why = EXPLICIT[recorded](mine[-1])
            res.update(verdict='PASS' if ok else 'FAIL', reason='explicit criteria (qa_selection.py): ' + why)
        else:
            res.update(verdict='PASS' if mine[-1].get('ok') else 'FAIL', reason="pf_e2e.py assertion (ok=%s)" % mine[-1].get('ok'))
        results[k] = res
    return finish(started, keys, results, [])


def finish(started, keys, results, problems):
    out = os.path.abspath(os.path.join(HERE, '..', '.out', 'magento-e2e'))
    os.makedirs(out, exist_ok=True)
    summary = dict(label=LABEL, started=started, finished=time.strftime('%Y-%m-%d %H:%M:%S %Z'),
                   environment_problems=problems,
                   counts={v: sum(1 for r in results.values() if r['verdict'] == v)
                           for v in ('PASS', 'FAIL', 'ERROR', 'NOT_RUN')},
                   results={k: dict(covers=SELECTION[k][0], **results[k]) for k in keys})
    path = os.path.join(out, 'qa-selection-%s.json' % time.strftime('%Y%m%d-%H%M%S'))
    with open(path, 'w') as fh:
        json.dump(summary, fh, ensure_ascii=False, indent=1)
    print('\n' + LABEL)
    for k in keys:
        print('%-8s %-24s %s' % (results[k]['verdict'], k, results[k].get('reason', '')))
    print('counts: %s' % summary['counts'])
    print('summary: %s' % path)
    return 0 if all(r['verdict'] == 'PASS' for r in results.values()) else 1


if __name__ == '__main__':
    sys.exit(main(sys.argv[1:]))
