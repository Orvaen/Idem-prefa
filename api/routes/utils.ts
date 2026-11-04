import type { z } from "zod";

/** Réponse d'erreur JSON standard : `{ error, details? }`. */
export function jsonError(error: string, status: number, details?: unknown) {
    return Response.json(details === undefined ? { error } : { error, details }, { status });
}

type BodyResult<T> =
    | { data: T; error?: undefined }
    | { data?: undefined; error: Response };

/**
 * Lit le corps JSON de la requête et le valide avec un schéma Zod.
 * - succès : `{ data }`  → les données, déjà typées
 * - échec  : `{ error }` → une réponse 400 prête à être renvoyée
 */
export async function readBody<S extends z.ZodType>(req: Request, schema: S): Promise<BodyResult<z.output<S>>> {
    // Vérifier si c bien du json
    let json: unknown;
    try {
        json = await req.json();
    } catch {
        return { error: jsonError("Invalid JSON", 400) };
    }

    // Vérifier si c le bon schema
    const validation = schema.safeParse(json);
    if (!validation.success) {
        return { error: jsonError("Validation failed", 400, validation.error.flatten().fieldErrors) };
    }

    return { data: validation.data };
}

export async function getToken(req: Request) {
    let auth = req.headers.get('authorization')
    
    if (!auth?.startsWith("Bearer ")) {
        return  Response.json(
            { error: "Missing token" }, 
            { status: 401 }
        );
    }
}