const express = require('express');
const { Pool } = require('pg');

const app = express();
const port = Number(process.env.PORT || 4000);
const allowedOrigin = process.env.WEB_ORIGIN || 'http://localhost:3100';

const pool = new Pool({
  host: process.env.DB_HOST || '127.0.0.1',
  port: Number(process.env.DB_PORT || 5432),
  database: process.env.DB_NAME || 'nexora_backup',
  user: process.env.DB_USER || 'nexora_backup',
  password: process.env.DB_PASSWORD || '',
  max: 10,
  connectionTimeoutMillis: 5000
});

app.use((req, res, next) => {
  res.setHeader('Access-Control-Allow-Origin', allowedOrigin);
  res.setHeader('Access-Control-Allow-Methods', 'GET,POST,OPTIONS');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type,Authorization');
  if (req.method === 'OPTIONS') return res.sendStatus(204);
  next();
});
app.use(express.json({ limit: '32kb' }));

app.get('/health/live', (_req, res) => {
  res.status(200).json({
    status: 'ok',
    service: 'nexora-backup-api',
    architecture: 'next-node-postgresql'
  });
});

app.get('/health/ready', async (_req, res) => {
  try {
    await pool.query('SELECT 1');
    res.status(200).json({ status: 'ready', database: 'connected' });
  } catch (_error) {
    res.status(503).json({ status: 'not_ready', database: 'unavailable' });
  }
});

app.get('/api/info', (_req, res) => {
  res.json({
    product: 'Nexora',
    mode: 'standby-reference',
    note: 'This is an alternate technical foundation, not a synchronized production replica.'
  });
});

if (require.main === module) {
  const server = app.listen(port, '0.0.0.0', () => {
    process.stdout.write('Nexora backup API listening on port ' + port + '\n');
  });

  const shutdown = () => {
    server.close(async () => {
      await pool.end();
      process.exit(0);
    });
  };

  process.on('SIGTERM', shutdown);
  process.on('SIGINT', shutdown);
}

module.exports = { app, pool };
