import * as dotenv from "dotenv";
import { docsRoutes } from "./docs/openapi";

// All routes
import { servicesRoutes } from "./routes/services.routes";
import { authRoutes } from "./routes/auth.routes";


// Load environment variables from .env file
dotenv.config();

const isDev = process.env.APP_ENV === "dev";

// Toutes les routes de l'API : la doc Scalar est générée à partir d'elles
const apiRoutes = {
    ...servicesRoutes,
    ...authRoutes,
};

// Create a Bun server
const server = Bun.serve({
    development: isDev,
    port: 3000,
    routes: {
        ...apiRoutes,
        ...docsRoutes(apiRoutes),
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