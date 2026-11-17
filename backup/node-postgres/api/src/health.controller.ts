import { Controller, Get, ServiceUnavailableException } from '@nestjs/common';
import { HealthService } from './health.service';

@Controller()
export class HealthController {
  constructor(private readonly health: HealthService) {}

  @Get('health/live')
  live() {
    return this.health.live();
  }

  @Get('health/ready')
  async ready() {
    const isReady = await this.health.databaseReady();
    if (!isReady) {
      throw new ServiceUnavailableException({
        status: 'not_ready',
        database: 'unavailable',
      });
    }
    return { status: 'ready', database: 'connected' };
  }

  @Get('api/info')
  info() {
    return {
      product: 'Nexora Group',
      mode: 'standby-reference',
      framework: 'NestJS',
      note: 'This is an alternate technical foundation, not a synchronized production replica.',
    };
  }
}