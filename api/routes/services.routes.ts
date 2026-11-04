import { route } from "../docs/openapi";

export const servicesRoutes = {
    "/api/services/version": {
        GET: route(
            { tags: ["Système"], summary: "Version de l'API", responses: { 200: "Version courante" } },
            () => Response.json({ version: "1.0.0" })
        ),
    },

    "/api/services/status": {
        GET: route(
            { tags: ["Système"], summary: "Vérifier le statut de l'API", responses: { 200: "API opérationnelle" } },
            () => Response.json({ status: "ok" })
        ),
    },
};
