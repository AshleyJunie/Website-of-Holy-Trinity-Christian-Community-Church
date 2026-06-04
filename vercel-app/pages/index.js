import { useEffect, useState } from 'react';

export default function Home() {
  const [status, setStatus] = useState('loading');

  useEffect(() => {
    fetch('/api/db')
      .then(r => r.json())
      .then(j => setStatus(j.ok ? 'db-ok' : 'db-error'))
      .catch(() => setStatus('fetch-error'));
  }, []);

  return (
    <main style={{fontFamily:'Arial, sans-serif', padding:40}}>
      <h1>HTCCC — Vercel Preview</h1>
      <p>Database status: <strong>{status}</strong></p>
      <p>To deploy this, set the Vercel project root to <code>vercel-app</code> and add DB environment variables in Vercel.</p>
    </main>
  );
}
