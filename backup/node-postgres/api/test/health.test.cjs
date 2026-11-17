const { test } = require('node:test');
const assert = require('node:assert/strict');
const { NestFactory } = require('@nestjs/core');
const { AppModule } = require('../dist/app.module');

test('NestJS standby API exposes a live health endpoint', async () => {
  const app = await NestFactory.create(AppModule, { logger: false });
  await app.listen(0, '127.0.0.1');

  try {
    const address = app.getHttpServer().address();
    const response = await fetch('http://127.0.0.1:' + address.port + '/health/live');
    const body = await response.json();

    assert.equal(response.status, 200);
    assert.equal(body.status, 'ok');
    assert.equal(body.architecture, 'next-nestjs-postgresql');
  } finally {
    await app.close();
  }
});