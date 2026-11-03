import * as dotenv from "dotenv";
import { SQL } from "bun";

// Load environment variables from .env file
dotenv.config();

const isDev = process.env.APP_ENV === "dev";

// Set up PostgreSQL connection parameters
const PostegresUsers = process.env.POSTGRES_USER ?? "IdemUsers";
const PostegresPassword = process.env.POSTGRES_PASSWORD ?? "IdemPassword";
const PostegresDB = process.env.POSTGRES_DB ?? "IdemDB";
const PostegresConnectionString = `postgres://${PostegresUsers}:${PostegresPassword}@postgres:5432/${PostegresDB}`;

const pg = new SQL(process.env.DATABASE_URL ?? PostegresConnectionString);

// Create a Bun server
const server = Bun.serve({
    development: isDev,
    port: 3000,
    routes: {
        // Define API version
        "/api/version": new Response(JSON.stringify({ version: "1.0.0" }), {
            headers: {
                "Content-Type": "application/json"
            }
        }),

        // Define API status
        "/api/status": new Response(JSON.stringify({ status: "ok" }), {
            headers: {
                "Content-Type": "application/json"
            }
        }),
    },

    // Handle unmatched routes
    fetch() {
        return new Response("Unmatched route", {
            headers: {
                "Content-Type": "text/plain"
            }
        });
    }
});

console.log(`Server running at http://localhost:${server.port}`);