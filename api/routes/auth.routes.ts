import { route } from "../docs/openapi";
import { LoginResponseSchema, loginSchema, RegisterSchema } from "../schemas/auth.schema";
import { login, register } from "../services/auth.service";
import { authentificate } from "../middleware/auth.middleware";
import { jsonError, readBody } from "./utils";

export const authRoutes = {
    "/api/auth/login": {
        POST: route({
            tags: ["Auth"],
            summary: "Connexion",
            body: loginSchema,
            responses: {
                200: { description: "Connexion réussie", schema: LoginResponseSchema },
                400: "JSON invalide ou validation échouée",
                401: "Identifiant ou mot de passe invalide",
                500: "Erreur serveur",
            },
        }, async (req: Request) => {
            let body: unknown;

            try {
                body = await req.json();
            } catch {
                return Response.json(
                    { error: "Invalid JSON" },
                    { status: 400 }
                );
            }

            const validation = loginSchema.safeParse(body);

            if (!validation.success) {
                return Response.json(
                    {
                        error: "Validation failed",
                        details: validation.error.flatten().fieldErrors,
                    },
                    { status: 400 }
                );
            }

            try {
                const result = await login(
                    validation.data.identifiant,
                    validation.data.password
                );

                if (!result) {
                    return Response.json(
                        { error: "Invalid identifiant or password" },
                        { status: 401 }
                    );
                }

                return Response.json(result);
            } catch (error) {
                console.error("Login error:", error);

                return Response.json(
                    { error: "Internal server error" },
                    { status: 500 }
                );
            }
        }),
    },

    "/api/auth/@me": {
        GET: route({
            tags: ["Auth"],
            summary: "Utilisateur connecté",
            auth: true,
            responses: {
                200: "Informations de l'utilisateur",
                401: "Token invalide ou manquant",
            },
        }, async (req: Request) => {
            const auth = await authentificate(req);

            if (auth.error) return auth.error;

            return Response.json(auth.user);
        }),
    },

    "/api/auth/register": {
        POST: route({
            tags: ["Auth"],
            summary: "Créer un compte",
            body: RegisterSchema,
            responses: {
                200: "Utilisateur créé",
                400: "JSON invalide ou validation échouée",
                409: "Email déjà utilisé",
                500: "Erreur serveur",
            },
        }, async (req: Request) => {
            // 1. Lire et valider le corps de la requête (sinon : 400)
            const body = await readBody(req, RegisterSchema);
            if (body.error) return body.error;

            const { name, prenom, email, password } = body.data;

            // 2. Créer le compte (un email vide "" = pas d'email)
            try {
                const result = await register({ name, prenom, password, email: email || null });
                return Response.json(result);
            } catch (err) {
                // 3. Erreurs : email déjà pris (409) ou imprévue (500)
                if (err instanceof Error && err.message === "EMAIL_ALREADY_EXISTS") {
                    return jsonError("Email already exists", 409);
                }

                console.error("Register error:", err);
                return jsonError("Internal server error", 500);
            }
        }),
    },
};
