import { spawnSync } from 'node:child_process';

const loggerProbe = (statement: string) => `
  import { LoggerModule } from './src/shared/logging/logger.module.ts';
  import pinoHttp from 'pino-http';

  const module = LoggerModule.forRoot('api');
  const pinoModule = module.imports?.find(
    (entry) => typeof entry === 'object' && entry !== null && 'providers' in entry,
  );
  const provider = pinoModule?.providers?.find(
    (entry) =>
      typeof entry === 'object' &&
      entry !== null &&
      'provide' in entry &&
      entry.provide === 'pino-params',
  );
  if (
    !provider ||
    typeof provider !== 'object' ||
    !('useFactory' in provider) ||
    typeof provider.useFactory !== 'function'
  ) {
    throw new Error('Pino params provider not found');
  }

  const params = provider.useFactory({
    getOrThrow: (key) =>
      ({ SERVICE_NAME: 'stock-service', LOG_LEVEL: 'info' })[key],
  });
  const logger = pinoHttp({ ...params.pinoHttp, stream: process.stdout });
  ${statement}
`;

function runLoggerProbe(statement: string) {
  return spawnSync(
    process.execPath,
    ['--import', 'tsx', '--input-type=module', '-e', loggerProbe(statement)],
    {
      cwd: process.cwd(),
      encoding: 'utf8',
      env: {
        SERVICE_NAME: 'stock-service',
        API_PORT: '3001',
        WORKER_PORT: '3002',
        DATABASE_URL: 'postgresql://stock:stock@localhost:5432/stock_db',
        RABBITMQ_URL: 'amqp://stock:stock@localhost:5672/ecommerce',
        LOG_LEVEL: 'info',
        SHUTDOWN_GRACE_MS: '10000',
      },
    },
  );
}

function assertProbeStarted(result: ReturnType<typeof runLoggerProbe>) {
  if (result.status !== 0) {
    throw new Error(result.stderr || 'Logger probe failed without stderr');
  }
}

describe('LoggerModule', () => {
  it('redacts sensitive fields recursively', () => {
    const result = runLoggerProbe(`logger.logger.info({
        nested: {
          deeper: {
            token: 'LEAK_TOKEN',
            password: 'LEAK_PASSWORD',
            secret: 'LEAK_SECRET',
          },
        },
      }, 'logger regression probe');`);

    assertProbeStarted(result);
    expect(result.stdout).not.toContain('LEAK_TOKEN');
    expect(result.stdout).not.toContain('LEAK_PASSWORD');
    expect(result.stdout).not.toContain('LEAK_SECRET');
  });

  it('removes query and fragment from request URLs', () => {
    const result = runLoggerProbe(`logger.logger.info({
        req: {
          id: 1,
          method: 'GET',
          url: 'https://example.test/orders?token=URL_SECRET#fragment',
        },
      }, 'logger regression probe');`);

    assertProbeStarted(result);
    const [record] = result.stdout
      .trim()
      .split(/\r?\n/)
      .map((line) => JSON.parse(line));
    expect(record.req.url).toBe('https://example.test/orders');
  });

  it('redacts sensitive child bindings recursively', () => {
    const result = runLoggerProbe(`logger.logger
      .child({
        nested: {
          deeper: {
            token: 'CHILD_LEAK_TOKEN',
            password: 'CHILD_LEAK_PASSWORD',
            secret: 'CHILD_LEAK_SECRET',
          },
        },
      })
      .info('child binding regression probe');`);

    assertProbeStarted(result);
    expect(result.stdout).not.toContain('CHILD_LEAK_TOKEN');
    expect(result.stdout).not.toContain('CHILD_LEAK_PASSWORD');
    expect(result.stdout).not.toContain('CHILD_LEAK_SECRET');
  });

  it('preserves non-plain structured values', () => {
    const result = runLoggerProbe(`logger.logger.info({
        date: new Date('2026-01-02T03:04:05.000Z'),
        urlValue: new URL('https://example.test/items'),
        buffer: Buffer.from('hello'),
      }, 'structured values regression probe');`);

    assertProbeStarted(result);
    const [record] = result.stdout
      .trim()
      .split(/\r?\n/)
      .map((line) => JSON.parse(line));
    expect(record.date).toBe('2026-01-02T03:04:05.000Z');
    expect(record.urlValue).toBe('https://example.test/items');
    expect(record.buffer).toEqual({
      type: 'Buffer',
      data: [104, 101, 108, 108, 111],
    });
  });
});
