import 'reflect-metadata';
import { NestFactory } from '@nestjs/core';
import { AppModule } from './app.module';

async function bootstrap(): Promise<void> {
  const app = await NestFactory.create(AppModule, { bufferLogs: true });
  const allowedOrigin = process.env.WEB_ORIGIN || 'http://localhost:3100';

  app.enableCors({
    origin: allowedOrigin.split(',').map((origin) => origin.trim()),
    methods: ['GET', 'POST', 'OPTIONS'],
    allowedHeaders: ['Content-Type', 'Authorization'],
  });

  const port = Number(process.env.PORT || 4000);
  await app.listen(port, '0.0.0.0');
}

void bootstrap();