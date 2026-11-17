'use client';

import { useState } from 'react';

const apiUrl = process.env.NEXT_PUBLIC_API_URL || 'http://localhost:4100';

export default function HomePage() {
  const [status, setStatus] = useState('Not checked');
  const [busy, setBusy] = useState(false);

  async function checkApi() {
    setBusy(true);
    setStatus('Checking backup API...');
    try {
      const response = await fetch(apiUrl + '/health/live');
      const result = await response.json();
      if (!response.ok) throw new Error('API returned HTTP ' + response.status);
      setStatus('API online — ' + result.architecture);
    } catch (error) {
      setStatus('API check failed — ' + error.message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <main className="shell">
      <section className="panel">
        <p className="eyebrow">NEXORA · STANDBY ARCHITECTURE</p>
        <h1>Backup platform foundation</h1>
        <p className="lead">
          This separate Next.js, Node.js and PostgreSQL stack is retained as an
          alternative to the primary Laravel and MySQL application.
        </p>
        <button onClick={checkApi} disabled={busy}>
          {busy ? 'Checking...' : 'Check backup API'}
        </button>
        <p className="status" aria-live="polite">{status}</p>
        <p className="footnote">
          Standby only. It does not automatically receive or synchronize primary-system data.
        </p>
      </section>
    </main>
  );
}
