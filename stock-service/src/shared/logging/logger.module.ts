import { Module, type DynamicModule } from '@nestjs/common';
import { ConfigService } from '@nestjs/config';
import { LoggerModule as PinoLoggerModule, type Params } from 'nestjs-pino';

import type { Environment } from '../config/environment.schema.js';
import { ConfigModule } from '../config/config.module.js';

export type LoggerRole = 'api' | 'worker';

const sensitiveKeyPattern =
  /password|passphrase|token|secret|authorization|cookie|payload|body|api[-_]?key|database[-_]?url|rabbitmq[-_]?url/i;

function sanitizeUrl(url: unknown): unknown {
  if (typeof url !== 'string') {
    return url;
  }

  const queryStart = url.indexOf('?');
  const fragmentStart = url.indexOf('#');
  const sensitiveStart =
    queryStart === -1
      ? fragmentStart
      : fragmentStart === -1
        ? queryStart
        : Math.min(queryStart, fragmentStart);

  return sensitiveStart === -1 ? url : url.slice(0, sensitiveStart);
}

function sanitizeLogValue(value: unknown, seen = new WeakSet<object>()): unknown {
  if (value === null || typeof value !== 'object') {
    return value;
  }

  if (value instanceof Error) {
    return value;
  }

  if (seen.has(value)) {
    return '[Circular]';
  }
  seen.add(value);

  if (Array.isArray(value)) {
    const sanitizedArray = value.map((item) => sanitizeLogValue(item, seen));
    seen.delete(value);
    return sanitizedArray;
  }

  const sanitizedObject: Record<string, unknown> = {};
  for (const [key, nestedValue] of Object.entries(value)) {
    if (sensitiveKeyPattern.test(key)) {
      continue;
    }

    sanitizedObject[key] = sanitizeLogValue(nestedValue, seen);
  }

  seen.delete(value);
  return sanitizedObject;
}

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
                  url: sanitizeUrl(request.url),
                }),
                res: (response) => ({
                  statusCode: response.statusCode,
                }),
              },
              hooks: {
                logMethod(args, method) {
                  method.apply(
                    this,
                    args.map((argument) => sanitizeLogValue(argument)) as Parameters<
                      typeof method
                    >,
                  );
                },
              },
            },
          }),
        }),
      ],
    };
  }
}
