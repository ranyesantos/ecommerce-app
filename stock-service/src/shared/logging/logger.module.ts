import { Module, type DynamicModule } from '@nestjs/common';
import { ConfigService } from '@nestjs/config';
import { LoggerModule as PinoLoggerModule, type Params } from 'nestjs-pino';

import type { Environment } from '../config/environment.schema.js';
import { ConfigModule } from '../config/config.module.js';

export type LoggerRole = 'api' | 'worker';

const sensitivePaths = [
  'req.headers.authorization',
  'req.headers.cookie',
  'req.headers["set-cookie"]',
  'headers.authorization',
  'headers.cookie',
  '*.password',
  '*.token',
  '*.secret',
  '*.payload',
  '*.body',
  'password',
  'token',
  'secret',
  'payload',
  'body',
  'req.body',
  'res.body',
  'accessToken',
  'refreshToken',
  'DATABASE_URL',
  'RABBITMQ_URL',
];

@Module({})
export class LoggerModule {
  static forRoot(role: LoggerRole): DynamicModule {
    return {
      module: LoggerModule,
      imports: [
        ConfigModule,
        PinoLoggerModule.forRootAsync({
          imports: [ConfigModule],
          inject: [ConfigService],
          useFactory: (config: ConfigService<Environment, true>): Params => ({
            pinoHttp: {
              level: config.getOrThrow('LOG_LEVEL', { infer: true }),
              stream: process.stdout,
              base: {
                service: config.getOrThrow('SERVICE_NAME', { infer: true }),
                role,
              },
              redact: {
                paths: sensitivePaths,
                remove: true,
              },
              serializers: {
                req: (request) => ({
                  id: request.id,
                  method: request.method,
                  url: request.url,
                }),
                res: (response) => ({
                  statusCode: response.statusCode,
                }),
              },
            },
          }),
        }),
      ],
    };
  }
}
