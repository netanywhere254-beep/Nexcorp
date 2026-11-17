const { test } = require('node:test');
const assert = require('node:assert/strict');
const { app } = require('../src/server');

test('live health endpoint identifies the standby architecture', async () => {
  const server = app.listen(0, '127.0.0.1');
  await new Promise((resolve) => server.once('listening', resolve));

  try {
    const address = server.address();
    const response = await fetch('http://127.0.0.1:' + address.port + '/health/live');
    const body = await response.json();

    assert.equal(response.status, 200);
    assert.equal(body.status, 'ok');
    assert.equal(body.architecture, 'next-node-postgresql');
  } finally {
    await new Promise((resolve, reject) => server.close((error) => error ? reject(error) : resolve()));
  }
});
