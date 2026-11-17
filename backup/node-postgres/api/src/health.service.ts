import { Injectable, OnModuleDestroy } from '@nestjs/common';
import { Pool } from 'pg';

@Injectable()
export class HealthService implements OnModuleDestroy {
  private readonly pool: Pool;

  constructor() {
    this.pool = new Pool({
      host: process.env.DB_HOST || '127.0.0.1',
      port: Number(process.env.DB_PORT || 5432),
      database: process.env.DB_NAME || 'nexora_backup',
      user: process.env.DB_USER || 'nexora_backup',
      password: process.env.DB_PASSWORD || '',
      max: 10,
      connectionTimeoutMillis: 3000,
      idleTimeoutMillis: 10000,
    });
  }

  live() {
    return {
      status: 'ok',
      service: 'nexora-standby-api',
      architecture: 'next-nestjs-postgresql',
    };
  }

  async databaseReady(): Promise<boolean> {
    try {
      await this.pool.query('SELECT 1');
      return true;
    } catch {
      return false;
    }
  }

  async onModuleDestroy(): Promise<void> {
    await this.pool.end();
  }
}