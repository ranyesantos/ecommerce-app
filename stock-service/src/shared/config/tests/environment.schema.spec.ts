import { environmentSchema } from '../environment.schema.js';

const validEnvironment = {
  SERVICE_NAME: 'stock-service',
  API_PORT: '3001',
  WORKER_PORT: '3002',
  DATABASE_URL: 'postgresql://stock:stock@localhost:5432/stock_db',
  RABBITMQ_URL: 'amqp://stock:stock@localhost:5672/ecommerce',
  LOG_LEVEL: 'info',
  SHUTDOWN_GRACE_MS: '10000',
};

describe('environmentSchema', () => {
  it('rejects an empty environment', () => {
    expect(() => environmentSchema.parse({})).toThrow();
  });

  it('parses a valid environment', () => {
    expect(environmentSchema.parse(validEnvironment).SERVICE_NAME).toBe(
      'stock-service',
    );
  });

  it.each(['', '   '])(
    'rejects an empty shutdown grace period (%j)',
    (gracePeriod) => {
      expect(() =>
        environmentSchema.parse({
          ...validEnvironment,
          SHUTDOWN_GRACE_MS: gracePeriod,
        }),
      ).toThrow();
    },
  );
});
