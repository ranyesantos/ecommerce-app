import { z } from 'zod';

const portSchema = z.coerce.number().int().min(1).max(65_535);
const gracePeriodSchema = z
  .union([z.string().trim().min(1), z.number()])
  .pipe(z.coerce.number<string | number>().int().min(0));

export const environmentSchema = z.object({
  SERVICE_NAME: z.string().trim().min(1),
  API_PORT: portSchema,
  WORKER_PORT: portSchema,
  DATABASE_URL: z.string().url(),
  RABBITMQ_URL: z.string().url(),
  LOG_LEVEL: z.enum(['fatal', 'error', 'warn', 'info', 'debug', 'trace', 'silent']),
  SHUTDOWN_GRACE_MS: gracePeriodSchema,
});

export type Environment = z.infer<typeof environmentSchema>;
