import { z } from "zod";
import type { BunRequest, Server } from "bun";

// La doc est générée à partir des routes : chaque handler enveloppé dans
// `route()` porte sa propre description. Rien à redéclarer ici.

type ResponseDoc = string | { description: string; schema?: z.ZodType };

export type RouteDoc = {
    tags?: string[];
    summary: string;
    /** Schéma Zod du corps JSON attendu. */
    body?: z.ZodType;
    /** Route protégée par un token Bearer. */
    auth?: boolean;
    /** Une chaîne suffit ; pour les codes >= 400, le schéma `{ error }` est ajouté. */
    responses: Record<number, ResponseDoc>;
};

type Handler = (req: BunRequest<any>, server: Server<any>) => Response | Promise<Response>;

/** Attache la doc OpenAPI au handler, sans changer son comportement. */
export function route<H extends Handler>(doc: RouteDoc, handler: H): H & { doc: RouteDoc } {
    return Object.assign(handler, { doc });
}

const errorSchema = z.object({ error: z.string() });

const jsonContent = (schema: z.ZodType, io: "input" | "output") => ({
    "application/json": { schema: z.toJSONSchema(schema, { io }) },
});

function toOperation(path: string, doc: RouteDoc) {
    const operation: Record<string, unknown> = { tags: doc.tags, summary: doc.summary };

    // Bun écrit `/users/:id`, OpenAPI attend un paramètre `id` déclaré.
    const params = [...path.matchAll(/:(\w+)/g)].map(([, name]) => ({
        name,
        in: "path",
        required: true,
        schema: { type: "string" },
    }));
    if (params.length) operation.parameters = params;

    if (doc.body) operation.requestBody = { required: true, content: jsonContent(doc.body, "input") };
    if (doc.auth) operation.security = [{ bearerAuth: [] }];

    operation.responses = Object.fromEntries(
        Object.entries(doc.responses).map(([status, res]) => {
            const { description, schema } = typeof res === "string" ? { description: res } : res;
            const body = schema ?? (Number(status) >= 400 ? errorSchema : undefined);
            return [status, body ? { description, content: jsonContent(body, "output") } : { description }];
        })
    );

    return operation;
}

export function buildOpenApiDocument(routes: Record<string, unknown>) {
    const paths: Record<string, Record<string, unknown>> = {};

    for (const [path, methods] of Object.entries(routes)) {
        if (typeof methods !== "object" || methods === null || methods instanceof Response) continue;

        for (const [method, handler] of Object.entries(methods)) {
            const doc = (handler as { doc?: RouteDoc } | undefined)?.doc;
            if (!doc) continue;

            const openApiPath = path.replace(/:(\w+)/g, "{$1}");
            (paths[openApiPath] ??= {})[method.toLowerCase()] = toOperation(path, doc);
        }
    }

    return {
        openapi: "3.1.0",
        info: {
            title: "Idem API",
            version: "1.0.0",
            description: "Documentation de l'API Idem",
        },
        servers: [{ url: "http://localhost:3000" }],
        components: {
            securitySchemes: {
                bearerAuth: { type: "http", scheme: "bearer", bearerFormat: "JWT" },
            },
        },
        paths,
    };
}

/** Routes `/docs` (Scalar) et `/openapi.json`, générées depuis les routes de l'API. */
export function docsRoutes(routes: Record<string, unknown>) {
    return {
        "/openapi.json": Response.json(buildOpenApiDocument(routes)),
        "/docs": new Response(
            `<!DOCTYPE html>
<html>
    <head>
        <title>Idem API Documentation</title>
        <meta charset="UTF-8">
    </head>
    <body>
        <script id="api-reference" data-url="/openapi.json"
            src="https://cdn.jsdelivr.net/npm/@scalar/api-reference"></script>
    </body>
</html>`,
            { headers: { "Content-Type": "text/html; charset=utf-8" } }
        ),
    };
}
