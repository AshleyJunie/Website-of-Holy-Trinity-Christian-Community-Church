import mysql from 'mysql2/promise';

let pool;
function getPool() {
  if (!pool) {
    pool = mysql.createPool({
      host: process.env.DB_HOST || '127.0.0.1',
      user: process.env.DB_USERNAME || process.env.DB_USER || 'root',
      password: process.env.DB_PASSWORD || '',
      database: process.env.DB_NAME || 'htccc-data-base',
      waitForConnections: true,
      connectionLimit: 5,
      queueLimit: 0
    });
  }
  return pool;
}

export default async function handler(req, res) {
  const pool = getPool();
  try {
    const [rows] = await pool.query('SELECT 1 AS ok');
    res.status(200).json({ ok: true, rows });
  } catch (err) {
    console.error('DB error:', err);
    res.status(500).json({ ok: false, error: 'Database error' });
  }
}
