import { SQL } from "bun";

const connectionString =
    process.env.DATABASE_URL ??
    `postgres://${process.env.POSTGRES_USER ?? "IdemUsers"}:${process.env.POSTGRES_PASSWORD ?? "IdemPassword"}@postgres:5432/idemdb2`;

export const pg = new SQL(connectionString);