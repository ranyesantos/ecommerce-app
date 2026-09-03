import { Module } from '@nestjs/common';
import { ConfigModule as NestConfigModule } from '@nestjs/config';

import { environmentSchema } from './environment.schema.js';

@Module({
  imports: [
    NestConfigModule.forRoot({
      cache: true,
      isGlobal: true,
      validate: (config) => environmentSchema.parse(config),
    }),
  ],
  exports: [NestConfigModule],
})
export class ConfigModule {}
