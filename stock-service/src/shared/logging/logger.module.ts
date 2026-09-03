import { Module, type DynamicModule } from '@nestjs/common';
import { ConfigService } from '@nestjs/config';
import { LoggerModule as PinoLoggerModule, type Params } from 'nestjs-pino';
import pino from 'pino';

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

  const prototype = Object.getPrototypeOf(value);
  if (prototype !== Object.prototype && prototype !== null && !Array.isArray(value)) {
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

function sanitizeLogLine(line: string): string {
  const lineEnding = line.endsWith('\r\n')
    ? '\r\n'
    : line.endsWith('\n')
      ? '\n'
      : '';
  const json = line.slice(0, line.length - lineEnding.length);

  try {
    return `${JSON.stringify(sanitizeLogValue(JSON.parse(json)))}${lineEnding}`;
  } catch {
    return `${JSON.stringify({ level: 50, msg: 'log sanitization failed' })}${lineEnding}`;
  }
}

const safeSerializers = {
  req: (request: { id?: unknown; method?: unknown; url?: unknown }) => ({
    id: request.id,
    method: request.method,
    url: sanitizeUrl(request.url),
  }),
  res: (response: { statusCode?: unknown }) => ({
    statusCode: response.statusCode,
  }),
};

const sensitivePaths = [
  'req.headers.authorization',
  'req.headers.cookie',
  'req.headers["set-cookie"]',
  'headers.authorization',
  'headers.cookie',
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

function createPinoLogger(
  service: string,
  level: string,
  role: LoggerRole,
): pino.Logger {
  const logger = pino(
    {
      level,
      base: {
        service,
        role,
      },
      redact: {
        paths: sensitivePaths,
        remove: true,
      },
      serializers: safeSerializers,
      hooks: {
        logMethod(args, method) {
          method.apply(
            this,
            args.map((argument) => sanitizeLogValue(argument)) as Parameters<
              typeof method
            >,
          );
        },
        streamWrite: sanitizeLogLine,
      },
      formatters: {
        bindings: (bindings) =>
          sanitizeLogValue(bindings) as Record<string, unknown>,
      },
    },
    process.stdout,
  );

  const originalChild = logger.child;
  logger.child = (function (
    this: typeof logger,
    bindings: Record<string, unknown>,
    options?: Parameters<typeof originalChild>[1],
  ) {
    return originalChild.call(
      this,
      sanitizeLogValue(bindings) as Record<string, unknown>,
      options,
    );
  }) as typeof logger.child;

  return logger;
}

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
              logger: createPinoLogger(
                config.getOrThrow('SERVICE_NAME', { infer: true }),
                config.getOrThrow('LOG_LEVEL', { infer: true }),
                role,
              ),
              serializers: safeSerializers,
            },
          }),
        }),
      ],
    };
  }
}
